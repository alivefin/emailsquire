<?php

use App\Auth\AdminSetup;
use App\Http\Middleware\RequireStepUp;
use App\Mail\SecurityNotice;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAuthenticator;
use Tests\Support\FakePasswordChecker;
use Tests\Support\GuideFixture;

// Signing in and confirming a step-up with a passkey (passkeys spec decisions 2, 5-7, 10, 12-13; plan
// Task 2). FakeAuthenticator signs real ES256 assertions, so webauthn-lib's verification runs every time.

function pskUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
    ], $attributes));
}

function pskLoginOptions(): array
{
    test()->getJson('/api/v1/auth/csrf');

    return test()->postJson('/api/v1/auth/passkey/options')->assertOk()->json();
}

/** A passwordless sign-in: fresh options, then $authenticator's assertion (with $user's handle). */
function pskSignIn(FakeAuthenticator $authenticator, ?User $user, array $tamper = [], ?string $handle = null): TestResponse
{
    $options = pskLoginOptions();

    return test()->postJson('/api/v1/auth/passkey', [
        'credential' => $authenticator->assert($options, $handle ?? $user?->getPasskeyUserHandle(), $tamper),
    ]);
}

function pskPasswordStep(string $email = 'admin@example.org'): TestResponse
{
    test()->getJson('/api/v1/auth/csrf');

    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'correct horse battery']);
}

function pskSignOut(): void
{
    test()->postJson('/api/v1/auth/logout')->assertNoContent();
}

function pskFailed(TestResponse $response, string $field = 'credential', string $code = 'passkey_failed'): TestResponse
{
    return $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code)
        ->assertJsonPath("errors.{$field}.0", $code === 'passkey_failed'
            ? 'The passkey could not be verified. Try again.'
            : 'This passkey looks copied, so it was turned off. Sign in another way, then remove it.');
}

/** Expects exactly one fail2ban line, for $email. */
function pskExpectFailLine(string $email): void
{
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with("emailsquire auth failure kind=passkey user={$email} ip=127.0.0.1");
    Log::shouldReceive('info', 'debug', 'error', 'notice')->zeroOrMoreTimes();
}

function pskStoredCounter(Passkey $passkey): int
{
    return (int) $passkey->refresh()->credential['counter'];
}

beforeEach(fn () => Http::fake());

// --- Passwordless sign-in ---------------------------------------------------------------------------

it('passwordless options name no user and no credential, need user verification and are not cached', function () {
    $this->getJson('/api/v1/auth/csrf');
    $response = $this->postJson('/api/v1/auth/passkey/options')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $options = $response->json();
    expect($options['rpId'])->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and($options['userVerification'])->toBe('required')
        ->and($options['allowCredentials'] ?? [])->toBe([])
        ->and(strlen(base64_decode(strtr($options['challenge'], '-_', '+/'))))->toBe(32)
        ->and($options)->not->toHaveKey('user');
});

it('signs in with a passkey alone and answers like a password sign-in', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);

    pskSignIn($authenticator, $user)->assertOk()
        ->assertJsonPath('kind', 'console')
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'admin@example.org')
        ->assertJsonPath('second_factor', true)
        ->assertJsonPath('passkeys', 1);

    expect(session('auth.signed_in_at'))->toBeInt()
        ->and($user->refresh()->last_login_at)->not->toBeNull()
        ->and($passkey->refresh()->last_used_at)->not->toBeNull()
        ->and(pskStoredCounter($passkey))->toBe(1);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);
});

it('end to end: a passkey registered through the API signs in after sign-out', function () {
    $user = pskUser();
    $this->actingAs($user);
    confirmStepUp();
    $creation = $this->postJson('/api/v1/me/passkeys/options')->assertOk()->json();
    $authenticator = new FakeAuthenticator;
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($creation)])->assertCreated();
    pskSignOut();
    $this->getJson('/api/v1/me')->assertUnauthorized();

    // The browser returns the user handle it was given at registration.
    $handle = base64_decode(strtr($creation['user']['id'], '-_', '+/'));
    expect($handle)->toBe($user->getPasskeyUserHandle());
    pskSignIn($authenticator, null, handle: $handle)->assertOk()->assertJsonPath('id', $user->id);
    $this->getJson('/api/v1/me')->assertOk();
});

it('a passkey sign-in regenerates the session id and the CSRF token', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $options = pskLoginOptions();
    $id = session()->getId();
    $token = session()->token();

    $this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())])->assertOk();

    expect(session()->getId())->not->toBe($id)->and(session()->token())->not->toBe($token);
});

it('a passkey sign-in drops an earlier step-up window and a pending two-factor sign-in', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $this->withSession([RequireStepUp::SESSION_KEY => now()->getTimestamp(), 'login.id' => 99, 'login.remember' => false, 'login.at' => now()->getTimestamp()]);

    pskSignIn($authenticator, $user)->assertOk();

    expect(session(RequireStepUp::SESSION_KEY))->toBeNull()
        ->and(session('login.id'))->toBeNull()
        ->and(session('login.at'))->toBeNull();
    $this->getJson('/api/v1/me/recovery-codes')->assertStatus(423);
});

it('a portal session that signs in with a passkey loses its mailbox, on a new session id', function () {
    config(['session.driver' => 'database']);
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_users'))->insert(['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
    $checker = app(PasswordChecker::class);
    assert($checker instanceof FakePasswordChecker);
    $checker->accept('anna@example.org', 'mailbox passphrase 2');
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);

    $as = function (string $id) {
        app('auth')->forgetGuards();
        session()->flush();

        return test()->withCredentials()->withCookie(config('session.cookie'), $id);
    };
    $portal = $as('')->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'mailbox passphrase 2'])
        ->assertOk()->getCookie(config('session.cookie'))->getValue();
    $as($portal)->getJson('/api/v1/portal/me')->assertOk();

    $options = $as($portal)->postJson('/api/v1/auth/passkey/options')->assertOk()->json();
    $console = $as($portal)->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())])
        ->assertOk()->assertJsonPath('kind', 'console')->getCookie(config('session.cookie'))->getValue();

    expect($console)->not->toBe($portal);
    $as($console)->getJson('/api/v1/portal/me')->assertUnauthorized();
    $as($console)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'admin@example.org');
    $as($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('a passkey sign-in clears the sign-in limiter for that email and IP', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'wrong wrong wrong'])->assertStatus(422);
    }

    pskSignIn($authenticator, $user)->assertOk();
    pskSignOut();

    pskPasswordStep()->assertOk()->assertJsonPath('two_factor', true);
});

it('refuses a tampered assertion with the one uniform answer and signs nobody in', function (array $tamper) {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);

    pskFailed(pskSignIn($authenticator, $user, $tamper));

    $this->getJson('/api/v1/me')->assertUnauthorized();
    expect($passkey->refresh()->last_used_at)->toBeNull()->and($passkey->disabled_at)->toBeNull();
})->with([
    'wrong origin' => [['origin' => 'https://evil.example']],
    'wrong RP ID' => [['rpId' => 'evil.example']],
    'wrong challenge' => [['challenge' => FakeAuthenticator::b64url(random_bytes(32))]],
    'webauthn.create type' => [['type' => 'webauthn.create']],
    'no user verification' => [['uv' => false]],
    'no user presence' => [['up' => false]],
    'bad signature' => [['signature' => random_bytes(70)]],
]);

it('refuses a sign-in uniformly whatever is wrong with the account or credential', function (Closure $arrange) {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    [$who, $handle, $by] = $arrange($user, $authenticator);

    pskFailed(pskSignIn($by ?? $authenticator, $who, handle: $handle));

    $this->getJson('/api/v1/me')->assertUnauthorized();
    expect($user->refresh()->last_login_at)->toBeNull();
})->with([
    'unknown credential' => [fn (User $u) => [$u, null, new FakeAuthenticator]],
    'deactivated user' => [function (User $u) {
        $u->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        return [$u, null, null];
    }],
    'user who never set a password' => [function (User $u) {
        $u->forceFill(['password_set_at' => null])->save();

        return [$u, null, null];
    }],
    'disabled passkey' => [function (User $u) {
        Passkey::query()->update(['disabled_at' => now()]);

        return [$u, null, null];
    }],
    'no user handle' => [fn (User $u) => [null, null, null]],
    'another user\'s handle' => [fn (User $u) => [null, pskUser(['email' => 'bob@example.org'])->getPasskeyUserHandle(), null]],
]);

it('logs every refused passkey sign-in for fail2ban with kind=passkey, the unknown one as user=-', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    pskExpectFailLine('-');

    pskFailed(pskSignIn(new FakeAuthenticator, $user));
});

it('logs a refused assertion of a known passkey with its owner', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    pskExpectFailLine('admin@example.org');

    pskFailed(pskSignIn($authenticator, $user, ['uv' => false]));
});

it('refuses a malformed credential with the same answer', function (mixed $credential) {
    pskLoginOptions();

    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $credential]));
})->with([
    'empty object' => [['x' => 1]],
    'no response' => [['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key']],
    'garbage response' => [['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key', 'response' => ['clientDataJSON' => '!!', 'authenticatorData' => '!!', 'signature' => '!!']]],
]);

it('a missing or non-object credential is a validation error', function (array $body) {
    pskLoginOptions();

    $this->postJson('/api/v1/auth/passkey', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['credential']]);
})->with([
    'missing' => [[]],
    'string' => [['credential' => 'abc']],
]);

it('refuses an assertion without options in the session, and options older than 5 minutes', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);

    $this->getJson('/api/v1/auth/csrf');
    $fake = ['challenge' => FakeAuthenticator::b64url(random_bytes(32)), 'rpId' => parse_url(config('app.url'), PHP_URL_HOST)];
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($fake, $user->getPasskeyUserHandle())]));

    $options = pskLoginOptions();
    $this->travel(300)->seconds();
    $this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())])->assertOk();
    pskSignOut();

    $options = pskLoginOptions();
    $this->travel(301)->seconds();
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())]));
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('refuses a replayed assertion', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $options = pskLoginOptions();
    $assertion = $authenticator->assert($options, $user->getPasskeyUserHandle());

    $this->postJson('/api/v1/auth/passkey', ['credential' => $assertion])->assertOk();
    pskSignOut();

    // The same assertion again: its options are spent.
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $assertion]));
    // And against new options: the challenge is another one.
    pskLoginOptions();
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $assertion]));
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('options are good for one attempt: a failed one spends them', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $options = pskLoginOptions();

    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle(), ['uv' => false])]));
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())]));
});

it('spends the options once even when the session still holds them (a concurrent request)', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $options = pskLoginOptions();
    $pending = session('passkeys.assertion.login');
    expect($pending)->toBeArray();

    $this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())])->assertOk();
    pskSignOut();

    // A second request that read the session before the first one wrote it back.
    $this->withSession(['passkeys.assertion.login' => $pending]);
    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($options, $user->getPasskeyUserHandle())]));
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('accepts an authenticator that never counts (both counters zero)', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);

    pskSignIn($authenticator, $user, ['counter' => 0])->assertOk();

    expect($passkey->refresh()->disabled_at)->toBeNull();
});

it('a counter that does not go up disables the passkey, answers passkey_cloned and tells the user', function (int $counter) {
    Mail::fake();
    $user = pskUser(['locale' => 'fi']);
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);
    pskSignIn($authenticator, $user, ['counter' => 5])->assertOk();
    pskSignOut();

    pskFailed(pskSignIn($authenticator, $user, ['counter' => $counter]), 'credential', 'passkey_cloned');

    expect($passkey->refresh()->disabled_at)->not->toBeNull()
        ->and(pskStoredCounter($passkey))->toBe(5);
    $this->getJson('/api/v1/me')->assertUnauthorized();
    Mail::assertQueued(SecurityNotice::class, 1);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'passkey_cloned'
        && $mail->hasTo('admin@example.org') && $mail->locale === 'fi');

    // Disabled now: even a good counter is refused, uniformly, and no second notice is sent.
    pskFailed(pskSignIn($authenticator, $user, ['counter' => 50]));
    Mail::assertQueued(SecurityNotice::class, 1);
    $this->getJson('/api/v1/me')->assertJsonPath('code', 'unauthenticated');
})->with(['equal' => [5], 'lower' => [3], 'zero' => [0]]);

it('a cloned passkey no longer counts as a second factor', function () {
    Mail::fake();
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    pskSignIn($authenticator, $user, ['counter' => 5])->assertOk();
    pskSignOut();
    pskFailed(pskSignIn($authenticator, $user, ['counter' => 4]), 'credential', 'passkey_cloned');

    expect($user->refresh()->hasSecondFactor())->toBeFalse();
});

it('the eleventh passkey sign-in request in a minute from one IP is 429', function () {
    $this->getJson('/api/v1/auth/csrf');
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/passkey/options')->assertOk();
    }

    $this->postJson('/api/v1/auth/passkey/options')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    $this->postJson('/api/v1/auth/passkey', ['credential' => ['id' => 'AAAA']])->assertStatus(429);
    $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertStatus(429);
});

it('answers the refusal in Finnish for a Finnish browser', function () {
    pskLoginOptions();

    $this->postJson('/api/v1/auth/passkey', ['credential' => ['id' => 'AAAA']], ['Accept-Language' => 'fi'])
        ->assertStatus(422)->assertJsonPath('errors.credential.0', 'Pääsyavainta ei voitu vahvistaa. Yritä uudelleen.');
});

it('never logs the credential, the signature or the challenge', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $options = pskLoginOptions();
    $good = $authenticator->assert($options, $user->getPasskeyUserHandle(), ['uv' => false]);
    $this->postJson('/api/v1/auth/passkey', ['credential' => $good])->assertStatus(422);

    $all = implode("\n", $logged);
    expect($logged)->not->toBeEmpty()
        ->and($all)->not->toContain($authenticator->id())
        ->and($all)->not->toContain($good['response']['signature'])
        ->and($all)->not->toContain($good['response']['clientDataJSON'])
        ->and($all)->not->toContain($options['challenge']);
});

// --- The two-factor step ----------------------------------------------------------------------------

it('a password sign-in for a passkey-only user stops at the two-factor step', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);

    pskPasswordStep()->assertOk()->assertExactJson(['two_factor' => true, 'methods' => ['passkey', 'recovery']]);

    expect(session('login.id'))->toBe($user->id)->and($user->refresh()->last_login_at)->toBeNull();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('a user with TOTP and a passkey is offered both', function () {
    $user = pskUser();
    tfaEnrol($user);
    passkeyEnrol($user, new FakeAuthenticator);

    pskPasswordStep()->assertExactJson(['two_factor' => true, 'methods' => ['totp', 'passkey', 'recovery']]);
});

it('a disabled passkey alone is no second factor: the password signs in', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator, disabled: true);

    pskPasswordStep()->assertOk()->assertJsonPath('kind', 'console');
});

it('a setup link for a passkey-only user leaves the sign-in pending', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);
    $token = app(AdminSetup::class)->issue($user);
    $this->getJson('/api/v1/auth/csrf');

    $this->postJson('/api/v1/auth/setup', [
        'email' => 'admin@example.org', 'token' => $token,
        'password' => 'a long passphrase 42', 'password_confirmation' => 'a long passphrase 42',
    ])->assertOk()->assertExactJson(['two_factor' => true, 'methods' => ['passkey', 'recovery']]);

    expect(session('login.id'))->toBe($user->id);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('two-factor passkey options name only the pending user\'s usable passkeys', function () {
    $user = pskUser();
    $laptop = new FakeAuthenticator;
    $phone = new FakeAuthenticator;
    passkeyEnrol($user, $laptop);
    passkeyEnrol($user, $phone, 'Old phone', disabled: true);
    passkeyEnrol(pskUser(['email' => 'bob@example.org']), new FakeAuthenticator, 'Bob');
    pskPasswordStep()->assertJsonPath('two_factor', true);

    $response = $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_column($response->json('allowCredentials'), 'id'))->toBe([$laptop->id()])
        ->and($response->json('userVerification'))->toBe('required');
});

it('two-factor passkey options need a fresh pending sign-in of an active user', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);
    $this->getJson('/api/v1/auth/csrf');

    $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertStatus(422)
        ->assertJsonPath('errors.passkey', ['Sign in with your email and password first.']);

    pskPasswordStep();
    $this->travel(301)->seconds();
    $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertStatus(422)
        ->assertJsonPath('errors.passkey', ['Your sign-in has expired. Enter your email and password again.']);

    pskPasswordStep();
    $user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
    $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertStatus(422)
        ->assertJsonPath('errors.passkey', ['Your sign-in has expired. Enter your email and password again.']);
});

it('the pending user\'s passkey finishes the sign-in', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);
    foreach (range(1, 5) as $_) {
        pskPasswordStep()->assertJsonPath('two_factor', true);
    }
    $before = session()->getId();
    $options = $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk()->json();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => $authenticator->assert($options)])->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('second_factor', true);

    expect(session('login.id'))->toBeNull()
        ->and(session()->getId())->not->toBe($before)
        ->and($user->refresh()->last_login_at)->not->toBeNull()
        ->and(pskStoredCounter($passkey))->toBe(1);
    $this->getJson('/api/v1/me')->assertOk();
    pskSignOut();
    // The finished sign-in cleared the login limiter.
    pskPasswordStep()->assertOk()->assertJsonPath('two_factor', true);
});

it('refuses another user\'s passkey at the two-factor step and keeps the sign-in pending', function () {
    $anna = pskUser();
    passkeyEnrol($anna, new FakeAuthenticator);
    $bob = pskUser(['email' => 'bob@example.org']);
    $bobs = new FakeAuthenticator;
    $bobsKey = passkeyEnrol($bob, $bobs, 'Bob');
    pskPasswordStep();
    $options = $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk()->json();
    pskExpectFailLine('admin@example.org');

    pskFailed($this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => $bobs->assert($options, $bob->getPasskeyUserHandle())]), 'passkey');

    expect(session('login.id'))->toBe($anna->id)->and($bobsKey->refresh()->last_used_at)->toBeNull();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('refuses another user\'s passkey even with options that would list it', function () {
    $anna = pskUser();
    passkeyEnrol($anna, new FakeAuthenticator);
    $bobs = new FakeAuthenticator;
    passkeyEnrol(pskUser(['email' => 'bob@example.org']), $bobs, 'Bob');
    pskPasswordStep();
    $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk();
    // Doctor the stored options to allow any credential, as a bug in the options would.
    $pending = session('passkeys.assertion.two_factor');
    $options = json_decode($pending['options'], true);
    unset($options['allowCredentials']);
    $pending['options'] = json_encode($options);
    $this->withSession(['passkeys.assertion.two_factor' => $pending]);

    pskFailed($this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => $bobs->assert($options)]), 'passkey');
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('two-factor options cannot serve a passwordless sign-in, or the other way round', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    pskPasswordStep();
    $twoFactor = $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk()->json();

    pskFailed($this->postJson('/api/v1/auth/passkey', ['credential' => $authenticator->assert($twoFactor, $user->getPasskeyUserHandle())]));

    $login = $this->postJson('/api/v1/auth/passkey/options')->assertOk()->json();
    pskFailed($this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => $authenticator->assert($login)]), 'passkey');
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('a passkey-only user\'s TOTP code is refused as a wrong code, and a recovery code works', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);
    pskPasswordStep();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(422)
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'aaaa-bbbb'])->assertOk()
        ->assertJsonPath('id', $user->id);
});

it('a pending user deactivated since the password step cannot finish with a passkey', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    pskPasswordStep();
    $options = $this->postJson('/api/v1/auth/two-factor-challenge/passkey-options')->assertOk()->json();
    $user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => $authenticator->assert($options)])->assertStatus(422)
        ->assertJsonPath('errors.passkey', ['Your sign-in has expired. Enter your email and password again.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('the challenge takes exactly one of code, recovery_code and passkey', function (array $body, array $fields) {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);
    pskPasswordStep();

    $response = $this->postJson('/api/v1/auth/two-factor-challenge', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(array_keys($response->json('errors')))->toEqualCanonicalizing($fields);
})->with([
    'code and passkey' => [['code' => '123456', 'passkey' => ['id' => 'AAAA']], ['code']],
    'recovery code and passkey' => [['recovery_code' => 'aaaa-bbbb', 'passkey' => ['id' => 'AAAA']], ['recovery_code']],
    'non-object passkey' => [['passkey' => 'abc'], ['passkey']],
]);

it('two-factor passkey attempts count against passkey-login', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $this->getJson('/api/v1/auth/csrf');
    foreach (range(1, 9) as $_) {
        $this->postJson('/api/v1/auth/passkey/options')->assertOk();
    }
    pskPasswordStep();

    // The tenth passkey request of this IP still passes; the eleventh is refused.
    $this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => ['id' => 'AAAA']])->assertStatus(422);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['passkey' => ['id' => 'AAAA']])->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');
});

// --- Step-up ----------------------------------------------------------------------------------------

it('a passkey confirms a step-up and opens the 300 s window', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    $passkey = passkeyEnrol($user, $authenticator);
    passkeyEnrol($user, new FakeAuthenticator, 'Old', disabled: true);
    $this->actingAs($user);
    $this->getJson('/api/v1/me/recovery-codes')->assertStatus(423);

    $response = $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_column($response->json('allowCredentials'), 'id'))->toBe([$authenticator->id()]);

    $this->postJson('/api/v1/auth/confirm-password', ['passkey' => $authenticator->assert($response->json())])->assertNoContent();

    expect(session(RequireStepUp::SESSION_KEY))->toBeInt()
        ->and(pskStoredCounter($passkey))->toBe(1);
    $this->getJson('/api/v1/me/recovery-codes')->assertOk();
    $this->travel(301)->seconds();
    $this->getJson('/api/v1/me/recovery-codes')->assertStatus(423);
});

it('step-up refuses another user\'s passkey and a replay, and logs them', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $bobs = new FakeAuthenticator;
    passkeyEnrol(pskUser(['email' => 'bob@example.org']), $bobs, 'Bob');
    $this->actingAs($user);

    $options = $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertOk()->json();
    pskFailed($this->postJson('/api/v1/auth/confirm-password', ['passkey' => $bobs->assert($options)]), 'passkey');
    expect(session(RequireStepUp::SESSION_KEY))->toBeNull();

    $options = $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertOk()->json();
    $assertion = $authenticator->assert($options);
    $this->postJson('/api/v1/auth/confirm-password', ['passkey' => $assertion])->assertNoContent();
    session()->forget(RequireStepUp::SESSION_KEY);
    pskFailed($this->postJson('/api/v1/auth/confirm-password', ['passkey' => $assertion]), 'passkey');
});

it('step-up writes the fail2ban line with kind=passkey for a refused passkey', function () {
    $user = pskUser();
    $authenticator = new FakeAuthenticator;
    passkeyEnrol($user, $authenticator);
    $this->actingAs($user);
    $options = $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertOk()->json();
    pskExpectFailLine('admin@example.org');

    pskFailed($this->postJson('/api/v1/auth/confirm-password', ['passkey' => $authenticator->assert($options, tamper: ['uv' => false])]), 'passkey');
});

it('step-up passkey options are refused to a user without a usable passkey', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator, disabled: true);
    $this->actingAs($user);

    pskFailed($this->postJson('/api/v1/auth/confirm-password/passkey-options'), 'passkey');
});

it('step-up passkey requests count against the step-up limiter', function () {
    $user = pskUser();
    passkeyEnrol($user, new FakeAuthenticator);
    $this->actingAs($user);
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertOk();
    }

    $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertStatus(429);
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertStatus(429);
});

it('step-up options need a signed-in console user', function () {
    $this->postJson('/api/v1/auth/confirm-password/passkey-options')->assertUnauthorized();
});

// --- Resets -------------------------------------------------------------------------------------

it('the users API reset deletes the passkeys and recovery codes of a passkey-only user', function () {
    Mail::fake();
    $admin = pskUser(['email' => 'boss@example.org']);
    $target = pskUser(['email' => 'anna@example.org', 'is_server_admin' => false]);
    passkeyEnrol($target, new FakeAuthenticator);
    passkeyEnrol($target, new FakeAuthenticator, 'Old', disabled: true);
    $this->actingAs($admin);
    confirmStepUp();

    $this->postJson("/api/v1/users/{$target->id}/two-factor/reset")->assertNoContent();

    expect(Passkey::query()->where('user_id', $target->id)->count())->toBe(0)
        ->and($target->refresh()->two_factor_recovery_codes)->toBeNull();
    expect(AuditEntry::query()->where('action', 'user.two_factor_reset')->sole()->object_key)->toBe('anna@example.org');
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'two_factor_disabled' && $mail->hasTo('anna@example.org'));
});

it('the users API reset deletes the passkeys of a user with TOTP too', function () {
    Mail::fake();
    $admin = pskUser(['email' => 'boss@example.org']);
    $target = pskUser(['email' => 'anna@example.org', 'is_server_admin' => false]);
    tfaEnrol($target);
    passkeyEnrol($target, new FakeAuthenticator);
    $this->actingAs($admin);
    confirmStepUp();

    $this->postJson("/api/v1/users/{$target->id}/two-factor/reset")->assertNoContent();

    $target->refresh();
    expect(Passkey::query()->count())->toBe(0)
        ->and($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->hasSecondFactor())->toBeFalse();
});

it('the users API reset of a user with neither TOTP nor passkeys is still 409', function () {
    $admin = pskUser(['email' => 'boss@example.org']);
    $target = pskUser(['email' => 'anna@example.org', 'is_server_admin' => false]);
    $this->actingAs($admin);
    confirmStepUp();

    $this->postJson("/api/v1/users/{$target->id}/two-factor/reset")->assertStatus(409)->assertJsonPath('code', 'two_factor_not_enabled');
});

it('the users list and detail say whether a user has any second factor', function () {
    $admin = pskUser(['email' => 'boss@example.org']);
    tfaEnrol($admin);
    $passkeyOnly = pskUser(['email' => 'anna@example.org', 'is_server_admin' => false]);
    passkeyEnrol($passkeyOnly, new FakeAuthenticator);
    $disabledOnly = pskUser(['email' => 'mia@example.org', 'is_server_admin' => false]);
    passkeyEnrol($disabledOnly, new FakeAuthenticator, disabled: true);
    $this->actingAs($admin);

    $rows = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->keyBy('email');
    expect($rows['boss@example.org'])->toMatchArray(['two_factor_confirmed' => true, 'second_factor' => true])
        ->and($rows['anna@example.org'])->toMatchArray(['two_factor_confirmed' => false, 'second_factor' => true])
        ->and($rows['mia@example.org'])->toMatchArray(['two_factor_confirmed' => false, 'second_factor' => false]);
    $this->getJson("/api/v1/users/{$passkeyOnly->id}")->assertOk()
        ->assertJsonPath('two_factor_confirmed', false)->assertJsonPath('second_factor', true);
});

it('any sign-in drops the passkey options the session held', function () {
    pskUser(['email' => 'bob@example.org']);
    pskLoginOptions();
    expect(session('passkeys.assertion.login'))->toBeArray();

    pskPasswordStep('bob@example.org')->assertOk()->assertJsonPath('kind', 'console');

    expect(session('passkeys.assertion.login'))->toBeNull();
});
