<?php

namespace EmailSquire\Helpers;

/**
 * Runs the `maildir.*` rows of helper_tasks as the vmail user. Framework-free on purpose (see
 * docs/backend-plan.md §3): it trusts nothing in a task row, derives every path from its own roots
 * and the address's parts, re-checks the guide tables, and only then renames.
 *
 * Refusals (UnsafePath, unknown type) fail a task at once. I/O errors retry with backoff:
 * after the nth failed attempt run_after moves 2^n minutes ahead, and attempt MAX_ATTEMPTS fails it.
 * Database times come from the server's NOW(); the clock only names archives.
 */
final class MaildirHelper
{
    public const MAX_ATTEMPTS = 5;

    private const IDENTIFIER = '/^[A-Za-z0-9_]+$/';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param  (\Closure(): int)|null  $clock  unix time, used only to name archives
     * @param  (\Closure(string): void)|null  $log  one line per finished task; STDOUT by default
     */
    public function __construct(
        private readonly \PDO $db,
        private readonly string $guideSchema,
        private readonly string $appSchema,
        private readonly string $mailRoot,
        private readonly string $trashDir,
        ?\Closure $clock = null,
        ?\Closure $log = null,
    ) {
        foreach ([$guideSchema, $appSchema] as $schema) {
            if (preg_match(self::IDENTIFIER, $schema) !== 1) {
                throw new \InvalidArgumentException('schema names must match '.self::IDENTIFIER);
            }
        }
        foreach ([$mailRoot, $trashDir] as $dir) {
            if (! str_starts_with($dir, '/')) {
                throw new \InvalidArgumentException('the mail root and trash dir must be absolute paths');
            }
        }
        $this->clock = $clock ?? time(...);
        $this->log = $log ?? static function (string $line): void {
            fwrite(STDOUT, $line.PHP_EOL);
        };

        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("SET time_zone = '+00:00'");
    }

    /** @return array{done: int, failed: int, retried: int} */
    public function run(int $limit = 50): array
    {
        $tasks = "`{$this->appSchema}`.helper_tasks";
        $counts = ['done' => 0, 'failed' => 0, 'retried' => 0];

        // systemd never runs two helpers at once, so a running row was left behind by a crash.
        $this->db->exec("UPDATE {$tasks} SET status = 'pending', updated_at = NOW() WHERE status = 'running' AND type LIKE 'maildir.%'");

        $due = $this->db->prepare(
            "SELECT id, type, address, attempts FROM {$tasks}
             WHERE status = 'pending' AND type LIKE 'maildir.%' AND run_after <= NOW() ORDER BY id LIMIT ?"
        );
        $due->bindValue(1, max(0, $limit), \PDO::PARAM_INT);
        $due->execute();
        /** @var list<array{id: int|string, type: string, address: string|null, attempts: int|string}> $rows */
        $rows = $due->fetchAll(\PDO::FETCH_ASSOC);

        $claim = $this->db->prepare("UPDATE {$tasks} SET status = 'running', updated_at = NOW() WHERE id = ? AND status = 'pending'");
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $claim->execute([$id]);
            if ($claim->rowCount() !== 1) {
                continue;
            }

            try {
                [$ok, $message] = match ($row['type']) {
                    'maildir.archive' => $this->archive($row['address'] ?? ''),
                    'maildir.restore' => $this->restore($row),
                    default => throw new UnsafePath('unknown task type'),
                };
            } catch (UnsafePath $e) {
                $this->finish($id, 'failed', $e->getMessage(), 'attempts = attempts + 1, last_error = ?', [$e->getMessage()]);
                $counts['failed']++;

                continue;
            }

            if ($ok) {
                $this->finish($id, 'done', $message, 'note = ?, last_error = NULL', [$message]);
                $counts['done']++;
            } elseif (($attempts = (int) $row['attempts'] + 1) >= self::MAX_ATTEMPTS) {
                $this->finish($id, 'failed', $message, 'attempts = ?, last_error = ?', [$attempts, $message]);
                $counts['failed']++;
            } else {
                $this->finish($id, 'pending', $message, 'attempts = ?, last_error = ?, run_after = NOW() + INTERVAL ? MINUTE', [$attempts, $message, 2 ** $attempts]);
                $counts['retried']++;
            }
        }

        return $counts;
    }

    /**
     * Moves <mail-root>/<domain>/<local> to <trash>/<domain>/<local>-<YYYYmmddHHMMSS>.
     *
     * @return array{0: bool, 1: string} [true, note] when done, [false, error] to retry
     *
     * @throws UnsafePath
     */
    private function archive(string $address): array
    {
        [$local, $domain] = SafePath::splitAddress($address);

        if ($this->mailboxExists($address)) {
            return [true, 'mailbox exists again; maildir left in place'];
        }

        $maildir = SafePath::walk($this->mailRoot, $domain, $local);
        if (! is_dir($this->mailRoot)) {
            return [false, 'mail root is missing'];
        }
        if (! is_dir($maildir)) {
            return [true, 'no maildir to archive'];
        }

        $trashDomain = SafePath::walk($this->trashDir, $domain);
        foreach ([$this->trashDir, $trashDomain] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0700)) {
                return [false, "cannot create the trash directory for {$domain}"];
            }
        }

        $name = $local.'-'.gmdate('YmdHis', ($this->clock)());
        $archive = SafePath::walk($this->trashDir, $domain, $name);
        clearstatcache(true, $archive);
        // rename() onto an empty directory would replace it, so a taken name waits for the next second.
        if (@lstat($archive) !== false) {
            return [false, "archive name taken: {$domain}/{$name}"];
        }
        if (! @rename($maildir, $archive)) {
            return [false, "rename to {$domain}/{$name} failed"];
        }

        return [true, "archived to {$domain}/{$name}"];
    }

    /**
     * Filled in by the restore task.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: bool, 1: string}
     *
     * @throws UnsafePath
     */
    private function restore(array $row): array
    {
        throw new UnsafePath('restore not implemented');
    }

    /** Reads only the email column: the vmail grant covers nothing else. */
    private function mailboxExists(string $address): bool
    {
        $query = $this->db->prepare("SELECT email FROM `{$this->guideSchema}`.virtual_users WHERE email = ? LIMIT 1");
        $query->execute([$address]);

        return $query->fetchColumn() !== false;
    }

    /**
     * Writes a task's outcome and logs one line. $set holds only fixed SQL; values go in $bindings.
     *
     * @param  list<int|string>  $bindings
     */
    private function finish(int $id, string $status, string $message, string $set, array $bindings): void
    {
        $this->db->prepare("UPDATE `{$this->appSchema}`.helper_tasks SET status = ?, {$set}, updated_at = NOW() WHERE id = ?")
            ->execute([$status, ...$bindings, $id]);

        // Messages can carry address parts, which may hold control characters: escape them for the journal.
        ($this->log)(addcslashes("task {$id} {$status}: {$message}", "\0..\37\177"));
    }
}
