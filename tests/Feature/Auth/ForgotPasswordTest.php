<?php

use App\Auth\AdminSetup;
use App\Mail\ResetPasswordLink;
use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

function fpUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'anna@example.org',
        'password' => 'the old passphrase 1',
        'is_server_admin' => true,
        'password_set_at' => now(),
        'locale' => 'en',
    ], $attributes));
}

function fpForgot(string $email, array $headers = [])
{
    return test()->postJson('/api/v1/auth/forgot-password', ['email' => $email], $headers);
}

/** Asks for a reset link for $user and answers the token from the queued mail's link. */
function fpToken(User $user): string
{
    Mail::fake();
    fpForgot($user->email)->assertStatus(202);
    $token = null;
    Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$token) {
        $token = fpTokenFromUrl($mail->url);

        return true;
    });

    return (string) $token;
}

function fpTokenFromUrl(string $url): ?string
{
    return preg_match('#/reset-password/([^/?]+)\?#', $url, $m) === 1 ? $m[1] : null;
}

function fpReset(string $token, array $over = [])
{
    return test()->postJson('/api/v1/auth/reset-password', array_merge([
        'token' => $token,
        'email' => 'anna@example.org',
        'password' => 'a brand new passphrase 7',
        'password_confirmation' => 'a brand new passphrase 7',
    ], $over));
}

// --- Forgot password -----------------------------------------------------------------------------

it('answers the same 202 body for a known, unknown, deactivated and setup-pending email', function () {
    Mail::fake();
    fpUser();
    fpUser(['email' => 'gone@example.org', 'status' => 'deactivated']);
    fpUser(['email' => 'new@example.org', 'password_set_at' => null]);

    $bodies = [];
    foreach (['anna@example.org', 'nobody@example.org', 'gone@example.org', 'new@example.org'] as $email) {
        $response = fpForgot($email)->assertStatus(202)->assertHeader('Content-Type', 'application/json');
        $bodies[$email] = $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1);
    Mail::assertQueued(ResetPasswordLink::class, 1);
    Mail::assertQueued(ResetPasswordLink::class, fn (ResetPasswordLink $mail) => $mail->hasTo('anna@example.org'));
    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['anna@example.org']);
});

it('mails an active user a link in their language that carries a working token and says it expires in 30 minutes', function () {
    Mail::fake();
    $user = fpUser(['locale' => 'fi']);

    fpForgot('Anna@Example.org')->assertStatus(202);

    Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) use ($user) {
        $token = fpTokenFromUrl($mail->url);
        $html = $mail->locale('fi')->render();

        return $mail->hasTo('anna@example.org')
            && $mail->locale === 'fi'
            && str_starts_with($mail->url, rtrim(config('app.url'), '/').'/reset-password/')
            && str_ends_with($mail->url, '?email=anna%40example.org')
            && $token !== null
            && Password::broker('users')->tokenExists($user, $token)
            && str_contains($html, '30 minuuttia')
            && str_contains($html, e($mail->url))
            && ! str_contains($html, '<img');
    });
});

it('writes the reset mail in English with a plain-text part', function () {
    $mail = (new ResetPasswordLink(fpUser(), 'abc123'))->locale('en');

    $mail->assertSeeInHtml('30 minutes')
        ->assertSeeInText('30 minutes')
        ->assertSeeInText('/reset-password/abc123?email=anna%40example.org')
        ->assertDontSeeInText('<');
    expect($mail->envelope()->subject)->toBe('Reset your EmailSquire password');
});

it('builds the link from APP_URL, never from the request host', function () {
    Mail::fake();
    fpUser();

    $this->postJson('http://evil.example/api/v1/auth/forgot-password', ['email' => 'anna@example.org'])->assertStatus(202);

    Mail::assertQueued(ResetPasswordLink::class, fn (ResetPasswordLink $mail) => str_starts_with($mail->url, rtrim(config('app.url'), '/').'/')
        && ! str_contains($mail->url, 'evil.example'));
});

it('does a dummy password hash check when no mail goes out, so the timing matches', function (array $attributes, string $email) {
    if ($attributes !== []) {
        fpUser($attributes);
    }
    Mail::fake();
    Hash::spy();

    fpForgot($email)->assertStatus(202);

    Hash::shouldHaveReceived('check')->once();
    Hash::shouldNotHaveReceived('make');
    Mail::assertNothingQueued();
})->with([
    'unknown' => [[], 'nobody@example.org'],
    'deactivated' => [['email' => 'gone@example.org', 'status' => 'deactivated'], 'gone@example.org'],
    'setup pending' => [['email' => 'new@example.org', 'password_set_at' => null], 'new@example.org'],
]);

it('allows 5 requests an hour for one email, then answers a 429 problem', function () {
    Mail::fake();
    fpUser();

    foreach (range(1, 5) as $i) {
        fpForgot('anna@example.org')->assertStatus(202);
    }
    fpForgot('Anna@example.org')->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
    Mail::assertQueued(ResetPasswordLink::class, 5);

    // Another email from the same address is still served, and the hour passes.
    fpForgot('other@example.org')->assertStatus(202);
    $this->travel(61)->minutes();
    fpForgot('anna@example.org')->assertStatus(202);
});

it('allows 20 requests an hour from one IP address', function () {
    Mail::fake();

    foreach (range(1, 20) as $i) {
        fpForgot("user{$i}@example.org")->assertStatus(202);
    }
    fpForgot('user21@example.org')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('validates the email, in Finnish for a Finnish browser', function () {
    fpForgot('not an email', ['Accept-Language' => 'fi'])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email', ['Kentän sähköpostiosoite on oltava kelvollinen sähköpostiosoite.']);
});

// --- Reset password ------------------------------------------------------------------------------

it('a valid link sets the password, ends every session of that user and signs nobody in', function () {
    $user = fpUser(['locale' => 'fi', 'password_set_at' => now()->subYear()]);
    $other = fpUser(['email' => 'bob@example.org']);
    $token = fpToken($user);
    insertSessionRow($user->id, 'anna-laptop');
    insertSessionRow($user->id, 'anna-phone');
    insertSessionRow($other->id, 'bob-laptop');

    fpReset($token)->assertOk()->assertExactJson(['reset' => true]);

    $user->refresh();
    expect(Hash::check('a brand new passphrase 7', $user->password))->toBeTrue()
        ->and($user->password_set_at->isToday())->toBeTrue()
        ->and(sessionRowIds())->toBe(['bob-laptop']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'password_reset'
        && $mail->hasTo('anna@example.org') && $mail->locale === 'fi');

    // The new password works through the normal sign-in.
    $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'a brand new passphrase 7'])->assertOk();
});

it('audits the reset as the user, with no password-like value anywhere in the row', function () {
    $user = fpUser();
    $token = fpToken($user);

    fpReset($token)->assertOk();

    $audit = AuditEntry::query()->where('action', 'user.password_reset')->sole();
    expect($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('anna@example.org')
        ->and($audit->domain_name)->toBeNull()
        ->and($audit->changes)->toBeNull()
        ->and($audit->actor_type)->toBe('user')
        ->and($audit->actor_id)->toBe((string) $user->id);
    $row = json_encode(DB::table('audit_log')->where('id', $audit->id)->first());
    expect($row)->not->toContain('passphrase')->not->toContain($token)->not->toMatch('/"[^"]*(pass|token|secret|hash)[^"]*":/i');
});

it('refuses a link a second time', function () {
    $token = fpToken(fpUser());

    fpReset($token)->assertOk();
    fpReset($token, ['password' => 'yet another passphrase 9', 'password_confirmation' => 'yet another passphrase 9'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.token', ['This reset link is invalid or has expired.']);
    expect(Hash::check('a brand new passphrase 7', User::sole()->password))->toBeTrue();
});

it('refuses a link after 30 minutes and accepts it before', function () {
    $user = fpUser();
    $token = fpToken($user);

    $this->travel(29)->minutes();
    expect(Password::broker('users')->tokenExists($user, $token))->toBeTrue();

    $this->travel(2)->minutes();
    fpReset($token)->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
    expect(Hash::check('the old passphrase 1', $user->refresh()->password))->toBeTrue();
    Mail::assertNotQueued(SecurityNotice::class);
});

it('refuses a link for another email, a wrong token and a deactivated user', function () {
    $user = fpUser();
    fpUser(['email' => 'bob@example.org']);
    $token = fpToken($user);

    fpReset($token, ['email' => 'bob@example.org'])->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
    fpReset(str_repeat('a', 64))->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);

    $user->forceFill(['status' => 'deactivated'])->save();
    fpReset($token)->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
    expect(Hash::check('the old passphrase 1', $user->refresh()->password))->toBeTrue()
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('uses the setup rules for the new password', function () {
    $token = fpToken(fpUser());

    fpReset($token, ['password' => 'short pass1', 'password_confirmation' => 'short pass1'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    fpReset($token, ['password_confirmation' => 'something else entirely'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

    // A refused password does not spend the link.
    fpReset($token)->assertOk();
});

it('says an invalid link is invalid in Finnish', function () {
    fpUser();

    fpReset('nope', [])->assertStatus(422);
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'nope', 'email' => 'anna@example.org',
        'password' => 'a brand new passphrase 7', 'password_confirmation' => 'a brand new passphrase 7',
    ], ['Accept-Language' => 'fi'])
        ->assertStatus(422)
        ->assertJsonPath('errors.token', ['Tämä palautuslinkki on virheellinen tai vanhentunut.']);
});

it('does not get past two-factor: the user still needs a code to sign in', function () {
    $user = fpUser();
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $token = fpToken($user);

    fpReset($token)->assertOk()->assertExactJson(['reset' => true]);

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'a brand new passphrase 7'])
        ->assertOk()->assertExactJson(['two_factor' => true, 'methods' => ['totp', 'recovery']]);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('signs out the browser the reset was made in', function () {
    $user = fpUser();
    $token = fpToken($user);
    $this->actingAs($user);
    $this->getJson('/api/v1/me')->assertOk();

    fpReset($token)->assertOk();

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('keeps reset links and setup links apart', function () {
    // A forgot-password link must not work as a setup link: setup signs in without two-factor.
    $user = fpUser();
    $resetToken = fpToken($user);
    $this->postJson('/api/v1/auth/setup', [
        'token' => $resetToken, 'email' => 'anna@example.org',
        'password' => 'a brand new passphrase 7', 'password_confirmation' => 'a brand new passphrase 7',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
    $this->getJson('/api/v1/me')->assertUnauthorized();

    // And a setup link (create-admin --reset) is not a reset link.
    $setupToken = app(AdminSetup::class)->issue($user);
    fpReset($setupToken)->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);

    // Each broker keeps its own token: issuing one leaves the other working.
    fpReset($resetToken)->assertOk();
    expect(Hash::check('the old passphrase 1', $user->refresh()->password))->toBeFalse();
});

it('allows 10 reset attempts a minute from one IP address', function () {
    fpUser();

    foreach (range(1, 10) as $i) {
        fpReset("wrong-{$i}")->assertStatus(422);
    }
    fpReset('wrong-11')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('drops a half-finished two-factor sign-in in the browser the reset was made in', function () {
    $user = fpUser();
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $token = fpToken($user);
    $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'the old passphrase 1'])
        ->assertExactJson(['two_factor' => true, 'methods' => ['totp', 'recovery']]);
    expect(session('login.id'))->toBe($user->id);

    fpReset($token)->assertOk();

    expect(session('login.id'))->toBeNull();
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('queues the reset mail encrypted, so neither the token nor the link is readable in the jobs table', function () {
    config(['queue.default' => 'database']);
    fpUser();

    fpForgot('anna@example.org')->assertStatus(202);

    $payload = (string) DB::table('jobs')->sole()->payload;
    // The worker's view: the command decrypted with APP_KEY (left as is if it was never encrypted).
    $command = json_decode($payload, true)['data']['command'];
    $plain = str_starts_with($command, 'O:') ? $command : decrypt($command);
    $token = preg_match('#/reset-password/([0-9a-f]{64})\?#', $plain, $m) === 1 ? $m[1] : null;
    expect($token)->not->toBeNull()
        ->and($payload)->not->toContain($token)
        ->and($payload)->not->toContain('reset-password')
        ->and($payload)->not->toContain('anna%40example.org');
});
