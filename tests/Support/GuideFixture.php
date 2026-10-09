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

    private static function schema(): string
    {
        $schema = config('emailsquire.guide_schema');

        if (! is_string($schema) || preg_match('/^[A-Za-z0-9_]+$/', $schema) !== 1) {
            throw new RuntimeException('emailsquire.guide_schema must be a plain identifier.');
        }

        return $schema;
    }
}
