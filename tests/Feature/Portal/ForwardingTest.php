<?php

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\Exceptions\SelfServiceDisabled;
use App\MailServer\GuideSchema;
use App\MailServer\Queries\AliasExpansion;
use App\MailServer\Repositories\AliasRepository;
use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use App\Models\DomainSetting;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\GuideFixture;

// GET|PUT /portal/forwarding (spec decision 6, plan Task 4): the mailbox owner forwards their own
// mailbox, keeping a copy or not. Internal targets (any domain on this server) are fine while the
// domain's `forwarding` switch is on; external ones need `external_forwarding`. At most 10 targets.

const PFW_PASSWORD = 'mailbox passphrase 4';

beforeEach(function () {
    GuideFixture::load('trixie'); // example.org (10): john, mary; jack@example.org -> john
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'other.example']);
    DB::table(GuideSchema::table('virtual_users'))->insert([
        ['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}x', 'quota' => 0],
        ['domain_id' => 11, 'email' => 'olga@other.example', 'password' => '{BLF-CRYPT}x', 'quota' => 0],
    ]);
    app(PasswordChecker::class)->accept('anna@example.org', PFW_PASSWORD);
    test()->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => PFW_PASSWORD])->assertOk()->assertExactJson(['kind' => 'mailbox']);
});

/** @param  array{forwarding?: bool, aliases?: bool, external_forwarding?: bool}  $switches */
function pfwSwitches(array $switches): void
{
    $setting = DomainSetting::query()->find(10) ?? new DomainSetting;
    $setting->forceFill([
        'domain_id' => 10,
        'domain_name' => 'example.org',
        'allow_user_forwarding' => $switches['forwarding'] ?? true,
        'allow_user_aliases' => $switches['aliases'] ?? false,
        'allow_external_forwarding' => $switches['external_forwarding'] ?? false,
    ])->save();
}

/** @return list<array{0: string, 1: string}> the rows of $source, as [source, destination] in id order */
function pfwRows(string $source): array
{
    return DB::table(GuideSchema::table('virtual_aliases'))->whereRaw('lower(source) = ?', [$source])->orderBy('id')
        ->get()->map(fn ($r) => [$r->source, $r->destination])->all();
}

function pfwPut(array $body): TestResponse
{
    return test()->putJson('/api/v1/portal/forwarding', $body);
}

it('portal forwarding locks every mailbox before any alias locking query', function () {
    $locks = [];
    DB::listen(function (QueryExecuted $q) use (&$locks) {
        if (str_contains($q->sql, 'for update') || str_contains($q->sql, 'lock in share mode')) {
            if (str_contains($q->sql, 'virtual_users')) {
                $locks[] = 'mailbox';
            } elseif (str_contains($q->sql, 'virtual_aliases')) {
                $locks[] = 'alias';
            }
        }
    });

    pfwPut(['targets' => ['olga@other.example'], 'keep_copy' => false])->assertOk();
    expect($locks)->toContain('mailbox', 'alias');
    $alias = array_search('alias', $locks, true);
    expect(array_slice($locks, 0, $alias))->not->toContain('alias')
        ->and(array_slice($locks, $alias))->not->toContain('mailbox');
});

it('alias expansion locks only aliases while preserving alias precedence', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'mary@example.org']);
    $locks = [];
    DB::listen(function (QueryExecuted $q) use (&$locks) {
        if (str_contains($q->sql, 'lock in share mode')) {
            $locks[] = str_contains($q->sql, 'virtual_users') ? 'mailbox' : 'alias';
        }
    });
    $result = DB::transaction(fn () => app(AliasExpansion::class)->step(['john@example.org', 'olga@other.example', 'missing@other.example'], true));

    expect($locks)->toContain('alias')->not->toContain('mailbox')
        ->and($result['john@example.org'])->toBe(['via' => 'rows', 'to' => ['mary@example.org']])
        ->and($result['olga@other.example'])->toBe(['via' => 'mailbox', 'to' => []])
        ->and($result['missing@other.example'])->toBe(['via' => 'none', 'to' => []]);
});

it('portal forwarding retries a deadlock and audits only the committed attempt', function () {
    $deadlocks = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$deadlocks) {
        if ($deadlocks === 0 && str_contains($q->sql, 'virtual_aliases') && str_contains($q->sql, 'for update')) {
            $deadlocks++;
            throw new QueryException('mysql', $q->sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    pfwPut(['targets' => ['olga@other.example'], 'keep_copy' => false])->assertOk();
    expect($deadlocks)->toBe(1)
        ->and(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'olga@other.example']])
        ->and(AuditEntry::query()->where('action', 'portal.forwarding_changed')->count())->toBe(1);
});

it('portal forwarding sees a reverse rule committed before alias locking with snapshot protection off', function (bool $update) {
    $snapshotIsolation = DB::selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS enabled')->enabled;
    DB::statement('SET SESSION innodb_snapshot_isolation = OFF');
    pfwSwitches([]);
    if ($update) {
        DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 11, 'source' => 'olga@other.example', 'destination' => 'john@example.org']);
    }
    config(['database.connections.forwarding_race' => config('database.connections.mysql')]);
    $inserted = false;
    DB::listen(function (QueryExecuted $q) use (&$inserted, $update) {
        if (! $inserted && $q->connectionName === 'mysql' && DB::transactionLevel() > 0
            && str_contains($q->sql, 'virtual_users') && str_contains($q->sql, 'lock in share mode')) {
            $inserted = true;
            $aliases = DB::connection('forwarding_race')->table(GuideSchema::table('virtual_aliases'));
            if ($update) {
                $aliases->where('source', 'olga@other.example')->update(['destination' => 'anna@example.org']);
            } else {
                $aliases->insert(['domain_id' => 11, 'source' => 'olga@other.example', 'destination' => 'anna@example.org']);
            }
        }
    });

    try {
        $response = pfwPut(['targets' => ['olga@other.example'], 'keep_copy' => false]);
        $response->assertUnprocessable()->assertJsonValidationErrors('targets.0')
            ->assertJsonPath('detail', __('emailsquire.portal.target_loops', ['address' => 'olga@other.example']));
        expect($inserted)->toBeTrue()->and(pfwRows('anna@example.org'))->toBe([]);
    } finally {
        DB::statement('SET SESSION innodb_snapshot_isolation = '.((int) $snapshotIsolation));
        DB::purge('forwarding_race');
    }
})->with(['insert' => false, 'update' => true]);

it('portal expansion uses current alias reads throughout a chain without later mailbox locks', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 11, 'source' => 'first@other.example', 'destination' => 'second@other.example'],
        ['domain_id' => 11, 'source' => 'second@other.example', 'destination' => 'olga@other.example'],
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries) {
        if (DB::transactionLevel() > 0 && (str_contains($q->sql, 'virtual_users') || str_contains($q->sql, 'virtual_aliases'))) {
            $queries[] = $q->sql;
        }
    });

    pfwPut(['targets' => ['first@other.example'], 'keep_copy' => false])->assertOk();
    $aliasLocked = false;
    $aliasReads = 0;
    foreach ($queries as $sql) {
        $locking = str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode');
        if (str_contains($sql, 'virtual_aliases') && str_starts_with($sql, 'select')) {
            expect($locking)->toBeTrue();
            $aliasLocked = true;
            $aliasReads++;
        }
        if ($aliasLocked && str_contains($sql, 'virtual_users')) {
            expect($locking)->toBeFalse();
        }
    }
    expect($aliasReads)->toBeGreaterThanOrEqual(4)
        ->and(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'first@other.example']]);
});

// --- Reading ---------------------------------------------------------------------------------------

it('reads no forwarding as no targets', function () {
    $this->getJson('/api/v1/portal/forwarding')->assertOk()->assertExactJson(['targets' => [], 'keep_copy' => false]);
});

it('reads only this mailbox\'s own forwarding', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'Anna@Example.org', 'destination' => 'Out@Gmail.com'], // legacy spelling
        ['domain_id' => 10, 'source' => 'anna@example.org', 'destination' => 'anna@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'elsewhere@gmail.com'],
        // Someone else's alias that points at anna is not her forwarding.
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'anna@example.org'],
    ]);

    $this->getJson('/api/v1/portal/forwarding')->assertOk()->assertExactJson(['targets' => ['out@gmail.com'], 'keep_copy' => true]);
});

// --- Writing ---------------------------------------------------------------------------------------

it('forwards with a copy kept, to internal targets on any domain of this server', function () {
    pfwPut(['targets' => ['john@example.org', 'olga@other.example', 'nobody@other.example'], 'keep_copy' => true])
        ->assertOk()
        ->assertExactJson(['targets' => ['john@example.org', 'olga@other.example', 'nobody@other.example'], 'keep_copy' => true]);

    expect(pfwRows('anna@example.org'))->toBe([
        ['anna@example.org', 'john@example.org'],
        ['anna@example.org', 'olga@other.example'],
        ['anna@example.org', 'nobody@other.example'],
        ['anna@example.org', 'anna@example.org'],
    ]);
    expect(DB::table(GuideSchema::table('virtual_aliases'))->where('source', 'anna@example.org')->pluck('domain_id')->unique()->all())->toBe([10]);
});

it('forwards only, without a copy and without the console\'s redirect confirmation', function () {
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => false])->assertOk()
        ->assertExactJson(['targets' => ['john@example.org'], 'keep_copy' => false]);

    expect(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'john@example.org']]);
});

it('switches keep-copy on and off', function () {
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => false])->assertOk();
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => true])->assertOk()->assertJsonPath('keep_copy', true);
    expect(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'john@example.org'], ['anna@example.org', 'anna@example.org']]);

    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => false])->assertOk()->assertJsonPath('keep_copy', false);
    expect(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'john@example.org']]);
    $this->getJson('/api/v1/portal/forwarding')->assertExactJson(['targets' => ['john@example.org'], 'keep_copy' => false]);
});

it('turns forwarding off with no targets, whatever keep_copy says', function () {
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => true])->assertOk();

    pfwPut(['targets' => [], 'keep_copy' => true])->assertOk()->assertExactJson(['targets' => [], 'keep_copy' => false]);

    expect(pfwRows('anna@example.org'))->toBe([]);
    $audit = AuditEntry::query()->where('action', 'portal.forwarding_changed')->orderByDesc('id')->firstOrFail();
    expect($audit->changes)->toBe(['old' => ['john@example.org'], 'new' => [], 'keep_copy' => ['old' => true, 'new' => false]]);
});

it('replaces legacy mixed-case rows of the owner\'s address', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => 'Anna@Example.org', 'destination' => 'Old@gmail.com']);
    pfwSwitches(['external_forwarding' => true]);

    pfwPut(['targets' => ['new@gmail.com']])->assertOk();

    expect(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'new@gmail.com']]);
});

it('normalises targets, keeps a + and stores a duplicate once', function () {
    pfwSwitches(['external_forwarding' => true]);

    pfwPut(['targets' => [' Me+Tag@Gmail.COM ', 'me+tag@gmail.com', 'John@Example.org']])->assertOk()
        ->assertJsonPath('targets', ['me+tag@gmail.com', 'john@example.org']);
});

it('refuses the owner\'s own address as a target: that is keep_copy', function () {
    pfwPut(['targets' => ['john@example.org', 'ANNA@example.org']])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors', ['targets.1' => ['An address can’t deliver to itself.']]);
    expect(pfwRows('anna@example.org'))->toBe([]);
});

it('refuses one of the owner\'s own aliases as a target: the mail would loop', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'Info@Example.org', 'destination' => 'Anna@example.org'], // a legacy spelling
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'anna@example.org'],
    ]);

    pfwPut(['targets' => ['john@example.org', 'INFO@example.org', 'sales@example.org']])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors', ['targets.1' => ['info@example.org already delivers mail to this mailbox, so forwarding to it would loop.']]);
    expect(pfwRows('anna@example.org'))->toBe([]);

    $this->withHeader('Accept-Language', 'fi');
    pfwPut(['targets' => ['sales@example.org']])->assertStatus(422)
        ->assertJsonPath('errors', ['targets.0' => ['sales@example.org toimittaa jo viestejä tähän postilaatikkoon, joten edelleenlähetyksestä sinne tulisi silmukka.']]);

    // Even with a copy kept: forwarding to an address that only delivers back here does nothing useful.
    pfwPut(['targets' => ['sales@example.org'], 'keep_copy' => true])->assertStatus(422);
});

/** The `targets.0` refusal of a forwarding target that delivers back to anna. */
function pfwLoops(string $target): void
{
    pfwPut(['targets' => [$target], 'keep_copy' => false])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors', ['targets.0' => ["{$target} already delivers mail to this mailbox, so forwarding to it would loop."]]);
    expect(pfwRows('anna@example.org'))->toBe([]);
}

/** With a copy kept the loop ends at the mailbox (Postfix's "been here"), so the same target is fine. */
function pfwAllowedWithCopy(string $target): void
{
    pfwPut(['targets' => [$target], 'keep_copy' => true])->assertOk()
        ->assertExactJson(['targets' => [$target], 'keep_copy' => true]);
    pfwPut(['targets' => []])->assertOk();
}

it('refuses, without a copy, a shared alias that also delivers back to the owner, legacy spellings included', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'team@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'Team@Example.org', 'destination' => 'ANNA@example.org'], // a case-variant row
    ]);

    pfwLoops('team@example.org');
    pfwAllowedWithCopy('team@example.org');
});

it('refuses, without a copy, an admin-made alias on another hosted domain that delivers to the owner', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 11, 'source' => 'crew@other.example', 'destination' => 'olga@other.example'],
        ['domain_id' => 11, 'source' => 'crew@other.example', 'destination' => 'anna@example.org'],
    ]);

    pfwLoops('crew@other.example');
    pfwAllowedWithCopy('crew@other.example');
});

it('refuses, without a copy, an address with no rows that a catch-all delivers to the owner, but not a mailbox there', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 11, 'source' => '@Other.example', 'destination' => 'Anna@example.org']);

    pfwLoops('nobody@other.example');
    pfwAllowedWithCopy('nobody@other.example');
    // A mailbox is found before the catch-all (the guide's email2email map): mail to it stays there.
    pfwPut(['targets' => ['olga@other.example'], 'keep_copy' => false])->assertOk()->assertJsonPath('targets', ['olga@other.example']);
});

it('refuses, without a copy, a target that reaches the owner through more aliases, a mailbox\'s forwarding or a domain alias', function () {
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 12, 'name' => 'alias.example']);
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'hop@example.org', 'destination' => 'relay@other.example'],
        ['domain_id' => 11, 'source' => 'relay@other.example', 'destination' => 'olga@other.example'],
        ['domain_id' => 11, 'source' => 'relay@other.example', 'destination' => 'anna@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'anna@example.org'], // john's mailbox forwards here
        ['domain_id' => 12, 'source' => '@alias.example', 'destination' => '@example.org'],     // every address on alias.example
    ]);

    foreach (['hop@example.org', 'john@example.org', 'anna@alias.example'] as $target) {
        pfwLoops($target);
        pfwAllowedWithCopy($target);
    }
    // A chain that never comes back is fine without a copy.
    pfwPut(['targets' => ['mary@example.org', 'olga@other.example', 'out@alias.example'], 'keep_copy' => false])->assertOk();
});

it('validates the body', function () {
    pfwPut([])->assertStatus(422)->assertJsonStructure(['errors' => ['targets']]);
    pfwPut(['targets' => 'john@example.org'])->assertStatus(422)->assertJsonStructure(['errors' => ['targets']]);
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => 'maybe'])->assertStatus(422)->assertJsonStructure(['errors' => ['keep_copy']]);
    pfwPut(['targets' => ['john@example.org', 'nope', 7]])->assertStatus(422)
        ->assertJsonPath('errors', ['targets.1' => ['“nope” is not a complete address.'], 'targets.2' => ['“” is not a complete address.']]);
    expect(pfwRows('anna@example.org'))->toBe([]);
});

// --- Limits and switches ---------------------------------------------------------------------------

it('accepts 10 targets and refuses the 11th', function () {
    $targets = array_map(fn (int $i) => "t{$i}@other.example", range(1, 11));

    pfwPut(['targets' => $targets])->assertStatus(422)
        ->assertJsonPath('code', 'too_many_targets')
        ->assertJsonPath('errors.targets', ['You can forward to at most 10 addresses.']);
    expect(pfwRows('anna@example.org'))->toBe([]);

    pfwPut(['targets' => array_slice($targets, 0, 10)])->assertOk()->assertJsonCount(10, 'targets');
});

it('counts distinct targets: 11 entries that collapse to 10 are fine', function () {
    $targets = [...array_map(fn (int $i) => "t{$i}@other.example", range(1, 10)), 'T1@Other.Example'];

    pfwPut(['targets' => $targets])->assertOk()->assertJsonCount(10, 'targets');
});

it('refuses more than 50 entries without looking at them', function () {
    pfwPut(['targets' => array_fill(0, 51, 'john@example.org')])->assertStatus(422)->assertJsonPath('code', 'too_many_targets');
    pfwPut(['targets' => [...array_fill(0, 49, 'john@example.org'), 'nope']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('checks the switch again in the repository, whatever the route did', function () {
    pfwSwitches(['forwarding' => false]);
    $owner = new MailboxPrincipal('anna@example.org', 10, 'example.org', 0, '{BLF-CRYPT}x');

    expect(fn () => app(AliasRepository::class)->replaceOwn($owner, [], true))->toThrow(SelfServiceDisabled::class);
});

it('allows 30 portal changes a minute per mailbox, shared by forwarding and aliases', function () {
    pfwSwitches(['aliases' => true]);
    foreach (range(1, 30) as $i) {
        pfwPut(['targets' => [$i % 2 === 0 ? 'john@example.org' : 'mary@example.org']])->assertOk();
    }

    pfwPut(['targets' => ['john@example.org']])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    $this->postJson('/api/v1/portal/aliases', ['local' => 'sales'])->assertStatus(429);
    $this->getJson('/api/v1/portal/forwarding')->assertOk(); // reads are not limited
});

it('refuses an external target while external forwarding is off, and accepts it when it is on', function () {
    pfwPut(['targets' => ['john@example.org', 'out@gmail.com'], 'keep_copy' => true])->assertStatus(422)
        ->assertJsonPath('code', 'external_forwarding_disabled')
        ->assertJsonPath('errors', ['targets.1' => ['Forwarding to addresses outside this server is turned off. Remove out@gmail.com.']]);
    expect(pfwRows('anna@example.org'))->toBe([]);

    pfwSwitches(['external_forwarding' => true]);
    pfwPut(['targets' => ['john@example.org', 'out@gmail.com'], 'keep_copy' => true])->assertOk()
        ->assertJsonPath('targets', ['john@example.org', 'out@gmail.com']);
});

it('refuses every change while forwarding is off, and still reads', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => 'anna@example.org', 'destination' => 'john@example.org']);
    pfwSwitches(['forwarding' => false]);

    pfwPut(['targets' => ['mary@example.org']])->assertForbidden()
        ->assertJsonPath('code', 'self_service_disabled')
        ->assertJsonPath('detail', 'Your administrator has turned this off.');
    // Even an invalid body: the switch is checked first.
    pfwPut(['targets' => 'nope'])->assertForbidden()->assertJsonPath('code', 'self_service_disabled');
    pfwPut(['targets' => []])->assertForbidden();

    $this->getJson('/api/v1/portal/forwarding')->assertOk()->assertExactJson(['targets' => ['john@example.org'], 'keep_copy' => false]);
    expect(pfwRows('anna@example.org'))->toBe([['anna@example.org', 'john@example.org']]);
});

it('answers in Finnish', function () {
    pfwPut(['targets' => ['out@gmail.com']])->assertStatus(422); // English by default
    $this->withHeader('Accept-Language', 'fi');

    pfwPut(['targets' => ['out@gmail.com']])->assertStatus(422)
        ->assertJsonPath('errors', ['targets.0' => ['Edelleenlähetys tämän palvelimen ulkopuolisiin osoitteisiin on poistettu käytöstä. Poista out@gmail.com.']]);
    pfwSwitches(['forwarding' => false]);
    pfwPut(['targets' => ['john@example.org']])->assertForbidden()->assertJsonPath('detail', 'Ylläpitäjäsi on poistanut tämän käytöstä.');
});

it('refuses forwarding from the owner\'s address while it is blocked', function () {
    BlockedAddress::create(['address' => 'anna@example.org', 'reason' => null, 'created_by' => null]);

    pfwPut(['targets' => ['john@example.org']])->assertStatus(422)
        ->assertJsonPath('code', 'address_unavailable')
        ->assertJsonPath('errors', ['targets' => ['This address isn’t available.']]);
    expect(pfwRows('anna@example.org'))->toBe([]);
});

// --- Audit and the console -------------------------------------------------------------------------

it('audits the change with the mailbox as actor', function () {
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => true])->assertOk();
    pfwPut(['targets' => ['mary@example.org', 'john@example.org']])->assertOk();

    $rows = AuditEntry::query()->where('action', 'portal.forwarding_changed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[1]->only(['actor_type', 'actor_id', 'object_type', 'object_key', 'domain_name']))->toBe([
        'actor_type' => 'mailbox', 'actor_id' => 'anna@example.org',
        'object_type' => 'forwarding', 'object_key' => 'anna@example.org', 'domain_name' => 'example.org',
    ])->and($rows[1]->changes)->toBe([
        'old' => ['john@example.org'], 'new' => ['mary@example.org', 'john@example.org'], 'keep_copy' => ['old' => true, 'new' => false],
    ]);
    expect(AuditEntry::query()->where('action', 'forwarding.replace')->exists())->toBeFalse();
});

it('writes nothing and audits nothing when nothing changes', function () {
    pfwPut(['targets' => ['john@example.org'], 'keep_copy' => true])->assertOk();
    pfwPut(['targets' => ['John@example.org'], 'keep_copy' => true])->assertOk();
    pfwPut(['targets' => []])->assertOk();
    pfwPut(['targets' => []])->assertOk();

    expect(AuditEntry::query()->where('action', 'portal.forwarding_changed')->count())->toBe(2);
});

it('files the forwarding rows under the activity log\'s mailboxes group', function () {
    pfwPut(['targets' => ['john@example.org']])->assertOk();

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['is_server_admin' => true]), 'web');
    $this->getJson('/api/v1/audit-log?action_group=mailboxes')->assertOk()
        ->assertJsonPath('data.*.action', ['portal.forwarding_changed'])
        ->assertJsonPath('data.0.actor_kind', 'mailbox');
    $this->getJson('/api/v1/audit-log?action_group=forwarding')->assertOk()->assertJsonPath('data', []);
});

it('shows the owner\'s change in a console admin\'s forwarding list', function () {
    pfwPut(['targets' => ['olga@other.example'], 'keep_copy' => true])->assertOk();

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['is_server_admin' => true]), 'web');
    $this->getJson('/api/v1/domains/example.org/forwarding?q=anna')->assertOk()
        ->assertJsonPath('data.0.source', 'anna@example.org')
        ->assertJsonPath('data.0.keep_copy', true)
        ->assertJsonPath('data.0.targets', [['address' => 'olga@other.example', 'kind' => 'mailbox']]);
});

it('changes nothing for a mailbox deleted since the request signed in', function () {
    $gone = new MailboxPrincipal('gone@example.org', 10, 'example.org', 0, '{BLF-CRYPT}x');

    expect(app(AliasRepository::class)->replaceOwn($gone, [], true))->toBeFalse();
    expect(pfwRows('gone@example.org'))->toBe([]);
});
