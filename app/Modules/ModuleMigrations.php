<?php

namespace App\Modules;

use Illuminate\Database\Migrations\Migrator;

/** Adds each loaded module's migrations directory to the core's, so one `migrate` runs both. */
final class ModuleMigrations
{
    public static function register(ModuleRegistry $registry, Migrator $migrator): void
    {
        foreach ($registry->loaded() as $module) {
            $dir = $module->provider->migrations();
            if ($dir !== null) {
                $migrator->path($dir);
            }
        }
    }
}
