<?php

namespace App\Doctor\Support;

/** Reads single values out of a Postfix `mysql:` map file. */
final class PostfixMapFile
{
    /** Caller must have checked is_readable(). */
    public static function query(string $file): ?string
    {
        foreach (self::lines($file) as $line) {
            if (preg_match('/^query\s*=\s*(.*)$/', $line, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    public static function contains(string $file, string $needle): bool
    {
        $contents = @file_get_contents($file);

        return $contents !== false && str_contains($contents, $needle);
    }

    /** @return list<string> */
    private static function lines(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        return array_map('trim', preg_split('/\R/', $contents) ?: []);
    }
}
