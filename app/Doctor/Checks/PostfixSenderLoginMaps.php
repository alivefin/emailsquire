<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\PostfixConfig;
use App\Doctor\Support\PostfixMapFile;

final class PostfixSenderLoginMaps extends BaseCheck
{
    public const PLACEHOLDER = 'MAILSERVER-PASSWORD-HERE';

    public function id(): string
    {
        return 'postfix.sender_login_maps';
    }

    public function title(): string
    {
        return 'Postfix sender login maps';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $mainCf = config('emailsquire.paths.postfix_main_cf');
        if (! is_string($mainCf) || ! is_file($mainCf)) {
            return $this->result(Status::Skip, 'main.cf not found');
        }

        $files = PostfixConfig::fromFile($mainCf)->mapFiles('smtpd_sender_login_maps');

        foreach ($files as $file) {
            if (! is_readable($file)) {
                return $this->result(Status::Skip, 'run as root to read '.$file);
            }
        }

        if ($files === []) {
            return $this->result(Status::Warn, 'smtpd_sender_login_maps is empty: any authenticated user may send as any address');
        }

        $aliases = false;
        foreach ($files as $file) {
            if (PostfixMapFile::contains($file, self::PLACEHOLDER)) {
                return $this->result(Status::Fail, $file.' still contains the installer placeholder password '.self::PLACEHOLDER);
            }

            if (str_contains(PostfixMapFile::query($file) ?? '', 'virtual_aliases')) {
                $aliases = true;
            }
        }

        return $this->result(Status::Ok, $aliases ? 'aliases grant send-as' : 'own address only');
    }
}
