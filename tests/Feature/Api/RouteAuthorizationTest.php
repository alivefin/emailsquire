<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\GuideFixture;

/**
 * Every /api/v1 route except the session ones (auth/*) and the signed-in user's own profile and
 * security routes (GET and PATCH me, me/two-factor*, me/recovery-codes, me/password), which act only on that user.
 *
 * @return list<LaravelRoute>
 */
function guardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/')
            && ! str_starts_with($r->uri(), 'api/v1/auth/')
            && ! ($r->uri() === 'api/v1/me' && in_array($r->methods(), [['GET', 'HEAD'], ['PATCH']], true))
            && ! in_array($r->uri(), ['api/v1/me/two-factor', 'api/v1/me/two-factor/setup', 'api/v1/me/two-factor/confirm', 'api/v1/me/recovery-codes', 'api/v1/me/password'], true))
        ->values()
        ->all();
}

function routeUrl(LaravelRoute $route): string
{
    return '/'.preg_replace_callback(
        '/\{(\w+)\??}/',
        fn (array $m) => match ($m[1]) {
            'domain' => 'example.org',
            'address' => 'anna@example.org',
            'mailbox' => 'john',
            'local' => 'anna',
            'user' => '1',
            'invitation' => '1',
            default => 'anna',
        },
        $route->uri(),
    );
}

it('every api route except auth and csrf carries a can: middleware', function () {
    $routes = guardedRoutes();
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $can = collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
        expect($can->isNotEmpty())->toBeTrue("{$route->methods()[0]} {$route->uri()} has no can: middleware");
    }
});

it('a signed-out client gets 401 on every guarded route', function () {
    GuideFixture::load('trixie');

    foreach (guardedRoutes() as $route) {
        $method = $route->methods()[0];
        $this->json($method, routeUrl($route), [])->assertUnauthorized();
    }
});

it('every can: route is in the role matrix, and every matrix row is a route', function () {
    $routes = [];
    foreach (guardedRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = $method.' '.substr($route->uri(), strlen('api/v1/'));
        }
    }

    // MatrixTest runs each row for every principal; a route without a row has no tested answer.
    expect(array_values(array_diff($routes, array_keys(AuthorizationMatrix::routes()))))->toBe([])
        ->and(array_values(array_diff(array_keys(AuthorizationMatrix::routes()), $routes)))->toBe([]);
});

it('on every step-up route the can: check runs first, so an out-of-scope object stays a 404, not a 423', function () {
    $stepUp = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('step-up', $r->gatherMiddleware(), true));
    expect($stepUp)->not->toBeEmpty();

    foreach ($stepUp as $route) {
        if (! in_array($route, guardedRoutes(), true)) {
            continue; // the user's own /me routes act on nothing but that user
        }
        $middleware = array_values($route->gatherMiddleware());
        $can = collect($middleware)->search(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
        $label = "{$route->methods()[0]} {$route->uri()}";

        expect($can)->not->toBeFalse("{$label} has no can: middleware")
            ->and($can)->toBeLessThan(array_search('step-up', $middleware, true), "{$label} runs step-up before can:");
    }
});
