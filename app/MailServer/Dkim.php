<?php

namespace App\MailServer;

use App\MailServer\Dns\DnsHealth;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Domain;
use App\Models\DomainDkim;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A domain's DKIM keys as the console manages them (spec decisions 2 to 5). The app never touches a
 * key: it queues `dkim.*` tasks for the helper that runs as `_rspamd` (helpers/dkim.php) and reads the
 * public results back into `domain_dkim`.
 *
 * Results are read on request: every read of a domain's keys, and every DKIM write before it decides,
 * first applies the domain's finished tasks that the app has not read yet (sync()). The console is the
 * only place the rows are shown or acted on, so a result is applied before anyone can see or act on
 * stale state, without a scheduler entry; `helper_tasks.applied_at`, set under the task rows' locks,
 * makes each result apply exactly once.
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

    /** Any selector a helper result may name (a DNS label), within domain_dkim.selector's 32 characters. */
    private const LABEL = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D';

    /** A DKIM TXT record as the helper builds it: printable ASCII, at most 4 KiB. */
    private const TXT = '/^v=DKIM1;[\x20-\x7e]{1,4087}$/D';

    public function __construct(
        private readonly HelperTasks $tasks,
        private readonly AuditLog $audit,
        private readonly DnsHealth $dns,
    ) {}

    /**
     * The domain's keys (newest first), its active selector, and the DKIM task the helper has yet to
     * finish, or the newest one when it failed.
     *
     * @return array{active: string|null, keys: list<array{selector: string, state: 'pending'|'active'|'retired', txt: string|null, created_at: string|null}>, pending_task: array{type: string, status: string}|null}
     */
    public function state(Domain $domain): array
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
                ? ['type' => $latest->type, 'status' => $latest->status]
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
            if (Domain::query()->whereKey($domain->id)->lockForUpdate()->first() === null) {
                return;
            }
            foreach ($this->tasks->dkimUnapplied($domain, lock: true) as $task) {
                if ($task->applied_at !== null) {
                    continue;
                }
                if ($task->status === 'done') {
                    $this->apply($domain, $task->type, $task->result ?? []);
                }
                // Not updated_at: for an activation it says when rspamd's map changed (the retire hold).
                $task->timestamps = false;
                $task->forceFill(['applied_at' => now()])->save();
            }
        });
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
     * admin forces it, the latest DNS check must show the key's record (Review Focus 2); `forced` in the
     * audit row says the activation went ahead without that.
     */
    public function activate(Domain $domain, string $selector, bool $force): void
    {
        $this->write($domain, function (Domain $live) use ($selector, $force) {
            $key = $this->key($live, $selector);
            $this->ensureIdle($live);
            if ($key->state === 'active') {
                throw new Conflict('dkim_active', __('emailsquire.dkim.active', ['selector' => $key->selector, 'domain' => $live->name]));
            }
            $published = $this->dns->selectorPublished($live, $key->selector);
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
     * Reads results first, then runs $change in a transaction that holds the domain row's lock. A domain
     * deleted meanwhile is a 404.
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
            $change($live);
        });
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
     * One finished task's result. The helper writes only public data, but the app checks its shape
     * anyway: a result that doesn't fit changes nothing.
     *
     * @param  array<string, mixed>  $result
     */
    private function apply(Domain $domain, string $type, array $result): void
    {
        $selector = self::selector($result['selector'] ?? null);
        $txt = self::txt($result['txt'] ?? null);

        match ($type) {
            'dkim.generate' => $selector !== null && $txt !== null ? $this->put($domain, $selector, $txt, 'pending') : null,
            'dkim.activate' => $selector !== null ? $this->activated($domain, $selector, $txt) : null,
            'dkim.retire' => $selector !== null ? $this->retired($domain, $selector) : null,
            'dkim.inspect' => $this->inspected($domain, $result),
            default => null,
        };
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
     * selector, `pending` otherwise), and rows whose file is gone become `retired`.
     *
     * @param  array<string, mixed>  $result
     */
    private function inspected(Domain $domain, array $result): void
    {
        $active = self::selector($result['active_selector'] ?? null);
        $found = [];
        foreach (is_array($result['keys'] ?? null) ? $result['keys'] : [] as $entry) {
            $selector = is_array($entry) ? self::selector($entry['selector'] ?? null) : null;
            if ($selector === null) {
                continue;
            }
            $found[] = strtolower($selector);
            $state = $active !== null && strcasecmp($active, $selector) === 0 ? 'active' : 'pending';
            $this->put($domain, $selector, self::txt($entry['txt'] ?? null), $state);
        }

        foreach ($this->keys($domain) as $key) {
            if ($key->state !== 'retired' && ! in_array(strtolower($key->selector), $found, true)) {
                $key->forceFill(['state' => 'retired'])->save();
            }
        }
    }

    /**
     * Creates or updates the domain's row for $selector. A null $txt keeps the row's record, or stores
     * '' for a key whose public half the helper could not derive. A row of an earlier domain with this id
     * (another name) is taken over.
     *
     * @param  'pending'|'active'  $state
     */
    private function put(Domain $domain, string $selector, ?string $txt, string $state): void
    {
        $key = DomainDkim::query()->where('domain_id', $domain->id)->where('selector', $selector)->first();
        $stale = $key !== null && strtolower($key->domain_name) !== strtolower($domain->name);
        $key ??= new DomainDkim;

        $key->forceFill([
            'domain_id' => $domain->id,
            'domain_name' => $domain->name,
            'selector' => $selector,
            'txt' => $txt ?? ($key->exists && ! $stale ? $key->txt : ''),
            'state' => $state,
        ])->save();
    }

    private static function selector(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::LABEL, $value) === 1 ? $value : null;
    }

    private static function txt(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::TXT, $value) === 1 ? $value : null;
    }
}
