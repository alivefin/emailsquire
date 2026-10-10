<?php

use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureSameOrigin;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', EnsureSameOrigin::class])->prefix('api/v1')->group(function () {
        Route::post('_t/write', fn () => response()->json(['ok' => true]));
    });
    Route::middleware(['web', 'auth', AbsoluteSessionLimit::class])->prefix('api/v1')->group(function () {
        Route::get('_t/private', fn () => response()->json(['ok' => true]));
    });
});

it('rejects a POST without an XSRF token with 419', function () {
    config(['emailsquire.testing.enforce_csrf' => true]);

    $this->postJson('/api/v1/_t/write')
        ->assertStatus(419)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'csrf_mismatch');
});

it('rejects a POST from a foreign Origin', function () {
    $this->withHeader('Origin', 'https://evil.example')->postJson('/api/v1/_t/write')
        ->assertStatus(403)
        ->assertJsonPath('code', 'cross_origin');
});

it('rejects a POST with Sec-Fetch-Site cross-site', function () {
    $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/api/v1/_t/write')
        ->assertStatus(403)
        ->assertJsonPath('code', 'cross_origin');
});

it('accepts a same-origin POST with a valid token', function () {
    config(['emailsquire.testing.enforce_csrf' => true]);

    $this->withSession(['_token' => 'tok'])
        ->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://localhost', 'X-CSRF-TOKEN' => 'tok'])
        ->postJson('/api/v1/_t/write')
        ->assertOk();
});

it('puts the security headers on every response', function () {
    $response = $this->get('/up');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("form-action 'self'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");
    $response->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $this->getJson('/api/v1/nope')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('logs out a session older than 12 hours', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.signed_in_at' => now()->subHours(12)->subSecond()->timestamp])
        ->getJson('/api/v1/_t/private')
        ->assertStatus(401)
        ->assertJsonPath('code', 'session_expired');

    $this->assertGuest();
});

it('keeps a session just under 12 hours', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.signed_in_at' => now()->subHours(11)->subMinutes(59)->timestamp])
        ->getJson('/api/v1/_t/private')
        ->assertOk();
});

it('stamps an authenticated session that has no sign-in time', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/_t/private')->assertOk();

    expect(session('auth.signed_in_at'))->toBeInt();
});

it('refuses to serve with APP_DEBUG on in production', function () {
    config(['app.env' => 'production', 'app.debug' => true]);

    $this->get('/up')->assertStatus(503)->assertSee('APP_DEBUG');
});

it('hardens the session config', function () {
    expect(config('session.cookie'))->toBe('__Host-emailsquire')
        ->and(config('session.same_site'))->toBe('strict')
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.domain'))->toBeNull()
        ->and(config('session.lifetime'))->toBe(30)
        ->and(config('session.expire_on_close'))->toBeFalse()
        ->and(config('session.secure'))->toBeTrue();
});

it('never bypasses CSRF outside the console, even with APP_ENV=testing', function () {
    $this->app->detectEnvironment(fn () => 'testing');
    expect(app()->runningUnitTests())->toBeTrue();
    (fn () => $this->isRunningInConsole = false)->call($this->app);

    $this->postJson('/api/v1/_t/write')->assertStatus(419)->assertJsonPath('code', 'csrf_mismatch');
});
