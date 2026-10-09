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
        ->toContain("CREATE USER IF NOT EXISTS 'emailsquire_app'@'localhost' IDENTIFIED BY 'CHANGE-ME';")
        ->toContain("GRANT INSERT, SELECT ON `emailsquire_test`.`audit_log` TO 'emailsquire_app'@'localhost';")
        ->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `emailsquire_test`.`domain_grants` TO 'emailsquire_app'@'localhost';")
        ->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `mailserver_test`.`virtual_users` TO 'emailsquire_app'@'localhost';")
        ->toContain("CREATE USER IF NOT EXISTS 'vmail'@'localhost' IDENTIFIED VIA unix_socket;")
        ->toContain("GRANT SELECT, UPDATE ON `emailsquire_test`.`helper_tasks` TO 'vmail'@'localhost';")
        ->toContain("CREATE USER IF NOT EXISTS '_rspamd'@'localhost' IDENTIFIED VIA unix_socket;")
        ->toContain("GRANT SELECT ON `mailserver_test`.`virtual_domains` TO '_rspamd'@'localhost';")
        ->not->toContain('`migrations`')
        ->not->toContain('ALL PRIVILEGES')
        ->not->toMatch('/GRANT [^;]*UPDATE[^;]*`audit_log`/');
});

it('rejects an app user or host that is not a plain identifier', function () {
    expect(Artisan::call('emailsquire:grants', ['--app-user' => "x'@'%"]))->toBe(1)
        ->and(Artisan::call('emailsquire:grants', ['--host' => "x' OR 1"]))->toBe(1);
});

it('grants exactly what the app needs when executed', function () {
    GuideFixture::load('trixie');
    DB::statement("DROP USER IF EXISTS 'grants_probe'@'%'");

    Artisan::call('emailsquire:grants', ['--app-user' => 'grants_probe', '--host' => '%']);
    $statements = array_filter(
        explode("\n", Artisan::output()),
        fn (string $line) => str_contains($line, 'grants_probe'),
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

        expect($probe->table(GuideSchema::table('virtual_users'))->count())->toBe(2);

        expect(fn () => $probe->statement('CREATE TABLE '.GuideSchema::table('x').' (i int)'))
            ->toThrow(QueryException::class);
    } finally {
        DB::purge('grants_probe');
        DB::statement("DROP USER IF EXISTS 'grants_probe'@'%'");
    }
});
