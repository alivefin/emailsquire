<?php

use App\MailServer\GuideSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

it('prints per-table grants with audit_log insert-only', function () {
    expect(Artisan::call('emailsquire:grants'))->toBe(0);
    $sql = Artisan::output();

    expect($sql)
        ->toContain("-- If this account already exists, check SHOW GRANTS FOR 'emailsquire_app'@'localhost'; and revoke anything beyond the grants below.\nCREATE USER IF NOT EXISTS 'emailsquire_app'@'localhost' IDENTIFIED BY 'CHANGE-ME';")
        ->toContain("-- If this account already exists, check SHOW GRANTS FOR 'vmail'@'localhost'; and revoke anything beyond the grants below.\nCREATE USER IF NOT EXISTS 'vmail'@'localhost'")
        ->toContain("-- If this account already exists, check SHOW GRANTS FOR '_rspamd'@'localhost'; and revoke anything beyond the grants below.\nCREATE USER IF NOT EXISTS '_rspamd'@'localhost'")
        ->toContain('php artisan migrate --database=migrator')
        ->toContain("GRANT INSERT, SELECT ON `emailsquire_test`.`audit_log` TO 'emailsquire_app'@'localhost';")
        ->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `emailsquire_test`.`domain_grants` TO 'emailsquire_app'@'localhost';")
        ->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `mailserver_test`.`virtual_users` TO 'emailsquire_app'@'localhost';")
        ->toContain("CREATE USER IF NOT EXISTS 'vmail'@'localhost' IDENTIFIED VIA unix_socket;")
        ->toContain("GRANT SELECT, UPDATE ON `emailsquire_test`.`helper_tasks` TO 'vmail'@'localhost';")
        ->toContain("CREATE USER IF NOT EXISTS '_rspamd'@'localhost' IDENTIFIED VIA unix_socket;")
        ->toContain("GRANT SELECT ON `mailserver_test`.`virtual_domains` TO '_rspamd'@'localhost';")
        ->toContain("GRANT SELECT ON `emailsquire_test`.`migrations` TO 'emailsquire_app'@'localhost';")
        ->not->toMatch('/GRANT [^;]*(INSERT|UPDATE|DELETE)[^;]*`migrations`/')
        ->not->toContain('ALL PRIVILEGES')
        ->not->toMatch('/GRANT [^;]*UPDATE[^;]*`audit_log`/');
});

it('rejects an app user, host or connection that is not usable', function () {
    expect(Artisan::call('emailsquire:grants', ['--app-user' => "x'@'%"]))->toBe(1)
        ->and(Artisan::call('emailsquire:grants', ['--host' => "x' OR 1"]))->toBe(1)
        ->and(Artisan::call('emailsquire:grants', ['--database' => 'no_such_connection']))->toBe(1)
        ->and(Artisan::output())->not->toContain('GRANT');
});

it('reads the table list through the --database connection', function () {
    config(['database.connections.grants_root' => config('database.connections.mysql')]);

    try {
        expect(Artisan::call('emailsquire:grants', ['--database' => 'grants_root']))->toBe(0)
            ->and(Artisan::output())->toContain('`emailsquire_test`.`audit_log`');
    } finally {
        DB::purge('grants_root');
    }
});

it('refuses to print grants before the app schema is migrated', function () {
    DB::statement('DROP DATABASE IF EXISTS `emailsquire_grants_empty`');
    DB::statement('CREATE DATABASE `emailsquire_grants_empty`');
    $original = config('database.connections.mysql');

    try {
        // A fresh install: the app schema exists but is empty, and the migrator connection is root.
        config([
            'database.connections.mysql.database' => 'emailsquire_grants_empty',
            'database.connections.grants_empty' => [...$original, 'database' => 'emailsquire_grants_empty'],
        ]);
        DB::purge('mysql');

        expect(Artisan::call('emailsquire:grants', ['--database' => 'grants_empty']))->toBe(1);
        expect(Artisan::output())
            ->not->toContain('GRANT')
            ->toContain('App tables not visible on connection "grants_empty": run php artisan migrate --database=grants_empty first, then re-run with --database=grants_empty.');
    } finally {
        config(['database.connections.mysql' => $original]);
        DB::purge('grants_empty');
        DB::purge('mysql');
        DB::statement('DROP DATABASE IF EXISTS `emailsquire_grants_empty`');
    }
});

it('refuses to print grants when the connection cannot see every app table', function () {
    DB::statement("DROP USER IF EXISTS 'grants_partial'@'%'");
    DB::statement("CREATE USER 'grants_partial'@'%' IDENTIFIED BY 'probe'");
    DB::statement("GRANT SELECT ON `emailsquire_test`.`migrations` TO 'grants_partial'@'%'");

    try {
        config(['database.connections.grants_probe_conn' => array_merge(
            config('database.connections.mysql'),
            ['username' => 'grants_partial', 'password' => 'probe'],
        )]);

        expect(Artisan::call('emailsquire:grants', ['--database' => 'grants_probe_conn']))->toBe(1);
        expect(Artisan::output())
            ->not->toContain('GRANT')
            ->toContain('App tables not visible on connection "grants_probe_conn"');
    } finally {
        DB::purge('grants_probe_conn');
        DB::statement("DROP USER IF EXISTS 'grants_partial'@'%'");
    }
});

it('grants exactly what the app needs when executed', function () {
    GuideFixture::load('trixie');
    DB::statement("DROP USER IF EXISTS 'grants_probe'@'%'");

    Artisan::call('emailsquire:grants', ['--app-user' => 'grants_probe', '--host' => '%']);
    $statements = array_filter(
        explode("\n", Artisan::output()),
        fn (string $line) => str_contains($line, 'grants_probe') && ! str_starts_with($line, '--'),
    );

    expect($statements)->not->toBeEmpty();

    try {
        foreach ($statements as $statement) {
            DB::statement(str_replace('CHANGE-ME', 'probe', $statement));
        }

        config(['database.connections.grants_probe' => array_merge(
            config('database.connections.mysql'),
            ['username' => 'grants_probe', 'password' => 'probe'],
        )]);
        $probe = DB::connection('grants_probe');

        $probe->table('audit_log')->insert([
            'occurred_at' => now(), 'actor_type' => 'system', 'action' => 'probe',
            'object_type' => 'test', 'object_key' => 'grants',
        ]);
        expect(DB::table('audit_log')->where('action', 'probe')->count())->toBe(1);

        expect(fn () => $probe->update("UPDATE audit_log SET action = 'tampered'"))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('42000'));

        expect($probe->table('migrations')->count())->toBeGreaterThan(0);
        expect(fn () => $probe->table('migrations')->insert(['migration' => 'probe', 'batch' => 99]))
            ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('42000'));

        expect($probe->table(GuideSchema::table('virtual_users'))->count())->toBe(2);

        expect(fn () => $probe->statement('CREATE TABLE '.GuideSchema::table('x').' (i int)'))
            ->toThrow(QueryException::class);
    } finally {
        DB::purge('grants_probe');
        DB::statement("DROP USER IF EXISTS 'grants_probe'@'%'");
    }
});

it('defines the migrator connection over the local socket', function () {
    $migrator = config('database.connections.migrator');

    expect($migrator)->toBeArray()
        ->and($migrator['driver'])->toBe('mysql')
        ->and($migrator['unix_socket'])->toBe('/run/mysqld/mysqld.sock')
        ->and($migrator['username'])->toBe('root')
        ->and($migrator['database'])->toBe('emailsquire_test')
        ->and($migrator['collation'])->toBe(config('database.connections.mysql.collation'));
});
