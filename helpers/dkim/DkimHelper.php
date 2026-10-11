<?php

namespace EmailSquire\Helpers\Dkim;

/**
 * Runs the `dkim.*` rows of helper_tasks as rspamd's user (`_rspamd`). Framework-free on purpose, like
 * the maildir helper (docs/backend-plan.md §3): it trusts nothing in a task row. It checks the payload's
 * domain against a strict host name pattern and its exact spelling in virtual_domains, checks the
 * selector's form, and only then builds a path, from its own --dkim-dir and --selector-map.
 *
 * - dkim.generate {domain}: a new key, not used yet. Result {selector, txt}.
 * - dkim.activate {domain, selector}: points the selector map at an existing key. Result {selector, txt}.
 * - dkim.retire {domain, selector}: deletes a key file, never the active one. Result {selector}.
 * - dkim.inspect {domain}: the map's selector and every key's TXT, including keys the helper did not
 *   make. Result {active_selector, keys: [{selector, txt}]}.
 *
 * Results hold public data only; a private key never leaves KeyStore. Refusals fail a task at once;
 * I/O errors retry with backoff, as in the maildir helper.
 */
final class DkimHelper
{
    public const MAX_ATTEMPTS = 5;

    private const IDENTIFIER = '/^[A-Za-z0-9_]+$/';

    /** Lowercase ASCII (IDN as punycode), at least two labels, at most 50 characters. */
    private const DOMAIN = '/^(?=.{1,50}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    private const TYPES = ['dkim.generate', 'dkim.activate', 'dkim.retire', 'dkim.inspect'];

    private readonly KeyStore $keys;

    private readonly SelectorMap $map;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param  (\Closure(): int)|null  $clock  unix time, used to date new selectors
     * @param  (\Closure(string): void)|null  $log  one line per finished task; STDOUT by default
     *
     * @throws \InvalidArgumentException when a setting is unsafe (see checkSettings())
     */
    public function __construct(
        private readonly \PDO $db,
        private readonly string $guideSchema,
        private readonly string $appSchema,
        string $dkimDir,
        string $selectorMap,
        ?\Closure $clock = null,
        ?\Closure $log = null,
    ) {
        self::checkSettings($guideSchema, $appSchema, $dkimDir, $selectorMap);
        $this->keys = new KeyStore(rtrim($dkimDir, '/'));
        $this->map = new SelectorMap($selectorMap);
        $this->clock = $clock ?? time(...);
        $this->log = $log ?? static function (string $line): void {
            fwrite(STDOUT, $line.PHP_EOL);
        };

        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("SET time_zone = '+00:00'");
    }

    /**
     * The command line calls this before it connects, so a bad unit file fails fast with usage.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkSettings(string $guideSchema, string $appSchema, string $dkimDir, string $selectorMap): void
    {
        foreach ([$guideSchema, $appSchema] as $schema) {
            if (preg_match(self::IDENTIFIER, $schema) !== 1) {
                throw new \InvalidArgumentException('schema names must match '.self::IDENTIFIER);
            }
        }
        if (! str_starts_with($dkimDir, '/') || rtrim($dkimDir, '/') === '' || str_contains($dkimDir, "\0")) {
            throw new \InvalidArgumentException('the DKIM directory must be an absolute path other than /');
        }
        if (! str_starts_with($selectorMap, '/') || str_ends_with($selectorMap, '/') || str_contains($selectorMap, "\0")
            || in_array(basename($selectorMap), ['.', '..'], true)) {
            throw new \InvalidArgumentException('the selector map must be an absolute path to a file');
        }
    }

    /** @return array{done: int, failed: int, retried: int} */
    public function run(int $limit = 50): array
    {
        $tasks = "`{$this->appSchema}`.helper_tasks";
        $counts = ['done' => 0, 'failed' => 0, 'retried' => 0];

        // systemd never runs two helpers at once, so a running row was left behind by a crash.
        $this->db->exec("UPDATE {$tasks} SET status = 'pending', updated_at = NOW() WHERE status = 'running' AND type LIKE 'dkim.%'");

        $due = $this->db->prepare(
            "SELECT id, type, domain_name, payload, attempts FROM {$tasks}
             WHERE status = 'pending' AND type LIKE 'dkim.%' AND run_after <= NOW() ORDER BY id LIMIT ?"
        );
        $due->bindValue(1, max(0, $limit), \PDO::PARAM_INT);
        $due->execute();
        /** @var list<array{id: int|string, type: string, domain_name: string|null, payload: string|null, attempts: int|string}> $rows */
        $rows = $due->fetchAll(\PDO::FETCH_ASSOC);

        $claim = $this->db->prepare("UPDATE {$tasks} SET status = 'running', updated_at = NOW() WHERE id = ? AND status = 'pending'");
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $claim->execute([$id]);
            if ($claim->rowCount() !== 1) {
                continue;
            }

            try {
                [$note, $result] = $this->handle($row);
            } catch (Refused $e) {
                $this->finish($id, 'failed', $e->getMessage(), 'attempts = attempts + 1, last_error = ?, result = NULL', [$e->getMessage()]);
                $counts['failed']++;

                continue;
            } catch (\Throwable $e) {
                // Anything else (a missing directory, a full disk, a failed query) may pass: back off.
                $message = $e::class === \RuntimeException::class ? $e->getMessage() : $e::class.': '.$e->getMessage();
                if (($attempts = (int) $row['attempts'] + 1) >= self::MAX_ATTEMPTS) {
                    $this->finish($id, 'failed', $message, 'attempts = ?, last_error = ?, result = NULL', [$attempts, $message]);
                    $counts['failed']++;
                } else {
                    $this->finish($id, 'pending', $message, 'attempts = ?, last_error = ?, result = NULL, run_after = NOW() + INTERVAL ? MINUTE', [$attempts, $message, 2 ** $attempts]);
                    $counts['retried']++;
                }

                continue;
            }

            $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->finish($id, 'done', $note, 'note = ?, result = ?, last_error = NULL', [$note, $json]);
            $counts['done']++;
        }

        return $counts;
    }

    /**
     * Validates the row, then runs it.
     *
     * @param  array{type: string, domain_name: string|null, payload: string|null}  $row
     * @return array{0: string, 1: array<string, mixed>} [note, result]
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function handle(array $row): array
    {
        $type = $row['type'];
        if (! in_array($type, self::TYPES, true)) {
            throw new Refused('refused: unknown task type');
        }

        $payload = json_decode($row['payload'] ?? 'null', true);
        $domain = is_array($payload) ? ($payload['domain'] ?? null) : null;
        if (! is_string($domain)) {
            throw new Refused('refused: the payload has no domain');
        }
        if (preg_match(self::DOMAIN, $domain) !== 1) {
            throw new Refused('refused: the domain is not a lowercase ASCII host name');
        }
        if ($row['domain_name'] !== null && $row['domain_name'] !== $domain) {
            throw new Refused('refused: the payload domain differs from the task row');
        }

        $selector = '';
        if ($type === 'dkim.activate' || $type === 'dkim.retire') {
            $selector = $payload['selector'] ?? null; // $payload is an array: it held the domain
            if (! is_string($selector) || preg_match(KeyStore::SELECTOR, $selector) !== 1) {
                throw new Refused('refused: the selector does not match es + YYYYMMDD + an optional letter');
            }
        }

        if (! $this->domainExists($domain)) {
            throw new Refused("refused: {$domain} is not in virtual_domains");
        }

        return match ($type) {
            'dkim.generate' => $this->generate($domain),
            'dkim.activate' => $this->activate($domain, $selector),
            'dkim.retire' => $this->retire($domain, $selector),
            'dkim.inspect' => $this->inspect($domain),
        };
    }

    /**
     * @return array{0: string, 1: array{selector: string, txt: string}}
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function generate(string $domain): array
    {
        $selector = $this->keys->nextSelector($domain, ($this->clock)(), $this->map->selectorFor($domain));
        $txt = $this->keys->generate($domain, $selector);

        return ["generated {$domain} {$selector}", ['selector' => $selector, 'txt' => $txt]];
    }

    /**
     * @return array{0: string, 1: array{selector: string, txt: string}}
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function activate(string $domain, string $selector): array
    {
        $file = KeyStore::fileName($domain, $selector);
        if (! $this->keys->exists($domain, $selector)) {
            throw new Refused("activate refused: no key file {$file}");
        }
        // rspamd must never be pointed at a key it cannot sign with.
        $txt = $this->keys->txtOf($domain, $selector) ?? throw new Refused("activate refused: {$file} is not an RSA private key");

        $note = $this->map->activate($domain, $selector) ? "activated {$domain} {$selector}" : "{$domain} {$selector} is already active";

        return [$note, ['selector' => $selector, 'txt' => $txt]];
    }

    /**
     * @return array{0: string, 1: array{selector: string}}
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function retire(string $domain, string $selector): array
    {
        $active = $this->map->selectorFor($domain);
        if ($active !== null && strcasecmp($active, $selector) === 0) {
            throw new Refused("retire refused: {$selector} is the active selector for {$domain}");
        }
        $note = $this->keys->delete($domain, $selector)
            ? "retired {$domain} {$selector}"
            : 'no key file '.KeyStore::fileName($domain, $selector).' to retire';

        return [$note, ['selector' => $selector]];
    }

    /**
     * @return array{0: string, 1: array{active_selector: string|null, keys: list<array{selector: string, txt: string|null}>}}
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function inspect(string $domain): array
    {
        $active = $this->map->selectorFor($domain);
        $keys = [];
        foreach ($this->keys->selectors($domain) as $selector) {
            $keys[] = ['selector' => $selector, 'txt' => $this->keys->txtOf($domain, $selector)];
        }

        return ["inspected {$domain}: ".count($keys).' key(s)', ['active_selector' => $active, 'keys' => $keys]];
    }

    /**
     * Exact and case-sensitive, like the maildir helper's mailbox check: a key path is built from this
     * spelling. Converts before collating, which also works on restored utf8mb3 tables.
     */
    private function domainExists(string $domain): bool
    {
        $query = $this->db->prepare(
            "SELECT name FROM `{$this->guideSchema}`.virtual_domains WHERE convert(name using utf8mb4) collate utf8mb4_bin = ? LIMIT 1"
        );
        $query->execute([$domain]);

        return $query->fetchColumn() !== false;
    }

    /**
     * Writes a task's outcome and logs one line. $set holds only fixed SQL; values go in $bindings.
     *
     * @param  list<int|string>  $bindings
     */
    private function finish(int $id, string $status, string $message, string $set, array $bindings): void
    {
        ($this->log)(addcslashes("task {$id} {$status}: {$message}", "\0..\37\177"));

        $this->db->prepare("UPDATE `{$this->appSchema}`.helper_tasks SET status = ?, {$set}, updated_at = NOW() WHERE id = ?")
            ->execute([$status, ...$bindings, $id]);
    }
}
