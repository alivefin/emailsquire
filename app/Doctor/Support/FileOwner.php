<?php

namespace App\Doctor\Support;

final class FileOwner
{
    /** The owning user's name, or null when the path or the user can't be resolved. */
    public static function name(string $path): ?string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }

        $info = posix_getpwuid($uid);

        return $info === false ? null : $info['name'];
    }
}
