<?php

namespace App\Modules;

use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;

/** Serves each loaded module's routes file under /api/v1/modules/<name>, behind the console's middleware. */
final class ModuleRoutes
{
    /**
     * What routes/api.php applies to its console routes, in the same order. The core applies it, so a
     * module can't forget authentication; checking for a server admin stays the module's job.
     */
    public const MIDDLEWARE = [
        'web',
        EnsureSameOrigin::class,
        'auth:web',
        EnsureActiveUser::class,
        AbsoluteSessionLimit::class,
        RequireTwoFactor::class,
    ];

    public static function register(ModuleRegistry $registry): void
    {
        foreach ($registry->loaded() as $module) {
            $file = $module->provider->routes();
            if ($file === null) {
                continue;
            }
            Route::prefix("api/v1/modules/{$module->manifest->name}")
                ->middleware(['api', ...self::MIDDLEWARE])
                ->group($file);
        }
    }
}
