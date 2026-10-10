<?php

use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use App\Models\User;

it('hides the saved password of a disabled mailbox from serialisation', function () {
    $row = DisabledMailbox::create([
        'mailbox_email' => 'a@example.org', 'domain_id' => 10,
        'saved_password' => '{BLF-CRYPT}x', 'disabled_at' => now(),
    ]);

    expect($row->fresh()->getKey())->toBe('a@example.org')
        ->and($row->fresh()->toArray())->not->toHaveKey('saved_password')
        ->and($row->fresh()->saved_password)->toBe('{BLF-CRYPT}x');
});

it('round-trips string keys, nullable booleans and the audit changes array', function () {
    MailboxSetting::create(['mailbox_email' => 'a@example.org', 'domain_id' => 10, 'allow_user_aliases' => false]);
    MailboxUsage::create(['email' => 'a@example.org', 'used_kib' => 5, 'checked_at' => now()]);
    $entry = AuditEntry::create([
        'occurred_at' => now(), 'actor_type' => 'system', 'action' => 'probe',
        'object_type' => 'test', 'object_key' => 'k', 'changes' => ['a' => ['old' => 1, 'new' => 2]],
    ]);

    $setting = MailboxSetting::find('a@example.org');

    expect($setting->allow_user_aliases)->toBeFalse()
        ->and($setting->allow_user_forwarding)->toBeNull()
        ->and(MailboxUsage::find('a@example.org')->used_kib)->toBe(5)
        ->and($entry->fresh()->changes)->toBe(['a' => ['old' => 1, 'new' => 2]]);
});

it('casts the user admin flag and timestamps', function () {
    $user = User::factory()->create(['is_server_admin' => 1, 'password_set_at' => now()]);

    expect($user->fresh()->is_server_admin)->toBeTrue()
        ->and($user->fresh()->password_set_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($user->fresh()->last_login_at)->toBeNull()
        ->and($user->fresh()->toArray())->not->toHaveKeys(['password', 'remember_token']);
});
