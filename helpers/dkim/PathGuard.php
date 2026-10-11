<?php

namespace EmailSquire\Helpers\Dkim;

/**
 * lstat-based path checks for the DKIM helper. Nothing here follows a symlink: every component of a
 * directory's absolute path, from / down, must be a real directory, and a file must be a regular file.
 */
final class PathGuard
{
    /**
     * True when $dir exists. Every component that exists must be a real directory, never a symlink;
     * a missing component means the rest cannot exist either.
     *
     * @throws Refused
     */
    public static function directory(string $dir, string $name): bool
    {
        if (! str_starts_with($dir, '/') || str_contains($dir, "\0")) {
            throw new Refused("unsafe path: {$name} is not an absolute path");
        }

        $path = '';
        foreach (explode('/', trim($dir, '/')) as $component) {
            if ($component === '') {
                continue;
            }
            if ($component === '.' || $component === '..') {
                throw new Refused("unsafe path: {$name} has a dot entry in its path");
            }
            $path .= "/{$component}";
            $type = self::type($path);
            if ($type === null) {
                return false;
            }
            if ($type !== 'dir') {
                throw new Refused($type === 'link' ? "unsafe path: a component of {$name} is a symlink" : "unsafe path: a component of {$name} is not a directory");
            }
        }

        return true;
    }

    /**
     * True when $path is a regular file, false when nothing is there.
     *
     * @throws Refused when a symlink or anything other than a regular file is there
     */
    public static function file(string $path, string $name): bool
    {
        return match (self::type($path)) {
            null => false,
            'file' => true,
            'link' => throw new Refused("unsafe path: {$name} is a symlink"),
            default => throw new Refused("unsafe path: {$name} is not a regular file"),
        };
    }

    /**
     * Reads a regular file without following a symlink: the inode opened must be the one lstat saw.
     * Null when nothing is there. A file over $maxBytes is refused, not read.
     *
     * @return array{0: string, 1: array<int|string, int>}|null [contents, lstat]
     *
     * @throws Refused when it is a symlink, not a regular file, or too large
     * @throws \RuntimeException when it cannot be read (an I/O error: retried)
     */
    public static function read(string $path, string $name, int $maxBytes): ?array
    {
        if (! self::file($path, $name)) {
            return null;
        }
        $stat = @lstat($path);
        $handle = @fopen($path, 'rb');
        if ($stat === false || $handle === false) {
            throw new \RuntimeException("cannot read {$name}");
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new Refused("unsafe path: {$name} changed while it was opened");
            }
            if ($opened['size'] > $maxBytes) {
                throw new Refused("refused: {$name} is larger than {$maxBytes} bytes");
            }
            // One byte more than allowed, so a file that grew after fstat is caught too.
            $contents = stream_get_contents($handle, $maxBytes + 1);
            if ($contents === false) {
                throw new \RuntimeException("cannot read {$name}");
            }
            if (strlen($contents) > $maxBytes) {
                throw new Refused("refused: {$name} is larger than {$maxBytes} bytes");
            }
        } finally {
            fclose($handle);
        }

        return [$contents, $stat];
    }

    /**
     * fsyncs a directory after a rename() or link() in it, so the new entry survives a crash. Skipped
     * quietly where the platform can't open or sync a directory.
     */
    public static function syncDirectory(string $dir): void
    {
        $handle = @fopen($dir, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    /** 'dir', 'file', 'link', 'other', or null when nothing is there. */
    private static function type(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }

        return match ($stat['mode'] & 0170000) {
            0040000 => 'dir',
            0100000 => 'file',
            0120000 => 'link',
            default => 'other',
        };
    }
}
