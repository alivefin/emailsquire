<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

final class RuntimeCaches extends BaseCheck
{
    public function id(): string
    {
        return 'runtime.caches';
    }

    public function title(): string
    {
        return 'Config and route caches';
    }

    public function run(): CheckResult
    {
        if (! app()->environment('production')) {
            return $this->result(Status::Skip, 'Not a production environment');
        }

        $missing = [];
        if (! app()->configurationIsCached()) {
            $missing[] = 'config';
        }
        if (! app()->routesAreCached()) {
            $missing[] = 'routes';
        }

        return $missing === []
            ? $this->result(Status::Ok, 'Config and routes are cached')
            : $this->result(Status::Warn, 'Not cached: '.implode(', ', $missing).' (run php artisan optimize)');
    }
}
