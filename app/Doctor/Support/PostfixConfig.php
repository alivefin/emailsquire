<?php

namespace App\Doctor\Support;

/**
 * Minimal reader for Postfix `main.cf`: `name = value` lines, `#` comments and
 * continuation lines (a line starting with whitespace continues the previous
 * one). `$vars` are not expanded. The last assignment of a parameter wins.
 */
final class PostfixConfig
{
    /** @param  array<string, string>  $params */
    private function __construct(private readonly array $params) {}

    public static function fromFile(string $path): self
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return self::fromString($contents === false ? '' : $contents);
    }

    public static function fromString(string $contents): self
    {
        $logical = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                // Comments and blank lines do not end a logical line in Postfix,
                // but they carry no content either.
                continue;
            }

            if (preg_match('/^\s/', $line) === 1 && $logical !== []) {
                $logical[array_key_last($logical)] .= ' '.trim($line);

                continue;
            }

            $logical[] = trim($line);
        }

        $params = [];
        foreach ($logical as $line) {
            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $line, $m) === 1) {
                $params[$m[1]] = trim($m[2]);
            }
        }

        return new self($params);
    }

    public function get(string $param): ?string
    {
        return $this->params[$param] ?? null;
    }

    /** @return list<string> */
    public function mapFiles(string $param): array
    {
        $value = $this->get($param);
        if ($value === null) {
            return [];
        }

        $files = [];
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (str_starts_with($entry, 'mysql:') && strlen($entry) > 6) {
                $files[] = substr($entry, 6);
            }
        }

        return $files;
    }
}
