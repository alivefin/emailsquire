<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use Illuminate\Contracts\Foundation\Application;

final class AppMigrated extends BaseCheck
{
    public function __construct(private readonly Application $app) {}

    public function id(): string
    {
        return 'app.migrated';
    }

    public function title(): string
    {
        return 'App schema migrated';
    }

    public function run(): CheckResult
    {
        $pending = $this->pending();

        if ($pending === null) {
            return $this->result(Status::Fail, 'The migrations table is missing; run php artisan migrate --database=migrator.');
        }

        if ($pending !== []) {
            return $this->result(
                Status::Fail,
                count($pending).' migration(s) pending; run php artisan migrate --database=migrator.',
                $pending,
            );
        }

        return $this->result(Status::Ok, 'All migrations have run.');
    }

    /**
     * Names of migrations that have not run, or null when the migrations
     * table itself is missing.
     *
     * @return list<string>|null
     */
    public function pending(): ?array
    {
        $migrator = $this->app->make('migrator');

        if (! $migrator->repositoryExists()) {
            return null;
        }

        $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->app->databasePath('migrations')]);
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }
}
