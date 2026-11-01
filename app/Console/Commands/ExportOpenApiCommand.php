<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Dedoc\Scramble\Contracts\Diagnostics\Diagnostic;
use Dedoc\Scramble\Contracts\RouteProvider;
use Dedoc\Scramble\Diagnostics\DiagnosticSeverity;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\OperationBuilder;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Writes the OpenAPI 3.1 document the SPA generates its TypeScript types from. Needs no database. */
class ExportOpenApiCommand extends Command
{
    /**
     * Scramble diagnostics that every export produces by design, so they are not reported:
     *
     * - MD001 "cannot read database schema": the export runs against an empty in-memory database
     *   (see handle()), so no model has a table. Response shapes come from annotations instead.
     * - JR001 "cannot infer the resource model": the JsonResources wrap plain row arrays, not
     *   Eloquent models, so there is no model to infer from; their keys are annotated per property.
     *
     * Any other diagnostic is printed, and an error-level one fails the command.
     */
    private const EXPECTED_DIAGNOSTICS = ['MD001', 'JR001'];

    protected $signature = 'emailsquire:openapi {path=resources/js/api/openapi.json : Where to write the document}
        {--module= : Export only this loaded module\'s routes}
        {--output= : Where to write the document (overrides the path argument)}';

    protected $description = 'Export the OpenAPI document for the /api/v1 routes';

    public function handle(): int
    {
        $path = $this->option('output') ?: $this->argument('path');
        $module = $this->option('module');
        if (is_string($module) && $module !== '') {
            if (app(ModuleRegistry::class)->find($module) === null) {
                $this->error("No loaded module is named \"{$module}\".");

                return self::FAILURE;
            }
            Scramble::configure()->routes(fn (Route $route) => str_starts_with($route->uri(), "api/v1/modules/{$module}/"));
        } else {
            // The core's document names the core's audit groups only, whatever modules this host loads.
            app()->instance(ModuleRegistry::class, new ModuleRegistry);
        }
        if ($path === '') {
            return self::INVALID;
        }
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        File::ensureDirectoryExists(dirname($path));

        // Scramble reads Eloquent column types while it infers responses. Point it at an empty
        // in-memory database so the export needs no server and is the same on every machine.
        $previous = [config('database.default'), config('emailsquire.guide_schema')];
        config([
            'database.connections.openapi' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'openapi',
            'emailsquire.guide_schema' => 'main', // SQLite's own schema name, so qualified guide tables resolve
        ]);

        try {
            $result = (new Generator(app(OperationBuilder::class), app(RouteProvider::class)))
                ->generate(Scramble::getGeneratorConfig('default'));
        } finally {
            config(['database.default' => $previous[0], 'emailsquire.guide_schema' => $previous[1]]);
            DB::purge('openapi');
        }

        File::put($path, json_encode($result->spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $failed = false;
        /** @var Diagnostic $diagnostic */
        foreach ($result->diagnostics() as $diagnostic) {
            if (in_array($diagnostic->code(), self::EXPECTED_DIAGNOSTICS, true)) {
                continue;
            }
            $isError = $diagnostic->severity() === DiagnosticSeverity::Error;
            $failed = $failed || $isError;
            $this->warn(sprintf('[%s] %s: %s', $isError ? 'ERROR' : 'WARNING', $diagnostic->code(), $diagnostic->message()));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
