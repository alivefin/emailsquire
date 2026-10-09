<?php

namespace App\MailServer;

use RuntimeException;

/**
 * The single place that builds schema-qualified guide table names, so one
 * connection (and one transaction) spans the app and guide schemas.
 */
final class GuideSchema
{
    public static function table(string $table): string
    {
        $schema = config('emailsquire.guide_schema');

        if (! is_string($schema) || preg_match('/^[A-Za-z0-9_]+$/', $schema) !== 1) {
            throw new RuntimeException('emailsquire.guide_schema must be a plain identifier.');
        }

        return "{$schema}.{$table}";
    }
}
