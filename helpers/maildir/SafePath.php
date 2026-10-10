<?php

namespace EmailSquire\Helpers;

/**
 * Path checks for the maildir helper. Every path the helper touches is built here from its own
 * configured root plus parts of a task's address, never from a path stored in the database.
 */
final class SafePath
{
    private const MAX_PART_BYTES = 100;

    /**
     * Splits at the last `@`. Both parts are returned exactly as stored: legacy mixed-case
     * maildirs live under their stored spelling.
     *
     * @return array{0: string, 1: string}
     *
     * @throws UnsafePath
     */
    public static function splitAddress(string $address): array
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            throw new UnsafePath('unsafe address: no @');
        }
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        self::checkPart($local, 'local part');
        self::checkPart($domain, 'domain');
        if (str_starts_with($domain, '.')) {
            throw new UnsafePath('unsafe address: domain starts with a dot');
        }

        return [$local, $domain];
    }

    /**
     * Joins $components onto $root. Every component that exists, the root included, must be a real
     * directory and not a symlink; components after the first missing one cannot exist either.
     *
     * @throws UnsafePath
     */
    public static function walk(string $root, string ...$components): string
    {
        $path = rtrim($root, '/');
        if (! str_starts_with($root, '/') || $path === '' || str_contains($root, "\0")) {
            throw new UnsafePath('unsafe path: the root must be an absolute path other than /');
        }
        foreach ($components as $component) {
            if (in_array($component, ['', '.', '..'], true) || strpbrk($component, "/\0") !== false) {
                throw new UnsafePath('unsafe path: a component is empty, a dot entry, or contains / or NUL');
            }
        }

        $exists = self::isRealDirectory($path);
        foreach ($components as $component) {
            $path .= "/{$component}";
            $exists = $exists && self::isRealDirectory($path);
        }

        return $path;
    }

    /**
     * False when nothing is there. Uses lstat, so a symlink is seen as itself, never followed.
     *
     * @throws UnsafePath when something other than a real directory is there
     */
    private static function isRealDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return false;
        }

        return match ($stat['mode'] & 0170000) {
            0040000 => true,
            0120000 => throw new UnsafePath("unsafe path: {$path} is a symlink"),
            default => throw new UnsafePath("unsafe path: {$path} is not a directory"),
        };
    }

    /** @throws UnsafePath */
    private static function checkPart(string $part, string $name): void
    {
        $problem = match (true) {
            $part === '' => 'is empty',
            strlen($part) > self::MAX_PART_BYTES => 'is longer than '.self::MAX_PART_BYTES.' bytes',
            $part === '.' => 'is a dot',
            str_contains($part, '..') => "contains '..'",
            str_contains($part, '/') => "contains '/'",
            str_contains($part, "\0") => 'contains NUL',
            default => null,
        };
        if ($problem !== null) {
            throw new UnsafePath("unsafe address: {$name} {$problem}");
        }
    }
}
