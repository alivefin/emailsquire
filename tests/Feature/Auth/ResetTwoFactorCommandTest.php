<?php

use App\Mail\SecurityNotice;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function rtfUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'boss@example.org', 'password' => 'correct horse battery',
        'is_server_admin' => true, 'password_set_at' => now(), 'locale' => 'fi',
    ], $attributes));
}

it('turns off confirmed two-factor for an admin who lost both phone and recovery codes', function () {
    Mail::fake();
    $user = rtfUser();
    tfaEnrol($user);

    $this->artisan('emailsquire:reset-two-factor', ['email' => 'Boss@Example.org'])
        ->expectsOutput('Two-factor authentication is off for boss@example.org; they can sign in with their password and set it up again.')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(Hash::check('correct horse battery', $user->password))->toBeTrue();
    $audit = AuditEntry::query()->sole();
    expect($audit->action)->toBe('user.two_factor_disabled')
        ->and($audit->actor_type)->toBe('cli')
        ->and($audit->object_type)->toBe('user')
        ->and($audit->object_key)->toBe('boss@example.org')
        ->and($audit->domain_name)->toBeNull()
        ->and($audit->changes)->toBeNull();
    Mail::assertQueued(SecurityNotice::class, 1);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'two_factor_disabled'
        && $mail->hasTo('boss@example.org') && $mail->locale === 'fi' && $mail->ip === null);
});

it('lets the user sign in with the password alone afterwards', function () {
    Mail::fake();
    $user = rtfUser();
    tfaEnrol($user);
    $this->artisan('emailsquire:reset-two-factor', ['email' => 'boss@example.org'])->assertExitCode(0);

    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.org', 'password' => 'correct horse battery'])
        ->assertOk()->assertJsonPath('two_factor.enabled', false);
});

it('refuses an unknown email and changes nothing', function () {
    Mail::fake();

    $this->artisan('emailsquire:reset-two-factor', ['email' => 'nobody@example.org'])
        ->expectsOutputToContain('No console user nobody@example.org.')
        ->assertExitCode(1);

    expect(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('refuses a user without confirmed two-factor and leaves an unconfirmed setup alone', function () {
    Mail::fake();
    $user = rtfUser();
    tfaEnrol($user);
    $user->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->artisan('emailsquire:reset-two-factor', ['email' => 'boss@example.org'])
        ->expectsOutputToContain('boss@example.org has no confirmed two-factor authentication.')
        ->assertExitCode(1);

    expect($user->refresh()->two_factor_secret)->not->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
