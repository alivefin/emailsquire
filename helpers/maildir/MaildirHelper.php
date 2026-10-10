<?php

namespace EmailSquire\Helpers;

/**
 * Runs the `maildir.*` rows of helper_tasks as the vmail user, and purges old trash. Framework-free
 * on purpose (see docs/backend-plan.md §3): it trusts nothing in a task row, derives every path from
 * its own roots and the address's parts, re-checks the guide tables, and only then renames.
 *
 * Refusals (UnsafePath: an unsafe path, an unknown type, a failed restore precondition) fail a task
 * at once. I/O errors retry with backoff: after the nth failed attempt run_after moves 2^n minutes
 * ahead, and attempt MAX_ATTEMPTS fails it. Database times come from the server's NOW(); the clock
 * names archives and dates the purge cutoff.
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
     * @param  (\Closure(): int)|null  $clock  unix time, used to name archives and date the purge cutoff
     * @param  (\Closure(string): void)|null  $log  one line per finished task or purged entry; STDOUT by default
     *
     * @throws \InvalidArgumentException when a setting is unsafe (see checkSettings())
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
        self::checkSettings($guideSchema, $appSchema, $mailRoot, $trashDir);
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
    public static function checkSettings(string $guideSchema, string $appSchema, string $mailRoot, string $trashDir): void
    {
        foreach ([$guideSchema, $appSchema] as $schema) {
            if (preg_match(self::IDENTIFIER, $schema) !== 1) {
                throw new \InvalidArgumentException('schema names must match '.self::IDENTIFIER);
            }
        }
        foreach ([$mailRoot, $trashDir] as $dir) {
            if (! str_starts_with($dir, '/') || rtrim($dir, '/') === '') {
                throw new \InvalidArgumentException('the mail root and trash dir must be absolute paths other than /');
            }
        }
    }

    /** @return array{done: int, failed: int, retried: int} */
    public function run(int $limit = 50): array
    {
        $tasks = "`{$this->appSchema}`.helper_tasks";
        $counts = ['done' => 0, 'failed' => 0, 'retried' => 0];

        // systemd never runs two helpers at once, so a running row was left behind by a crash.
        $this->db->exec("UPDATE {$tasks} SET status = 'pending', updated_at = NOW() WHERE status = 'running' AND type LIKE 'maildir.%'");

        $due = $this->db->prepare(
            "SELECT id, type, address, payload, attempts FROM {$tasks}
             WHERE status = 'pending' AND type LIKE 'maildir.%' AND run_after <= NOW() ORDER BY id LIMIT ?"
        );
        $due->bindValue(1, max(0, $limit), \PDO::PARAM_INT);
        $due->execute();
        /** @var list<array{id: int|string, type: string, address: string|null, payload: string|null, attempts: int|string}> $rows */
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
                    'maildir.restore' => $this->restore($row['address'] ?? '', $row['payload']),
                    default => throw new UnsafePath('unknown task type'),
                };
            } catch (UnsafePath $e) {
                $this->finish($id, 'failed', $e->getMessage(), 'attempts = attempts + 1, last_error = ?', [$e->getMessage()]);
                $counts['failed']++;

                continue;
            } catch (\Throwable $e) {
                // Anything else (a failed guide query, say) is an I/O error: back off rather than block the queue.
                [$ok, $message] = [false, $e::class.': '.$e->getMessage()];
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
     * Moves <trash>/<domain>/<local>-<YYYYmmddHHMMSS> back to <mail-root>/<domain>/<local>. The payload
     * names the archive (`{"from": "<YYYYmmddHHMMSS>"}`) or asks for the newest (`{"from": null}`).
     * Only the mailbox's re-creation makes a restore safe, and an existing maildir is never replaced.
     *
     * @return array{0: bool, 1: string} [true, note] when done, [false, error] to retry
     *
     * @throws UnsafePath
     */
    private function restore(string $address, ?string $payload): array
    {
        [$local, $domain] = SafePath::splitAddress($address);

        $decoded = json_decode($payload ?? 'null', true);
        $from = is_array($decoded) && array_key_exists('from', $decoded) ? $decoded['from'] : false;
        if ($from !== null && (! is_string($from) || preg_match('/^\d{14}$/D', $from) !== 1)) {
            throw new UnsafePath('restore refused: malformed from');
        }

        if (! $this->mailboxExists($address)) {
            throw new UnsafePath('restore refused: the mailbox does not exist');
        }
        $maildir = SafePath::walk($this->mailRoot, $domain, $local);
        if (is_dir($maildir)) {
            throw new UnsafePath('restore refused: the maildir already exists');
        }
        if (! is_dir($this->mailRoot)) {
            return [false, 'mail root is missing'];
        }

        $trashDomain = SafePath::walk($this->trashDir, $domain);
        $entries = is_dir($trashDomain) ? $this->children($trashDomain) : [];
        if ($entries === null) {
            return [false, "cannot read the trash directory for {$domain}"];
        }
        $stamps = [];
        foreach ($entries as $entry) {
            if (preg_match('/^'.preg_quote($local, '/').'-(\d{14})$/D', $entry, $match) === 1) {
                $stamps[] = $match[1];
            }
        }
        $stamp = $from === null ? ($stamps === [] ? null : max($stamps)) : (in_array($from, $stamps, true) ? $from : null);
        if ($stamp === null) {
            throw new UnsafePath('restore refused: no matching archive');
        }
        $name = "{$local}-{$stamp}";
        $archive = SafePath::walk($this->trashDir, $domain, $name);

        $mailDomain = SafePath::walk($this->mailRoot, $domain);
        if (! is_dir($mailDomain) && ! @mkdir($mailDomain, 0700)) {
            return [false, "cannot create the mail directory for {$domain}"];
        }
        // Should Dovecot create the maildir meanwhile, rename() fails on it (or replaces it only while
        // empty), and the retry refuses.
        if (! @rename($archive, $maildir)) {
            return [false, "rename from {$domain}/{$name} failed"];
        }

        return [true, "restored {$domain}/{$name}"];
    }

    /**
     * Deletes trash entries more than $retentionDays old. The age comes from the name's UTC timestamp,
     * not mtime, because rename() keeps a maildir's old mtime. Names without a timestamp are left alone.
     * A symlink or non-directory where a domain directory or entry should be, and an entry that cannot
     * be removed, is refused: logged, counted and left in place. Nothing is ever followed.
     *
     * @return array{purged: int, refused: int}
     *
     * @throws \InvalidArgumentException when $retentionDays is below 1
     */
    public function purge(int $retentionDays): array
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('the retention must be at least one day');
        }
        $cutoff = ($this->clock)() - $retentionDays * 86400;
        $counts = ['purged' => 0, 'refused' => 0];
        $refuse = function (string $what, string $why) use (&$counts): void {
            $this->logLine("refused {$what}: {$why}");
            $counts['refused']++;
        };

        try {
            if (! is_dir(SafePath::walk($this->trashDir))) {
                return $counts;
            }
        } catch (UnsafePath $e) {
            $refuse('the trash directory', $e->getMessage());

            return $counts;
        }

        foreach ($this->children($this->trashDir) ?? [] as $domain) {
            try {
                $domainDir = SafePath::walk($this->trashDir, $domain);
            } catch (UnsafePath $e) {
                $refuse($domain, $e->getMessage());

                continue;
            }
            $entries = $this->children($domainDir);
            if ($entries === null) {
                $refuse($domain, 'cannot read the directory');

                continue;
            }

            $purgedHere = 0;
            foreach ($entries as $entry) {
                if (preg_match('/-(\d{14})$/D', $entry, $match) !== 1 || ($time = self::stampTime($match[1])) === null) {
                    continue;
                }
                try {
                    $path = SafePath::walk($this->trashDir, $domain, $entry);
                } catch (UnsafePath $e) {
                    $refuse("{$domain}/{$entry}", $e->getMessage());

                    continue;
                }
                if ($time >= $cutoff) {
                    continue;
                }
                if (! $this->removeTree($path)) {
                    $refuse("{$domain}/{$entry}", 'could not remove it completely');

                    continue;
                }
                $this->logLine("purged {$domain}/{$entry}");
                $counts['purged']++;
                $purgedHere++;
            }
            if ($purgedHere > 0) {
                @rmdir($domainDir); // fails, as it should, unless the purge left it empty
            }
        }

        return $counts;
    }

    /** Unix time of a YYYYmmddHHMMSS stamp in UTC, or null when it is not a real time. */
    private static function stampTime(string $stamp): ?int
    {
        [$month, $day, $hour, $minute, $second] = array_map(intval(...), str_split(substr($stamp, 4), 2));
        $time = gmmktime($hour, $minute, $second, $month, $day, (int) substr($stamp, 0, 4));

        return $time !== false && gmdate('YmdHis', $time) === $stamp ? $time : null;
    }

    /**
     * Deletes $dir depth first. A symlink is unlinked, never followed: RecursiveDirectoryIterator does
     * not descend into links unless asked to.
     */
    private function removeTree(string $dir): bool
    {
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            /** @var \SplFileInfo $item */
            foreach ($items as $item) {
                $path = $item->getPathname();
                if (! ($item->isLink() || ! $item->isDir() ? @unlink($path) : @rmdir($path))) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException) {
            return false; // a subdirectory could not be opened
        }

        return @rmdir($dir);
    }

    /**
     * Names in $dir without the dot entries, sorted; null when it cannot be read.
     *
     * @return list<string>|null
     */
    private function children(string $dir): ?array
    {
        $names = @scandir($dir);

        return $names === false ? null : array_values(array_diff($names, ['.', '..']));
    }

    /**
     * Exact and case-sensitive: Dovecot's home is the stored spelling, so a live `anna@` does not own
     * `Anna`'s maildir. Converts before collating, which also works on restored utf8mb3 tables (a
     * collate straight on the column fails there with error 1253). Reads only the email column: the
     * vmail grant covers nothing else.
     */
    private function mailboxExists(string $address): bool
    {
        $query = $this->db->prepare(
            "SELECT email FROM `{$this->guideSchema}`.virtual_users WHERE convert(email using utf8mb4) collate utf8mb4_bin = ? LIMIT 1"
        );
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
        // Logged first, so an archive name survives in the journal even if the status write fails. The
        // row then stays running; the next run finds no maildir and finishes it without touching trash.
        $this->logLine("task {$id} {$status}: {$message}");

        $this->db->prepare("UPDATE `{$this->appSchema}`.helper_tasks SET status = ?, {$set}, updated_at = NOW() WHERE id = ?")
            ->execute([$status, ...$bindings, $id]);
    }

    /** Lines can carry address parts or trash names, which may hold control characters: escape them. */
    private function logLine(string $line): void
    {
        ($this->log)(addcslashes($line, "\0..\37\177"));
    }
}
