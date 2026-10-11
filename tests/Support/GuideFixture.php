<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the ISPmail guide schema for tests. The only place that issues DDL
 * against the guide schema. Never issues USE: the connection's default
 * database stays the app schema.
 */
final class GuideFixture
{
    public static function load(string ...$names): void
    {
        $schema = self::schema();

        DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
        DB::statement("CREATE DATABASE `{$schema}`");

        foreach ($names as $name) {
            $path = __DIR__."/../fixtures/sql/{$name}.sql";
            $sql = file_get_contents($path);

            if ($sql === false) {
                throw new RuntimeException("Fixture not found: {$path}");
            }

            DB::unprepared(str_replace('{{schema}}', $schema, $sql));
        }
    }

    /** Drops the guide schema, to test how the app behaves without one. */
    public static function drop(): void
    {
        DB::statement('DROP DATABASE IF EXISTS `'.self::schema().'`');
    }

    /**
     * Applies the optional schema extension of backend plan section 10 to the loaded guide schema: the
     * `enabled` and `sendonly` columns of `virtual_users`. Pass names to add only those columns.
     */
    public static function addMailboxExtension(string ...$columns): void
    {
        $schema = self::schema();
        $definitions = ['enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'sendonly' => 'TINYINT(1) NOT NULL DEFAULT 0'];
        $columns = $columns === [] ? array_keys($definitions) : $columns;

        foreach ($columns as $column) {
            if (! isset($definitions[$column])) {
                throw new RuntimeException("Unknown extension column: {$column}");
            }
            DB::statement("ALTER TABLE `{$schema}`.`virtual_users` ADD COLUMN `{$column}` {$definitions[$column]}");
        }
    }

    /** Removes the extension columns again (those that exist). */
    public static function dropMailboxExtension(): void
    {
        $schema = self::schema();

        foreach (['enabled', 'sendonly'] as $column) {
            $exists = DB::selectOne(
                'SELECT 1 AS present FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$schema, 'virtual_users', $column],
            );
            if ($exists !== null) {
                DB::statement("ALTER TABLE `{$schema}`.`virtual_users` DROP COLUMN `{$column}`");
            }
        }
    }

    private static function schema(): string
    {
        $schema = config('emailsquire.guide_schema');

        if (! is_string($schema) || preg_match('/^[A-Za-z0-9_]+$/', $schema) !== 1) {
            throw new RuntimeException('emailsquire.guide_schema must be a plain identifier.');
        }

        return $schema;
    }
}
