<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

/** Assertions a module's own test suite reuses. */
final class ModuleAssertions
{
    /**
     * Runs the migrations in $migrationsDir on the test app schema (never the guide schema) and asserts
     * every table they create starts with $prefix. What they created is rolled back, or dropped if
     * a migration has no working `down()`, before the assertion is made.
     */
    public static function tablesArePrefixed(string $migrationsDir, string $prefix): void
    {
        $migrator = app('migrator');
        $before = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);
        $created = [];

        try {
            $migrator->run([$migrationsDir]);
            $created = array_values(array_diff(Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false), $before));
        } finally {
            self::cleanUp($migrationsDir, $before);
        }

        $stray = array_values(array_filter($created, fn (string $table) => ! str_starts_with($table, $prefix)));
        Assert::assertSame([], $stray, "Tables outside the \"{$prefix}\" prefix: ".implode(', ', $stray));
    }

    /** @param list<string> $before */
    private static function cleanUp(string $dir, array $before): void
    {
        try {
            app('migrator')->rollback([$dir]);
        } finally {
            Schema::disableForeignKeyConstraints();
            foreach (array_diff(Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false), $before) as $table) {
                Schema::dropIfExists($table);
            }
            Schema::enableForeignKeyConstraints();

            $names = array_map(fn (string $file) => basename($file, '.php'), glob($dir.'/*.php') ?: []);
            DB::table('migrations')->whereIn('migration', $names)->delete();
        }
    }
}
