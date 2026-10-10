<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Writes the OpenAPI 3.1 document the SPA generates its TypeScript types from. Needs no database. */
class ExportOpenApiCommand extends Command
{
    protected $signature = 'emailsquire:openapi {path=resources/js/api/openapi.json}';

    protected $description = 'Export the OpenAPI document for the /api/v1 routes';

    public function handle(): int
    {
        $path = $this->argument('path');
        if ($path === '') {
            return self::INVALID;
        }
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        File::ensureDirectoryExists(dirname($path));

        // Scramble reads Eloquent column types while it infers responses. Point it at an empty
        // in-memory database so the export needs no server and is the same on every machine; the
        // response shapes come from resources and annotations, not from the live schema.
        $previous = [config('database.default'), config('emailsquire.guide_schema')];
        config([
            'database.connections.openapi' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'openapi',
            'emailsquire.guide_schema' => 'main', // SQLite's own schema name, so qualified guide tables resolve
        ]);

        try {
            return $this->call('scramble:export', ['--path' => $path]);
        } finally {
            config(['database.default' => $previous[0], 'emailsquire.guide_schema' => $previous[1]]);
            DB::purge('openapi');
        }
    }
}
