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
        $recorded = DB::table('migrations')->whereIn('migration', self::migrationNames($migrationsDir))->pluck('migration')->all();
        // Already migrated (say by the harness's own migrate): running finds nothing, and the cleanup
        // below would roll the module's real tables back for later tests. Refuse before touching anything.
        Assert::assertSame([], $recorded, 'Migrations already ran on the test schema: '.implode(', ', $recorded).'. Check them on a schema where the module is not migrated.');

        $before = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);
        $created = [];

        try {
            $migrator->run([$migrationsDir]);
            $created = array_values(array_diff(Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false), $before));
        } finally {
            self::cleanUp($migrationsDir, $before);
        }

        Assert::assertNotSame([], $created, "The migrations in {$migrationsDir} created no tables.");

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

            DB::table('migrations')->whereIn('migration', self::migrationNames($dir))->delete();
        }
    }

    /** @return list<string> */
    private static function migrationNames(string $dir): array
    {
        return array_map(fn (string $file) => basename($file, '.php'), glob($dir.'/*.php') ?: []);
    }
}
