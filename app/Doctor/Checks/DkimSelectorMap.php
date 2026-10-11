<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\FileOwner;

/**
 * rspamd's DKIM selector map, which the DKIM helper rewrites as the rspamd user: a temp file in the
 * map's directory, then rename(). So the map must be a regular file (the helper refuses a symlink) that
 * the rspamd user can write, in a directory it can write. Group write is enough for either.
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
        if (! is_string($map) || $map === '' || ! is_dir(dirname($map))) {
            return $this->result(Status::Skip, 'rspamd configuration directory not found');
        }

        clearstatcache(true, $map);
        $stat = @lstat($map);
        if ($stat === false) {
            return $this->result(Status::Warn, "{$map} does not exist; create it with one \"<domain> <selector>\" line per domain");
        }
        if (($stat['mode'] & 0170000) === 0120000) {
            return $this->result(Status::Fail, "{$map} is a symlink; the DKIM helper refuses it");
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            return $this->result(Status::Fail, "{$map} is not a regular file");
        }

        $user = config()->string('emailsquire.rspamd_user');
        if (posix_getpwnam($user) === false) {
            return $this->result(Status::Warn, "the rspamd user {$user} does not exist");
        }
        if (FileOwner::writableBy($map, $user) !== true) {
            return $this->result(Status::Fail, "{$map} is not writable by {$user}");
        }
        $dir = dirname($map);
        if (FileOwner::writableBy($dir, $user) !== true) {
            return $this->result(Status::Fail, "{$dir} is not writable by {$user}: the DKIM helper replaces the map with a rename in that directory");
        }

        return $this->result(Status::Ok, "{$map} is a regular file that {$user} can write and replace");
    }
}
