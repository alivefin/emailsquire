<?php

use App\MailServer\AuditLog;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('writes one row with actor, ip, action and object inside a transaction', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.9');

    DB::transaction(fn () => app(AuditLog::class)->record('domain.create', 'domain', 'x.fi', 'x.fi'));

    $entry = AuditEntry::sole();
    expect($entry->actor_type)->toBe('user')
        ->and($entry->actor_id)->toBe((string) $user->id)
        ->and($entry->ip)->toBe('203.0.113.9')
        ->and($entry->action)->toBe('domain.create')
        ->and($entry->object_type)->toBe('domain')
        ->and($entry->object_key)->toBe('x.fi')
        ->and($entry->domain_name)->toBe('x.fi');
});

it('records the invoking os user as a cli actor when nobody is signed in', function () {
    DB::transaction(fn () => app(AuditLog::class)->record('domain.delete', 'domain', 'x.fi'));

    $entry = AuditEntry::sole();
    $expected = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
    expect($entry->actor_type)->toBe('cli')->and($entry->actor_id)->toBe($expected);
});

it('redacts secret-looking keys at any depth', function () {
    DB::transaction(fn () => app(AuditLog::class)->record('mailbox.update', 'mailbox', 'a@x.fi', 'x.fi', [
        'password' => 'x', 'nested' => ['new_hash' => 'y'], 'quota_bytes' => 5,
    ]));

    expect(DB::table('audit_log')->value('changes'))
        ->toBe('{"password":"[redacted]","nested":{"new_hash":"[redacted]"},"quota_bytes":5}');
});

it('refuses to write outside a transaction', function () {
    app(AuditLog::class)->record('domain.create', 'domain', 'x.fi');
})->throws(LogicException::class);

it('allows admin.create and admin.setup outside a transaction', function () {
    app(AuditLog::class)->record('admin.create', 'user', 'a@x.fi');
    app(AuditLog::class)->record('admin.setup', 'user', 'a@x.fi');

    expect(AuditEntry::count())->toBe(2);
});

it('records a given actor when nobody is signed in', function () {
    $user = User::factory()->create();

    DB::transaction(fn () => app(AuditLog::class)->record('user.password_reset', 'user', $user->email, actor: $user));

    $entry = AuditEntry::sole();
    expect($entry->actor_type)->toBe('user')->and($entry->actor_id)->toBe((string) $user->id);
});
