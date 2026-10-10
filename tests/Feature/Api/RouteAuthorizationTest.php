<?php

use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\GuideFixture;

/**
 * Every /api/v1 route except the session ones (auth/*) and GET me.
 *
 * @return list<LaravelRoute>
 */
function guardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/')
            && ! str_starts_with($r->uri(), 'api/v1/auth/')
            && ! ($r->uri() === 'api/v1/me' && $r->methods() === ['GET', 'HEAD']))
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

it('a non-admin console user gets 404 on every guarded route', function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => false]));

    foreach (guardedRoutes() as $route) {
        $method = $route->methods()[0];
        $this->json($method, routeUrl($route), [])->assertNotFound();
    }
});
