<?php

namespace App\Modules;

/** Whether a module's directories are root's alone. */
final class PathOwner
{
    /**
     * Whether every directory from $path up to and including $root is a real directory (not a link) owned
     * by uid 0 and not writable by its group or others. $path must be $root or below it; pass both
     * through realpath() first.
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
            if ($stat === false || ($stat['mode'] & 0o170000) !== 0o040000
                || $stat['uid'] !== 0 || ($stat['mode'] & 0o022) !== 0) {
                return false;
            }
            if ($path === $root) {
                return true;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }
    }

    private static function trim(string $path): string
    {
        return $path === '/' ? $path : rtrim($path, '/');
    }
}
