<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\GuideFixture;

it('creates every app table', function () {
    $tables = [
        'users', 'domain_grants', 'invitations', 'domain_settings', 'mailbox_settings',
        'disabled_mailboxes', 'blocked_addresses', 'helper_tasks', 'audit_log',
        'sessions', 'cache', 'jobs', 'failed_jobs', 'password_reset_tokens', 'admin_setup_tokens',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('users', ['is_server_admin', 'status', 'locale', 'last_login_at']))->toBeTrue()
        ->and(Schema::hasColumns('users', ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->toBeTrue()
        ->and(Schema::hasColumns('helper_tasks', ['payload', 'note']))->toBeTrue();
});

it('creates the usage, server storage and pinned domain tables', function () {
    expect(Schema::hasColumns('mailbox_usage', ['email', 'used_kib', 'limit_kib', 'checked_at']))->toBeTrue()
        ->and(Schema::hasColumns('server_storage', ['id', 'total_bytes', 'free_bytes', 'checked_at']))->toBeTrue()
        ->and(Schema::hasColumns('pinned_domains', ['id', 'user_id', 'domain_id', 'domain_name', 'position']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'password_set_at'))->toBeTrue();

    // MariaDB reports display widths (bigint(20) unsigned); MySQL 8 does not. Compare without them.
    $type = fn (string $table, string $column) => preg_replace(
        '/^(tinyint|smallint|int|bigint)\(\d+\)/', '$1',
        collect(Schema::getColumns($table))->firstWhere('name', $column)['type'],
    );

    expect($type('mailbox_usage', 'used_kib'))->toBe('bigint unsigned')
        ->and($type('mailbox_usage', 'limit_kib'))->toBe('bigint unsigned')
        ->and($type('server_storage', 'id'))->toStartWith('tinyint')
        ->and($type('server_storage', 'total_bytes'))->toBe('bigint unsigned')
        ->and($type('server_storage', 'free_bytes'))->toBe('bigint unsigned')
        ->and($type('pinned_domains', 'position'))->toBe('smallint unsigned')
        ->and($type('pinned_domains', 'domain_name'))->toBe('varchar(50)')
        ->and($type('mailbox_usage', 'email'))->toBe('varchar(100)');

    $unique = collect(Schema::getIndexes('pinned_domains'))->first(fn ($i) => $i['unique'] && $i['columns'] === ['user_id', 'domain_id']);
    expect($unique)->not->toBeNull();
});

it('gives sessions an indexed mailbox_address beside user_id', function () {
    $column = collect(Schema::getColumns('sessions'))->firstWhere('name', 'mailbox_address');
    $index = collect(Schema::getIndexes('sessions'))->first(fn ($i) => $i['columns'] === ['mailbox_address']);

    expect($column['type'])->toBe('varchar(100)')
        ->and($column['nullable'])->toBeTrue()
        ->and($index)->not->toBeNull();
});

it('never touches the guide schema on migrate:fresh', function () {
    GuideFixture::load('trixie');
    $schema = config('emailsquire.guide_schema');

    Artisan::call('migrate:fresh');

    expect(DB::table("{$schema}.virtual_users")->count())->toBe(2);

    $names = collect(DB::select('SELECT table_name AS n FROM information_schema.tables WHERE table_schema = ?', [$schema]))
        ->pluck('n')->sort()->values()->all();

    expect($names)->toBe(['virtual_aliases', 'virtual_domains', 'virtual_users']);
});
