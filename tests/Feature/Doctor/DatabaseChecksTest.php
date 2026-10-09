<?php

use App\Doctor\Check;
use App\Doctor\CheckResult;
use App\Doctor\Checks\AddressFormat;
use App\Doctor\Checks\AliasShadowsMailbox;
use App\Doctor\Checks\GuideSchemaShape;
use App\Doctor\Doctor;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

/** @return array<string, CheckResult> */
function doctorResultsById(): array
{
    $results = [];

    foreach (app(Doctor::class)->run() as $result) {
        $results[$result->id] = $result;
    }

    return $results;
}

it('passes every database check on a clean trixie install', function () {
    GuideFixture::load('trixie');

    $results = doctorResultsById();

    expect($results)->toHaveCount(10);

    foreach ($results as $id => $result) {
        expect($result->status)->toBe(Status::Ok, "{$id}: {$result->detail}");
    }
});

it('reports every anomaly in the anomalies fixture', function () {
    GuideFixture::load('trixie', 'anomalies');

    $results = doctorResultsById();

    $schemes = $results['guide.password_schemes'];
    expect($schemes->status)->toBe(Status::Fail)
        ->and(implode("\n", $schemes->items))->toContain('PLAIN-MD5')->toContain('(none)');

    $format = $results['guide.address_format'];
    expect($format->status)->toBe(Status::Warn)
        ->and($format->items)->toContain('Mixed@example.org', 'Example.com', 'jörg@example.org')
        ->and($format->items)->not->toContain('john@example.org');

    $mismatch = $results['guide.domain_mismatch'];
    expect($mismatch->status)->toBe(Status::Warn)
        ->and($mismatch->items)->toBe(['wrong@example.net', 'x@example.net']);

    $shadows = $results['guide.alias_shadows_mailbox'];
    expect($shadows->status)->toBe(Status::Warn)
        ->and($shadows->items)->toBe(['mary@example.org']);

    $duplicates = $results['guide.duplicate_aliases'];
    expect($duplicates->status)->toBe(Status::Warn)
        ->and($duplicates->items)->toBe(['jack@example.org → john@example.org']);

    expect($results['guide.schema']->status)->toBe(Status::Ok)
        ->and($results['guide.domain_unique_key']->status)->toBe(Status::Ok);
});

it('does not count a forward that keeps a copy as shadowing the mailbox', function () {
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'john@example.org'],
    ]);

    expect(app(AliasShadowsMailbox::class)->run()->status)->toBe(Status::Ok);
});

it('suggests the unique key on restored bookworm data and warns on legacy hashes', function () {
    GuideFixture::load('restored-bookworm');

    $results = doctorResultsById();

    expect($results['guide.schema']->status)->toBe(Status::Ok)
        ->and($results['guide.domain_unique_key']->status)->toBe(Status::Warn)
        ->and($results['guide.domain_unique_key']->detail)
        ->toBe('ALTER TABLE mailserver_test.virtual_domains ADD UNIQUE KEY (name);')
        ->and($results['guide.password_schemes']->status)->toBe(Status::Warn)
        ->and($results['guide.password_schemes']->detail)->toContain('upgrades on next password change');
});

it('fails the unique key check when duplicate domain names exist', function () {
    GuideFixture::load('restored-bookworm');
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 20, 'name' => 'example.org']);

    $result = doctorResultsById()['guide.domain_unique_key'];

    expect($result->status)->toBe(Status::Fail)
        ->and($result->items)->toBe(['example.org']);
});

it('fails guide.schema and skips the dependent guide checks without a guide schema', function () {
    GuideFixture::drop();

    $results = doctorResultsById();

    expect($results)->toHaveCount(10)
        ->and($results['guide.schema']->status)->toBe(Status::Fail)
        ->and($results['database.connection']->status)->toBe(Status::Ok)
        ->and($results['app.migrated']->status)->toBe(Status::Ok);

    foreach ($results as $id => $result) {
        if (str_starts_with($id, 'guide.') && $id !== 'guide.schema') {
            expect($result->status)->toBe(Status::Skip, $id);
        }
    }
});

it('reports missing tables, missing columns and length differences in the guide shape', function () {
    $columns = [
        ['table' => 'virtual_domains', 'column' => 'id', 'type' => 'int', 'length' => null],
        ['table' => 'virtual_domains', 'column' => 'name', 'type' => 'varchar', 'length' => 50],
        ['table' => 'virtual_users', 'column' => 'id', 'type' => 'int', 'length' => null],
        ['table' => 'virtual_users', 'column' => 'domain_id', 'type' => 'int', 'length' => null],
        ['table' => 'virtual_users', 'column' => 'email', 'type' => 'varchar', 'length' => 255],
        ['table' => 'virtual_users', 'column' => 'password', 'type' => 'varchar', 'length' => 150],
        ['table' => 'virtual_users', 'column' => 'quota', 'type' => 'int', 'length' => null],
    ];

    expect(GuideSchemaShape::diff($columns))->toBe([
        'virtual_users.email is varchar(255), expected varchar(100)',
        'virtual_users.quota is int, expected bigint',
        'table virtual_aliases is missing',
    ]);

    unset($columns[1]);
    expect(GuideSchemaShape::diff($columns))->toContain('column virtual_domains.name is missing');
});

it('fails app.migrated and skips app.orphans after the app migrations are rolled back', function () {
    GuideFixture::load('trixie');

    try {
        Artisan::call('migrate:reset');

        $results = doctorResultsById();

        expect($results)->toHaveCount(10)
            ->and($results['app.migrated']->status)->toBe(Status::Fail)
            ->and($results['app.migrated']->items)->toContain('2026_10_09_000008_create_audit_log_table')
            ->and($results['app.orphans']->status)->toBe(Status::Skip);
    } finally {
        Artisan::call('migrate');
    }
});

it('turns an exception inside a check into a fail and still runs every check', function () {
    GuideFixture::load('trixie');

    app()->bind(AddressFormat::class, fn () => new class implements Check
    {
        public function id(): string
        {
            return 'guide.address_format';
        }

        public function title(): string
        {
            return 'Address format';
        }

        public function isServerCheck(): bool
        {
            return false;
        }

        public function run(): CheckResult
        {
            throw new RuntimeException('boom');
        }
    });

    $results = app(Doctor::class)->run();

    expect($results)->toHaveCount(count(Doctor::CHECKS));

    $byId = collect($results)->keyBy('id');
    expect($byId['guide.address_format']->status)->toBe(Status::Fail)
        ->and($byId['guide.address_format']->detail)->toBe('RuntimeException: boom')
        ->and($byId['guide.duplicate_aliases']->status)->toBe(Status::Ok);
});

it('caps huge item lists and keeps --json valid', function () {
    GuideFixture::load('trixie');

    $rows = [];
    for ($i = 1; $i <= 1000; $i++) {
        $rows[] = ['domain_id' => 10, 'source' => "UPPER{$i}@example.org", 'destination' => 'john@example.org'];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table(GuideSchema::table('virtual_aliases'))->insert($chunk);
    }

    $format = doctorResultsById()['guide.address_format'];

    expect($format->status)->toBe(Status::Warn)
        ->and($format->items)->toHaveCount(21)
        ->and($format->items[20])->toBe('… and 980 more');

    Artisan::call('emailsquire:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveCount(10);
});

it('forces check result items to valid UTF-8', function () {
    $result = new CheckResult('x', Status::Warn, 't', items: ["\xC3\x28", 'ok']);

    foreach ($result->items as $item) {
        expect(mb_check_encoding($item, 'UTF-8'))->toBeTrue();
    }

    expect($result->items)->toHaveCount(2);
});

it('warns on app rows whose guide domain is gone or renamed', function () {
    GuideFixture::load('trixie');
    $user = User::factory()->create();

    DB::table('domain_grants')->insert([
        ['user_id' => $user->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => 'admin'],
        ['user_id' => $user->id, 'domain_id' => 99, 'domain_name' => 'gone.test', 'role' => 'admin'],
    ]);
    DB::table('domain_settings')->insert(['domain_id' => 10, 'domain_name' => 'renamed.test']);
    DB::table('mailbox_settings')->insert(['mailbox_email' => 'john@example.org', 'domain_id' => 10]);
    DB::table('disabled_mailboxes')->insert([
        'mailbox_email' => 'old@other.test', 'domain_id' => 10,
        'saved_password' => '{BLF-CRYPT}x', 'disabled_at' => now(),
    ]);

    $result = doctorResultsById()['app.orphans'];

    expect($result->status)->toBe(Status::Warn)
        ->and($result->items)->toHaveCount(3)
        ->and(implode("\n", $result->items))
        ->toContain('domain_grants')->toContain('gone.test')
        ->toContain('domain_settings')->toContain('renamed.test')
        ->toContain('old@other.test')
        ->not->toContain('john@example.org');
});
