<?php

use App\Auth\OtherSessions;
use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function loUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'anna@example.org',
        'password' => 'the old passphrase 1',
        'is_server_admin' => true,
        'password_set_at' => now(),
        'locale' => 'en',
    ], $attributes));
}

/** See cpAs() in ChangePasswordTest: only the session cookie says who the request is. */
function loAs(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

function loSignIn(): string
{
    return loAs('')->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'the old passphrase 1'])->assertOk()
        ->getCookie(config('session.cookie'))->getValue();
}

it('signs out every other browser of the user and keeps this one', function () {
    config(['session.driver' => 'database']);
    Mail::fake();
    loUser(['locale' => 'fi']);
    $bob = loUser(['email' => 'bob@example.org']);
    $current = loSignIn();
    $laptop = loSignIn();
    $phone = loSignIn();
    insertSessionRow($bob->id, 'bob-laptop');

    loAs($current)->postJson('/api/v1/auth/logout-all')->assertNoContent();

    expect(sessionRowIds())->toEqualCanonicalizing([$current, 'bob-laptop']);
    loAs($current)->getJson('/api/v1/me')->assertOk();
    loAs($laptop)->getJson('/api/v1/me')->assertUnauthorized();
    loAs($phone)->getJson('/api/v1/me')->assertUnauthorized();

    $audit = AuditEntry::query()->where('action', 'user.sessions_revoked')->sole();
    expect($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('anna@example.org')
        ->and($audit->domain_name)->toBeNull()
        ->and($audit->changes)->toBe(['count' => 2]);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'sessions_revoked'
        && $mail->hasTo('anna@example.org') && $mail->locale === 'fi');
});

it('counts zero and still sends the notice when there is no other session', function () {
    Mail::fake();
    $this->actingAs(loUser());

    $this->postJson('/api/v1/auth/logout-all')->assertNoContent();

    expect(AuditEntry::query()->where('action', 'user.sessions_revoked')->sole()->changes)->toBe(['count' => 0]);
    Mail::assertQueued(SecurityNotice::class, 1);
    $this->getJson('/api/v1/me')->assertOk();
});

it('needs a session', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/logout-all')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    Mail::assertNothingQueued();
});

it('OtherSessions::end deletes only that user\'s rows except the kept one and leaves pending sign-ins', function () {
    $anna = loUser();
    $bob = loUser(['email' => 'bob@example.org']);
    insertSessionRow($anna->id, 'a1');
    insertSessionRow($anna->id, 'a2');
    insertSessionRow($anna->id, 'a3');
    insertSessionRow($bob->id, 'b1');
    // A half-finished two-factor sign-in has no user_id yet; it still needs the code.
    insertSessionRow(null, 'pending');

    expect(OtherSessions::end($anna, 'a2'))->toBe(2)
        ->and(sessionRowIds())->toBe(['a2', 'b1', 'pending'])
        ->and(OtherSessions::end($anna, null))->toBe(1)
        ->and(sessionRowIds())->toBe(['b1', 'pending']);
});
