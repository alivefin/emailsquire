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

// --- A setup link for a user with confirmed two-factor ---------------------------------------------

/** An admin with a password and confirmed two-factor, holding a setup link from create-admin --reset. */
function twoFactorAdminWithLink(): array
{
    $user = User::factory()->create([
        'email' => 'new@example.org', 'password' => 'the old passphrase 1', 'is_server_admin' => true, 'password_set_at' => now(),
    ]);
    $secret = tfaEnrol($user);

    return [$user, $secret, app(AdminSetup::class)->issue($user)];
}

it('a setup link for a user with confirmed two-factor sets the password but leaves the sign-in pending', function () {
    [$user, , $token] = twoFactorAdminWithLink();
    $this->getJson('/api/v1/auth/csrf');
    $before = session()->getId();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertOk()->assertExactJson(['two_factor' => true]);

    expect(Hash::check('a long passphrase 42', $user->refresh()->password))->toBeTrue()
        ->and($user->last_login_at)->toBeNull()
        ->and(session()->getId())->not->toBe($before)
        ->and(session('login.id'))->toBe($user->id)
        ->and(session('login.remember'))->toBeFalse()
        ->and(session('login.at'))->toBe(now()->getTimestamp())
        ->and(session('auth.signed_in_at'))->toBeNull();
    expect(AuditEntry::where('action', 'admin.setup')->where('actor_type', 'user')->where('actor_id', (string) $user->id)->count())->toBe(1);
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});

it('the pending setup sign-in finishes with the two-factor challenge', function () {
    [$user, $secret, $token] = twoFactorAdminWithLink();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertExactJson(['two_factor' => true]);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => tfaOtp($secret)])->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('two_factor.confirmed', true);

    expect(session('login.id'))->toBeNull()->and(session('auth.signed_in_at'))->toBeInt();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'new@example.org');
});

it('a setup link still ends the other sessions of a user with two-factor', function () {
    [$user, , $token] = twoFactorAdminWithLink();
    insertSessionRow($user->id, 'old-laptop');

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertExactJson(['two_factor' => true]);

    expect(sessionRowIds())->toBe([]);
});

it('a user whose two-factor is set up but not confirmed is signed in by the setup link as before', function () {
    [$user, , $token] = twoFactorAdminWithLink();
    $user->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->postJson('/api/v1/auth/setup', setupPayload($token))->assertOk()->assertJsonPath('id', $user->id);
    $this->getJson('/api/v1/me')->assertOk();
});
