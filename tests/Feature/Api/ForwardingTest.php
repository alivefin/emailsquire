<?php

use App\MailServer\AuditLog;
use App\MailServer\GuideSchema;
use App\MailServer\SourceLock;
use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use App\Models\HelperTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    $this->fwdTable = fn () => DB::table(GuideSchema::table('virtual_aliases'));
    $this->fwdPairs = fn (string $source) => ($this->fwdTable)()->whereRaw('lower(source) = ?', [$source])->orderBy('id')
        ->get()->map(fn ($r) => [$r->source, $r->destination])->all();
    $this->fwdUrl = fn (string $local) => "/api/v1/domains/example.org/forwarding/{$local}";
});

it('creates forwarding to a mailbox and an external address', function () {
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'other.example']);

    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['john@example.org', 'out@gmail.com', 'staff@other.example']])
        ->assertCreated()
        ->assertJsonPath('local', 'sales')
        ->assertJsonPath('source', 'sales@example.org')
        ->assertJsonPath('source_is_mailbox', false)
        ->assertJsonPath('catch_all', false)
        ->assertJsonPath('keep_copy', false)
        ->assertJsonPath('targets', [
            ['address' => 'john@example.org', 'kind' => 'mailbox'],
            ['address' => 'out@gmail.com', 'kind' => 'external'],
            ['address' => 'staff@other.example', 'kind' => 'server'],
        ]);

    expect(($this->fwdPairs)('sales@example.org'))->toBe([
        ['sales@example.org', 'john@example.org'],
        ['sales@example.org', 'out@gmail.com'],
        ['sales@example.org', 'staff@other.example'],
    ]);
});

it('PUT replaces the full target set in one go', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['a@gmail.com', 'b@gmail.com', 'c@gmail.com']])->assertCreated();
    $this->putJson(($this->fwdUrl)('Sales'), ['targets' => ['d@gmail.com']])->assertOk()
        ->assertJsonCount(1, 'targets');

    expect(($this->fwdPairs)('sales@example.org'))->toBe([['sales@example.org', 'd@gmail.com']]);
    $audit = AuditEntry::query()->where('action', 'forwarding.replace')->orderByDesc('id')->firstOrFail();
    expect($audit->object_key)->toBe('sales@example.org')
        ->and($audit->changes['old'])->toBe(['a@gmail.com', 'b@gmail.com', 'c@gmail.com'])
        ->and($audit->changes['new'])->toBe(['d@gmail.com']);
});

it('replaces legacy mixed-case rows of the same source', function () {
    ($this->fwdTable)()->insert(['domain_id' => 10, 'source' => 'Info@example.org', 'destination' => 'Old@gmail.com']);

    $this->putJson(($this->fwdUrl)('info'), ['targets' => ['new@gmail.com']])->assertOk();

    expect(($this->fwdPairs)('info@example.org'))->toBe([['info@example.org', 'new@gmail.com']]);
});

it('forwarding a mailbox with keep_copy writes the self row and reports keep_copy true', function () {
    $this->putJson(($this->fwdUrl)('john'), ['targets' => ['out@gmail.com'], 'keep_copy' => true])
        ->assertCreated()
        ->assertJsonPath('keep_copy', true)
        ->assertJsonPath('source_is_mailbox', true)
        ->assertJsonPath('targets', [['address' => 'out@gmail.com', 'kind' => 'external']]);

    expect(($this->fwdPairs)('john@example.org'))->toBe([
        ['john@example.org', 'out@gmail.com'],
        ['john@example.org', 'john@example.org'],
    ]);
});

it('ignores keep_copy for a source that is not a mailbox', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['out@gmail.com'], 'keep_copy' => true])
        ->assertCreated()->assertJsonPath('keep_copy', false);

    expect(($this->fwdPairs)('sales@example.org'))->toBe([['sales@example.org', 'out@gmail.com']]);
});

it('forwarding a mailbox without keep_copy requires redirect_mailbox', function () {
    $this->putJson(($this->fwdUrl)('john'), ['targets' => ['out@gmail.com'], 'keep_copy' => false])
        ->assertStatus(422)
        ->assertJsonPath('code', 'redirect_not_confirmed')
        ->assertJsonStructure(['errors' => ['redirect_mailbox']]);
    expect(($this->fwdPairs)('john@example.org'))->toBe([]);

    $this->putJson(($this->fwdUrl)('john'), ['targets' => ['out@gmail.com'], 'keep_copy' => false, 'redirect_mailbox' => true])
        ->assertCreated()->assertJsonPath('keep_copy', false);
    expect(($this->fwdPairs)('john@example.org'))->toBe([['john@example.org', 'out@gmail.com']]);
});

it('duplicate targets in one request are stored once', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['a@gmail.com', 'A@Gmail.com', ' a@gmail.com ']])
        ->assertCreated()->assertJsonCount(1, 'targets');

    expect(($this->fwdPairs)('sales@example.org'))->toBe([['sales@example.org', 'a@gmail.com']]);
});

it('a target equal to the source is refused', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['a@gmail.com', 'Sales@Example.org']])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['targets.1' => ['An address can’t deliver to itself.']]);
    expect(($this->fwdPairs)('sales@example.org'))->toBe([]);
});

it('targets are normalised', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => [' Info@Example.ORG ']])
        ->assertCreated()->assertJsonPath('targets', [['address' => 'info@example.org', 'kind' => 'server']]);

    expect(($this->fwdPairs)('sales@example.org'))->toBe([['sales@example.org', 'info@example.org']]);
});

it('validates the target list', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => []])->assertStatus(422)
        ->assertJsonPath('errors.targets', ['Add at least one destination.']);
    $this->putJson(($this->fwdUrl)('sales'), [])->assertStatus(422)
        ->assertJsonPath('errors.targets', ['Add at least one destination.']);
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['a@gmail.com', 'nope', 'b@']])->assertStatus(422)
        ->assertJsonPath('errors', [
            'targets.1' => ['“nope” is not a complete address.'],
            'targets.2' => ['“b@” is not a complete address.'],
        ]);
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => array_map(fn ($i) => "u{$i}@gmail.com", range(1, 51))])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['targets']]);
    $this->putJson(($this->fwdUrl)('sales..x'), ['targets' => ['a@gmail.com']])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['local']]);

    $this->putJson(($this->fwdUrl)('sales'), ['targets' => array_map(fn ($i) => "u{$i}@gmail.com", range(1, 50))])->assertCreated();
});

it('a blocked source is refused', function () {
    BlockedAddress::create(['address' => 'promo@example.org', 'reason' => 'spam', 'created_by' => null]);

    $this->putJson(($this->fwdUrl)('promo'), ['targets' => ['a@gmail.com']])
        ->assertStatus(422)->assertJsonPath('code', 'blocked');
    expect(($this->fwdPairs)('promo@example.org'))->toBe([]);
});

it('a non-mailbox source with a pending archive is 409', function () {
    $task = new HelperTask;
    $task->forceFill([
        'type' => 'maildir.archive', 'address' => 'gone@example.org', 'domain_name' => 'example.org',
        'status' => 'pending', 'run_after' => now(),
    ])->save();

    $this->putJson(($this->fwdUrl)('gone'), ['targets' => ['a@gmail.com']])
        ->assertStatus(409)->assertJsonPath('code', 'archiving');
    expect(($this->fwdPairs)('gone@example.org'))->toBe([]);
});

it('DELETE removes every row for the source and audits forwarding.delete', function () {
    ($this->fwdTable)()->insert([
        ['domain_id' => 10, 'source' => 'Sales@example.org', 'destination' => 'a@gmail.com'],
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'b@gmail.com'],
    ]);

    $this->deleteJson(($this->fwdUrl)('sales'))->assertNoContent();

    expect(($this->fwdPairs)('sales@example.org'))->toBe([])
        ->and(($this->fwdPairs)('jack@example.org'))->toHaveCount(1);
    $audit = AuditEntry::query()->where('action', 'forwarding.delete')->sole();
    expect($audit->object_key)->toBe('sales@example.org')->and($audit->changes['removed'])->toBe(2);

    $this->deleteJson(($this->fwdUrl)('sales'))->assertNotFound();
    $this->deleteJson(($this->fwdUrl)('bad..local'))->assertNotFound();
});

it('the list groups by source, filters by kind and searches targets', function () {
    ($this->fwdTable)()->insert([
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'out@gmail.com'],
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'mary@home.net'],
        ['domain_id' => 10, 'source' => 'zed@example.org', 'destination' => 'zed@elsewhere.org'],
    ]);
    $addresses = fn (string $query) => collect($this->getJson("/api/v1/domains/example.org/forwarding{$query}")->assertOk()->json('data'))->pluck('source')->all();

    $all = $this->getJson('/api/v1/domains/example.org/forwarding')->assertOk()
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 4, 'last_page' => 1]);
    expect($all->json('data.*.source'))->toBe(['jack@example.org', 'mary@example.org', 'sales@example.org', 'zed@example.org']);

    $mary = collect($all->json('data'))->firstWhere('local', 'mary');
    expect($mary)->toBe([
        'local' => 'mary', 'source' => 'mary@example.org', 'source_is_mailbox' => true, 'catch_all' => false, 'keep_copy' => true,
        'targets' => [['address' => 'mary@home.net', 'kind' => 'external']],
    ]);

    expect($addresses('?kind=local'))->toBe(['jack@example.org', 'sales@example.org'])
        ->and($addresses('?kind=external'))->toBe(['mary@example.org', 'sales@example.org', 'zed@example.org'])
        ->and($addresses('?q=gmail'))->toBe(['sales@example.org'])
        ->and($addresses('?q=ZED'))->toBe(['zed@example.org'])
        ->and($addresses('?q=gmail&kind=local'))->toBe(['sales@example.org'])
        ->and($addresses('?q=elsewhere&kind=local'))->toBe([])
        ->and($addresses('?q=%25'))->toBe([]);

    $this->getJson('/api/v1/domains/example.org/forwarding?limit=2&page=2')->assertOk()
        ->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2])
        ->assertJsonCount(2, 'data');
    // The full target set shows even when the search matched one target only.
    $sales = collect($this->getJson('/api/v1/domains/example.org/forwarding?q=gmail')->json('data'))->first();
    expect($sales['targets'])->toHaveCount(2);
});

it('a catch-all row in the data is listed read-only', function () {
    ($this->fwdTable)()->insert(['domain_id' => 10, 'source' => '@example.org', 'destination' => 'john@example.org']);

    $row = collect($this->getJson('/api/v1/domains/example.org/forwarding')->assertOk()->json('data'))->firstWhere('catch_all', true);

    expect($row)->toBe([
        'local' => '', 'source' => '@example.org', 'source_is_mailbox' => false, 'catch_all' => true, 'keep_copy' => false,
        'targets' => [['address' => 'john@example.org', 'kind' => 'mailbox']],
    ]);
});

it('concurrent PUTs to one source never interleave', function () {
    ($this->fwdTable)()->insert(['domain_id' => 10, 'source' => 'x@example.org', 'destination' => 'old@gmail.com']);
    $name = SourceLock::name('x@example.org');
    $other = DB::build(config('database.connections.'.config('database.default')));
    $other->selectOne('select get_lock(?, 0) as l', [$name]);
    config(['emailsquire.source_lock_timeout' => 1]);

    $this->putJson(($this->fwdUrl)('x'), ['targets' => ['new@gmail.com']])
        ->assertStatus(409)->assertJsonPath('code', 'busy');
    expect(($this->fwdPairs)('x@example.org'))->toBe([['x@example.org', 'old@gmail.com']]);

    $other->selectOne('select release_lock(?)', [$name]);
    $this->putJson(($this->fwdUrl)('x'), ['targets' => ['new@gmail.com']])->assertOk();
    expect(($this->fwdPairs)('x@example.org'))->toBe([['x@example.org', 'new@gmail.com']]);
});

it('the lock is released after a failing write', function () {
    ($this->fwdTable)()->insert(['domain_id' => 10, 'source' => 'x@example.org', 'destination' => 'old@gmail.com']);
    $audit = Mockery::mock(AuditLog::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    app()->instance(AuditLog::class, $audit);
    $this->withoutExceptionHandling();

    expect(fn () => $this->putJson(($this->fwdUrl)('x'), ['targets' => ['new@gmail.com']]))->toThrow(RuntimeException::class, 'audit down');

    expect((int) DB::selectOne('select is_free_lock(?) as f', [SourceLock::name('x@example.org')])->f)->toBe(1)
        ->and(($this->fwdPairs)('x@example.org'))->toBe([['x@example.org', 'old@gmail.com']]);
});

it('a mailbox target is shared-locked so a concurrent mailbox delete waits for the commit', function () {
    // The repository takes FOR SHARE on virtual_users rows of mailbox targets before touching aliases.
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['john@example.org', 'out@gmail.com']])->assertCreated();

    $shared = collect($queries)->search(fn ($s) => str_contains($s, 'virtual_users') && str_contains($s, 'lock in share mode'));
    $delete = collect($queries)->search(fn ($s) => str_starts_with($s, 'delete from') && str_contains($s, 'virtual_aliases'));
    expect($shared)->not->toBeFalse()->and($delete)->not->toBeFalse()->and($shared)->toBeLessThan($delete);
});

it('works against the restored bookworm (utf8mb3) guide tables', function () {
    GuideFixture::load('restored-bookworm');
    ($this->fwdTable)()->insert(['domain_id' => 10, 'source' => 'Info@example.org', 'destination' => 'Old@gmail.com']);

    $this->putJson(($this->fwdUrl)('info'), ['targets' => ['john@example.org', 'out@gmail.com']])->assertOk()
        ->assertJsonPath('targets.0.kind', 'mailbox');
    $this->putJson(($this->fwdUrl)('john'), ['targets' => ['out@gmail.com'], 'keep_copy' => true])->assertCreated();

    $list = $this->getJson('/api/v1/domains/example.org/forwarding?kind=local&q=john')->assertOk();
    expect($list->json('data.*.source'))->toBe(['info@example.org']);
    $this->getJson('/api/v1/domains/example.org/forwarding?kind=external')->assertOk()->assertJsonCount(2, 'data');
    $this->deleteJson(($this->fwdUrl)('info'))->assertNoContent();
    expect(($this->fwdPairs)('info@example.org'))->toBe([]);
});

it('allows plus addressing in a forwarding target but not in the source', function () {
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['Me+Tag@Gmail.com']])->assertCreated()
        ->assertJsonPath('targets', [['address' => 'me+tag@gmail.com', 'kind' => 'external']]);
    expect(($this->fwdPairs)('sales@example.org'))->toBe([['sales@example.org', 'me+tag@gmail.com']]);

    $this->putJson(($this->fwdUrl)('sa+les'), ['targets' => ['a@gmail.com']])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['local']]);
    $this->putJson(($this->fwdUrl)('sales'), ['targets' => ['a..b+c@gmail.com', '+x@gmail.com']])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['targets.0', 'targets.1']]);
});

it('a source lock held under the legacy mixed-case address blocks a PUT on the lowercase source', function () {
    $name = SourceLock::name('John@example.org');
    $other = DB::build(config('database.connections.'.config('database.default')));
    try {
        $other->selectOne('select get_lock(?, 0) as l', [$name]);
        config(['emailsquire.source_lock_timeout' => 0]);

        $this->putJson(($this->fwdUrl)('john'), ['targets' => ['a@gmail.com'], 'keep_copy' => true])
            ->assertStatus(409)->assertJsonPath('code', 'busy');
    } finally {
        $other->selectOne('select release_lock(?)', [$name]);
        $other->disconnect();
    }
});

it('name-folds source locks', function () {
    expect(SourceLock::name('John@Example.org'))->toBe(SourceLock::name('john@example.org'));
});

it('a non-ASCII search does not break the list on utf8mb3 guide tables', function () {
    GuideFixture::load('restored-bookworm');

    $this->getJson('/api/v1/domains/example.org/forwarding?q=😀')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/domains/example.org/mailboxes?q=😀')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/domains?q=😀')->assertOk()->assertJsonCount(0, 'data');
});
