<?php

use App\Auth\AdminSetup;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function pendingAdmin(): array
{
    $user = User::factory()->create([
        'email' => 'new@example.org',
        'password' => 'unusable-random-value',
        'is_server_admin' => true,
        'password_set_at' => null,
    ]);

    return [$user, app(AdminSetup::class)->issue($user)];
}

function setupPayload(string $token, array $over = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'new@example.org',
        'password' => 'a long passphrase 42',
        'password_confirmation' => 'a long passphrase 42',
    ], $over);
}

it('setup with a valid token sets the password and signs in', function () {
    [$user, $token] = pendingAdmin();

    $this->getJson('/api/v1/auth/csrf');
    $this->postJson('/api/v1/auth/setup', setupPayload($token))
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id, 'name' => $user->name, 'email' => 'new@example.org', 'is_server_admin' => true, 'locale' => 'en',
            'two_factor' => ['enabled' => false, 'confirmed' => false, 'recovery_codes_left' => 0], 'two_factor_required' => false,
        ]);

    $user->refresh();
    expect($user->password_set_at)->not->toBeNull();
    expect(Hash::check('a long passphrase 42', $user->password))->toBeTrue();
    expect(AuditEntry::where('action', 'admin.setup')->where('actor_type', 'user')->where('actor_id', (string) $user->id)->where('object_key', 'new@example.org')->count())->toBe(1);
    expect(DB::table('admin_setup_tokens')->where('email', 'new@example.org')->count())->toBe(0);
    $this->getJson('/api/v1/me')->assertOk();
    expect(session('auth.signed_in_at'))->toBeInt();
});

it('a setup token works once', function () {
    [, $token] = pendingAdmin();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['token']]);
});

it('an expired setup token (73 h) is refused', function () {
    [$user, $token] = pendingAdmin();

    $this->travel(73)->hours();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
    expect($user->refresh()->password_set_at)->toBeNull();
});

it('a setup token still works at 71 h', function () {
    [, $token] = pendingAdmin();

    $this->travel(71)->hours();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertOk();
});

it('setup rejects a token for a different email', function () {
    [, $token] = pendingAdmin();
    User::factory()->create(['email' => 'other@example.org']);

    $this->postJson('/api/v1/auth/setup', setupPayload($token, ['email' => 'other@example.org']))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
});

it('setup rejects a password under 12 characters', function () {
    [$user, $token] = pendingAdmin();

    $this->postJson('/api/v1/auth/setup', setupPayload($token, ['password' => 'short pass1', 'password_confirmation' => 'short pass1']))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    expect($user->refresh()->password_set_at)->toBeNull();
});

it('setup requires the confirmation to match', function () {
    [, $token] = pendingAdmin();

    $this->postJson('/api/v1/auth/setup', setupPayload($token, ['password_confirmation' => 'something else entirely']))
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
});

it('setup refuses a cross-origin request', function () {
    [, $token] = pendingAdmin();

    $this->withHeader('Origin', 'https://evil.example')->postJson('/api/v1/auth/setup', setupPayload($token))
        ->assertStatus(403)->assertJsonPath('code', 'cross_origin');
});

it('a reset through a setup link signs out the user\'s other sessions but keeps the setup session', function () {
    $user = User::factory()->create([
        'email' => 'new@example.org', 'password' => 'the old passphrase 1', 'is_server_admin' => true, 'password_set_at' => now(),
    ]);
    $cookie = config('session.cookie');
    // Each request below stands alone: only its session cookie says who it is. The test client keeps the
    // signed-in guard and the session store's attributes between requests, so drop both before each one.
    $as = function (string $sessionId) use ($cookie) {
        app('auth')->forgetGuards();
        session()->flush();

        return $this->withCredentials()->withCookie($cookie, $sessionId);
    };

    $old = $as('')->postJson('/api/v1/auth/login', ['email' => 'new@example.org', 'password' => 'the old passphrase 1'])->assertOk()
        ->getCookie($cookie)->getValue();
    $as($old)->getJson('/api/v1/me')->assertOk();

    $token = app(AdminSetup::class)->issue($user);
    $setup = $as('')->postJson('/api/v1/auth/setup', setupPayload($token))->assertOk()->getCookie($cookie)->getValue();
    expect($setup)->not->toBe($old);

    $as($old)->getJson('/api/v1/me')->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'unauthenticated');
    $as($setup)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'new@example.org');
});

it('a reset through a setup link deletes the user\'s other session rows', function () {
    $user = User::factory()->create([
        'email' => 'new@example.org', 'password' => 'the old passphrase 1', 'is_server_admin' => true, 'password_set_at' => now(),
    ]);
    $bob = User::factory()->create(['email' => 'bob@example.org']);
    insertSessionRow($user->id, 'old-laptop');
    insertSessionRow($bob->id, 'bob-laptop');

    $this->postJson('/api/v1/auth/setup', setupPayload(app(AdminSetup::class)->issue($user)))->assertOk();

    expect(sessionRowIds())->toBe(['bob-laptop']);
});
