<?php

use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function cpUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'anna@example.org',
        'password' => 'the old passphrase 1',
        'is_server_admin' => true,
        'password_set_at' => now(),
        'locale' => 'en',
    ], $attributes));
}

function cpBody(array $over = []): array
{
    return array_merge([
        'current_password' => 'the old passphrase 1',
        'password' => 'a brand new passphrase 7',
        'password_confirmation' => 'a brand new passphrase 7',
    ], $over);
}

/**
 * A request from the browser whose session cookie is $sessionId, on the database session driver.
 * The test client keeps the signed-in guard and the session store's attributes between requests, so
 * both are dropped first; only the cookie says who it is.
 */
function cpAs(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

function cpSignIn(string $email = 'anna@example.org', string $password = 'the old passphrase 1'): string
{
    return cpAs('')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk()
        ->getCookie(config('session.cookie'))->getValue();
}

// --- Change password -----------------------------------------------------------------------------

it('changes the password, keeps this browser signed in on a new session id and ends the others', function () {
    config(['session.driver' => 'database']);
    Mail::fake();
    $user = cpUser(['locale' => 'fi']);
    $bob = cpUser(['email' => 'bob@example.org']);
    $current = cpSignIn();
    $otherBrowser = cpSignIn();
    insertSessionRow($bob->id, 'bob-laptop');
    insertSessionRow(null, 'pending-two-factor');
    expect(sessionRowIds())->toContain($current, $otherBrowser);

    $response = cpAs($current)->putJson('/api/v1/me/password', cpBody())->assertNoContent();
    $renewed = $response->getCookie(config('session.cookie'))->getValue();

    expect($renewed)->not->toBe($current)
        ->and(sessionRowIds())->toEqualCanonicalizing([$renewed, 'bob-laptop', 'pending-two-factor'])
        ->and(Hash::check('a brand new passphrase 7', $user->refresh()->password))->toBeTrue();
    cpAs($renewed)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'anna@example.org');
    cpAs($current)->getJson('/api/v1/me')->assertUnauthorized();
    cpAs($otherBrowser)->getJson('/api/v1/me')->assertUnauthorized();

    Mail::assertQueued(SecurityNotice::class, 1);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'password_changed'
        && $mail->hasTo('anna@example.org') && $mail->locale === 'fi');
});

it('audits the change as the user without any password in the row', function () {
    Mail::fake();
    $user = cpUser();
    $this->actingAs($user);

    $this->putJson('/api/v1/me/password', cpBody())->assertNoContent();
    $this->getJson('/api/v1/me')->assertOk();

    $audit = AuditEntry::query()->where('action', 'user.password_changed')->sole();
    expect($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('anna@example.org')
        ->and($audit->domain_name)->toBeNull()
        ->and($audit->changes)->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and(json_encode($audit->getAttributes()))->not->toContain('passphrase');
});

it('needs the current password and logs a wrong one like a failed sign-in', function () {
    Mail::fake();
    $user = cpUser();
    insertSessionRow($user->id, 'anna-phone');
    $this->actingAs($user);
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=console user=anna@example.org ip=127.0.0.1');

    $this->putJson('/api/v1/me/password', cpBody(['current_password' => 'not my password at all']))
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.current_password', ['The password is incorrect.']);

    expect(Hash::check('the old passphrase 1', $user->refresh()->password))->toBeTrue()
        ->and(sessionRowIds())->toBe(['anna-phone'])
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('says the current password is wrong in Finnish', function () {
    $this->actingAs(cpUser(['locale' => 'fi']));

    $this->putJson('/api/v1/me/password', cpBody(['current_password' => 'not my password at all']))
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password', ['Salasana on väärä.']);
});

it('uses the setup rules for the new password', function (array $over, string $field) {
    $user = cpUser();
    $this->actingAs($user);

    $this->putJson('/api/v1/me/password', cpBody($over))->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
    expect(Hash::check('the old passphrase 1', $user->refresh()->password))->toBeTrue();
})->with([
    'too short' => [['password' => 'short pass1', 'password_confirmation' => 'short pass1'], 'password'],
    'confirmation differs' => [['password_confirmation' => 'something else entirely'], 'password'],
    'no current password' => [['current_password' => null], 'current_password'],
]);

it('needs a session', function () {
    $this->putJson('/api/v1/me/password', cpBody())->assertUnauthorized();
});

it('is open to an admin who still has to set up two-factor', function () {
    config(['emailsquire.require_two_factor' => 'admins']);
    Mail::fake();
    $this->actingAs(cpUser());

    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $this->putJson('/api/v1/me/password', cpBody())->assertNoContent();
});

it('shares the step-up limit: the seventh password guess in a minute is 429', function () {
    $this->actingAs(cpUser());

    foreach (range(1, 6) as $i) {
        $this->putJson('/api/v1/me/password', cpBody(['current_password' => "guess number {$i}"]))->assertStatus(422);
    }
    $this->putJson('/api/v1/me/password', cpBody())->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

// --- PATCH /me -----------------------------------------------------------------------------------

it('PATCH /me saves the name and leaves the locale alone', function () {
    $user = cpUser(['name' => 'Anna', 'locale' => 'fi']);
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', ['name' => 'Anna Virtanen'])->assertOk()
        ->assertJsonPath('name', 'Anna Virtanen')
        ->assertJsonPath('locale', 'fi');

    expect($user->refresh()->name)->toBe('Anna Virtanen')->and($user->locale)->toBe('fi');
});

it('PATCH /me saves the name and the locale together', function () {
    $user = cpUser();
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', ['name' => 'Anna V', 'locale' => 'fi'])->assertOk();

    expect($user->refresh()->name)->toBe('Anna V')->and($user->locale)->toBe('fi');
});

it('PATCH /me refuses a missing, empty or too long name', function (array $body, string $field) {
    $user = cpUser(['name' => 'Anna']);
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', $body)->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$field]]);
    expect($user->refresh()->name)->toBe('Anna');
})->with([
    'neither field' => [[], 'name'],
    'empty' => [['name' => ''], 'name'],
    'blank' => [['name' => '   '], 'name'],
    '101 characters' => [['name' => str_repeat('a', 101)], 'name'],
    'not a string' => [['name' => ['Anna']], 'name'],
]);

it('PATCH /me accepts a 100-character name', function () {
    $this->actingAs(cpUser());

    $this->patchJson('/api/v1/me', ['name' => str_repeat('ä', 100)])->assertOk()->assertJsonPath('name', str_repeat('ä', 100));
});

it('PATCH /me explains a too long name in Finnish', function () {
    $this->actingAs(cpUser(['locale' => 'fi']));

    $this->patchJson('/api/v1/me', ['name' => str_repeat('a', 101)])->assertStatus(422)
        ->assertJsonPath('errors.name', ['Kentässä nimi saa olla enintään 100 merkkiä.']);
});
