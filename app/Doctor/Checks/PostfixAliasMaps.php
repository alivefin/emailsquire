<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\PostfixConfig;
use App\Doctor\Support\PostfixMapFile;

final class PostfixAliasMaps extends BaseCheck
{
    public const EMAIL2EMAIL_QUERY = "SELECT email FROM virtual_users WHERE email='%s'";

    public function id(): string
    {
        return 'postfix.alias_maps';
    }

    public function title(): string
    {
        return 'Postfix alias maps (catch-all)';
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

        foreach (PostfixConfig::fromFile($mainCf)->mapFiles('virtual_alias_maps') as $file) {
            if (str_contains(basename($file), 'email2email')) {
                return $this->result(Status::Ok, 'catch-all supported');
            }

            if (is_readable($file) && PostfixMapFile::query($file) === self::EMAIL2EMAIL_QUERY) {
                return $this->result(Status::Ok, 'catch-all supported');
            }
        }

        return $this->result(Status::Warn, 'catch-all aliases will be refused: virtual_alias_maps has no email2email map');
    }
}
