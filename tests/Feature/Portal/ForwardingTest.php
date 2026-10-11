<?php

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\MailServer\Repositories\AliasRepository;
use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use App\Models\DomainSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    pfwPut(['targets' => ['john@example.org']])->assertStatus(422)->assertJsonPath('code', 'blocked');
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
