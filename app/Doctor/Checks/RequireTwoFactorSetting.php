<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

/** Required two-factor (`EMAILSQUIRE_REQUIRE_TWO_FACTOR`) is never switched off on a production server. */
final class RequireTwoFactorSetting extends BaseCheck
{
    public function id(): string
    {
        return 'auth.require_two_factor';
    }

    public function title(): string
    {
        return 'Two-factor authentication required';
    }

    public function run(): CheckResult
    {
        $value = config('emailsquire.require_two_factor');
        $value = is_string($value) ? $value : '';

        if ($value === 'off' && app()->environment('production')) {
            return $this->result(Status::Fail, 'EMAILSQUIRE_REQUIRE_TWO_FACTOR is off in production; set it to admins or everyone');
        }

        return $this->result(Status::Ok, match ($value) {
            'admins', 'everyone', 'off' => "require_two_factor is {$value}",
            default => 'require_two_factor is “'.mb_substr($value, 0, 40).'”, read as admins',
        });
    }
}
