<?php

use App\Doctor\Checks\RequireTwoFactorSetting;
use App\Doctor\Doctor;
use App\Doctor\Status;
use App\Http\Middleware\RequireStepUp;
use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\GuideFixture;

function tfaUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
    ], $attributes));
}

function tfaWrongOtp(string $secret): string
{
    return str_pad((string) (((int) tfaOtp($secret) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
}

/** @return list<string> */
function tfaCodes(User $user): array
{
    return json_decode(Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_recovery_codes), true);
}

function tfaPasswordStep(string $email = 'admin@example.org')
{
    test()->getJson('/api/v1/auth/csrf');

    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'correct horse battery']);
}

function tfaChallenge(array $body)
{
    return test()->postJson('/api/v1/auth/two-factor-challenge', $body);
}

function tfaNoStore(TestResponse $response): TestResponse
{
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    return $response;
}

function tfaSignOut(): void
{
    test()->postJson('/api/v1/auth/logout')->assertNoContent();
}

beforeEach(fn () => Http::fake());

// --- Sign-in -------------------------------------------------------------------------------------

it('a password sign-in for a two-factor user answers {two_factor: true} and signs nobody in', function () {
    $user = tfaUser();
    tfaEnrol($user);

    tfaPasswordStep()->assertOk()->assertExactJson(['two_factor' => true]);

    expect(session('login.id'))->toBe($user->id)
        ->and($user->refresh()->last_login_at)->toBeNull();
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('a user whose two-factor is enabled but not confirmed signs in with the password alone', function () {
    $user = tfaUser();
    tfaEnrol($user);
    $user->forceFill(['two_factor_confirmed_at' => null])->save();

    tfaPasswordStep()->assertOk()->assertJsonPath('id', $user->id);
    $this->getJson('/api/v1/me')->assertOk();
});

it('a wrong password for a two-factor user is the usual 422 and starts no challenge', function () {
    $user = tfaUser();
    tfaEnrol($user);

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'nope nope nope nope'])
        ->assertStatus(422)->assertJsonPath('errors.email', ['Email or password is wrong.']);
    expect(session('login.id'))->toBeNull();
});

it('a correct code finishes the sign-in and answers the me payload', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    tfaPasswordStep()->assertOk();
    $before = session()->getId();

    tfaChallenge(['code' => tfaOtp($secret)])->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'admin@example.org')
        ->assertJsonPath('two_factor', ['enabled' => true, 'confirmed' => true, 'recovery_codes_left' => 8]);

    expect(session('login.id'))->toBeNull()
        ->and(session()->getId())->not->toBe($before)
        ->and(session('auth.signed_in_at'))->toBeInt()
        ->and($user->refresh()->last_login_at)->not->toBeNull();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);
});

it('refuses the same code a second time within its window (replay)', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    $code = tfaOtp($secret);

    tfaPasswordStep();
    tfaChallenge(['code' => $code])->assertOk();
    tfaSignOut();

    tfaPasswordStep()->assertExactJson(['two_factor' => true]);
    tfaChallenge(['code' => $code])->assertStatus(422)
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('a recovery code signs in once and is then gone', function () {
    $user = tfaUser();
    tfaEnrol($user);
    $code = tfaCodes($user)[3];

    tfaPasswordStep();
    tfaChallenge(['recovery_code' => $code])->assertOk()
        ->assertJsonPath('two_factor.recovery_codes_left', 7);
    expect(tfaCodes($user))->toHaveCount(7)->not->toContain($code);
    tfaSignOut();

    tfaPasswordStep();
    tfaChallenge(['recovery_code' => $code])->assertStatus(422)
        ->assertJsonPath('errors.recovery_code', ['That recovery code is wrong or has already been used.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('after all 8 recovery codes are used the user can still regenerate them after step-up', function () {
    $user = tfaUser();
    tfaEnrol($user);

    foreach (tfaCodes($user) as $i => $code) {
        if ($i > 0) {
            tfaSignOut();
            $this->travel(61)->seconds(); // the login and two-factor limiters allow 5 a minute
        }
        tfaPasswordStep()->assertExactJson(['two_factor' => true]);
        tfaChallenge(['recovery_code' => $code])->assertOk()->assertJsonPath('two_factor.recovery_codes_left', 7 - $i);
    }

    expect(tfaCodes($user))->toBe([]);
    $this->postJson('/api/v1/me/recovery-codes')->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();

    $codes = $this->postJson('/api/v1/me/recovery-codes')->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(8)->and(tfaCodes($user))->toBe($codes);
    $this->getJson('/api/v1/me')->assertJsonPath('two_factor.recovery_codes_left', 8);
});

it('a wrong code is 422 on code and writes the fail2ban line with the pending user, never the code', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    tfaPasswordStep();
    $wrong = tfaWrongOtp($secret);
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure user=admin@example.org ip=127.0.0.1');

    tfaChallenge(['code' => $wrong])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);

    expect(session('login.id'))->toBe($user->id);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('a wrong recovery code is 422 on recovery_code and is logged', function () {
    $user = tfaUser();
    tfaEnrol($user);
    tfaPasswordStep();
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure user=admin@example.org ip=127.0.0.1');

    tfaChallenge(['recovery_code' => 'nope-nope'])->assertStatus(422)
        ->assertJsonPath('errors.recovery_code', ['That recovery code is wrong or has already been used.']);
    expect(tfaCodes($user))->toHaveCount(8);
});

it('the sixth challenge in a minute is 429', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    tfaPasswordStep();

    foreach (range(1, 5) as $_) {
        tfaChallenge(['code' => tfaWrongOtp($secret)])->assertStatus(422);
    }

    tfaChallenge(['code' => tfaOtp($secret)])->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('without a pending sign-in the challenge is 422', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);

    tfaChallenge(['code' => tfaOtp($secret)])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.code', ['Sign in with your email and password first.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('the challenge needs exactly one of code and recovery_code', function (array $body, array $fields) {
    $user = tfaUser();
    tfaEnrol($user);
    tfaPasswordStep();

    $response = tfaChallenge($body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(array_keys($response->json('errors')))->toEqualCanonicalizing($fields);
})->with([
    'neither' => [[], ['code', 'recovery_code']],
    'both' => [['code' => '123456', 'recovery_code' => 'aaaa-bbbb'], ['code']],
    'non-string code' => [['code' => ['123456']], ['code']],
]);

it('the wrong-code message is Finnish for a Finnish browser', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    tfaPasswordStep();

    tfaChallenge(['code' => tfaWrongOtp($secret)])->assertStatus(422);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'nope'], ['Accept-Language' => 'fi'])
        ->assertJsonPath('errors.recovery_code', ['Varakoodi on väärä tai jo käytetty.']);
});

// --- Enrolment -----------------------------------------------------------------------------------

it('enrols end to end: step-up, enable, setup, confirm, /me', function () {
    $user = tfaUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('two_factor', ['enabled' => false, 'confirmed' => false, 'recovery_codes_left' => 0])
        ->assertJsonPath('two_factor_required', false);

    $this->postJson('/api/v1/me/two-factor')->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    expect($user->refresh()->two_factor_secret)->toBeNull();

    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    expect($user->refresh()->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
    $this->getJson('/api/v1/me')->assertJsonPath('two_factor', ['enabled' => true, 'confirmed' => false, 'recovery_codes_left' => 0]);

    $setup = tfaNoStore($this->getJson('/api/v1/me/two-factor/setup')->assertOk());
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    expect($setup->json('secret'))->toBe($secret)
        ->and(base64_decode($setup->json('qr_svg_base64'), true))->toStartWith('<svg')->toContain('</svg>');

    $codes = tfaNoStore($this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk())
        ->json('recovery_codes');
    expect($codes)->toHaveCount(8)->each->toBeString();
    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->getJson('/api/v1/me')->assertJsonPath('two_factor', ['enabled' => true, 'confirmed' => true, 'recovery_codes_left' => 8]);

    $audit = AuditEntry::query()->where('action', 'user.two_factor_enabled')->sole();
    expect($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('admin@example.org')
        ->and($audit->domain_name)->toBeNull()
        ->and($audit->changes)->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id);
});

it('setting up again before confirming starts with a fresh secret', function () {
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();

    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $first = $user->refresh()->two_factor_secret;
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();

    expect(Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret))
        ->not->toBe(Fortify::currentEncrypter()->decrypt($first));
});

it('setup and confirm answer two_factor_not_enabled before enabling', function () {
    $this->actingAs(tfaUser());
    confirmStepUp();

    $this->getJson('/api/v1/me/two-factor/setup')->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'two_factor_not_enabled')
        ->assertJsonPath('detail', 'Two-factor authentication is not turned on.');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => '123456'])->assertNotFound()
        ->assertJsonPath('code', 'two_factor_not_enabled');
});

it('setup, confirm and enable answer two_factor_already_confirmed once confirmed', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    $this->actingAs($user);
    confirmStepUp();

    $this->getJson('/api/v1/me/two-factor/setup')->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_already_confirmed')
        ->assertJsonPath('detail', 'Two-factor authentication is already on.')
        ->assertJsonMissingPath('secret');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_already_confirmed');
    // The factory user has no locale attribute until a reload, so Accept-Language decides; enable
    // reloads it from the locked row (locale en), so it goes last.
    $this->getJson('/api/v1/me/two-factor/setup', ['Accept-Language' => 'fi'])->assertStatus(409)
        ->assertJsonPath('detail', 'Kaksivaiheinen tunnistautuminen on jo käytössä.');
    $this->postJson('/api/v1/me/two-factor')->assertStatus(409)->assertJsonPath('code', 'two_factor_already_confirmed');
});

it('a wrong confirmation code is 422 on code and leaves two-factor unconfirmed', function () {
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret);

    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaWrongOtp($secret)])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);
    $this->postJson('/api/v1/me/two-factor/confirm', [])->assertStatus(422)->assertJsonValidationErrorFor('code', 'errors');

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'user.two_factor_enabled')->exists())->toBeFalse();
});

it('turning two-factor off needs step-up, is audited and queues a security notice', function () {
    Mail::fake();
    $user = tfaUser(['locale' => 'fi']);
    tfaEnrol($user);
    $this->actingAs($user);

    $this->deleteJson('/api/v1/me/two-factor')->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
    Mail::assertNothingQueued();

    confirmStepUp();
    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
    $audit = AuditEntry::query()->where('action', 'user.two_factor_disabled')->sole();
    expect($audit->object_type)->toBe('user')->and($audit->object_key)->toBe('admin@example.org')->and($audit->changes)->toBeNull();
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'two_factor_disabled'
        && $mail->hasTo('admin@example.org') && $mail->locale === 'fi');
    $this->getJson('/api/v1/me')->assertJsonPath('two_factor', ['enabled' => false, 'confirmed' => false, 'recovery_codes_left' => 0]);
});

it('turning off two-factor that was never on changes nothing and sends nothing', function () {
    Mail::fake();
    $this->actingAs(tfaUser());
    confirmStepUp();

    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    expect(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('shows and regenerates recovery codes only after step-up, without caching', function () {
    $user = tfaUser();
    tfaEnrol($user);
    $before = tfaCodes($user);
    $this->actingAs($user);

    $this->getJson('/api/v1/me/recovery-codes')->assertStatus(423);
    $this->postJson('/api/v1/me/recovery-codes')->assertStatus(423);
    expect(tfaCodes($user))->toBe($before);

    confirmStepUp();
    tfaNoStore($this->getJson('/api/v1/me/recovery-codes')->assertOk())
        ->assertExactJson(['recovery_codes' => $before]);

    $new = tfaNoStore($this->postJson('/api/v1/me/recovery-codes')->assertOk())->json('recovery_codes');
    expect($new)->toHaveCount(8)->and(array_intersect($new, $before))->toBe([])->and(tfaCodes($user))->toBe($new);

    $audit = AuditEntry::query()->where('action', 'user.recovery_codes_regenerated')->sole();
    expect($audit->object_key)->toBe('admin@example.org')->and($audit->changes)->toBeNull();
});

it('recovery codes answer two_factor_not_enabled without confirmed two-factor', function () {
    $this->actingAs(tfaUser());
    confirmStepUp();

    $this->getJson('/api/v1/me/recovery-codes')->assertNotFound()->assertJsonPath('code', 'two_factor_not_enabled');
    $this->postJson('/api/v1/me/recovery-codes')->assertNotFound()->assertJsonPath('code', 'two_factor_not_enabled');
});

it('never writes the secret or the codes into the audit log', function () {
    Mail::fake();
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();

    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->json('secret');
    $codes = $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->json('recovery_codes');
    $codes = [...$codes, ...$this->postJson('/api/v1/me/recovery-codes')->json('recovery_codes')];
    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    $rows = DB::table('audit_log')->get();
    expect($rows->pluck('action')->all())->toEqualCanonicalizing(['user.two_factor_enabled', 'user.recovery_codes_regenerated', 'user.two_factor_disabled']);
    $dump = json_encode($rows->all());
    foreach ([$secret, ...$codes] as $value) {
        expect($dump)->not->toContain($value);
    }
});

it('the enrolment routes need a signed-in user', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
})->with([
    ['POST', '/api/v1/me/two-factor'],
    ['GET', '/api/v1/me/two-factor/setup'],
    ['POST', '/api/v1/me/two-factor/confirm'],
    ['DELETE', '/api/v1/me/two-factor'],
    ['GET', '/api/v1/me/recovery-codes'],
    ['POST', '/api/v1/me/recovery-codes'],
]);

// --- Required two-factor ---------------------------------------------------------------------------

it('with admins required, an admin without two-factor is blocked everywhere but their profile and setup', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = tfaUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/dashboard')->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'two_factor_required')
        ->assertJsonPath('detail', 'Set up two-factor authentication to continue.');
    $this->getJson('/api/v1/domains')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $this->getJson('/api/v1/me/pinned-domains')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor_required', true);
    $this->patchJson('/api/v1/me', ['locale' => 'fi'])->assertOk();
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->assertOk()->json('secret');
    $this->getJson('/api/v1/me/recovery-codes')->assertNotFound()->assertJsonPath('code', 'two_factor_not_enabled');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk();

    GuideFixture::load('trixie');
    $this->getJson('/api/v1/dashboard')->assertOk();
});

it('explains the block in Finnish', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $this->actingAs(tfaUser(['locale' => 'fi']));

    $this->getJson('/api/v1/dashboard')->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required')
        ->assertJsonPath('detail', 'Ota kaksivaiheinen tunnistautuminen käyttöön, jotta voit jatkaa.');
});

it('with admins required, an admin with two-factor enabled but not confirmed is still blocked', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = tfaUser();
    tfaEnrol($user);
    $user->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($user);

    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
});

it('with admins required, a non-admin is not blocked', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    GuideFixture::load('trixie');
    $this->actingAs(tfaUser(['is_server_admin' => false]));

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor_required', false);
    $this->getJson('/api/v1/dashboard')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('with everyone required, a non-admin is blocked too', function () {
    config()->set('emailsquire.require_two_factor', 'everyone');
    $this->actingAs(tfaUser(['is_server_admin' => false]));

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor_required', true);
    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
});

it('with off, nobody is blocked', function () {
    config()->set('emailsquire.require_two_factor', 'off');
    GuideFixture::load('trixie');
    $this->actingAs(tfaUser());

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor_required', false);
    $this->getJson('/api/v1/dashboard')->assertOk();
});

it('the test suite runs with required two-factor off and the app defaults to admins', function () {
    expect(config('emailsquire.require_two_factor'))->toBe('off')
        ->and(file_get_contents(base_path('config/emailsquire.php')))
        ->toContain("'require_two_factor' => env('EMAILSQUIRE_REQUIRE_TWO_FACTOR', 'admins')");
});

// --- Doctor --------------------------------------------------------------------------------------

it('doctor fails required two-factor off in production and passes it otherwise', function () {
    $check = app(RequireTwoFactorSetting::class);
    expect($check->id())->toBe('auth.require_two_factor')
        ->and($check->isServerCheck())->toBeFalse()
        ->and(Doctor::CHECKS)->toContain(RequireTwoFactorSetting::class);

    config()->set('emailsquire.require_two_factor', 'off');
    expect($check->run()->status)->toBe(Status::Ok);
    $previous = app()['env'];
    app()['env'] = 'production';
    try {
        $result = $check->run();
        expect($result->status)->toBe(Status::Fail)
            ->and($result->detail)->toContain('EMAILSQUIRE_REQUIRE_TWO_FACTOR');

        foreach (['admins', 'everyone'] as $value) {
            config()->set('emailsquire.require_two_factor', $value);
            expect($check->run()->status)->toBe(Status::Ok);
        }
    } finally {
        app()['env'] = $previous;
    }
});

// --- Step-up rulings -------------------------------------------------------------------------------

it('a new sign-in on the same session drops an earlier step-up', function () {
    $user = tfaUser();
    $this->actingAs($user);
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    expect(session(RequireStepUp::SESSION_KEY))->toBeInt();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'correct horse battery'])->assertOk();

    expect(session(RequireStepUp::SESSION_KEY))->toBeNull();
    $this->postJson('/api/v1/me/two-factor')->assertStatus(423);
});

it('a two-factor sign-in on the same session drops an earlier step-up', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    $this->withSession([RequireStepUp::SESSION_KEY => now()->getTimestamp()]);

    tfaPasswordStep()->assertExactJson(['two_factor' => true]);
    tfaChallenge(['code' => tfaOtp($secret)])->assertOk();

    expect(session(RequireStepUp::SESSION_KEY))->toBeNull();
    $this->getJson('/api/v1/me/recovery-codes')->assertStatus(423);
});

it('cancelling an unconfirmed setup clears it without an audit row or a notice', function () {
    Mail::fake();
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();

    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    expect($user->refresh()->two_factor_secret)->toBeNull()->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('setup and confirm need a fresh step-up, so a stolen session cannot take over an unconfirmed setup', function () {
    $user = tfaUser();
    $this->actingAs($user);
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret);

    $this->travel(301)->seconds();

    $this->getJson('/api/v1/me/two-factor/setup')->assertStatus(423)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'step_up_required')
        ->assertJsonMissingPath('secret');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertStatus(423)
        ->assertJsonPath('code', 'step_up_required')
        ->assertJsonMissingPath('recovery_codes');
    expect($user->refresh()->two_factor_confirmed_at)->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('the whole enrolment fits in one step-up window: confirm, enable, setup, confirm within 300 s', function () {
    $user = tfaUser();
    $this->actingAs($user);
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();

    $this->travel(100)->seconds();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $this->travel(100)->seconds();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->assertOk()->json('secret');
    $this->travel(99)->seconds();
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk()
        ->assertJsonCount(8, 'recovery_codes');

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('a required-2FA admin steps up with the password and completes enrolment while everything else stays blocked', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = tfaUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/me/two-factor/setup')->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->postJson('/api/v1/auth/confirm-password', ['password' => 'correct horse battery'])->assertNoContent();
    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->assertOk()->json('secret');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk();

    $this->getJson('/api/v1/me')->assertJsonPath('two_factor.confirmed', true)->assertJsonPath('two_factor_required', true);
    $this->getJson('/api/v1/me/pinned-domains')->assertOk();
});

// --- Pending sign-in and confirm hardening ---------------------------------------------------------

it('a pending two-factor sign-in gets a new session id and a timestamp', function () {
    $user = tfaUser();
    tfaEnrol($user);
    $this->getJson('/api/v1/auth/csrf');
    $before = session()->getId();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'correct horse battery'])
        ->assertExactJson(['two_factor' => true]);

    expect(session()->getId())->not->toBe($before)
        ->and(session('login.id'))->toBe($user->id)
        ->and(session('login.at'))->toBe(now()->getTimestamp());
});

it('accepts the challenge 5 minutes after the password and refuses it after that, clearing the pending sign-in', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);

    tfaPasswordStep();
    $this->travel(300)->seconds();
    tfaChallenge(['code' => tfaOtp($secret)])->assertOk();
    tfaSignOut();

    tfaPasswordStep();
    $this->travel(301)->seconds();
    tfaChallenge(['code' => tfaOtp($secret)])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.code', ['Your sign-in has expired. Enter your email and password again.']);

    expect(session('login.id'))->toBeNull()->and(session('login.at'))->toBeNull();
    tfaChallenge(['code' => tfaOtp($secret)])->assertStatus(422)
        ->assertJsonPath('errors.code', ['Sign in with your email and password first.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('the expired sign-in message is Finnish for a Finnish browser', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    tfaPasswordStep();
    $this->travel(301)->seconds();

    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => tfaOtp($secret)], ['Accept-Language' => 'fi'])
        ->assertStatus(422)
        ->assertJsonPath('errors.code', ['Sisäänkirjautuminen vanheni. Kirjaudu uudelleen sähköpostiosoitteella ja salasanalla.']);
});

it('a pending sign-in without a timestamp is refused', function () {
    $user = tfaUser();
    $secret = tfaEnrol($user);
    $this->withSession(['login.id' => $user->id]);

    tfaChallenge(['code' => tfaOtp($secret)])->assertStatus(422)
        ->assertJsonPath('errors.code', ['Your sign-in has expired. Enter your email and password again.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('confirm checks the code against the secret stored now, not the one this request loaded', function () {
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $seen = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

    // A concurrent force-enable (another tab) replaced the secret after this request loaded the user.
    $replaced = app(Google2FA::class)->generateSecretKey();
    DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($replaced)]);

    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($seen)])->assertStatus(422)
        ->assertJsonPath('errors.code', ['The code is wrong or has expired.']);
    expect(DB::table('users')->where('id', $user->id)->value('two_factor_confirmed_at'))->toBeNull();

    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($replaced)])->assertOk()
        ->assertJsonCount(8, 'recovery_codes');
    expect(Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret))->toBe($replaced)
        ->and($user->two_factor_confirmed_at)->not->toBeNull();
});

it('confirm answers 409 when another request confirmed first', function () {
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    DB::table('users')->where('id', $user->id)->update(['two_factor_confirmed_at' => now()]);

    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertStatus(409)
        ->assertJsonPath('code', 'two_factor_already_confirmed');
    expect(AuditEntry::query()->count())->toBe(0);
});

it('enable answers 409 and keeps the secret when another request confirmed after this one loaded the user', function () {
    $user = tfaUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $stored = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    // The in-memory user still reads as unconfirmed; the row was confirmed by a parallel request.
    DB::table('users')->where('id', $user->id)->update(['two_factor_confirmed_at' => now()]);
    expect($user->two_factor_confirmed_at)->toBeNull();

    $this->postJson('/api/v1/me/two-factor')->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'two_factor_already_confirmed');
    expect(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->toBe($stored);
});
