<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const SETUP_LINK = '#https?://[^/]+/setup/[A-Za-z0-9]{64}\?email=#';

it('create-admin creates a server admin and prints a setup link', function () {
    $this->artisan('emailsquire:create-admin', ['email' => 'Boss@Example.org'])
        ->expectsOutputToContain('/setup/')
        ->assertExitCode(0);

    $user = User::where('email', 'boss@example.org')->firstOrFail();
    expect($user->is_server_admin)->toBeTrue();
    expect($user->password_set_at)->toBeNull();
    $audit = AuditEntry::where('action', 'admin.create')->firstOrFail();
    expect($audit->actor_type)->toBe('cli');
    expect($audit->object_type)->toBe('user');
    expect($audit->object_key)->toBe('boss@example.org');
    expect(DB::table('admin_setup_tokens')->where('email', 'boss@example.org')->count())->toBe(1);
});

it('prints a link in the documented shape', function () {
    Artisan::call('emailsquire:create-admin', ['email' => 'boss@example.org']);

    expect(Artisan::output())->toMatch(SETUP_LINK);
});

it('create-admin re-issues a link for a user without a password', function () {
    Artisan::call('emailsquire:create-admin', ['email' => 'boss@example.org']);
    $first = Artisan::output();

    $this->artisan('emailsquire:create-admin', ['email' => 'boss@example.org'])->assertExitCode(0);
    Artisan::call('emailsquire:create-admin', ['email' => 'boss@example.org']);

    expect(Artisan::output())->toMatch(SETUP_LINK)->not->toBe($first);
    expect(User::where('email', 'boss@example.org')->count())->toBe(1);
    expect(DB::table('admin_setup_tokens')->where('email', 'boss@example.org')->count())->toBe(1);
});

it('create-admin refuses a user who has a password unless --reset', function () {
    $user = User::factory()->create([
        'email' => 'boss@example.org', 'password' => 'correct horse battery',
        'is_server_admin' => true, 'password_set_at' => now(),
    ]);

    $this->artisan('emailsquire:create-admin', ['email' => 'boss@example.org'])
        ->expectsOutputToContain('already has a password; use --reset')
        ->assertExitCode(1);
    expect(DB::table('admin_setup_tokens')->count())->toBe(0);

    Artisan::call('emailsquire:create-admin', ['email' => 'boss@example.org', '--reset' => true]);
    expect(Artisan::output())->toMatch(SETUP_LINK);

    $user->refresh();
    expect(Hash::check('correct horse battery', $user->password))->toBeTrue();
    expect($user->password_set_at)->not->toBeNull();
    expect(DB::table('admin_setup_tokens')->where('email', 'boss@example.org')->count())->toBe(1);
});

it('create-admin rejects an invalid email', function () {
    $this->artisan('emailsquire:create-admin', ['email' => 'not-an-email'])->assertExitCode(1);
    expect(User::count())->toBe(0);
});
