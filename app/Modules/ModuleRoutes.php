<?php

namespace App\Modules;

use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Routing\RouteFileRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * Serves each loaded module's routes file under /api/v1/modules/<name>, behind the console's middleware.
 * A module whose routes() throws, whose file is missing, or whose file throws (a ParseError included) is
 * logged and serves no routes, or only those its file registered before the throw; the others still do.
 */
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
            $file = $module->guard('routes()', fn () => $module->provider->routes(), null);
            if ($file === null) {
                continue;
            }
            if (! is_file($file)) {
                $module->warn("routes file {$file} does not exist.");

                continue;
            }
            // The file is required inside the group's closure, so a throw from it is caught there and the
            // router still leaves the group: the next module's routes don't land under this one's prefix.
            Route::prefix("api/v1/modules/{$module->manifest->name}")
                ->middleware(['api', ...self::MIDDLEWARE])
                ->group(function (Router $router) use ($module, $file): void {
                    $stack = $router->getGroupStack();
                    $module->guard('its routes file', fn () => (new RouteFileRegistrar($router))->register($file), null);
                    self::closeOpenGroups($router, $stack);
                });
        }
    }

    /**
     * Router::group() leaves a group only when its routes return, so a throw inside one of the module's own
     * groups leaves that group open. Puts the stack back as it was before the file ran.
     *
     * @param  array<mixed>  $stack
     */
    private static function closeOpenGroups(Router $router, array $stack): void
    {
        if ($router->getGroupStack() !== $stack) {
            (function (array $stack): void {
                $this->groupStack = $stack;
            })->call($router, $stack);
        }
    }
}
