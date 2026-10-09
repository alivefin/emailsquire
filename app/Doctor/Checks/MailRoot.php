<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

final class MailRoot extends BaseCheck
{
    public function id(): string
    {
        return 'fs.mail_root';
    }

    public function title(): string
    {
        return 'Mail root ownership and trash filesystem';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $root = config('emailsquire.paths.mail_root');
        if (! is_string($root) || ! is_dir($root)) {
            return $this->result(Status::Skip, 'mail root not found');
        }

        $expected = config()->string('emailsquire.vmail_user');
        $owner = self::ownerName($root);
        if ($owner !== $expected) {
            return $this->result(Status::Fail, "{$root} is owned by ".($owner ?? 'an unknown user').", expected {$expected}");
        }

        $trash = config('emailsquire.paths.trash_dir');
        if (is_string($trash) && is_dir($trash)) {
            if (self::device($root) !== self::device($trash)) {
                return $this->result(Status::Fail, "{$trash} is on a different filesystem than {$root}; moving to trash would copy instead of rename");
            }
        }

        return $this->result(Status::Ok, "{$root} is owned by {$expected}");
    }

    private static function device(string $path): ?int
    {
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }

    public static function ownerName(string $path): ?string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }

        $info = posix_getpwuid($uid);

        return $info === false ? null : $info['name'];
    }
}
