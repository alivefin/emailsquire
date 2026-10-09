<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

final class RuntimeDebug extends BaseCheck
{
    public function id(): string
    {
        return 'runtime.debug';
    }

    public function title(): string
    {
        return 'APP_DEBUG off in production';
    }

    public function run(): CheckResult
    {
        // Warn rather than skip: a server left on APP_ENV=local would otherwise pass silently.
        if (! app()->environment('production')) {
            $detail = 'APP_ENV is '.app()->environment().', not production';

            return $this->result(Status::Warn, config('app.debug') === true ? $detail.'; APP_DEBUG is on' : $detail);
        }

        return config('app.debug') === true
            ? $this->result(Status::Fail, 'APP_DEBUG is true in production')
            : $this->result(Status::Ok, 'APP_DEBUG is off');
    }
}
