<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

final class DkimDir extends BaseCheck
{
    public function id(): string
    {
        return 'fs.dkim_dir';
    }

    public function title(): string
    {
        return 'DKIM key directory';
    }

    public function run(): CheckResult
    {
        $dir = config('emailsquire.paths.dkim_dir');
        if (! is_string($dir) || ! is_dir($dir)) {
            return $this->result(Status::Skip, 'DKIM directory not found');
        }

        $expected = config()->string('emailsquire.rspamd_user');
        $owner = MailRoot::ownerName($dir);
        if ($owner !== $expected) {
            return $this->result(Status::Warn, "{$dir} is owned by ".($owner ?? 'an unknown user').", expected {$expected}");
        }

        return $this->result(Status::Ok, "{$dir} is owned by {$expected}");
    }
}
