<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseConnection extends BaseCheck
{
    public function id(): string
    {
        return 'database.connection';
    }

    public function title(): string
    {
        return 'Database connection';
    }

    public function run(): CheckResult
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return $this->result(Status::Fail, $e::class.': '.$e->getMessage());
        }

        return $this->result(Status::Ok, 'Connected to '.DB::connection()->getDatabaseName());
    }
}
