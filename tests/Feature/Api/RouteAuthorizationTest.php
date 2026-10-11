<?php

use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\GuideFixture;

/**
 * Every /api/v1 route except the session ones (auth/*), the mailbox portal's (portal/*, its own guard)
 * and the signed-in user's own profile and security routes (GET and PATCH me, me/two-factor*,
 * me/recovery-codes, me/password, me/passkeys*), which act only on that user.
 *
 * @return list<LaravelRoute>
 */
function guardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/')
            && ! str_starts_with($r->uri(), 'api/v1/auth/')
            && ! str_starts_with($r->uri(), 'api/v1/portal/')
            && ! ($r->uri() === 'api/v1/me' && in_array($r->methods(), [['GET', 'HEAD'], ['PATCH']], true))
            && ! in_array($r->uri(), ['api/v1/me/two-factor', 'api/v1/me/two-factor/setup', 'api/v1/me/two-factor/confirm', 'api/v1/me/recovery-codes', 'api/v1/me/password',
                'api/v1/me/passkeys', 'api/v1/me/passkeys/options', 'api/v1/me/passkeys/{id}'], true))
        ->values()
        ->all();
}

/** @return list<LaravelRoute> the mailbox portal's routes */
function portalRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/portal/'))
        ->values()
        ->all();
}

/** The routes a client may call signed out: signing in and the steps around it. */
const RA_PUBLIC_ROUTES = [
    'GET api/v1/auth/csrf', 'POST api/v1/auth/login', 'POST api/v1/auth/setup', 'POST api/v1/auth/two-factor-challenge',
    'POST api/v1/auth/forgot-password', 'POST api/v1/auth/reset-password',
    'POST api/v1/auth/invitations/lookup', 'POST api/v1/auth/invitations/accept',
    'POST api/v1/auth/two-factor-challenge/passkey-options', 'POST api/v1/auth/passkey/options', 'POST api/v1/auth/passkey',
];

/** @return list<string> the route's `auth` middleware, e.g. ['auth:web'] */
function authMiddleware(LaravelRoute $route): array
{
    return array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:'))));
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
            'selector' => 'es20261011',
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

it('every portal route takes only the mailbox guard, and every other signed-in route only the console guard', function () {
    expect(portalRoutes())->not->toBeEmpty();
    $wrong = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $label = $route->methods()[0].' '.$route->uri();
        $expected = match (true) {
            in_array($label, RA_PUBLIC_ROUTES, true) => [],
            // Sign-out ends either kind of session.
            $label === 'POST api/v1/auth/logout' => ['auth:web,mailbox'],
            str_starts_with($route->uri(), 'api/v1/portal/') => ['auth:mailbox'],
            default => ['auth:web'],
        };
        if (authMiddleware($route) !== $expected) {
            $wrong[] = $label.' has '.json_encode(authMiddleware($route));
        }
    }

    expect($wrong)->toBe([]);
});

it('a signed-out client and a console user get 401 on every portal route', function () {
    GuideFixture::load('trixie');

    foreach (portalRoutes() as $route) {
        $this->json($route->methods()[0], routeUrl($route), [])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    foreach (portalRoutes() as $route) {
        $this->json($route->methods()[0], routeUrl($route), [])->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }
});
