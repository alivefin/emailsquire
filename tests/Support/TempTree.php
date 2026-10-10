<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Temp directory trees for the maildir helper tests. Nothing here follows a symlink. */
final class TempTree
{
    /** Creates a fresh directory under the system temp dir. */
    public static function create(): string
    {
        $dir = sys_get_temp_dir().'/emailsquire-maildir-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        return $dir;
    }

    /** Removes $dir and everything under it, unlinking symlinks instead of following them. */
    public static function remove(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Every path under $dir: relative path => 'dir', 'file:<contents>' or 'link:<target>'.
     *
     * @return array<string, string>
     */
    public static function snapshot(string $dir): array
    {
        $tree = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($dir) + 1);
            $tree[$relative] = match (true) {
                $item->isLink() => 'link:'.readlink($item->getPathname()),
                $item->isDir() => 'dir',
                default => 'file:'.file_get_contents($item->getPathname()),
            };
        }
        ksort($tree);

        return $tree;
    }

    /** Makes a maildir with one message in it, creating parents as needed. */
    public static function maildir(string $path): void
    {
        mkdir("{$path}/cur", 0700, true);
        file_put_contents("{$path}/cur/1.mail", "Subject: hi\n");
    }
}
