<?php

namespace App\MailServer;

use App\MailServer\Dns\DnsHealth;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Domain;
use App\Models\DomainDkim;
use App\Models\HelperTask;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * A domain's DKIM keys as the console manages them (spec decisions 2 to 5). The app never touches a
 * key: it queues `dkim.*` tasks for the helper that runs as `_rspamd` (helpers/dkim.php) and reads the
 * public results back into `domain_dkim`.
 *
 * Results are read on request: every read of a domain's keys, every DKIM write (under the same lock,
 * before it decides) and every DNS check (CheckDomainDns) first applies the domain's finished tasks
 * that the app has not read yet. `helper_tasks.applied_at`, set under the domain row's and the task
 * rows' locks, makes each result apply exactly once.
 *
 * Results are checked strictly: the selector comes from the task's own payload (or, for a new key, must
 * be one the helper names), and a record must be `v=DKIM1; k=rsa; p=<an RSA public key>`. A result that
 * doesn't fit changes nothing; its task becomes `failed` with the reason, which admins see.
 *
 * Every write takes the domain row's lock first (the guide lock order), refuses while another DKIM task
 * of the domain is pending or running (`dkim_busy`), and audits the admin's request when it is queued:
 * the console asked for the change, and the helper's outcome shows in the key list.
 */
class Dkim
{
    /**
     * How long Retire waits after an activation: rspamd re-reads the selector map on a poll interval, so
     * for a while it may still sign with the previous key, which must stay until DNS caches of mail
     * signed with it have expired too.
     */
    public const RETIRE_HOLD_SECONDS = 900;

    /** A selector the console can activate or retire: what the helper accepts (KeyStore::SELECTOR). */
    public const SELECTOR = '^es[0-9]{8}[a-z]?$';

    /** Any selector an inspection may report (a DNS label), within domain_dkim.selector's 32 characters. */
    private const LABEL = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D';

    /** A DKIM record exactly as the helper builds it (KeyStore::txtOf()); `p` is checked as a key too. */
    private const TXT = '/^v=DKIM1; k=rsa; p=([A-Za-z0-9+\/]+={0,2})$/D';

    /** An RSA 8192 public key is about 1.4 KiB in base64; anything far above is no key. */
    private const TXT_MAX = 4096;

    public function __construct(
        private readonly HelperTasks $tasks,
        private readonly AuditLog $audit,
        private readonly DnsHealth $dns,
    ) {}

    /**
     * The domain's keys (newest first), its active selector, and the DKIM task the helper has yet to
     * finish, or the newest one when it failed. A failed task's `error` is given only $withError (to
     * admins); it names no key material, the helper and the app make sure of that.
     *
     * @return array{active: string|null, keys: list<array{selector: string, state: 'pending'|'active'|'retired', txt: string|null, created_at: string|null}>, pending_task: array{type: string, status: string, error: string|null}|null}
     */
    public function state(Domain $domain, bool $withError = false): array
    {
        $this->sync($domain);

        $keys = $this->keys($domain);
        $latest = $this->tasks->dkimLatest($domain);

        return [
            'active' => $keys->first(fn (DomainDkim $key) => $key->state === 'active')?->selector,
            'keys' => array_values(array_map(fn (DomainDkim $key) => [
                'selector' => $key->selector,
                'state' => $key->state,
                'txt' => $key->txt === '' ? null : $key->txt,
                'created_at' => $key->created_at?->toIso8601String(),
            ], $keys->all())),
            'pending_task' => $latest !== null && in_array($latest->status, ['pending', 'running', 'failed'], true)
                ? [
                    'type' => $latest->type,
                    'status' => $latest->status,
                    'error' => $withError && $latest->status === 'failed' ? $latest->last_error : null,
                ]
                : null,
        ];
    }

    /** Applies the domain's finished DKIM tasks the app has not read yet, oldest first, each once. */
    public function sync(Domain $domain): void
    {
        if ($this->tasks->dkimUnapplied($domain)->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($domain) {
            $live = Domain::query()->whereKey($domain->id)->lockForUpdate()->first();
            if ($live !== null) {
                $this->applyFinished($live);
            }
        });
    }

    /**
     * sync() for a queued job that carries a domain's id and name (CheckDomainDns): nothing when the
     * domain was deleted, or its id now carries another name.
     */
    public function syncQueued(int $domainId, string $domainName): void
    {
        $domain = Domain::query()->find($domainId);
        if ($domain instanceof Domain && strtolower($domain->name) === strtolower($domainName)) {
            $this->sync($domain);
        }
    }

    /** Queues a new key (`dkim.generate`), audited as `dkim.generated`. */
    public function generate(Domain $domain): void
    {
        $this->write($domain, function (Domain $live) {
            $this->ensureIdle($live);
            $this->tasks->queueDkim('dkim.generate', $live);
            $this->audit->record('dkim.generated', 'domain', $live->name, $live->name);
        });
    }

    /**
     * Queues pointing rspamd at $selector (`dkim.activate`), audited as `dkim.activated`. Unless the
     * admin forces it, the latest DNS check must show this very key's record, checked after the key row
     * last changed (DnsHealth::selectorPublished(), Review Focus 2); `forced` in the audit row says the
     * activation went ahead without that.
     */
    public function activate(Domain $domain, string $selector, bool $force): void
    {
        $this->write($domain, function (Domain $live) use ($selector, $force) {
            $key = $this->key($live, $selector);
            $this->ensureIdle($live);
            if ($key->state === 'active') {
                throw new Conflict('dkim_active', __('emailsquire.dkim.active', ['selector' => $key->selector, 'domain' => $live->name]));
            }
            $published = $this->dns->selectorPublished($live, $key);
            if (! $published && ! $force) {
                throw new Conflict('dkim_not_published', __('emailsquire.dkim.not_published', ['selector' => $key->selector]));
            }

            $this->tasks->queueDkim('dkim.activate', $live, $key->selector);
            $this->audit->record('dkim.activated', 'domain', $live->name, $live->name, ['selector' => $key->selector, 'forced' => ! $published]);
        });
    }

    /**
     * Queues deleting $selector's key file (`dkim.retire`), audited as `dkim.retired`. Never the active
     * key (the helper refuses it too), and not until RETIRE_HOLD_SECONDS after the domain's latest
     * activation.
     */
    public function retire(Domain $domain, string $selector): void
    {
        $this->write($domain, function (Domain $live) use ($selector) {
            $key = $this->key($live, $selector);
            $this->ensureIdle($live);
            if ($key->state === 'active') {
                throw new Conflict('dkim_active', __('emailsquire.dkim.active', ['selector' => $key->selector, 'domain' => $live->name]));
            }
            $activated = $this->tasks->dkimLastActivated($live);
            if ($activated !== null) {
                $left = $activated->getTimestamp() + self::RETIRE_HOLD_SECONDS - now()->getTimestamp();
                if ($left > 0) {
                    throw new Conflict('dkim_recently_activated', __('emailsquire.dkim.recently_activated'), $left);
                }
            }

            $this->tasks->queueDkim('dkim.retire', $live, $key->selector);
            $this->audit->record('dkim.retired', 'domain', $live->name, $live->name, ['selector' => $key->selector]);
        });
    }

    /**
     * Queues reading the selector map and key directory (`dkim.inspect`), which imports keys the
     * console did not make, such as the installer's. Changes nothing on the server, so not audited.
     */
    public function inspect(Domain $domain): void
    {
        $this->write($domain, function (Domain $live) {
            $this->ensureIdle($live);
            $this->tasks->queueDkim('dkim.inspect', $live);
        });
    }

    /**
     * Runs $change in a transaction that holds the domain row's lock, after applying the finished tasks
     * under that same lock, so it decides on current rows. The results are first applied and committed
     * on their own (sync()), so a refused change (a 409 rolls its transaction back) never undoes them;
     * the second pass inside picks up only a result that landed in between. A domain deleted meanwhile
     * is a 404.
     *
     * @param  \Closure(Domain): void  $change
     */
    private function write(Domain $domain, \Closure $change): void
    {
        $this->sync($domain);

        DB::transaction(function () use ($domain, $change) {
            $live = Domain::query()->whereKey($domain->id)->lockForUpdate()->first();
            if ($live === null) {
                throw (new ModelNotFoundException)->setModel(Domain::class);
            }
            $this->applyFinished($live);
            $change($live);
        });
    }

    /** Must run inside a transaction that holds $domain's row lock. */
    private function applyFinished(Domain $domain): void
    {
        foreach ($this->tasks->dkimUnapplied($domain, lock: true) as $task) {
            if ($task->applied_at !== null) {
                continue;
            }
            // Not updated_at: for an activation it says when rspamd's map changed (the retire hold).
            $task->timestamps = false;
            $fill = ['applied_at' => now()];
            if ($task->status === 'done') {
                try {
                    $this->apply($domain, $task);
                } catch (UnexpectedValueException $e) {
                    $fill += ['status' => 'failed', 'last_error' => 'the console refused the helper\'s result: '.$e->getMessage()];
                }
            }
            $task->forceFill($fill)->save();
        }
    }

    private function ensureIdle(Domain $domain): void
    {
        if ($this->tasks->dkimOpen($domain) !== null) {
            throw new Conflict('dkim_busy', __('emailsquire.dkim.busy', ['domain' => $domain->name]));
        }
    }

    /** The domain's key $selector that is not retired; a 404 otherwise, like a selector nobody has. */
    private function key(Domain $domain, string $selector): DomainDkim
    {
        $key = $this->keys($domain)->first(fn (DomainDkim $key) => $key->selector === $selector && $key->state !== 'retired');
        if ($key === null) {
            throw (new ModelNotFoundException)->setModel(DomainDkim::class);
        }

        return $key;
    }

    /**
     * The domain's rows, newest first. Like the other app rows keyed by a guide domain id, a row counts
     * only while that id still carries its domain_name.
     *
     * @return Collection<int, DomainDkim>
     */
    private function keys(Domain $domain): Collection
    {
        return DomainDkim::query()->where('domain_id', $domain->id)->orderByDesc('id')->get()
            ->filter(fn (DomainDkim $key) => strtolower($key->domain_name) === strtolower($domain->name))
            ->values();
    }

    /**
     * One finished task's result, checked before anything changes.
     *
     * @throws UnexpectedValueException when the result doesn't fit the task (its message says how)
     */
    private function apply(Domain $domain, HelperTask $task): void
    {
        $result = is_array($task->result) ? $task->result : [];
        $payload = is_array($task->payload) ? $task->payload : [];

        switch ($task->type) {
            case 'dkim.generate':
                $selector = $result['selector'] ?? null;
                if (! is_string($selector) || preg_match('/'.self::SELECTOR.'/D', $selector) !== 1) {
                    throw new UnexpectedValueException('no valid selector');
                }
                $this->put($domain, $selector, self::txt($result['txt'] ?? null) ?? throw new UnexpectedValueException('no valid DKIM record'), 'pending');
                break;
            case 'dkim.activate':
                $selector = self::taskSelector($payload, $result);
                $this->activated($domain, $selector, self::optionalTxt($result));
                break;
            case 'dkim.retire':
                $this->retired($domain, self::taskSelector($payload, $result));
                break;
            case 'dkim.inspect':
                $this->inspected($domain, $result);
                break;
            default:
                throw new UnexpectedValueException('an unknown task type');
        }
    }

    private function activated(Domain $domain, string $selector, ?string $txt): void
    {
        foreach ($this->keys($domain) as $key) {
            if ($key->state === 'active' && strcasecmp($key->selector, $selector) !== 0) {
                $key->forceFill(['state' => 'pending'])->save(); // still on disk: it can be activated again or retired
            }
        }
        $this->put($domain, $selector, $txt, 'active');
    }

    private function retired(Domain $domain, string $selector): void
    {
        $this->keys($domain)
            ->filter(fn (DomainDkim $key) => strcasecmp($key->selector, $selector) === 0)
            ->each(fn (DomainDkim $key) => $key->forceFill(['state' => 'retired'])->save());
    }

    /**
     * The key files the helper found are the truth: each one gets a row (`active` for the map's
     * selector, `pending` otherwise), and rows whose file is gone become `retired`. A key file whose
     * selector can't be a row here (longer than 32 characters, or not a DNS label) is left out; a record
     * that is not an RSA public key refuses the whole result.
     *
     * @param  array<array-key, mixed>  $result
     *
     * @throws UnexpectedValueException
     */
    private function inspected(Domain $domain, array $result): void
    {
        $active = $result['active_selector'] ?? null;
        if ($active !== null && (! is_string($active) || preg_match(self::LABEL, $active) !== 1)) {
            throw new UnexpectedValueException('an invalid active selector');
        }
        $entries = $result['keys'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new UnexpectedValueException('no list of keys');
        }

        $keys = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['selector'] ?? null)) {
                throw new UnexpectedValueException('a key without a selector');
            }
            if (preg_match(self::LABEL, $entry['selector']) === 1) {
                $keys[$entry['selector']] = self::optionalTxt($entry);
            }
        }

        foreach ($keys as $selector => $txt) {
            $selector = (string) $selector;
            $state = $active !== null && strcasecmp($active, $selector) === 0 ? 'active' : 'pending';
            $this->put($domain, $selector, $txt, $state);
        }
        $found = array_map(fn (int|string $selector) => strtolower((string) $selector), array_keys($keys));
        foreach ($this->keys($domain) as $key) {
            if ($key->state !== 'retired' && ! in_array(strtolower($key->selector), $found, true)) {
                $key->forceFill(['state' => 'retired'])->save();
            }
        }
    }

    /**
     * Creates or updates the domain's row for $selector. A null $txt keeps the row's record, or stores
     * '' for a key whose public half the helper could not derive. A row of an earlier domain with this id
     * (another name) is taken over. A new key under a known selector (a retired one revived, a stale row,
     * or another record) starts a new life: created_at is reset.
     *
     * @param  'pending'|'active'  $state
     */
    private function put(Domain $domain, string $selector, ?string $txt, string $state): void
    {
        $key = DomainDkim::query()->where('domain_id', $domain->id)->where('selector', $selector)->first();
        $stale = $key !== null && strtolower($key->domain_name) !== strtolower($domain->name);
        $renewed = $key !== null && ($stale || $key->state === 'retired' || ($txt !== null && $key->txt !== '' && $key->txt !== $txt));
        $key ??= new DomainDkim;

        $key->forceFill([
            'domain_id' => $domain->id,
            'domain_name' => $domain->name,
            'selector' => $selector,
            'txt' => $txt ?? ($key->exists && ! $stale ? $key->txt : ''),
            'state' => $state,
        ]);
        if ($renewed) {
            $key->forceFill(['created_at' => now()]);
        }
        $key->save();
    }

    /**
     * The selector an activate or retire acted on: the task's own payload. A result that names another
     * selector is not about this task.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array<array-key, mixed>  $result
     *
     * @throws UnexpectedValueException
     */
    private static function taskSelector(array $payload, array $result): string
    {
        $selector = $payload['selector'] ?? null;
        if (! is_string($selector) || preg_match('/'.self::SELECTOR.'/D', $selector) !== 1) {
            throw new UnexpectedValueException('the task has no valid selector');
        }
        if (($result['selector'] ?? null) !== $selector) {
            throw new UnexpectedValueException('the result names another selector');
        }

        return $selector;
    }

    /**
     * A record that may be absent (null: the helper could not read the key), but is valid when given.
     *
     * @param  array<array-key, mixed>  $result
     *
     * @throws UnexpectedValueException
     */
    private static function optionalTxt(array $result): ?string
    {
        $value = $result['txt'] ?? null;
        if ($value === null) {
            return null;
        }

        return self::txt($value) ?? throw new UnexpectedValueException('no valid DKIM record');
    }

    /**
     * $value when it is exactly `v=DKIM1; k=rsa; p=<base64>` and `p` is an RSA public key, which also
     * keeps private-key DER (or anything else in base64) out of the console.
     */
    private static function txt(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::TXT_MAX || preg_match(self::TXT, $value, $m) !== 1) {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($m[1], 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        while (openssl_error_string() !== false) {
            // drain OpenSSL's error queue, so a refused value leaves nothing behind for the next caller
        }
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? $value : null;
    }
}
