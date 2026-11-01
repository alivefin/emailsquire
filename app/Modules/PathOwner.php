<?php

namespace App\Modules;

/** Whether a module's files are root's alone, and stay inside it. */
final class PathOwner
{
    /**
     * Whether $path, every directory above it up to and including $root, and every directory above $root
     * up to `/` is a real directory (not a link) owned by uid 0 and not writable by its group or others (as
     * OpenSSH's StrictModes asks; a sticky world-writable directory such as /tmp fails too). $path must be
     * $root or below it; pass both through realpath() first.
     */
    public static function isRootOwnedUpTo(string $path, string $root): bool
    {
        $path = self::trim($path);
        $root = self::trim($root);
        if ($path !== $root && ! str_starts_with($path, rtrim($root, '/').'/')) {
            return false;
        }

        while (true) {
            $stat = @lstat($path);
            if ($stat === false || ! self::isDir($stat) || ! self::rootOnly($stat)) {
                return false;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return true; // `/` itself passed
            }
            $path = $parent;
        }
    }

    /**
     * The first file, directory or other entry below $dir (a realpath) that is not owned by uid 0 or is
     * writable by its group or others, or a directory that cannot be read; null when there is none. Links
     * are not followed: firstEscapingLink() keeps them inside $dir, where their targets are checked here.
     */
    public static function firstNotRootOwned(string $dir): ?string
    {
        return self::walk($dir, fn (array $stat) => ! self::isLink($stat) && ! self::rootOnly($stat));
    }

    /**
     * The first link below $dir (a realpath) that does not resolve to a path inside $dir, including one
     * that leads nowhere, or a directory that cannot be read; null when there is none.
     */
    public static function firstEscapingLink(string $dir): ?string
    {
        $inside = rtrim($dir, '/').'/';

        return self::walk($dir, function (array $stat, string $path) use ($inside) {
            if (! self::isLink($stat)) {
                return false;
            }
            $target = realpath($path);

            return $target === false || ! str_starts_with($target, $inside);
        });
    }

    /**
     * Visits every entry below $dir depth first, without following links, and returns the first one
     * $refuse picks: or a directory that cannot be listed, or an entry that cannot be stat'ed.
     *
     * @param  callable(array<int|string, int>, string): bool  $refuse
     */
    private static function walk(string $dir, callable $refuse): ?string
    {
        $names = @scandir($dir);
        if ($names === false) {
            return $dir;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "{$dir}/{$name}";
            $stat = @lstat($path);
            if ($stat === false || $refuse($stat, $path)) {
                return $path;
            }
            if (self::isDir($stat)) {
                $found = self::walk($path, $refuse);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param  array<int|string, int>  $stat */
    private static function rootOnly(array $stat): bool
    {
        return $stat['uid'] === 0 && ($stat['mode'] & 0o022) === 0;
    }

    /** @param  array<int|string, int>  $stat */
    private static function isDir(array $stat): bool
    {
        return ($stat['mode'] & 0o170000) === 0o040000;
    }

    /** @param  array<int|string, int>  $stat */
    private static function isLink(array $stat): bool
    {
        return ($stat['mode'] & 0o170000) === 0o120000;
    }

    private static function trim(string $path): string
    {
        return $path === '/' ? $path : rtrim($path, '/');
    }
}
