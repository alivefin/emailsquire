<?php

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\Exceptions\SelfServiceDisabled;
use App\MailServer\GuideSchema;
use App\MailServer\HelperTasks;
use App\MailServer\Repositories\AliasRepository;
use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use App\Models\DomainGrant;
use App\Models\DomainSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\GuideFixture;

// GET|POST|DELETE /portal/aliases (spec decision 7, plan Task 4, Review Focus 4): the mailbox owner's
// aliases are the sources on their own domain whose only destination is their mailbox. An owner may add
// `local@<own domain>` while the domain's `aliases` switch is on, at most 20, and never grab an address
// that is a mailbox, blocked, being archived or already forwarding anywhere (AddressClaims, backend
// plan §6 rules 1-5).

const PAL_PASSWORD = 'mailbox passphrase 5';

beforeEach(function () {
    GuideFixture::load('trixie'); // example.org (10): john, mary; jack@example.org -> john
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'other.example']);
    DB::table(GuideSchema::table('virtual_users'))->insert([
        ['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}x', 'quota' => 0],
        ['domain_id' => 11, 'email' => 'olga@other.example', 'password' => '{BLF-CRYPT}x', 'quota' => 0],
    ]);
    palSwitches(['aliases' => true]);
    app(PasswordChecker::class)->accept('anna@example.org', PAL_PASSWORD)->accept('john@example.org', PAL_PASSWORD);
    palSignIn('anna@example.org');
});

function palSignIn(string $email): void
{
    app('auth')->forgetGuards();
    test()->flushSession();
    test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => PAL_PASSWORD])->assertOk()->assertExactJson(['kind' => 'mailbox']);
}

/** @param  array{forwarding?: bool, aliases?: bool, external_forwarding?: bool}  $switches */
function palSwitches(array $switches): void
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

/** @param  list<array{0: string, 1: string}|array{0: string, 1: string, 2: int}>  $rows  [source, destination, domain_id = 10] */
function palInsert(array $rows): void
{
    DB::table(GuideSchema::table('virtual_aliases'))->insert(array_map(
        fn (array $r) => ['source' => $r[0], 'destination' => $r[1], 'domain_id' => $r[2] ?? 10],
        $rows,
    ));
}

/** @return list<array{0: string, 1: string}> */
function palRows(string $source): array
{
    return DB::table(GuideSchema::table('virtual_aliases'))->whereRaw('lower(source) = ?', [$source])->orderBy('id')
        ->get()->map(fn ($r) => [$r->source, $r->destination])->all();
}

function palPost(mixed $local): TestResponse
{
    return test()->postJson('/api/v1/portal/aliases', ['local' => $local]);
}

function palDelete(string $local): TestResponse
{
    return test()->deleteJson('/api/v1/portal/aliases/'.rawurlencode($local));
}

// --- The list --------------------------------------------------------------------------------------

it('lists only the sources on the owner\'s domain whose only destination is the owner', function () {
    palInsert([
        ['sales@example.org', 'anna@example.org'],
        ['Info@Example.org', 'Anna@Example.org'],         // legacy spelling: still hers
        ['shared@example.org', 'anna@example.org'],       // also delivers to john: not only hers
        ['shared@example.org', 'john@example.org'],
        ['help@other.example', 'anna@example.org', 11],   // another domain
        ['mary@example.org', 'anna@example.org'],         // a mailbox redirected to her: mary's, not an alias
        ['@example.org', 'anna@example.org'],             // a catch-all: admins and managers only
        ['anna@example.org', 'anna@example.org'],         // her own keep-copy row
        ['anna@example.org', 'out@gmail.com'],
    ]);

    $this->getJson('/api/v1/portal/aliases')->assertOk()->assertExactJson(['data' => [
        ['local' => 'info', 'address' => 'info@example.org'],
        ['local' => 'sales', 'address' => 'sales@example.org'],
    ]]);
});

it('does not list another mailbox\'s aliases', function () {
    palInsert([['sales@example.org', 'anna@example.org']]);

    $this->getJson('/api/v1/portal/aliases')->assertOk()->assertJsonPath('data.*.address', ['sales@example.org']);

    palSignIn('john@example.org');
    $this->getJson('/api/v1/portal/aliases')->assertOk()->assertExactJson(['data' => [['local' => 'jack', 'address' => 'jack@example.org']]]);
});

// --- Adding ----------------------------------------------------------------------------------------

it('adds an alias on the owner\'s domain that delivers to the owner', function () {
    palPost(' Sales ')->assertCreated()->assertExactJson(['local' => 'sales', 'address' => 'sales@example.org']);

    expect(palRows('sales@example.org'))->toBe([['sales@example.org', 'anna@example.org']])
        ->and(DB::table(GuideSchema::table('virtual_aliases'))->where('source', 'sales@example.org')->value('domain_id'))->toBe(10);
    $this->getJson('/api/v1/portal/aliases')->assertJsonPath('data.*.address', ['sales@example.org']);
});

it('audits adding and removing with the mailbox as actor', function () {
    palPost('sales')->assertCreated();
    palDelete('sales')->assertNoContent();

    $rows = AuditEntry::query()->whereIn('action', ['portal.alias_created', 'portal.alias_deleted'])->orderBy('id')->get();
    expect($rows->map(fn (AuditEntry $e) => $e->only(['action', 'actor_type', 'actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->all())->toBe([
        ['action' => 'portal.alias_created', 'actor_type' => 'mailbox', 'actor_id' => 'anna@example.org', 'object_type' => 'forwarding',
            'object_key' => 'sales@example.org', 'domain_name' => 'example.org', 'changes' => ['destination' => 'anna@example.org']],
        ['action' => 'portal.alias_deleted', 'actor_type' => 'mailbox', 'actor_id' => 'anna@example.org', 'object_type' => 'forwarding',
            'object_key' => 'sales@example.org', 'domain_name' => 'example.org', 'changes' => ['destination' => 'anna@example.org']],
    ]);
});

/** Every owner-facing refusal of an address is the same answer, whatever the reason (Ruling C). */
function palUnavailable(TestResponse $response): TestResponse
{
    return $response->assertStatus(422)
        ->assertJsonPath('code', 'address_unavailable')
        ->assertJsonPath('detail', 'This address isn’t available.')
        ->assertJsonPath('errors', ['local' => ['This address isn’t available.']]);
}

it('refuses a source that is a mailbox, the owner\'s own included', function (string $local) {
    palUnavailable(palPost($local));
    expect(palRows(strtolower($local).'@example.org'))->toBe([]);
})->with(['john', 'anna', 'JOHN']);

it('refuses a source that already forwards anywhere', function (string $local) {
    palInsert([
        ['shared@example.org', 'anna@example.org'],
        ['shared@example.org', 'john@example.org'],
        ['mine@example.org', 'anna@example.org'],
    ]);
    $before = palRows("{$local}@example.org");

    palUnavailable(palPost($local));
    expect(palRows("{$local}@example.org"))->toBe($before);
})->with([
    'someone else\'s alias' => 'jack',
    'shared with someone else' => 'shared',
    'already the owner\'s alias' => 'mine',
]);

it('refuses a blocked source without telling the owner it is blocked or why', function () {
    BlockedAddress::create(['address' => 'promo@example.org', 'reason' => 'spam', 'created_by' => null]);

    palUnavailable(palPost('promo'))->assertDontSee('spam')->assertDontSee('blocked');
    expect(palRows('promo@example.org'))->toBe([]);
});

it('refuses a source still being archived with the same answer', function () {
    $n = app(AddressNormalizer::class);
    app(HelperTasks::class)->queueArchive($n->addressIn('gone', $n->domain('example.org')));

    palUnavailable(palPost('gone'));
    expect(palRows('gone@example.org'))->toBe([]);
});

it('refuses the reserved local parts to owners', function (string $local) {
    palUnavailable(palPost($local));
    expect(palRows(strtolower($local).'@example.org'))->toBe([]);
})->with(['postmaster', 'abuse', 'hostmaster', 'webmaster', 'admin', 'administrator', 'root', 'security', 'mailer-daemon', 'noc', 'ssladmin', 'ssl-admin', 'info', 'PostMaster']);

it('still lets a manager forward a reserved address from the console', function () {
    $manager = User::factory()->create(['password_set_at' => now()]);
    DomainGrant::query()->forceCreate(['user_id' => $manager->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => 'manager']);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->actingAs($manager, 'web');

    $this->putJson('/api/v1/domains/example.org/forwarding/postmaster', ['targets' => ['anna@example.org']])->assertCreated();
    expect(palRows('postmaster@example.org'))->toBe([['postmaster@example.org', 'anna@example.org']]);
});

it('refuses an alias that would take mail from a catch-all that goes to someone else', function () {
    palInsert([['@example.org', 'boss@other.example']]);

    palUnavailable(palPost('fresh'));
    expect(palRows('fresh@example.org'))->toBe([]);
});

it('refuses an alias while a mixed-case catch-all goes to someone else', function () {
    // The guide's collation folds case: `@Example.org` is the catch-all of example.org.
    palInsert([['@Example.org', 'boss@other.example']]);

    palUnavailable(palPost('fresh'));
    expect(palRows('fresh@example.org'))->toBe([]);
});

it('refuses an alias the owner forwards to: the mail would loop', function () {
    palInsert([['anna@example.org', 'Travel@Example.org'], ['anna@example.org', 'anna@example.org']]);

    palUnavailable(palPost('travel'));
    expect(palRows('travel@example.org'))->toBe([]);
    palPost('other')->assertCreated();
});

it('allows an alias when the catch-all already goes only to the owner', function () {
    palInsert([['@Example.org', 'Anna@example.org']]);

    palPost('fresh')->assertCreated();
    expect(palRows('fresh@example.org'))->toBe([['fresh@example.org', 'anna@example.org']]);
});

it('answers the unavailable address in Finnish', function () {
    $this->withHeader('Accept-Language', 'fi');

    palPost('john')->assertStatus(422)->assertJsonPath('errors.local', ['Tämä osoite ei ole käytettävissä.']);
});

it('only takes a local part on the owner\'s own domain, never a catch-all or another domain', function (mixed $local) {
    palPost($local)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['local']]);
    expect(DB::table(GuideSchema::table('virtual_aliases'))->where('destination', 'anna@example.org')->count())->toBe(0);
})->with([
    'another domain' => 'help@other.example',
    'catch-all' => '@example.org',
    'empty' => '',
    'missing' => null,
    'plus' => 'me+tag',
    'not ascii' => 'mäki',
    'double dot' => 'a..b',
    'too long' => str_repeat('a', 89), // 89 + "@example.org" = 101
    'not a string' => [['a']],
]);

it('accepts a local part of the longest length', function () {
    palPost(str_repeat('a', 88))->assertCreated();
});

it('accepts 20 aliases and refuses the 21st', function () {
    palInsert(array_map(fn (int $i) => ["a{$i}@example.org", 'anna@example.org'], range(1, 19)));
    // Sources that are not hers don't count.
    palInsert([['jack2@example.org', 'john@example.org'], ['shared@example.org', 'anna@example.org'], ['shared@example.org', 'john@example.org']]);

    palPost('a20')->assertCreated();
    palPost('a21')->assertStatus(422)->assertJsonPath('code', 'too_many_aliases')
        ->assertJsonPath('errors.local', ['You can have at most 20 aliases.']);
    expect(palRows('a21@example.org'))->toBe([]);
});

it('refuses adding and removing while aliases are off, and still lists', function () {
    palInsert([['sales@example.org', 'anna@example.org']]);
    palSwitches(['aliases' => false]);

    palPost('new')->assertForbidden()->assertJsonPath('code', 'self_service_disabled');
    palPost('')->assertForbidden()->assertJsonPath('code', 'self_service_disabled');
    palDelete('sales')->assertForbidden()->assertJsonPath('code', 'self_service_disabled');
    $this->getJson('/api/v1/portal/aliases')->assertOk()->assertJsonPath('data.*.address', ['sales@example.org']);
    expect(palRows('sales@example.org'))->toBe([['sales@example.org', 'anna@example.org']])
        ->and(palRows('new@example.org'))->toBe([]);
});

it('is off by default', function () {
    DomainSetting::query()->whereKey(10)->delete();

    palPost('new')->assertForbidden()->assertJsonPath('code', 'self_service_disabled');
});

it('answers in Finnish', function () {
    palInsert(array_map(fn (int $i) => ["a{$i}@example.org", 'anna@example.org'], range(1, 20)));
    $this->withHeader('Accept-Language', 'fi');

    palPost('a21')->assertStatus(422)->assertJsonPath('errors.local', ['Sinulla voi olla enintään 20 aliasta.']);
});

// --- Removing --------------------------------------------------------------------------------------

it('removes the owner\'s alias, legacy spelling included', function () {
    palInsert([['Info@Example.org', 'Anna@Example.org'], ['sales@example.org', 'anna@example.org']]);

    palDelete('INFO')->assertNoContent();

    expect(palRows('info@example.org'))->toBe([])
        ->and(palRows('sales@example.org'))->toBe([['sales@example.org', 'anna@example.org']]);
});

it('answers 404 for a source that is not the owner\'s alias, and changes nothing', function (string $local) {
    palInsert([
        ['shared@example.org', 'anna@example.org'],
        ['shared@example.org', 'john@example.org'],
        ['mary@example.org', 'anna@example.org'],
        ['anna@example.org', 'anna@example.org'],
        ['anna@example.org', 'john@example.org'],
    ]);
    $before = DB::table(GuideSchema::table('virtual_aliases'))->orderBy('id')->get()->all();

    palDelete($local)->assertNotFound()->assertJsonPath('code', 'not_found');

    expect(DB::table(GuideSchema::table('virtual_aliases'))->orderBy('id')->get()->all())->toEqual($before);
    expect(AuditEntry::query()->where('action', 'portal.alias_deleted')->exists())->toBeFalse();
})->with([
    'someone else\'s alias' => 'jack',
    'shared with someone else' => 'shared',
    'a mailbox redirected to the owner' => 'mary',
    'the owner\'s own address' => 'anna',
    'unknown' => 'nothing',
    'invalid' => 'a..b',
]);

it('treats a source whose rows on another domain_id go elsewhere as not the owner\'s, in the list and on delete', function () {
    palInsert([['split@example.org', 'anna@example.org'], ['split@example.org', 'john@example.org', 11]]);

    $this->getJson('/api/v1/portal/aliases')->assertOk()->assertExactJson(['data' => []]);
    palDelete('split')->assertNotFound();
    expect(palRows('split@example.org'))->toHaveCount(2);
});

it('removes an alias whose rows sit on more than one domain_id when they all go to the owner', function () {
    palInsert([['split@example.org', 'anna@example.org'], ['split@example.org', 'Anna@example.org', 11]]);

    $this->getJson('/api/v1/portal/aliases')->assertJsonPath('data.*.address', ['split@example.org']);
    palDelete('split')->assertNoContent();
    expect(palRows('split@example.org'))->toBe([]);
});

it('checks the switch again in the repository, whatever the route did', function () {
    palInsert([['sales@example.org', 'anna@example.org']]);
    palSwitches(['aliases' => false]);
    $owner = new MailboxPrincipal('anna@example.org', 10, 'example.org', 0, '{BLF-CRYPT}x');
    $n = app(AddressNormalizer::class);
    $aliases = app(AliasRepository::class);

    expect(fn () => $aliases->addOwnAlias($owner, $n->addressIn('new', $n->domain('example.org'))))->toThrow(SelfServiceDisabled::class);
    expect(fn () => $aliases->removeOwnAlias($owner, $n->addressIn('sales', $n->domain('example.org'))))->toThrow(SelfServiceDisabled::class);
    expect(palRows('new@example.org'))->toBe([])
        ->and(palRows('sales@example.org'))->toBe([['sales@example.org', 'anna@example.org']]);
});

// --- The console -----------------------------------------------------------------------------------

it('files the alias rows under the activity log\'s mailboxes group', function () {
    palPost('sales')->assertCreated();
    palDelete('sales')->assertNoContent();

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['is_server_admin' => true]), 'web');
    $this->getJson('/api/v1/audit-log?action_group=mailboxes')->assertOk()
        ->assertJsonPath('data.*.action', ['portal.alias_deleted', 'portal.alias_created']);
    $this->getJson('/api/v1/audit-log?action_group=forwarding')->assertOk()->assertJsonPath('data', []);
});

it('shows the owner\'s alias in a console admin\'s forwarding list', function () {
    palPost('sales')->assertCreated();

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->actingAs(User::factory()->create(['is_server_admin' => true]), 'web');
    $this->getJson('/api/v1/domains/example.org/forwarding?q=sales')->assertOk()
        ->assertJsonPath('data.0.source', 'sales@example.org')
        ->assertJsonPath('data.0.targets', [['address' => 'anna@example.org', 'kind' => 'mailbox']]);
});
