<?php

use App\Auth\Grants;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\GuideFixture;

// Grants and the Access API (plan 2c, Task 3): who has a role on a domain, changing it and removing it.
// acc.org (id 60) is shared with a manager, a viewer and Carol (viewer); other.org (id 61) only with Olli.

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off', 'emailsquire.managers_can_share' => false]);
    GuideFixture::load('trixie');
    accGuide('virtual_domains')->insert([['id' => 60, 'name' => 'acc.org'], ['id' => 61, 'name' => 'other.org']]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@console.test', 'is_server_admin' => true, 'password_set_at' => now()]);
    $this->manager = accUser('Mia Manager', 'mia@console.test');
    $this->viewer = accUser('Vic Viewer', 'vic@console.test');
    $this->carol = accUser('Carol Viewer', 'carol@console.test');
    $this->olli = accUser('Olli Other', 'olli@console.test');

    Carbon::setTestNow('2026-10-01 09:00:00');
    accGrant($this->manager, 60, 'acc.org', 'manager', $this->admin);
    accGrant($this->viewer, 60, 'acc.org', 'viewer', $this->manager);
    accGrant($this->carol, 60, 'acc.org', 'viewer', null);
    accGrant($this->olli, 61, 'other.org', 'manager', $this->admin);
    Carbon::setTestNow();
});

function accGuide(string $table): Builder
{
    return DB::table(GuideSchema::table($table));
}

function accUser(string $name, string $email): User
{
    return User::factory()->create(['name' => $name, 'email' => $email, 'password_set_at' => now()]);
}

function accGrant(User $user, int $domainId, string $domainName, string $role, ?User $by): DomainGrant
{
    return DomainGrant::query()->forceCreate([
        'user_id' => $user->id, 'domain_id' => $domainId, 'domain_name' => $domainName, 'role' => $role, 'granted_by' => $by?->id,
    ]);
}

function accRole(User $user, int $domainId = 60): ?string
{
    $role = DomainGrant::query()->where('user_id', $user->id)->where('domain_id', $domainId)->value('role');

    return is_string($role) ? $role : null;
}

function accDomain(int $id = 60): Domain
{
    return Domain::query()->findOrFail($id);
}

it('lists the people with access sorted by name, with who added them and when, and no invitations yet', function () {
    $this->actingAs($this->viewer);

    $this->getJson('/api/v1/domains/acc.org/access')->assertOk()->assertExactJson([
        'people' => [
            ['id' => $this->carol->id, 'name' => 'Carol Viewer', 'email' => 'carol@console.test', 'role' => 'viewer', 'status' => 'active', 'granted_by_name' => null, 'granted_at' => '2026-10-01T09:00:00+00:00'],
            ['id' => $this->manager->id, 'name' => 'Mia Manager', 'email' => 'mia@console.test', 'role' => 'manager', 'status' => 'active', 'granted_by_name' => 'Ada Admin', 'granted_at' => '2026-10-01T09:00:00+00:00'],
            ['id' => $this->viewer->id, 'name' => 'Vic Viewer', 'email' => 'vic@console.test', 'role' => 'viewer', 'status' => 'active', 'granted_by_name' => 'Mia Manager', 'granted_at' => '2026-10-01T09:00:00+00:00'],
        ],
        'invitations' => [],
    ]);
});

it('lists a deactivated grantee with their status and leaves out a grant left from another domain name', function () {
    $this->carol->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
    // A grant on id 60 under a name the guide row no longer carries is not a grant on acc.org.
    accGrant(accUser('Aaron Stale', 'aaron@console.test'), 60, 'gone.org', 'manager', null);
    $this->actingAs($this->admin);

    $people = $this->getJson('/api/v1/domains/acc.org/access')->assertOk()->json('people');

    expect(array_column($people, 'email'))->toBe(['carol@console.test', 'mia@console.test', 'vic@console.test'])
        ->and($people[0]['status'])->toBe('deactivated');
});

it('lets the server admin change a role, returns the updated person and audits grant.changed', function () {
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'manager'])->assertOk()
        ->assertExactJson([
            'id' => $this->viewer->id, 'name' => 'Vic Viewer', 'email' => 'vic@console.test', 'role' => 'manager', 'status' => 'active',
            'granted_by_name' => 'Mia Manager', 'granted_at' => '2026-10-01T09:00:00+00:00',
        ]);

    expect(accRole($this->viewer))->toBe('manager');
    $audit = AuditEntry::query()->where('action', 'grant.changed')->sole();
    expect($audit->only(['actor_type', 'actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'actor_type' => 'user', 'actor_id' => (string) $this->admin->id, 'object_type' => 'user', 'object_key' => 'vic@console.test',
        'domain_name' => 'acc.org', 'changes' => ['role' => ['viewer', 'manager']],
    ]);
});

it('writes nothing and audits nothing when the role is already the one sent', function () {
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'viewer'])->assertOk()->assertJsonPath('role', 'viewer');

    expect(AuditEntry::query()->where('action', 'like', 'grant.%')->exists())->toBeFalse();
});

it('lets the server admin remove someone, answering 204 and auditing grant.removed', function () {
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->manager->id}")->assertNoContent();

    expect(accRole($this->manager))->toBeNull();
    $audit = AuditEntry::query()->where('action', 'grant.removed')->sole();
    expect($audit->only(['object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'object_type' => 'user', 'object_key' => 'mia@console.test', 'domain_name' => 'acc.org', 'changes' => ['role' => 'manager'],
    ]);
});

it('lets a manager with sharing change and remove other grantees, other managers included', function () {
    config(['emailsquire.managers_can_share' => true]);
    $other = accUser('Max Manager', 'max@console.test');
    accGrant($other, 60, 'acc.org', 'manager', $this->admin);
    $this->actingAs($this->manager);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'manager'])->assertOk();
    $this->patchJson("/api/v1/domains/acc.org/access/{$other->id}", ['role' => 'viewer'])->assertOk();
    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->carol->id}")->assertNoContent();
    $this->deleteJson("/api/v1/domains/acc.org/access/{$other->id}")->assertNoContent();

    expect(accRole($this->viewer))->toBe('manager')
        ->and(accRole($this->carol))->toBeNull()
        ->and(accRole($other))->toBeNull()
        ->and(AuditEntry::query()->where('actor_id', (string) $this->manager->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['grant.changed', 'grant.changed', 'grant.removed', 'grant.removed']);
});

it('refuses a change or another person\'s removal from a manager without sharing and from a viewer with 403', function (string $who) {
    $this->actingAs($who === 'manager' ? $this->manager : $this->viewer);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->carol->id}", ['role' => 'manager'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->carol->id}")->assertForbidden()->assertJsonPath('code', 'forbidden');

    expect(accRole($this->carol))->toBe('viewer')
        ->and(AuditEntry::query()->exists())->toBeFalse();
})->with(['manager', 'viewer']);

it('refuses a viewer\'s change of their own role', function () {
    $this->actingAs($this->viewer);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'manager'])->assertForbidden();
    expect(accRole($this->viewer))->toBe('viewer');
});

it('lets a viewer remove their own access, after which the domain answers 404', function () {
    $this->actingAs($this->viewer);
    confirmStepUp();

    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->viewer->id}")->assertNoContent();

    $this->getJson('/api/v1/domains/acc.org/access')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson('/api/v1/domains/acc.org/mailboxes')->assertNotFound();
    expect(AuditEntry::query()->where('action', 'grant.removed')->sole()->only(['actor_id', 'object_key', 'changes']))->toBe([
        'actor_id' => (string) $this->viewer->id, 'object_key' => 'vic@console.test', 'changes' => ['role' => 'viewer'],
    ]);
});

it('drops the removed user\'s pins on that domain in the same change, and keeps their other pins', function () {
    accGrant($this->viewer, 61, 'other.org', 'viewer', $this->admin);
    foreach ([60 => 'acc.org', 61 => 'other.org'] as $id => $name) {
        PinnedDomain::query()->create(['user_id' => $this->viewer->id, 'domain_id' => $id, 'domain_name' => $name, 'position' => $id]);
    }
    PinnedDomain::query()->create(['user_id' => $this->manager->id, 'domain_id' => 60, 'domain_name' => 'acc.org', 'position' => 1]);
    $this->actingAs($this->viewer);
    confirmStepUp();

    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->viewer->id}")->assertNoContent();

    expect(PinnedDomain::query()->where('user_id', $this->viewer->id)->pluck('domain_id')->all())->toBe([61])
        ->and(PinnedDomain::query()->where('user_id', $this->manager->id)->count())->toBe(1);
});

it('asks for step-up (423) before changing or removing a grant, the user\'s own included', function () {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'manager'])->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->viewer->id}")->assertStatus(423);

    $this->actingAs($this->viewer);
    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->viewer->id}")->assertStatus(423);

    expect(accRole($this->viewer))->toBe('viewer')
        ->and(AuditEntry::query()->exists())->toBeFalse();
});

it('answers 404 alike for a user of another domain, a server admin and an id nobody has, with or without step-up', function (bool $stepUp) {
    config(['emailsquire.managers_can_share' => true]);
    $this->actingAs($this->manager);
    if ($stepUp) {
        confirmStepUp();
    }
    $missing = User::query()->max('id') + 100;

    foreach (['patch', 'delete'] as $method) {
        $body = $this->json($method, "/api/v1/domains/acc.org/access/{$missing}", ['role' => 'viewer'])->assertNotFound()->getContent();
        foreach ([$this->olli, $this->admin] as $target) {
            expect($this->json($method, "/api/v1/domains/acc.org/access/{$target->id}", ['role' => 'viewer'])->assertNotFound()->getContent())->toBe($body);
        }
        expect($this->json($method, '/api/v1/domains/acc.org/access/not-a-number', ['role' => 'viewer'])->assertNotFound()->getContent())->toBe($body);
    }

    expect(accRole($this->olli, 61))->toBe('manager')
        ->and(accRole($this->olli))->toBeNull()
        ->and(AuditEntry::query()->exists())->toBeFalse();
})->with(['with step-up' => true, 'without step-up' => false]);

it('answers 404 for a domain the caller has no role on, whoever the target is', function () {
    $this->actingAs($this->olli);
    confirmStepUp();

    $this->getJson('/api/v1/domains/acc.org/access')->assertNotFound();
    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => 'manager'])->assertNotFound();
    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->olli->id}")->assertNotFound();
});

it('validates the role', function (mixed $role) {
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->viewer->id}", ['role' => $role])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('role');
})->with(['admin', 'owner', null, '']);

it('refuses with 409 already_has_access to change a grant a server admin still holds, and lets it be removed', function () {
    // Promoted after being granted: the grant gives a server admin nothing.
    $this->carol->forceFill(['is_server_admin' => true])->save();
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->patchJson("/api/v1/domains/acc.org/access/{$this->carol->id}", ['role' => 'manager'])->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'already_has_access');
    expect(accRole($this->carol))->toBe('viewer')
        ->and(AuditEntry::query()->exists())->toBeFalse();

    $this->deleteJson("/api/v1/domains/acc.org/access/{$this->carol->id}")->assertNoContent();
    expect(accRole($this->carol))->toBeNull()
        ->and(AuditEntry::query()->sole()->only(['action', 'object_key', 'domain_name', 'changes']))->toBe([
            'action' => 'grant.removed', 'object_key' => 'carol@console.test', 'domain_name' => 'acc.org', 'changes' => ['role' => 'viewer'],
        ]);
});

it('neither lists nor counts a grant left on a server admin', function () {
    $this->carol->forceFill(['is_server_admin' => true])->save();
    $this->actingAs($this->admin);

    $people = $this->getJson('/api/v1/domains/acc.org/access')->assertOk()->json('people');
    expect(array_column($people, 'email'))->toBe(['mia@console.test', 'vic@console.test']);

    $row = collect($this->getJson('/api/v1/domains')->assertOk()->json('data'))->firstWhere('name', 'acc.org');
    expect($row['people_count'])->toBe(2)
        ->and($row['people'])->toBe(['Mia Manager', 'Vic Viewer']);
});

it('sorts and names a person with an empty name by their email, and a granter the same way', function () {
    $this->manager->forceFill(['name' => ''])->save();
    $zed = accUser('', 'zz@console.test');
    accGrant($zed, 60, 'acc.org', 'viewer', null);
    $this->actingAs($this->admin);

    $people = $this->getJson('/api/v1/domains/acc.org/access')->assertOk()->json('people');

    // Carol, mia@ (no name), Vic, zz@ (no name): by name, or by email where the name is empty.
    expect(array_column($people, 'email'))->toBe(['carol@console.test', 'mia@console.test', 'vic@console.test', 'zz@console.test'])
        ->and($people[1]['name'])->toBe('')
        ->and($people[2]['granted_by_name'])->toBe('mia@console.test');
});

it('shows no granter once the user who gave the grant is deleted', function () {
    $this->admin->delete();
    $other = User::factory()->create(['is_server_admin' => true, 'password_set_at' => now()]);
    $this->actingAs($other);

    $people = collect($this->getJson('/api/v1/domains/acc.org/access')->assertOk()->json('people'))->keyBy('email');
    expect($people['mia@console.test']['granted_by_name'])->toBeNull()
        ->and($people['vic@console.test']['granted_by_name'])->toBe('Mia Manager');
});

it('Grants::put creates a grant with who gave it and audits grant.created', function () {
    $newbie = accUser('Nina New', 'nina@console.test');
    $this->actingAs($this->admin);

    expect(app(Grants::class)->put($newbie, accDomain(), 'viewer', $this->admin))->toBe('created');

    expect(DomainGrant::query()->where('user_id', $newbie->id)->sole()->only(['domain_id', 'domain_name', 'role', 'granted_by']))->toBe([
        'domain_id' => 60, 'domain_name' => 'acc.org', 'role' => 'viewer', 'granted_by' => $this->admin->id,
    ]);
    expect(AuditEntry::query()->where('action', 'grant.created')->sole()->only(['object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'object_type' => 'user', 'object_key' => 'nina@console.test', 'domain_name' => 'acc.org', 'changes' => ['role' => 'viewer'],
    ]);
    $this->getJson('/api/v1/domains')->assertOk();
});

it('Grants::put answers changed and unchanged, and replaces a grant left from another domain name as created', function () {
    $grants = app(Grants::class);

    expect($grants->put($this->viewer, accDomain(), 'manager', $this->admin))->toBe('changed')
        ->and($grants->put($this->viewer, accDomain(), 'manager', $this->admin))->toBe('unchanged');

    // Olli's row on id 61 was given on other.org; the guide row there now carries a new name.
    accGuide('virtual_domains')->where('id', 61)->update(['name' => 'renamed.org']);
    expect($grants->put($this->olli, accDomain(61), 'viewer', $this->admin))->toBe('created');
    expect(DomainGrant::query()->where('user_id', $this->olli->id)->sole()->only(['domain_name', 'role', 'granted_by']))
        ->toBe(['domain_name' => 'renamed.org', 'role' => 'viewer', 'granted_by' => $this->admin->id]);
    expect(AuditEntry::query()->orderBy('id')->get()->map(fn (AuditEntry $e) => [$e->action, $e->domain_name, $e->changes])->all())->toBe([
        ['grant.changed', 'acc.org', ['role' => ['viewer', 'manager']]],
        ['grant.created', 'renamed.org', ['role' => 'viewer']],
    ]);
});

it('Grants refuses a server admin target of put or change with Conflict already_has_access and writes nothing', function (string $call) {
    $grants = app(Grants::class);

    expect(fn () => $call === 'put' ? $grants->put($this->admin, accDomain(), 'viewer', $this->admin) : $grants->change($this->admin, accDomain(), 'viewer'))
        ->toThrow(fn (Conflict $e) => expect($e->errorCode)->toBe('already_has_access'));
    expect(DomainGrant::query()->where('user_id', $this->admin->id)->exists())->toBeFalse()
        ->and(AuditEntry::query()->exists())->toBeFalse();
})->with(['put', 'change']);

it('Grants::remove removes a grant left on a server admin and audits grant.removed', function () {
    // Promotion (Task 5) may remove a user's grants in the transaction that sets is_server_admin.
    $this->carol->forceFill(['is_server_admin' => true])->save();

    expect(app(Grants::class)->remove($this->carol, accDomain()))->toBeTrue()
        ->and(accRole($this->carol))->toBeNull()
        ->and(AuditEntry::query()->sole()->only(['action', 'object_key', 'changes']))
        ->toBe(['action' => 'grant.removed', 'object_key' => 'carol@console.test', 'changes' => ['role' => 'viewer']]);
});

it('Grants::change returns the person as written', function () {
    expect(app(Grants::class)->change($this->viewer, accDomain(), 'manager'))->toMatchArray([
        'id' => $this->viewer->id, 'email' => 'vic@console.test', 'role' => 'manager', 'granted_by_name' => 'Mia Manager',
    ]);
});

it('Grants::put refuses an unknown role', function () {
    expect(fn () => app(Grants::class)->put($this->carol, accDomain(), 'admin', $this->admin))->toThrow(InvalidArgumentException::class);
    expect(accRole($this->carol))->toBe('viewer');
});

it('Grants::remove answers false when there is no grant to remove', function () {
    expect(app(Grants::class)->remove($this->olli, accDomain()))->toBeFalse()
        ->and(AuditEntry::query()->exists())->toBeFalse();
});

it('takes the domain row shared and the target user row FOR UPDATE before reading the grant', function () {
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$sql) {
        $sql[] = strtolower($e->sql);
    });

    app(Grants::class)->put($this->carol, accDomain(), 'manager', $this->admin);

    $domain = collect($sql)->search(fn (string $q) => str_contains($q, 'virtual_domains') && str_contains($q, 'lock in share mode'));
    $user = collect($sql)->search(fn (string $q) => str_contains($q, 'from `users`') && str_contains($q, 'for update'));
    $grant = collect($sql)->search(fn (string $q) => str_contains($q, 'from `domain_grants`') && str_contains($q, 'for update'));
    expect($domain)->not->toBeFalse(implode("\n", $sql))
        ->and($user)->not->toBeFalse()
        ->and($grant)->not->toBeFalse()
        ->and($domain)->toBeLessThan($user)
        ->and($user)->toBeLessThan($grant);
});

it('shows people_count and the first three names by name on GET /domains, with no query per domain', function () {
    accGuide('virtual_domains')->insert([['id' => 62, 'name' => 'empty.org'], ['id' => 63, 'name' => 'more.org']]);
    accGrant(accUser('Zed Last', 'zed@console.test'), 60, 'acc.org', 'viewer', null);
    // Left from another name: neither counted nor named.
    accGrant(accUser('Aaron Stale', 'aaron@console.test'), 60, 'gone.org', 'viewer', null);
    $this->actingAs($this->admin);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $rows = collect($this->getJson('/api/v1/domains')->assertOk()->json('data'))->keyBy('name');
    $withFour = $queries;

    expect($rows['acc.org']['people_count'])->toBe(4)
        ->and($rows['acc.org']['people'])->toBe(['Carol Viewer', 'Mia Manager', 'Vic Viewer'])
        ->and($rows['other.org']['people_count'])->toBe(1)
        ->and($rows['other.org']['people'])->toBe(['Olli Other'])
        ->and($rows['empty.org']['people_count'])->toBe(0)
        ->and($rows['empty.org']['people'])->toBe([]);

    // More domains and more people on the page: the same number of queries.
    foreach (range(64, 70) as $id) {
        accGuide('virtual_domains')->insert(['id' => $id, 'name' => "d{$id}.org"]);
        accGrant(accUser("P {$id}", "p{$id}@console.test"), $id, "d{$id}.org", 'viewer', null);
    }
    $queries = 0;
    $this->getJson('/api/v1/domains?limit=50')->assertOk();
    expect($queries)->toBe($withFour);
});

it('shows a manager the people of their domains only', function () {
    $this->actingAs($this->manager);

    $this->getJson('/api/v1/domains')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.people_count', 3)
        ->assertJsonPath('data.0.people', ['Carol Viewer', 'Mia Manager', 'Vic Viewer']);
});
