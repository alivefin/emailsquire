<?php

use App\MailServer\GuideSchema;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\GuideFixture;

function stepUpAdmin(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
    ], $attributes));
    test()->actingAs($user);

    return $user;
}

/** Gives the user a confirmed TOTP secret and returns it in plain text. */
function withConfirmedTwoFactor(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

function deleteExampleOrg(array $headers = [])
{
    return test()->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'], $headers);
}

function exampleOrgExists(): bool
{
    return DB::table(GuideSchema::table('virtual_domains'))->where('name', 'example.org')->exists();
}

beforeEach(function () {
    GuideFixture::load('trixie');
    Http::fake();
});

it('refuses a domain delete without a fresh confirmation and deletes nothing', function () {
    stepUpAdmin();

    deleteExampleOrg()->assertStatus(423)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'step_up_required')
        ->assertJsonPath('title', 'Locked')
        ->assertJsonPath('detail', 'Confirm your password, two-factor code or passkey to continue.');

    expect(exampleOrgExists())->toBeTrue();
});

it('deletes the domain after the password is confirmed', function () {
    stepUpAdmin();

    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    expect(session('auth.password_confirmed_at'))->toBe(now()->timestamp);

    deleteExampleOrg()->assertNoContent();
    expect(exampleOrgExists())->toBeFalse();
});

it('accepts a confirmation 300 seconds old and refuses one 301 seconds old', function () {
    stepUpAdmin();
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();

    $this->travel(300)->seconds();
    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'wrong'])->assertStatus(422);

    $this->travel(1)->seconds();
    deleteExampleOrg()->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    expect(exampleOrgExists())->toBeTrue();
});

it('a wrong password is 422 on password and writes the fail2ban line without the password', function () {
    stepUpAdmin();
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=console user=admin@example.org ip=127.0.0.1');

    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'nope nope nope nope'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.password', ['The provided password is incorrect.']);

    expect(session('auth.password_confirmed_at'))->toBeNull();
    deleteExampleOrg()->assertStatus(423);
});

it('a code from a user without two-factor is 422 on code', function () {
    stepUpAdmin();

    $this->postJson('/api/v1/auth/confirm-password', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonPath('errors.code', ['Two-factor authentication is not turned on for your account. Use your password.']);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('a code is refused while two-factor is enabled but not yet confirmed', function () {
    $user = stepUpAdmin();
    $secret = withConfirmedTwoFactor($user);
    $user->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->postJson('/api/v1/auth/confirm-password', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('code', 'errors');
});

it('a valid TOTP code confirms a two-factor user', function () {
    $user = stepUpAdmin();
    $secret = withConfirmedTwoFactor($user);

    $this->postJson('/api/v1/auth/confirm-password', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertNoContent();

    deleteExampleOrg()->assertNoContent();
});

it('a wrong TOTP code is 422 on code and is logged for fail2ban', function () {
    $user = stepUpAdmin();
    $secret = withConfirmedTwoFactor($user);
    $current = app(Google2FA::class)->getCurrentOtp($secret);
    $wrong = str_pad((string) (((int) $current + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=console user=admin@example.org ip=127.0.0.1');

    $this->postJson('/api/v1/auth/confirm-password', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('needs exactly one of password, code and passkey', function (array $body, array $fields) {
    stepUpAdmin();

    $response = $this->postJson('/api/v1/auth/confirm-password', $body)->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
    expect(array_keys($response->json('errors')))->toEqualCanonicalizing($fields);
    expect(session('auth.password_confirmed_at'))->toBeNull();
})->with([
    'neither' => [[], ['password', 'code', 'passkey']],
    'both' => [['password' => 'correct horse battery', 'code' => '123456'], ['password']],
    'password and passkey' => [['password' => 'correct horse battery', 'passkey' => ['id' => 'AAAA']], ['password']],
    'code and passkey' => [['code' => '123456', 'passkey' => ['id' => 'AAAA']], ['code']],
    'non-string password' => [['password' => ['x']], ['password']],
    'non-object passkey' => [['passkey' => 'abc'], ['passkey']],
]);

it('needs a signed-in user', function () {
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])
        ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('throttles confirmation attempts to six a minute', function () {
    stepUpAdmin();

    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'nope nope nope nope'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])
        ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('explains the step-up in Finnish for Accept-Language fi', function () {
    stepUpAdmin();

    deleteExampleOrg(['Accept-Language' => 'fi'])->assertStatus(423)
        ->assertJsonPath('code', 'step_up_required')
        ->assertJsonPath('detail', 'Jatka vahvistamalla henkilöllisyytesi salasanalla, kaksivaiheisen tunnistautumisen koodilla tai pääsyavaimella.')
        ->assertHeader('Vary', 'Accept-Language');
});

it('a non-admin still gets 404, not 423, on a domain delete', function () {
    stepUpAdmin(['is_server_admin' => false]);

    deleteExampleOrg()->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('never serialises the two-factor secret or recovery codes', function () {
    $user = stepUpAdmin();
    withConfirmedTwoFactor($user);

    expect($user->refresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'password']);
    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonMissingPath('two_factor_secret')
        ->assertJsonMissingPath('two_factor_recovery_codes');
});

it('refuses the same TOTP code a second time (replay)', function () {
    $user = stepUpAdmin();
    $secret = withConfirmedTwoFactor($user);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/confirm-password', ['code' => $code])->assertNoContent();
    session()->forget('auth.password_confirmed_at');

    $this->postJson('/api/v1/auth/confirm-password', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('uses its own step-up limiter, counted per user', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/auth/confirm-password');
    expect($route->gatherMiddleware())->toContain('throttle:step-up');

    stepUpAdmin();
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'nope nope nope nope'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'nope nope nope nope'])->assertStatus(429);

    // Another admin on the same IP has a bucket of their own.
    $this->flushSession();
    stepUpAdmin(['email' => 'other@example.org']);
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
});
