<?php

namespace App\Doctor\Checks;

use App\Console\Commands\GrantsCommand;
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
        $connection = config('database.default');
        if (is_string($connection) && config("database.connections.{$connection}.password") === GrantsCommand::PLACEHOLDER_PASSWORD) {
            return $this->result(Status::Fail, 'The database password is '.GrantsCommand::PLACEHOLDER_PASSWORD
                .': the grants placeholder password was applied unedited. Set a real one with ALTER USER and in DB_PASSWORD.');
        }

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return $this->result(Status::Fail, $e::class.': '.$e->getMessage());
        }

        return $this->result(Status::Ok, 'Connected to '.DB::connection()->getDatabaseName());
    }
}
