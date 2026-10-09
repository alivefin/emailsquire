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
        if (! app()->environment('production')) {
            return $this->result(Status::Skip, 'Not a production environment');
        }

        return config('app.debug') === true
            ? $this->result(Status::Fail, 'APP_DEBUG is true in production')
            : $this->result(Status::Ok, 'APP_DEBUG is off');
    }
}
