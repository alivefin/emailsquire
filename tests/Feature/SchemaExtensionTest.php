<?php

use App\Doctor\Support\AliasMaps;
use App\MailServer\CatchAllSupport;
use App\MailServer\GuideSchema;
use App\MailServer\SchemaExtension;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;
use Tests\Support\TempTree;

/*
 * The optional schema extension (backend plan section 10): `enabled` and `sendonly` on virtual_users,
 * applied by the server admin on purpose. The app switches the two mailbox switches on only when the
 * columns exist, and never adds them. Each test that adds the columns drops them again afterwards.
 */

beforeEach(function () {
    GuideFixture::load('trixie');
    Cache::flush();
    $this->admin = User::factory()->create(['is_server_admin' => true]);
    $this->actingAs($this->admin);
    $this->url = '/api/v1/domains/example.org/mailboxes/john';
    $this->user = fn (string $email = 'john@example.org') => DB::table(GuideSchema::table('virtual_users'))->where('email', $email);
});

afterEach(function () {
    GuideFixture::dropMailboxExtension();
});

function extensionGrant(User $user, string $role): void
{
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => $role]);
}

it('detects each column of the extension on its own', function () {
    $ext = app(SchemaExtension::class);
    expect($ext->has('enabled'))->toBeFalse()->and($ext->has('sendonly'))->toBeFalse();

    GuideFixture::addMailboxExtension('sendonly');
    $ext->flush();
    expect($ext->has('enabled'))->toBeFalse()->and($ext->has('sendonly'))->toBeTrue();

    GuideFixture::addMailboxExtension('enabled');
    $ext->flush();
    expect($ext->has('enabled'))->toBeTrue()->and($ext->has('sendonly'))->toBeTrue();
});

it('refuses a column name that is not part of the extension', function () {
    expect(fn () => app(SchemaExtension::class)->has('quota'))->toThrow(InvalidArgumentException::class);
});

it('remembers the answer for 10 minutes and looks again after that', function () {
    $ext = app(SchemaExtension::class);
    expect($ext->has('enabled'))->toBeFalse();

    GuideFixture::addMailboxExtension();
    expect($ext->has('enabled'))->toBeFalse();

    $this->travel(9)->minutes();
    expect($ext->has('enabled'))->toBeFalse();

    $this->travel(2)->minutes();
    expect($ext->has('enabled'))->toBeTrue();
});

it('flush() forgets what was remembered', function () {
    $ext = app(SchemaExtension::class);
    expect($ext->has('sendonly'))->toBeFalse();
    GuideFixture::addMailboxExtension();

    $ext->flush();

    expect($ext->has('sendonly'))->toBeTrue();
});

it('GET /me tells the console what the server supports', function () {
    Cache::put(CatchAllSupport::CACHE_KEY, AliasMaps::MISSING, 600);
    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('capabilities', ['schema_extension' => ['receiving' => false, 'sendonly' => false], 'catch_all' => false]);

    GuideFixture::addMailboxExtension('enabled');
    app(SchemaExtension::class)->flush();
    Cache::put(CatchAllSupport::CACHE_KEY, AliasMaps::SUPPORTED, 600);
    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('capabilities', ['schema_extension' => ['receiving' => true, 'sendonly' => false], 'catch_all' => true]);
});

it('lists the switches as true and false per mailbox when the columns exist', function () {
    GuideFixture::addMailboxExtension();
    ($this->user)('mary@example.org')->update(['enabled' => 0, 'sendonly' => 1]);

    $this->getJson($this->url)->assertOk()->assertJsonPath('receiving', true)->assertJsonPath('sendonly', false);
    $this->getJson('/api/v1/domains/example.org/mailboxes/mary')->assertOk()->assertJsonPath('receiving', false)->assertJsonPath('sendonly', true);
    $this->getJson('/api/v1/domains/example.org/mailboxes')->assertOk()
        ->assertJsonPath('data.0.receiving', fn ($v) => is_bool($v));
});

it('gives null for the switches when the columns are missing', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('receiving', null)->assertJsonPath('sendonly', null);
    $this->getJson('/api/v1/domains/example.org/mailboxes')->assertOk()->assertJsonPath('data.0.receiving', null)->assertJsonPath('data.0.sendonly', null);
});

it('PATCH writes the switches and audits each change with old and new', function () {
    GuideFixture::addMailboxExtension();
    ($this->user)('john@example.org')->update(['quota' => 0]);

    $this->patchJson($this->url, ['receiving' => false, 'sendonly' => true])->assertOk()
        ->assertJsonPath('receiving', false)->assertJsonPath('sendonly', true);

    $row = ($this->user)()->first();
    expect((int) $row->enabled)->toBe(0)->and((int) $row->sendonly)->toBe(1);
    $entries = AuditEntry::query()->orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe(['mailbox.receiving_changed', 'mailbox.sendonly_changed'])
        ->and($entries[0]->object_key)->toBe('john@example.org')->and($entries[0]->domain_name)->toBe('example.org')
        ->and($entries[0]->changes)->toBe(['old' => true, 'new' => false])
        ->and($entries[1]->changes)->toBe(['old' => false, 'new' => true]);

    $this->patchJson($this->url, ['receiving' => true, 'sendonly' => false])->assertOk()
        ->assertJsonPath('receiving', true)->assertJsonPath('sendonly', false);
    expect((int) ($this->user)()->value('enabled'))->toBe(1)->and(AuditEntry::count())->toBe(4);
});

it('PATCH combines the switches with a quota change without mixing up the audit entries', function () {
    GuideFixture::addMailboxExtension();

    $this->patchJson($this->url, ['quota_bytes' => 3145728, 'receiving' => false])->assertOk();

    expect(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['mailbox.update', 'mailbox.receiving_changed'])
        ->and(AuditEntry::query()->where('action', 'mailbox.update')->sole()->changes)->toBe(['quota_bytes' => ['old' => 5368709120, 'new' => 3145728]]);
});

it('PATCH with the values already stored writes and audits nothing', function () {
    GuideFixture::addMailboxExtension();

    $this->patchJson($this->url, ['receiving' => true, 'sendonly' => false])->assertOk();

    expect(AuditEntry::count())->toBe(0);
});

it('stopping receiving leaves the forwarding rows alone', function () {
    GuideFixture::addMailboxExtension();
    $aliases = fn () => DB::table(GuideSchema::table('virtual_aliases'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $aliases();

    $this->patchJson($this->url, ['receiving' => false, 'sendonly' => true])->assertOk();

    expect($aliases())->toBe($before);
});

it('PATCH answers 422 schema_extension_missing on the field when its column is absent', function (string $field, array $columns) {
    if ($columns !== []) {
        GuideFixture::addMailboxExtension(...$columns);
    }
    app(SchemaExtension::class)->flush();

    $this->patchJson($this->url, [$field => true, 'quota_bytes' => 3145728])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'schema_extension_missing')
        ->assertJsonStructure(['errors' => [$field]]);

    // Nothing of the request was applied, the quota included.
    expect(($this->user)()->value('quota'))->toBe(0)->and(AuditEntry::count())->toBe(0);
})->with([
    'receiving, no columns' => ['receiving', []],
    'sendonly, no columns' => ['sendonly', []],
    'receiving, only sendonly' => ['receiving', ['sendonly']],
    'sendonly, only enabled' => ['sendonly', ['enabled']],
]);

it('PATCH validates the switches as booleans', function (mixed $value) {
    GuideFixture::addMailboxExtension();

    $this->patchJson($this->url, ['receiving' => $value])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['receiving']]);
})->with([null, 'maybe', [[]]]);

it('lets admins and managers change the switches and refuses viewers and strangers', function (?string $role, int $status) {
    GuideFixture::addMailboxExtension();
    $user = User::factory()->create();
    $this->actingAs($user);
    if ($role !== null) {
        extensionGrant($user, $role);
    }

    $this->patchJson($this->url, ['receiving' => false])->assertStatus($status);

    expect((int) ($this->user)()->value('enabled'))->toBe($status === 200 ? 0 : 1);
})->with([
    'manager' => ['manager', 200],
    'viewer' => ['viewer', 403],
    'no role' => [null, 404],
]);

it('reads the mailbox lists again, without the switches, when a remembered column has been dropped', function () {
    GuideFixture::addMailboxExtension();
    $this->getJson($this->url)->assertOk()->assertJsonPath('receiving', true);

    // The server admin removes the columns; the 10-minute answer still says they exist.
    GuideFixture::dropMailboxExtension();

    $this->getJson($this->url)->assertOk()->assertJsonPath('receiving', null)->assertJsonPath('sendonly', null);
    $this->getJson('/api/v1/domains/example.org/mailboxes')->assertOk()->assertJsonPath('data.0.receiving', null);
    expect(app(SchemaExtension::class)->has('enabled'))->toBeFalse();
});

it('refuses a PATCH cleanly, instead of failing, when a remembered column has been dropped', function () {
    GuideFixture::addMailboxExtension();
    expect(app(SchemaExtension::class)->has('enabled'))->toBeTrue();
    GuideFixture::dropMailboxExtension();

    $this->patchJson($this->url, ['receiving' => false])->assertStatus(422)->assertJsonPath('code', 'schema_extension_missing');
    expect(AuditEntry::count())->toBe(0);
});

it('still counts the gated copy of the email2email map (section 10) as the email2email map for catch-all support', function () {
    $dir = TempTree::create();
    try {
        $cf = fn (string $query) => "user = mailserver\npassword = secret\nhosts = 127.0.0.1\ndbname = mailserver\nquery = {$query}\n";
        file_put_contents("{$dir}/mariadb-virtual-alias-maps.cf", $cf("SELECT destination FROM virtual_aliases WHERE source='%s'"));
        file_put_contents("{$dir}/mariadb-email2email-receiving.cf", $cf("SELECT email FROM virtual_users WHERE email='%s' AND enabled = 1 AND sendonly = 0"));
        file_put_contents("{$dir}/main.cf", "virtual_alias_maps = mysql:{$dir}/mariadb-virtual-alias-maps.cf, mysql:{$dir}/mariadb-email2email-receiving.cf\n");

        expect(AliasMaps::inspect("{$dir}/main.cf"))->toBe(AliasMaps::SUPPORTED);
    } finally {
        TempTree::remove($dir);
    }
});
