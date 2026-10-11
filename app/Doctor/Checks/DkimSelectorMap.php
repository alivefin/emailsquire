<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\FileOwner;

/**
 * rspamd's DKIM selector map, which the DKIM helper rewrites as the rspamd user: a temp file in the
 * map's directory, then rename(). So the map's directory must be writable by that user (group write is
 * enough), and the map, when it exists, a regular file (the helper refuses a symlink). That directory
 * must be one of its own: if the rspamd user could write rspamd's configuration directory, it could add
 * a `rspamd.conf.local` that rspamd's root process loads; for the same reason it must not be able to
 * write `local.d`, `override.d` or a file in them. Finally, rspamd must actually read this map:
 * `selector_map` in dkim_signing.conf (override.d wins over local.d) should name the configured path.
 */
final class DkimSelectorMap extends BaseCheck
{
    public function id(): string
    {
        return 'fs.dkim_selector_map';
    }

    public function title(): string
    {
        return 'DKIM selector map';
    }

    public function run(): CheckResult
    {
        $map = config('emailsquire.paths.dkim_selector_map');
        $conf = config('emailsquire.paths.rspamd_config_dir');
        if (! is_string($map) || ! is_string($conf) || ! is_dir($conf)) {
            return $this->result(Status::Skip, 'rspamd configuration directory not found');
        }

        $user = config()->string('emailsquire.rspamd_user');
        if (posix_getpwnam($user) === false) {
            return $this->result(Status::Warn, "the rspamd user {$user} does not exist");
        }
        if (FileOwner::writableBy($conf, $user) !== false) {
            return $this->result(Status::Fail, "{$conf} is writable by {$user}, which lets it change rspamd's configuration; keep the selector map in a directory of its own");
        }
        foreach ($this->overrides($conf) as $path) {
            if (FileOwner::writableBy($path, $user) !== false) {
                return $this->result(Status::Fail, "{$path} is writable by {$user}, which lets it change rspamd's configuration; make it root's and not writable by {$user}");
            }
        }

        $dir = dirname($map);
        $dirType = self::type($dir);
        if ($dirType === null) {
            return $this->result(Status::Fail, "{$dir} does not exist; create it as root:{$user} with mode 0770 for the selector map");
        }
        if ($dirType !== 'dir') {
            return $this->result(Status::Fail, "{$dir} is ".($dirType === 'link' ? 'a symlink; the DKIM helper refuses it' : 'not a directory'));
        }
        if (FileOwner::writableBy($dir, $user) !== true) {
            return $this->result(Status::Fail, "{$dir} is not writable by {$user}: the DKIM helper replaces the map with a rename in that directory");
        }

        $mapType = self::type($map);
        if ($mapType === 'link') {
            return $this->result(Status::Fail, "{$map} is a symlink; the DKIM helper refuses it");
        }
        if ($mapType !== null && $mapType !== 'file') {
            return $this->result(Status::Fail, "{$map} is not a regular file");
        }

        $pointed = $this->configuredSelectorMap($conf);
        if ($pointed !== null && $pointed['value'] !== $map) {
            return $this->result(Status::Warn, $pointed['value'] === null
                ? "{$pointed['file']} sets no selector_map; point it at {$map}"
                : "selector_map in {$pointed['file']} is {$pointed['value']}, not {$map}; rspamd would not see the keys the console activates");
        }

        return $this->result(Status::Ok, $mapType === null
            ? "{$dir} is writable by {$user}; {$map} does not exist yet, and the first activation creates it"
            : "{$map} is a regular file in a directory {$user} can write");
    }

    /**
     * rspamd's `local.d` and `override.d`, when they exist, and the files in them: rspamd's root process
     * reads every one, so the rspamd user must not be able to write any of them (nor add a file).
     *
     * @return list<string>
     */
    private function overrides(string $conf): array
    {
        $paths = [];
        foreach (["{$conf}/local.d", "{$conf}/override.d"] as $dir) {
            if (self::type($dir) === null) {
                continue;
            }
            $paths[] = $dir;
            foreach (is_dir($dir) ? (@scandir($dir) ?: []) : [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $paths[] = "{$dir}/{$name}";
                }
            }
        }

        return $paths;
    }

    /**
     * The `selector_map` rspamd uses, from the readable dkim_signing.conf files (override.d wins, then
     * local.d). Null when neither file can be read; value null when they set none.
     *
     * @return array{file: string, value: string|null}|null
     */
    private function configuredSelectorMap(string $conf): ?array
    {
        $found = null;
        foreach (["{$conf}/override.d/dkim_signing.conf", "{$conf}/local.d/dkim_signing.conf"] as $file) {
            $contents = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
            if ($contents === false) {
                continue;
            }
            if (preg_match_all('/^\s*selector_map\s*[=:]\s*"?([^";\s]+)"?\s*;?\s*(?:#.*)?$/m', $contents, $matches) > 0) {
                return ['file' => $file, 'value' => end($matches[1]) ?: null];
            }
            $found ??= ['file' => $file, 'value' => null];
        }

        return $found;
    }

    /** 'dir', 'file', 'link', 'other', or null when nothing is there; never follows a symlink. */
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
