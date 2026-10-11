<?php

namespace EmailSquire\Helpers\Dkim;

/**
 * rspamd's selector map (`selector_map` in the guide's dkim_signing.conf): one `<domain> <selector>`
 * line per domain, whitespace between them; blank lines and `#` comments allowed. A domain's line is
 * matched on its first word, ignoring case. Activating rewrites that one line (or appends one) and
 * keeps every other byte; the new file goes to a temp file in the same directory, is synced, and
 * replaces the map with rename(), so rspamd never reads a partial map.
 */
final class SelectorMap
{
    private const NAME = 'the selector map';

    private const DIR_NAME = "the selector map's directory";

    public function __construct(private readonly string $path) {}

    /**
     * The domain's selector, or null when the map (or its line for the domain) is missing.
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function selectorFor(string $domain): ?string
    {
        return $this->load($domain)['selector'];
    }

    /**
     * Points the domain's line at $selector. False when it already does, and nothing is written.
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function activate(string $domain, string $selector): bool
    {
        if (! PathGuard::directory(dirname($this->path), self::DIR_NAME)) {
            throw new \RuntimeException("the selector map's directory is missing");
        }
        $map = $this->load($domain);
        if ($map['selector'] === $selector) {
            return false;
        }

        $lines = $map['lines'];
        $line = "{$domain} {$selector}";
        if ($map['index'] !== null) {
            $old = $lines[$map['index']];
            $lines[$map['index']] = $line.substr($old, strlen(rtrim($old, "\r\n")));
        } else {
            $last = array_key_last($lines);
            if ($last !== null && ! str_ends_with($lines[$last], "\n")) {
                $lines[$last] .= "\n";
            }
            $lines[] = "{$line}\n";
        }
        $this->replace(implode('', $lines), $map['stat']);

        return true;
    }

    /**
     * @return array{selector: string|null, index: int|null, lines: list<string>, stat: array<int|string, int>|null}
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    private function load(string $domain): array
    {
        $map = ['selector' => null, 'index' => null, 'lines' => [], 'stat' => null];
        if (! PathGuard::directory(dirname($this->path), self::DIR_NAME)) {
            return $map;
        }
        $read = PathGuard::read($this->path, self::NAME);
        if ($read === null) {
            return $map;
        }
        [$contents, $map['stat']] = $read;

        // Split after each \n, so every line keeps its own ending (\n, \r\n, or none on the last line).
        $map['lines'] = preg_split('/(?<=\n)/', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($map['lines'] as $index => $line) {
            $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === [] || str_starts_with($words[0], '#') || strcasecmp($words[0], $domain) !== 0) {
                continue;
            }
            if ($map['index'] !== null) {
                throw new Refused("refused: the selector map names {$domain} more than once");
            }
            $selector = $words[1] ?? '';
            if (preg_match(KeyStore::LABEL, $selector) !== 1 || (isset($words[2]) && ! str_starts_with($words[2], '#'))) {
                throw new Refused("refused: the selector map's line for {$domain} is malformed");
            }
            $map['index'] = $index;
            $map['selector'] = $selector;
        }

        return $map;
    }

    /**
     * Writes $contents to a temp file next to the map, syncs it, gives it the map's mode (0644 for a
     * new map), and renames it over the map. The temp file is removed whatever happens. A map that
     * changed since it was read is left alone and the task retried.
     *
     * @param  array<int|string, int>|null  $stat  the map's lstat when it was read; null when it did not exist
     *
     * @throws \RuntimeException
     */
    private function replace(string $contents, ?array $stat): void
    {
        $temp = dirname($this->path).'/.'.basename($this->path).'.'.bin2hex(random_bytes(6)).'.tmp';
        $umask = umask(0077);
        try {
            $handle = Io::call('cannot write the selector map', static fn () => fopen($temp, 'xb'));
        } finally {
            umask($umask);
        }
        try {
            $written = fwrite($handle, $contents) === strlen($contents) && fflush($handle) && fsync($handle);
            fclose($handle);
            if (! $written) {
                throw new \RuntimeException('cannot write the selector map');
            }
            Io::call('cannot set the selector map\'s mode', static fn () => chmod($temp, $stat === null ? 0644 : $stat['mode'] & 0777));

            clearstatcache(true, $this->path);
            $now = @lstat($this->path);
            $changed = $stat === null ? $now !== false
                : $now === false || $now['ino'] !== $stat['ino'] || $now['dev'] !== $stat['dev'] || $now['size'] !== $stat['size'] || $now['mtime'] !== $stat['mtime'];
            if ($changed) {
                throw new \RuntimeException('the selector map changed while it was rewritten');
            }
            Io::call('cannot replace the selector map', fn () => rename($temp, $this->path));
        } finally {
            if (@lstat($temp) !== false) {
                @unlink($temp);
            }
        }
    }
}
