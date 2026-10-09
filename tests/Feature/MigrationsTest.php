<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\GuideFixture;

it('creates every app table', function () {
    $tables = [
        'users', 'domain_grants', 'invitations', 'domain_settings', 'mailbox_settings',
        'disabled_mailboxes', 'blocked_addresses', 'helper_tasks', 'audit_log',
        'sessions', 'cache', 'jobs', 'failed_jobs', 'password_reset_tokens',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('users', ['is_server_admin', 'status', 'locale', 'last_login_at']))->toBeTrue();
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
