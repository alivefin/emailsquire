<?php

namespace App\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;

/**
 * Adds each loaded module's migrations directory to the core's, so one `migrate` runs both. Like a
 * provider's loadMigrationsFrom(), it waits for the migrator: its provider is deferred, and a web request
 * never resolves it.
 */
final class ModuleMigrations
{
    public static function register(ModuleRegistry $registry, Application $app): void
    {
        $add = function (Migrator $migrator) use ($registry): void {
            foreach ($registry->loaded() as $module) {
                $dir = $module->provider->migrations();
                if ($dir !== null) {
                    $migrator->path($dir);
                }
            }
        };

        $app->afterResolving('migrator', $add);
        if ($app->resolved('migrator')) {
            $add($app->make('migrator'));
        }
    }
}
