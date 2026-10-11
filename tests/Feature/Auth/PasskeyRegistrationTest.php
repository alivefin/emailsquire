<?php

use App\Http\Middleware\RequireStepUp;
use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use Tests\Support\FakeAuthenticator;
use Tests\Support\GuideFixture;

function pkUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
    ], $attributes));
}

/** Creation options from the API, after a fresh step-up. */
function pkOptions(): array
{
    confirmStepUp();

    return test()->postJson('/api/v1/me/passkeys/options')->assertOk()->json();
}

function pkRegister(FakeAuthenticator $authenticator, string $name = 'Laptop', array $tamper = []): TestResponse
{
    $options = pkOptions();

    return test()->postJson('/api/v1/me/passkeys', ['name' => $name, 'credential' => $authenticator->register($options, $tamper)]);
}

/** A stored passkey row without a ceremony, for tests about counting, listing and removing. */
function pkRow(User $user, string $name = 'Phone', bool $disabled = false): Passkey
{
    $passkey = new Passkey;
    $passkey->forceFill([
        'user_id' => $user->id,
        'name' => $name,
        'credential_id' => FakeAuthenticator::b64url(random_bytes(32)),
        'credential' => ['stored' => true],
        'disabled_at' => $disabled ? now() : null,
    ])->save();

    return $passkey;
}

/** @return list<string>|null */
function pkCodes(User $user): ?array
{
    $stored = $user->refresh()->two_factor_recovery_codes;

    return $stored === null ? null : json_decode(Fortify::currentEncrypter()->decrypt($stored), true);
}

function pkNoStore(TestResponse $response): TestResponse
{
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    return $response;
}

beforeEach(fn () => Http::fake());

// --- Registration ----------------------------------------------------------------------------------

it('registers a first passkey after step-up and returns 8 recovery codes once, uncached', function () {
    Mail::fake();
    $user = pkUser(['locale' => 'fi']);
    $this->actingAs($user);
    $this->postJson('/api/v1/me/passkeys/options')->assertStatus(423)->assertJsonPath('code', 'step_up_required');

    $options = pkOptions();
    expect($options['rp']['id'])->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and($options['authenticatorSelection'])->toMatchArray(['residentKey' => 'preferred', 'userVerification' => 'required'])
        ->and($options['attestation'])->toBe('none')
        ->and($options['user']['name'])->toBe('admin@example.org')
        ->and(strlen(base64_decode(strtr($options['challenge'], '-_', '+/'))))->toBe(32);

    $authenticator = new FakeAuthenticator;
    $response = pkNoStore($this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options)])
        ->assertCreated());

    $passkey = Passkey::query()->sole();
    $response->assertJsonPath('passkey', [
        'id' => $passkey->id,
        'name' => 'Laptop',
        'created_at' => $passkey->created_at?->toIso8601String(),
        'last_used_at' => null,
        'disabled' => false,
    ]);
    $codes = $response->json('recovery_codes');
    expect($codes)->toHaveCount(8)->and(pkCodes($user))->toBe($codes)
        ->and($passkey->user_id)->toBe($user->id)
        ->and($passkey->credential_id)->toBe($authenticator->id());

    $this->getJson('/api/v1/me/passkeys')->assertOk()->assertExactJson(['data' => [$response->json('passkey')]]);
    $this->getJson('/api/v1/me')->assertJsonPath('second_factor', true)->assertJsonPath('passkeys', 1)
        ->assertJsonPath('two_factor.recovery_codes_left', 8);

    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'passkey_added'
        && $mail->hasTo('admin@example.org') && $mail->locale === 'fi');
});

it('a second passkey returns no recovery codes and keeps the existing ones', function () {
    $user = pkUser();
    $this->actingAs($user);
    $first = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');

    pkRegister(new FakeAuthenticator, 'Phone')->assertCreated()->assertJsonMissingPath('recovery_codes');

    expect(pkCodes($user))->toBe($first)->and(Passkey::query()->count())->toBe(2);
});

it('a passkey added beside confirmed TOTP returns no recovery codes and keeps them', function () {
    $user = pkUser();
    tfaEnrol($user);
    $before = pkCodes($user);
    $this->actingAs($user);

    pkRegister(new FakeAuthenticator)->assertCreated()->assertJsonMissingPath('recovery_codes');

    expect(pkCodes($user))->toBe($before);
});

it('replaces the never-shown codes of an unconfirmed TOTP setup when the first passkey arrives', function () {
    $user = pkUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $unshown = pkCodes($user);

    $codes = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');

    expect($codes)->toHaveCount(8)->and(array_intersect($codes, $unshown))->toBe([])->and(pkCodes($user))->toBe($codes);
});

it('refuses a ceremony that is wrong in one way, uniformly and without storing anything', function (array $tamper) {
    Mail::fake();
    $user = pkUser();
    $this->actingAs($user);
    if (($tamper['origin'] ?? null) === 'subdomain') {
        $app = parse_url((string) config('app.url'));
        $tamper['origin'] = $app['scheme'].'://evil.'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '');
    }

    pkRegister(new FakeAuthenticator, 'Laptop', $tamper)->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'passkey_failed')
        ->assertJsonPath('errors.credential.0', 'The passkey could not be verified. Try again.');

    expect(Passkey::query()->count())->toBe(0)->and(pkCodes($user))->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'a wrong origin' => [['origin' => 'https://evil.example']],
    'a subdomain origin' => [['origin' => 'subdomain']],
    'a wrong RP ID' => [['rpId' => 'evil.example']],
    'a wrong challenge' => [['challenge' => FakeAuthenticator::b64url(random_bytes(32))]],
    'an assertion type' => [['type' => 'webauthn.get']],
    'no user verification' => [['uv' => false]],
    'no user presence' => [['up' => false]],
]);

it('refuses a replayed challenge: each options answer is good for one registration', function () {
    $user = pkUser();
    $this->actingAs($user);
    $options = pkOptions();
    $credential = (new FakeAuthenticator)->register($options);

    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $credential])->assertCreated();
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $credential])->assertStatus(422)->assertJsonPath('code', 'passkey_failed');
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Other', 'credential' => (new FakeAuthenticator)->register($options)])
        ->assertStatus(422)->assertJsonPath('code', 'passkey_failed');

    expect(Passkey::query()->count())->toBe(1);
});

it('a failed attempt also uses up the challenge', function () {
    $this->actingAs(pkUser());
    $options = pkOptions();
    $authenticator = new FakeAuthenticator;

    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options, ['uv' => false])])->assertStatus(422);
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($options)])
        ->assertStatus(422)->assertJsonPath('code', 'passkey_failed');
});

it('refuses a challenge older than 5 minutes', function () {
    $this->freezeTime();
    $this->actingAs(pkUser());

    $options = pkOptions();
    $this->travel(300)->seconds();
    confirmStepUp();
    $this->postJson('/api/v1/me/passkeys', ['name' => 'In time', 'credential' => (new FakeAuthenticator)->register($options)])->assertCreated();

    $options = pkOptions();
    $this->travel(301)->seconds();
    confirmStepUp();
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Late', 'credential' => (new FakeAuthenticator)->register($options)])
        ->assertStatus(422)->assertJsonPath('code', 'passkey_failed');

    expect(Passkey::query()->pluck('name')->all())->toBe(['In time']);
});

it('refuses a registration without options, and options made for another user', function () {
    $user = pkUser();
    $this->actingAs($user);
    confirmStepUp();
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => (new FakeAuthenticator)->register(['challenge' => 'AAAA'])])
        ->assertStatus(422)->assertJsonPath('code', 'passkey_failed');

    // Signing in keeps the session's data, so the options of the user who signed in before are still there.
    $options = pkOptions();
    pkUser(['email' => 'other@example.org', 'is_server_admin' => false]);
    $this->getJson('/api/v1/auth/csrf');
    $this->postJson('/api/v1/auth/login', ['email' => 'other@example.org', 'password' => 'correct horse battery'])->assertOk();
    confirmStepUp();
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => (new FakeAuthenticator)->register($options)])
        ->assertStatus(422)->assertJsonPath('code', 'passkey_failed');

    expect(Passkey::query()->count())->toBe(0);
});

it('refuses a credential that is already registered', function () {
    $this->actingAs(pkUser());
    $authenticator = new FakeAuthenticator;
    pkRegister($authenticator)->assertCreated();

    pkRegister($authenticator, 'Again')->assertStatus(422)->assertJsonPath('code', 'passkey_failed');

    expect(Passkey::query()->count())->toBe(1);
});

it('refuses malformed credentials with the same answer', function (mixed $credential) {
    $this->actingAs(pkUser());
    pkOptions();

    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $credential])->assertStatus(422)
        ->assertJsonPath('code', 'passkey_failed');
})->with([
    'no response' => [['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key']],
    'garbage' => [['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key', 'response' => ['clientDataJSON' => '!!', 'attestationObject' => '!!']]],
    'mismatched ids' => [['id' => 'AAAA', 'rawId' => 'BBBB', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30', 'attestationObject' => 'oA']]],
]);

it('allows at most 10 passkeys, disabled ones included', function () {
    $user = pkUser();
    $this->actingAs($user);
    foreach (range(1, 8) as $i) {
        pkRow($user, "Key {$i}", disabled: $i === 1);
    }
    pkRegister(new FakeAuthenticator, 'Ninth')->assertCreated();
    $options = pkOptions();
    pkRow($user, 'Tenth');

    confirmStepUp();
    $this->postJson('/api/v1/me/passkeys/options')->assertStatus(422)->assertJsonPath('code', 'too_many_passkeys')
        ->assertJsonPath('errors.credential.0', 'You can have at most 10 passkeys.');
    // Options fetched before the tenth was added don't get round the limit.
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Eleventh', 'credential' => (new FakeAuthenticator)->register($options)])
        ->assertStatus(422)->assertJsonPath('code', 'too_many_passkeys');

    expect(Passkey::query()->count())->toBe(10);
});

it('validates the name: 1 to 64 characters of plain text', function (mixed $name) {
    $this->actingAs(pkUser());
    pkOptions();
    $this->postJson('/api/v1/me/passkeys', ['name' => $name, 'credential' => (new FakeAuthenticator)->register(['challenge' => 'AAAA'])])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('name');
})->with([
    'missing' => [null],
    'empty' => [''],
    'too long' => [str_repeat('a', 65)],
    'a control character' => ["Lap\ntop"],
    'not a string' => [['Laptop']],
]);

it('takes a 64-character name with letters from any script', function () {
    $this->actingAs(pkUser());
    $name = 'Työkannettava '.str_repeat('ä', 50);

    pkRegister(new FakeAuthenticator, $name)->assertCreated()->assertJsonPath('passkey.name', $name);
});

// --- Rename and remove ----------------------------------------------------------------------------

it('renames a passkey without step-up and audits the old and new name', function () {
    $user = pkUser();
    $passkey = pkRow($user, 'Phone');
    $this->actingAs($user);

    $this->patchJson("/api/v1/me/passkeys/{$passkey->id}", ['name' => 'Work phone'])->assertOk()
        ->assertJsonPath('name', 'Work phone')->assertJsonPath('id', $passkey->id);

    expect($passkey->refresh()->name)->toBe('Work phone');
    $audit = AuditEntry::query()->where('action', 'user.passkey_renamed')->sole();
    expect($audit->object_type)->toBe('user')->and($audit->object_key)->toBe('admin@example.org')
        ->and($audit->changes)->toBe(['name' => ['Phone', 'Work phone']]);

    $this->patchJson("/api/v1/me/passkeys/{$passkey->id}", ['name' => 'Work phone'])->assertOk();
    expect(AuditEntry::query()->count())->toBe(1);
    $this->patchJson("/api/v1/me/passkeys/{$passkey->id}", ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('removes a passkey with step-up, audits it and sends a notice', function () {
    Mail::fake();
    $user = pkUser();
    tfaEnrol($user);
    $passkey = pkRow($user, 'Phone');
    $this->actingAs($user);

    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    expect(Passkey::query()->count())->toBe(1);

    confirmStepUp();
    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertNoContent();

    expect(Passkey::query()->count())->toBe(0)->and(pkCodes($user))->toHaveCount(8);
    $audit = AuditEntry::query()->where('action', 'user.passkey_removed')->sole();
    expect($audit->object_key)->toBe('admin@example.org')->and($audit->changes)->toBe(['name' => 'Phone']);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'passkey_removed' && $mail->hasTo('admin@example.org'));
});

it("another user's passkey and an unknown id are 404 to rename and remove", function () {
    $user = pkUser();
    $theirs = pkRow(pkUser(['email' => 'other@example.org']), 'Theirs');
    $this->actingAs($user);
    confirmStepUp();

    foreach ([$theirs->id, 999999] as $id) {
        $this->patchJson("/api/v1/me/passkeys/{$id}", ['name' => 'Mine'])->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->deleteJson("/api/v1/me/passkeys/{$id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    }
    $this->getJson('/api/v1/me/passkeys')->assertExactJson(['data' => []]);

    expect($theirs->refresh()->name)->toBe('Theirs');
});

it('lists only your own passkeys, oldest first, with disabled ones marked', function () {
    $user = pkUser();
    $this->freezeTime();
    $a = pkRow($user, 'A');
    $this->travel(1)->minutes();
    $b = pkRow($user, 'B', disabled: true);
    pkRow(pkUser(['email' => 'other@example.org']), 'Theirs');
    $this->actingAs($user);

    $data = $this->getJson('/api/v1/me/passkeys')->assertOk()->json('data');

    expect(array_column($data, 'name'))->toBe(['A', 'B'])
        ->and(array_column($data, 'disabled'))->toBe([false, true])
        ->and(array_keys($data[0]))->toBe(['id', 'name', 'created_at', 'last_used_at', 'disabled']);
    $this->getJson('/api/v1/me')->assertJsonPath('passkeys', 1);
});

// --- The last second factor -----------------------------------------------------------------------

it('refuses to remove the last passkey while two-factor is required', function () {
    Mail::fake();
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = pkUser();
    $this->actingAs($user);
    pkRegister(new FakeAuthenticator)->assertCreated();
    $disabled = pkRow($user, 'Old', disabled: true);
    $passkey = Passkey::query()->where('name', 'Laptop')->sole();

    confirmStepUp();
    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'last_second_factor');
    // A disabled passkey is no second factor, so removing it is fine.
    $this->deleteJson("/api/v1/me/passkeys/{$disabled->id}")->assertNoContent();

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue()->and(pkCodes($user))->toHaveCount(8)
        ->and(AuditEntry::query()->where('action', 'user.passkey_removed')->count())->toBe(1);
});

it('removes the last passkey while two-factor is required when TOTP remains, keeping the codes', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = pkUser();
    tfaEnrol($user);
    $codes = pkCodes($user);
    $passkey = pkRow($user);
    $this->actingAs($user);
    confirmStepUp();

    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertNoContent();

    expect(pkCodes($user))->toBe($codes);
});

it('removing the last second factor when two-factor is not required clears the recovery codes', function () {
    config()->set('emailsquire.require_two_factor', 'off');
    $user = pkUser();
    $this->actingAs($user);
    pkRegister(new FakeAuthenticator)->assertCreated();
    $passkey = Passkey::query()->sole();

    confirmStepUp();
    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertNoContent();

    expect(pkCodes($user))->toBeNull();
    $this->getJson('/api/v1/me')->assertJsonPath('second_factor', false)->assertJsonPath('passkeys', 0)
        ->assertJsonPath('two_factor.recovery_codes_left', 0);
});

it('refuses to turn TOTP off while two-factor is required and no passkey remains', function () {
    Mail::fake();
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = pkUser();
    tfaEnrol($user);
    pkRow($user, 'Old', disabled: true);
    $this->actingAs($user);
    confirmStepUp();

    $this->deleteJson('/api/v1/me/two-factor')->assertStatus(409)->assertJsonPath('code', 'last_second_factor');

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()->and(pkCodes($user))->toHaveCount(8)
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('turns TOTP off while a passkey remains, keeping the recovery codes and the passkey', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    $user = pkUser();
    tfaEnrol($user);
    $codes = pkCodes($user);
    pkRow($user);
    $this->actingAs($user);
    confirmStepUp();

    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(pkCodes($user))->toBe($codes)->and(Passkey::query()->count())->toBe(1);
    $this->getJson('/api/v1/me')->assertJsonPath('second_factor', true)->assertJsonPath('two_factor.confirmed', false)
        ->assertJsonPath('two_factor.recovery_codes_left', 8);
});

it('cancelling an unconfirmed TOTP setup keeps the codes of a passkey user', function () {
    $user = pkUser();
    $this->actingAs($user);
    $codes = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');
    confirmStepUp();

    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    expect(pkCodes($user))->toBe($codes);
    $this->deleteJson('/api/v1/me/two-factor')->assertNoContent();

    expect(pkCodes($user))->toBe($codes);
});

it('setting up TOTP beside a passkey keeps the recovery codes the passkey brought', function () {
    $user = pkUser();
    $this->actingAs($user);
    $codes = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');
    confirmStepUp();

    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->json('secret');
    $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk()
        ->assertExactJson(['recovery_codes' => $codes]);

    expect(pkCodes($user))->toBe($codes);
});

it('removing the last passkey during an unconfirmed TOTP setup leaves that setup new codes to show on confirm', function () {
    $user = pkUser();
    $this->actingAs($user);
    $old = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');
    confirmStepUp();
    $this->postJson('/api/v1/me/two-factor')->assertNoContent();
    $secret = $this->getJson('/api/v1/me/two-factor/setup')->json('secret');

    $this->deleteJson('/api/v1/me/passkeys/'.Passkey::query()->sole()->id)->assertNoContent();
    $codes = $this->postJson('/api/v1/me/two-factor/confirm', ['code' => tfaOtp($secret)])->assertOk()->json('recovery_codes');

    expect($codes)->toHaveCount(8)->and(array_intersect($codes, $old))->toBe([])->and(pkCodes($user))->toBe($codes);
});

it('a passkey-only user reads and regenerates recovery codes', function () {
    $user = pkUser();
    $this->actingAs($user);
    $codes = pkRegister(new FakeAuthenticator)->assertCreated()->json('recovery_codes');
    confirmStepUp();

    $this->getJson('/api/v1/me/recovery-codes')->assertOk()->assertExactJson(['recovery_codes' => $codes]);
    $new = $this->postJson('/api/v1/me/recovery-codes')->assertOk()->json('recovery_codes');

    expect($new)->toHaveCount(8)->and(array_intersect($new, $codes))->toBe([]);
});

// --- Required two-factor --------------------------------------------------------------------------

it('a passkey satisfies required two-factor; a disabled one does not', function () {
    config()->set('emailsquire.require_two_factor', 'admins');
    GuideFixture::load('trixie');
    $user = pkUser();
    $this->actingAs($user);

    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('second_factor', false)->assertJsonPath('passkeys', 0);

    pkRow($user, 'Old', disabled: true);
    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    // Blocked as they are, they can still list, add and rename passkeys.
    $this->getJson('/api/v1/me/passkeys')->assertOk();
    pkRegister(new FakeAuthenticator)->assertCreated();
    $passkey = Passkey::query()->where('name', 'Laptop')->sole();
    $this->patchJson("/api/v1/me/passkeys/{$passkey->id}", ['name' => 'Laptop 2'])->assertOk();

    $this->getJson('/api/v1/dashboard')->assertOk();
    $this->getJson('/api/v1/me')->assertJsonPath('second_factor', true)->assertJsonPath('passkeys', 1)
        ->assertJsonPath('two_factor.confirmed', false);
});

// --- Audit, notices and logs ----------------------------------------------------------------------

it('audits additions with the name only and never writes credential data to the audit log or the logs', function () {
    Mail::fake();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = [$e->message, $e->context];
    });
    $user = pkUser();
    $this->actingAs($user);
    $authenticator = new FakeAuthenticator;
    $options = pkOptions();
    $credential = $authenticator->register($options);

    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register(pkOptions(), ['origin' => 'https://evil.example'])])->assertStatus(422);
    $codes = pkRegister($authenticator)->assertCreated()->json('recovery_codes');
    $this->postJson('/api/v1/me/passkeys', ['name' => 'Laptop', 'credential' => $credential])->assertStatus(422);

    $audit = AuditEntry::query()->sole();
    expect($audit->action)->toBe('user.passkey_added')->and($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('admin@example.org')->and($audit->changes)->toBe(['name' => 'Laptop']);

    $stored = Passkey::query()->sole();
    $dump = json_encode(DB::table('audit_log')->get()->all()).json_encode($logged);
    $secrets = [
        $authenticator->id(),
        $credential['response']['clientDataJSON'],
        $credential['response']['attestationObject'],
        FakeAuthenticator::b64url($authenticator->coseKey()),
        $options['challenge'],
        json_encode($stored->credential),
        ...$codes,
    ];
    foreach ($secrets as $secret) {
        expect($dump)->not->toContain($secret);
    }
    expect(array_keys($stored->toArray()))->not->toContain('credential');
});

// --- Routes and configuration ---------------------------------------------------------------------

it('the passkey routes need a signed-in console user', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
})->with([
    ['GET', '/api/v1/me/passkeys'],
    ['POST', '/api/v1/me/passkeys/options'],
    ['POST', '/api/v1/me/passkeys'],
    ['PATCH', '/api/v1/me/passkeys/1'],
    ['DELETE', '/api/v1/me/passkeys/1'],
]);

it('registers no passkey routes but its own under /api/v1/me/passkeys', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->map(fn (LaravelRoute $r) => $r->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'passkey') && ! str_starts_with($uri, 'api/v1/me/passkeys'))
        ->values()->all();

    expect($routes)->toBe([]);
});

it('takes the RP ID and the one allowed origin from APP_URL', function () {
    $url = parse_url((string) config('app.url'));
    $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

    expect(config('passkeys.relying_party_id'))->toBe($url['host'])
        ->and(config('passkeys.allowed_origins'))->toBe([$origin]);
});

it('the passkey_failed message is Finnish for a Finnish user', function () {
    $this->actingAs(pkUser(['locale' => 'fi']));

    pkRegister(new FakeAuthenticator, 'Laptop', ['uv' => false])->assertStatus(422)
        ->assertJsonPath('errors.credential.0', 'Pääsyavainta ei voitu vahvistaa. Yritä uudelleen.');
});

it('step-up stays required for options, registration and removal after the window closes', function () {
    $this->freezeTime();
    $user = pkUser();
    $passkey = pkRow($user);
    $this->actingAs($user);
    $this->withSession([RequireStepUp::SESSION_KEY => now()->getTimestamp() - 301]);

    $this->postJson('/api/v1/me/passkeys/options')->assertStatus(423);
    $this->postJson('/api/v1/me/passkeys', ['name' => 'X', 'credential' => []])->assertStatus(423);
    $this->deleteJson("/api/v1/me/passkeys/{$passkey->id}")->assertStatus(423);
    $this->getJson('/api/v1/me/passkeys')->assertOk();
});

it('stores credential ids case-sensitively, as base64url is', function () {
    $user = pkUser();
    $id = FakeAuthenticator::b64url(random_bytes(32));
    $lower = strtolower($id) === $id ? 'A'.substr($id, 1) : strtolower($id);
    $other = $lower === $id ? strtoupper($id) : $lower;

    foreach ([$id, $other] as $credentialId) {
        $passkey = new Passkey;
        $passkey->forceFill(['user_id' => $user->id, 'name' => 'K', 'credential_id' => $credentialId, 'credential' => []])->save();
    }

    expect(Passkey::query()->where('credential_id', $id)->sole()->credential_id)->toBe($id);
});
