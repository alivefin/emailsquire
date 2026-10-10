<?php

use App\Auth\DomainAccess;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\PinnedDomain;
use App\Models\ServerStorage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthorizationMatrix as Matrix;

// What a signed-in user sees in lists, search and the dashboard: only the domains they hold a role on.

function scopeAudit(string $action, ?string $domain, string $key, array $over = []): void
{
    AuditEntry::create($over + [
        'occurred_at' => now(), 'actor_type' => 'cli', 'actor_id' => null, 'ip' => null,
        'action' => $action, 'object_type' => 'mailbox', 'object_key' => $key, 'domain_name' => $domain,
    ]);
}

beforeEach(function () {
    $this->principals = Matrix::seed();
    $guide = fn (string $t) => DB::table(GuideSchema::table($t));

    $guide('virtual_users')->insert(['domain_id' => Matrix::A_ID, 'email' => 'mary@a.org', 'password' => 'x', 'quota' => 0]);
    $guide('virtual_aliases')->insert([
        ['domain_id' => Matrix::A_ID, 'source' => 'ext@a.org', 'destination' => 'out@gmail.com'],
        // On the server, but on a domain the manager can't see: external to them.
        ['domain_id' => Matrix::A_ID, 'source' => 'cross@a.org', 'destination' => 'john@b.org'],
    ]);
    $kib = 5242880;
    DB::table('mailbox_usage')->insert([
        ['email' => 'john@a.org', 'used_kib' => (int) ($kib * 0.9), 'limit_kib' => $kib, 'checked_at' => '2026-10-10 12:00:00'],
        ['email' => 'john@b.org', 'used_kib' => (int) ($kib * 0.9), 'limit_kib' => $kib, 'checked_at' => '2026-10-10 10:00:00'],
    ]);
    ServerStorage::query()->forceCreate(['id' => 1, 'total_bytes' => 1000, 'free_bytes' => 400, 'checked_at' => now()]);

    scopeAudit('domain.create', 'a.org', 'a.org', ['object_type' => 'domain', 'occurred_at' => now()->subMinutes(5)]);
    scopeAudit('domain.create', 'b.org', 'b.org', ['object_type' => 'domain', 'occurred_at' => now()->subMinutes(4)]);
    scopeAudit('mailbox.create', 'a.org', 'mary@a.org', ['occurred_at' => now()->subMinutes(3)]);
    scopeAudit('mailbox.create', 'b.org', 'john@b.org', ['occurred_at' => now()->subMinutes(2)]);
    scopeAudit('user.sessions_revoked', null, 'admin@console.test', ['object_type' => 'user', 'occurred_at' => now()->subMinute()]);

    foreach (['a.org' => Matrix::A_ID, 'b.org' => Matrix::B_ID] as $name => $id) {
        PinnedDomain::create(['user_id' => $this->principals['manager']->id, 'domain_id' => $id, 'domain_name' => $name, 'position' => $id]);
    }
});

it('a manager lists only their domains, with their role on each', function () {
    $this->actingAs($this->principals['manager']);

    $this->getJson('/api/v1/domains')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'a.org')
        ->assertJsonPath('data.0.role', 'manager');
    $this->getJson('/api/v1/domains?q=b.org')->assertOk()->assertJsonPath('meta.total', 0);

    $this->getJson('/api/v1/domains/names')->assertOk()->assertExactJson(['data' => [
        ['name' => 'a.org', 'unicode_name' => 'a.org', 'mailbox_count' => 2, 'role' => 'manager'],
    ]]);
});

it('domain rows carry admin for a server admin and viewer for a viewer', function () {
    $this->actingAs($this->principals['admin']);
    expect(collect($this->getJson('/api/v1/domains/names')->json('data'))->pluck('role', 'name')->all())
        ->toBe(['a.org' => 'admin', 'b.org' => 'admin', 'example.org' => 'admin']);
    $this->getJson('/api/v1/domains?q=a.org')->assertJsonPath('data.0.role', 'admin');

    $this->actingAs($this->principals['viewer']);
    $this->getJson('/api/v1/domains')->assertJsonPath('data.0.role', 'viewer');
});

it('a manager finds only their own addresses, in the list and in search', function () {
    $this->actingAs($this->principals['manager']);

    $list = $this->getJson('/api/v1/addresses?limit=200')->assertOk();
    expect(collect($list->json('data'))->pluck('domain')->unique()->values()->all())->toBe(['a.org'])
        ->and($list->json('meta.total'))->toBe(5);
    $this->getJson('/api/v1/addresses?domain=b.org')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

    $search = $this->getJson('/api/v1/search?q=.org')->assertOk();
    expect($search->json('domains'))->toBe([['name' => 'a.org']])
        ->and(collect($search->json('addresses'))->pluck('domain')->unique()->values()->all())->toBe(['a.org']);
});

it('a manager\'s dashboard counts a.org alone and hides the server-wide figures', function () {
    $this->actingAs($this->principals['manager']);

    $response = $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('counts', [
            'domains' => 1,
            'domains_added_this_month' => 1,
            'mailboxes' => 2,
            // jack → john (local), ext → gmail, cross → john@b.org (b.org is not theirs: external)
            'forwarding' => 3,
            'forwarding_local' => 1,
            'forwarding_external' => 2,
        ])
        ->assertJsonPath('near_full.count', 1)
        ->assertJsonPath('near_full.items.0.address', 'john@a.org')
        ->assertJsonPath('server_storage', null)
        ->assertJsonPath('usage_checked_at', '2026-10-10T12:00:00+00:00');

    expect(collect($response->json('recent_activity'))->pluck('object_key')->all())->toBe(['mary@a.org', 'a.org']);
});

it('an admin\'s dashboard still counts everything', function () {
    $this->actingAs($this->principals['admin']);

    $response = $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('counts.domains', 3)
        ->assertJsonPath('counts.domains_added_this_month', 2)
        ->assertJsonPath('counts.mailboxes', 5)
        // jack@example.org, jack@a, cross (john@b.org is on the server), jack@b local; ext external.
        ->assertJsonPath('counts.forwarding', 5)
        ->assertJsonPath('counts.forwarding_local', 4)
        ->assertJsonPath('counts.forwarding_external', 1)
        ->assertJsonPath('near_full.count', 2)
        ->assertJsonPath('server_storage.total_bytes', 1000)
        ->assertJsonPath('usage_checked_at', '2026-10-10T10:00:00+00:00');

    expect($response->json('recent_activity'))->toHaveCount(5);
});

it('pinned domains and blocked addresses show only the manager\'s domains', function () {
    $this->actingAs($this->principals['manager']);

    expect(collect($this->getJson('/api/v1/me/pinned-domains')->assertOk()->json('data'))->pluck('name')->all())->toBe(['a.org']);
    $this->getJson('/api/v1/blocked-addresses')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.address', 'spam@a.org');
    $this->getJson('/api/v1/blocked-addresses?q=b.org')->assertOk()->assertJsonPath('meta.total', 0);
});

it('a user with no grant gets empty lists and zero counts', function () {
    $this->actingAs($this->principals['none']);

    $this->getJson('/api/v1/domains')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/domains/names')->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/search?q=.org')->assertOk()->assertExactJson(['domains' => [], 'addresses' => []]);
    $this->getJson('/api/v1/me/pinned-domains')->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/api/v1/blocked-addresses')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('counts', ['domains' => 0, 'domains_added_this_month' => 0, 'mailboxes' => 0, 'forwarding' => 0, 'forwarding_local' => 0, 'forwarding_external' => 0])
        ->assertJsonPath('near_full', ['count' => 0, 'items' => []])
        ->assertJsonPath('recent_activity', [])
        ->assertJsonPath('server_storage', null)
        ->assertJsonPath('usage_checked_at', null);
});

it('forwarding into a domain the manager can\'t see reads as external to them', function () {
    $this->actingAs($this->principals['manager']);
    $kinds = fn () => collect($this->getJson('/api/v1/domains/a.org/forwarding?q=cross')->assertOk()->json('data.0.targets'))->pluck('kind')->all();

    expect($kinds())->toBe(['external']);
    $this->getJson('/api/v1/domains/a.org/forwarding?kind=external')->assertOk()->assertJsonPath('meta.total', 2);

    $this->actingAs($this->principals['admin']);
    expect($kinds())->toBe(['mailbox']);
    $this->getJson('/api/v1/domains/a.org/forwarding?kind=external')->assertOk()->assertJsonPath('meta.total', 1);
});

it('a grant removed mid-session answers 404 on the very next request', function () {
    $this->actingAs($this->principals['manager']);
    $this->getJson('/api/v1/domains/a.org/mailboxes')->assertOk();

    DomainGrant::query()->where('user_id', $this->principals['manager']->id)->delete();

    $this->getJson('/api/v1/domains/a.org/mailboxes')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson('/api/v1/domains')->assertOk()->assertJsonPath('meta.total', 0);
});

it('a grant whose domain id now holds another name grants nothing', function () {
    DB::table(GuideSchema::table('virtual_domains'))->where('id', Matrix::A_ID)->update(['name' => 'renamed.org']);
    $this->actingAs($this->principals['manager']);

    $this->getJson('/api/v1/domains/renamed.org/mailboxes')->assertNotFound();
    $this->getJson('/api/v1/domains/names')->assertOk()->assertExactJson(['data' => []]);
});

it('me lands a user with exactly one domain on it', function () {
    $this->actingAs($this->principals['manager']);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('landing', 'a.org');

    Matrix::grant($this->principals['manager'], 'b.org', 'viewer');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('landing', null);

    $this->actingAs($this->principals['admin']);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('landing', null);

    $this->actingAs($this->principals['none']);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('landing', null);
});

it('me says whether managers may share', function (bool $setting) {
    config(['emailsquire.managers_can_share' => $setting]);
    $this->actingAs($this->principals['viewer']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('managers_can_share', $setting);
})->with([true, false]);

it('managers may not share by default', function () {
    expect(config('emailsquire.managers_can_share'))->toBeFalse();
});

it('a deactivated user has no role anywhere', function () {
    $access = app(DomainAccess::class);
    $manager = $this->principals['manager'];
    $admin = $this->principals['admin'];
    $domain = Domain::query()->findOrFail(Matrix::A_ID);
    expect($access->role($manager, $domain))->toBe('manager')
        ->and($access->role($admin, $domain))->toBe('admin');

    $manager->forceFill(['status' => 'deactivated']);
    $admin->forceFill(['status' => 'deactivated']);
    app()->forgetScopedInstances();
    $access = app(DomainAccess::class);

    expect($access->role($manager, $domain))->toBeNull()
        ->and($access->role($admin, $domain))->toBeNull()
        ->and($access->isServerAdmin($admin))->toBeFalse()
        ->and($access->scope($admin)->isAll())->toBeFalse();
});

it('only users with a role see a domain through the model scopes', function () {
    expect(Domain::query()->visibleTo($this->principals['viewer'])->pluck('name')->all())->toBe(['a.org'])
        ->and(Domain::query()->visibleTo($this->principals['none'])->count())->toBe(0)
        ->and(Domain::query()->visibleTo($this->principals['admin'])->count())->toBe(3)
        ->and(Mailbox::query()->visibleTo($this->principals['manager'])->orderBy('email')->pluck('email')->all())->toBe(['john@a.org', 'mary@a.org']);
});

/** b.org forwards into john@a.org: fwd@ keeps another target, only@ keeps none. */
function scopeForeignForwards(): void
{
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => Matrix::B_ID, 'source' => 'fwd@b.org', 'destination' => 'john@a.org'],
        ['domain_id' => Matrix::B_ID, 'source' => 'fwd@b.org', 'destination' => 'other@x.net'],
        ['domain_id' => Matrix::B_ID, 'source' => 'only@b.org', 'destination' => 'John@A.org'],
    ]);
}

it('a mailbox\'s forwarding count leaves out sources on domains the reader can\'t see', function () {
    scopeForeignForwards();

    $this->actingAs($this->principals['manager']);
    $this->getJson('/api/v1/domains/a.org/mailboxes/john')->assertOk()->assertJsonPath('forwarding_count', 1);
    expect(collect($this->getJson('/api/v1/domains/a.org/mailboxes')->json('data'))->firstWhere('local', 'john')['forwarding_count'])->toBe(1);
    $this->getJson('/api/v1/dashboard')->assertJsonPath('near_full.items.0.forwarding_count', 1);

    $this->actingAs($this->principals['admin']);
    // jack@a.org, fwd@b.org and only@b.org (its mixed-case row matches under the guide's collation).
    $this->getJson('/api/v1/domains/a.org/mailboxes/john')->assertOk()->assertJsonPath('forwarding_count', 3);
});

it('deleting a mailbox records each foreign source it unhooks under that source\'s own domain', function (string $principal, int $responseCount) {
    Http::fake();
    scopeForeignForwards();
    $this->actingAs($this->principals[$principal]);

    $this->deleteJson('/api/v1/domains/a.org/mailboxes/john')->assertOk()->assertExactJson(['forwarding_removed' => $responseCount]);

    expect(DB::table(GuideSchema::table('virtual_aliases'))->where('domain_id', Matrix::B_ID)->orderBy('id')->get(['source', 'destination'])
        ->map(fn ($r) => [$r->source, $r->destination])->all())
        ->toBe([['jack@b.org', 'john@b.org'], ['fwd@b.org', 'other@x.net']]);

    // a.org's own row counts only a.org's sources (jack@a.org): b.org's people may read it.
    $own = AuditEntry::query()->where('action', 'mailbox.delete')->sole();
    expect($own->domain_name)->toBe('a.org')->and($own->changes)->toBe(['forwarding_removed' => 1]);

    $foreign = AuditEntry::query()->where('domain_name', 'b.org')->where('action', 'like', 'forwarding.%')->orderBy('object_key')->get();
    expect($foreign->map(fn (AuditEntry $e) => [$e->action, $e->object_key, $e->changes])->all())->toBe([
        ['forwarding.replace', 'fwd@b.org', ['old' => ['john@a.org', 'other@x.net'], 'new' => ['other@x.net'], 'keep_copy' => ['old' => false, 'new' => false]]],
        ['forwarding.delete', 'only@b.org', ['removed' => 1, 'old' => ['john@a.org'], 'new' => [], 'keep_copy' => ['old' => false, 'new' => false]]],
    ]);
    expect($foreign->pluck('actor_id')->unique()->values()->all())->toBe([(string) $this->principals[$principal]->id]);
})->with([
    // The manager's count leaves out b.org; the admin's includes both b.org sources.
    'manager' => ['manager', 1],
    'admin' => ['admin', 3],
]);
