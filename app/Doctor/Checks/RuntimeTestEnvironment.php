<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

/**
 * The e2e and testing environments never run on a mail server. Under APP_ENV=e2e mailbox passwords
 * are checked by FakeDovecotAuthClient instead of Dovecot (no auth penalty, no auth log, BLF-CRYPT
 * only), so such an environment pointed at the production mail root fails.
 */
final class RuntimeTestEnvironment extends BaseCheck
{
    private const PRODUCTION_MAIL_ROOT = '/var/vmail';

    public function id(): string
    {
        return 'runtime.test_environment';
    }

    public function title(): string
    {
        return 'No test environment on the mail server';
    }

    public function run(): CheckResult
    {
        $env = app()->environment();
        $root = config('emailsquire.paths.mail_root');
        $root = is_string($root) ? rtrim($root, '/') : '';

        if (! app()->environment(['e2e', 'testing'])) {
            return $this->result(Status::Ok, "APP_ENV is {$env}");
        }

        if ($root === self::PRODUCTION_MAIL_ROOT) {
            return $this->result(Status::Fail, "APP_ENV is {$env} with the production mail root ".self::PRODUCTION_MAIL_ROOT
                .'; e2e checks mailbox passwords without Dovecot. Set APP_ENV=production, or point EMAILSQUIRE_MAIL_ROOT elsewhere for a test copy');
        }

        return $this->result(Status::Ok, "APP_ENV is {$env}, mail root {$root}");
    }
}
