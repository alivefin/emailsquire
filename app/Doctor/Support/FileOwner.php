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

    /**
     * Whether the named (non-root) user may write $path, read from its owner, group and mode bits, so
     * the answer holds whoever runs the doctor. Null when the path or the user can't be resolved.
     */
    public static function writableBy(string $path, string $user): ?bool
    {
        $stat = @stat($path);
        $account = posix_getpwnam($user);
        if ($stat === false || $account === false) {
            return null;
        }

        $groups = [$account['gid']];
        $group = posix_getgrgid($stat['gid']);
        if ($group !== false && in_array($user, $group['members'], true)) {
            $groups[] = $stat['gid'];
        }

        return self::modeAllowsWrite($stat['mode'], $stat['uid'], $stat['gid'], $account['uid'], $groups);
    }

    /**
     * The kernel's rule for a non-root user: the owner's bits apply to the owner alone, the group's to
     * group members, and the other bits to everyone else.
     *
     * @param  list<int>  $gids  the user's groups
     */
    public static function modeAllowsWrite(int $mode, int $fileUid, int $fileGid, int $uid, array $gids): bool
    {
        return match (true) {
            $fileUid === $uid => ($mode & 0200) !== 0,
            in_array($fileGid, $gids, true) => ($mode & 0020) !== 0,
            default => ($mode & 0002) !== 0,
        };
    }
}
