<?php

use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\DomainGrant;
use App\Models\HelperTask;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\GuideFixture;

function domainAdmin(): User
{
    $user = User::factory()->create(['is_server_admin' => true]);
    test()->actingAs($user);

    return $user;
}

function guide(string $table): Builder
{
    return DB::table(GuideSchema::table($table));
}

it('lists domains 10 per page sorted by name with counts', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    foreach (range(1, 12) as $i) {
        guide('virtual_domains')->insert(['name' => sprintf('d%02d.example', $i)]);
    }
    guide('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'jack@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
    ]);
    DB::table('mailbox_usage')->insert([
        ['email' => 'john@example.org', 'used_kib' => 10, 'limit_kib' => 100, 'checked_at' => now()],
        ['email' => 'mary@example.org', 'used_kib' => 5, 'limit_kib' => 100, 'checked_at' => now()],
    ]);

    $this->getJson('/api/v1/domains')->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 13, 'last_page' => 2])
        ->assertJsonPath('data.0.name', 'd01.example')
        ->assertJsonPath('data.0.mailbox_count', 0)
        ->assertJsonPath('data.0.used_bytes', null);

    $this->getJson('/api/v1/domains?page=2')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.page', 2);

    $this->getJson('/api/v1/domains?page=2&limit=5')->assertJsonCount(5, 'data');

    $row = collect($this->getJson('/api/v1/domains?limit=200')->json('data'))->firstWhere('name', 'example.org');
    // jack@ forwards; john@'s own keep-copy-only alias is not a forward.
    expect($row)->toBe([
        'name' => 'example.org', 'unicode_name' => 'example.org',
        'mailbox_count' => 2, 'forwarding_count' => 1, 'used_bytes' => 15 * 1024, 'role' => 'admin',
    ]);
});

it('searches domains by substring', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    guide('virtual_domains')->insert([['name' => 'shop.example.fi'], ['name' => 'other.net']]);

    $this->getJson('/api/v1/domains?q=EXAMPLE')->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.name', 'example.org')
        ->assertJsonPath('data.1.name', 'shop.example.fi');

    $this->getJson('/api/v1/domains?q=%25')->assertJsonPath('meta.total', 0);
});

it('adds a domain, normalising case and IDN', function () {
    GuideFixture::load('trixie');
    domainAdmin();

    $this->postJson('/api/v1/domains', ['name' => 'Exämple.FI'])->assertCreated()
        ->assertJsonPath('name', 'xn--exmple-cua.fi')
        ->assertJsonPath('unicode_name', 'exämple.fi')
        ->assertJsonPath('mailbox_count', 0);

    expect(guide('virtual_domains')->where('name', 'xn--exmple-cua.fi')->exists())->toBeTrue();
    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('domain.create')
        ->and($audit->object_type)->toBe('domain')
        ->and($audit->object_key)->toBe('xn--exmple-cua.fi')
        ->and($audit->domain_name)->toBe('xn--exmple-cua.fi');
});

it('refuses a duplicate domain with 409 domain_exists', function (string $fixture) {
    GuideFixture::load($fixture);
    domainAdmin();

    $this->postJson('/api/v1/domains', ['name' => 'EXAMPLE.org'])->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'domain_exists')
        ->assertJsonPath('detail', 'example.org is already on this server.');

    expect(guide('virtual_domains')->where('name', 'example.org')->count())->toBe(1)
        ->and(AuditEntry::count())->toBe(0);
})->with(['trixie', 'restored-bookworm']);

it('refuses a malformed domain with 422 errors.name', function (mixed $name) {
    GuideFixture::load('trixie');
    domainAdmin();

    $this->postJson('/api/v1/domains', ['name' => $name])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['name']]);
})->with(['not a domain', 'localhost', '', null, [['x.fi']]]);

it('deleting needs the exact typed name', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'EXAMPLE.ORG'])->assertStatus(422)
        ->assertJsonPath('errors.confirm_name', ['Type example.org to confirm.']);
    $this->deleteJson('/api/v1/domains/example.org')->assertStatus(422)
        ->assertJsonPath('errors.confirm_name', ['Type example.org to confirm.']);

    expect(guide('virtual_domains')->count())->toBe(1);
});

it('deleting an IDN domain accepts its exact punycode or unicode name', function (string $typed, bool $accepted) {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    Http::fake();
    guide('virtual_domains')->insert(['id' => 11, 'name' => 'xn--exmple-cua.fi']);

    $response = $this->deleteJson('/api/v1/domains/xn--exmple-cua.fi', ['confirm_name' => $typed]);

    if ($accepted) {
        $response->assertNoContent();
    } else {
        $response->assertStatus(422)->assertJsonPath('errors.confirm_name', ['Type exämple.fi (or xn--exmple-cua.fi) to confirm.']);
    }
    expect(guide('virtual_domains')->where('id', 11)->exists())->toBe(! $accepted);
})->with([
    'punycode' => ['xn--exmple-cua.fi', true],
    'unicode' => ['exämple.fi', true],
    'unicode, wrong case' => ['Exämple.fi', false],
    'punycode, wrong case' => ['XN--exmple-cua.fi', false],
]);

it('deleting removes the domain, its mailboxes, aliases and app rows in one transaction', function (string $fixture) {
    GuideFixture::load($fixture);
    domainAdmin();
    confirmStepUp();
    guide('virtual_domains')->insert(['id' => 11, 'name' => 'keep.example']);
    guide('virtual_users')->insert(['domain_id' => 11, 'email' => 'x@keep.example', 'password' => 'x']);
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10]);
    MailboxSetting::create(['mailbox_email' => 'x@keep.example', 'domain_id' => 11]);
    DisabledMailbox::create(['mailbox_email' => 'mary@example.org', 'domain_id' => 10, 'saved_password' => 'x', 'disabled_at' => now()]);
    PinnedDomain::create(['user_id' => 1, 'domain_id' => 10, 'domain_name' => 'example.org', 'position' => 0]);
    PinnedDomain::create(['user_id' => 1, 'domain_id' => 11, 'domain_name' => 'keep.example', 'position' => 1]);
    MailboxUsage::create(['email' => 'john@example.org', 'used_kib' => 1, 'limit_kib' => 2, 'checked_at' => now()]);
    MailboxUsage::create(['email' => 'x@keep.example', 'used_kib' => 1, 'limit_kib' => 2, 'checked_at' => now()]);

    $kicked = [];
    Http::fake(function (Request $request) use (&$kicked) {
        $call = $request->data()[0];
        $kicked[] = $call[1]['mask'];

        return Http::response([['doveadmResponse', [], $call[2]]]);
    });

    $this->deleteJson('/api/v1/domains/EXAMPLE.org', ['confirm_name' => 'example.org'])->assertNoContent();

    expect(guide('virtual_domains')->pluck('name')->all())->toBe(['keep.example'])
        ->and(guide('virtual_users')->pluck('email')->all())->toBe(['x@keep.example'])
        ->and(guide('virtual_aliases')->count())->toBe(0)
        ->and(MailboxSetting::pluck('mailbox_email')->all())->toBe(['x@keep.example'])
        ->and(DisabledMailbox::count())->toBe(0)
        ->and(PinnedDomain::pluck('domain_id')->all())->toBe([11])
        ->and(MailboxUsage::pluck('email')->all())->toBe(['x@keep.example'])
        ->and(HelperTask::where('type', 'maildir.archive')->orderBy('address')->pluck('address')->all())
        ->toBe(['john@example.org', 'mary@example.org']);

    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('domain.delete')
        ->and($audit->object_key)->toBe('example.org')
        ->and($audit->changes)->toBe(['mailboxes' => 2, 'forwarding' => 1, 'incoming_forwarding_removed' => 0]);
    expect(collect($kicked)->flatten()->sort()->values()->all())->toBe(['john@example.org', 'mary@example.org']);
})->with(['trixie', 'restored-nofk']);

it('deleting a domain removes its grants and keeps the other domains\' grants', function () {
    GuideFixture::load('trixie');
    $admin = domainAdmin();
    confirmStepUp();
    guide('virtual_domains')->insert(['id' => 11, 'name' => 'keep.example']);
    $manager = User::factory()->create();
    foreach ([10 => 'example.org', 11 => 'keep.example'] as $id => $name) {
        DomainGrant::query()->forceCreate(['user_id' => $manager->id, 'domain_id' => $id, 'domain_name' => $name, 'role' => 'manager', 'granted_by' => $admin->id]);
    }
    Http::fake();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();

    expect(DomainGrant::query()->pluck('domain_name')->all())->toBe(['keep.example']);
});

it('deleting removes forwarding from other domains into the deleted domain and counts it', function (string $fixture) {
    GuideFixture::load($fixture);
    domainAdmin();
    confirmStepUp();
    Http::fake();
    guide('virtual_domains')->insert([['id' => 11, 'name' => 'a.org'], ['id' => 12, 'name' => 'b.org']]);
    guide('virtual_users')->insert(['domain_id' => 12, 'email' => 'anna@b.org', 'password' => 'x']);
    guide('virtual_aliases')->insert([
        ['domain_id' => 11, 'source' => 'sales@a.org', 'destination' => 'anna@b.org'],
        ['domain_id' => 11, 'source' => 'sales@a.org', 'destination' => 'out@gmail.com'],
        ['domain_id' => 11, 'source' => 'info@a.org', 'destination' => 'Legacy@B.ORG'],
        ['domain_id' => 11, 'source' => 'keep@a.org', 'destination' => 'x@sub.b.org'],
        ['domain_id' => 10, 'source' => 'jack@example.org', 'destination' => 'nobody@b.org'],
    ]);

    $this->deleteJson('/api/v1/domains/b.org', ['confirm_name' => 'b.org'])->assertNoContent();

    $pairs = fn (string $like) => guide('virtual_aliases')->where('destination', 'like', $like)->orderBy('id')->get()
        ->map(fn ($r) => [$r->source, $r->destination])->all();
    // Only exact domain matches go: a subdomain of b.org is another domain.
    expect($pairs('%b.org'))->toBe([['keep@a.org', 'x@sub.b.org']])
        ->and($pairs('%gmail.com'))->toBe([['sales@a.org', 'out@gmail.com']]);
    $audit = AuditEntry::sole();
    expect($audit->changes)->toBe(['mailboxes' => 1, 'forwarding' => 0, 'incoming_forwarding_removed' => 3]);
})->with(['trixie', 'restored-bookworm']);

it('deleting locks the domain row and its mailbox rows before reading them', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    Http::fake();
    $other = DB::build(config('database.connections.'.config('database.default')));
    $probe = null;
    DB::listen(function (QueryExecuted $query) use ($other, &$probe) {
        // The first virtual_users statement of the delete: by now no other writer may share-lock the domain or its mailboxes.
        if ($probe !== null || ! str_contains($query->sql, 'virtual_users')) {
            return;
        }
        $probe = [];
        foreach ([
            'domain' => 'select id from '.GuideSchema::table('virtual_domains').' where id = 10 lock in share mode nowait',
            'mailboxes' => 'select id from '.GuideSchema::table('virtual_users').' where domain_id = 10 lock in share mode nowait',
        ] as $what => $sql) {
            try {
                $other->select($sql);
                $probe[$what] = 'free';
            } catch (QueryException) {
                $probe[$what] = 'locked';
            }
        }
    });

    try {
        $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();
    } finally {
        $other->disconnect();
    }

    expect($probe)->toBe(['domain' => 'locked', 'mailboxes' => 'locked']);
});

it('a write that starts after a concurrent domain delete is a 404 and leaves no orphan rows', function (string $method, string $uri, array $body) {
    GuideFixture::load('restored-nofk');
    domainAdmin();
    Http::fake();
    $before = [guide('virtual_users')->count(), guide('virtual_aliases')->count()];
    $other = DB::build(config('database.connections.'.config('database.default')));
    $deleted = false;
    // Another request's domain delete commits after route binding, just as this write opens its transaction.
    Event::listen(TransactionBeginning::class, function () use ($other, &$deleted) {
        if (! $deleted) {
            $deleted = true;
            $other->table(GuideSchema::table('virtual_domains'))->where('id', 10)->delete();
        }
    });

    try {
        $this->json($method, $uri, $body)->assertNotFound();
    } finally {
        $other->disconnect();
    }

    expect($deleted)->toBeTrue()
        ->and([guide('virtual_users')->count(), guide('virtual_aliases')->count()])->toBe($before)
        ->and(DisabledMailbox::count())->toBe(0)
        ->and(MailboxSetting::count())->toBe(0)
        ->and(AuditEntry::count())->toBe(0);
})->with([
    'mailbox create' => ['POST', '/api/v1/domains/example.org/mailboxes', ['local' => 'anna', 'display_name' => 'Anna', 'password' => 'correct-horse-9', 'quota_bytes' => 2147483648]],
    'forwarding replace' => ['PUT', '/api/v1/domains/example.org/forwarding/sales', ['targets' => ['out@gmail.com']]],
    'bulk disable' => ['POST', '/api/v1/domains/example.org/mailboxes/bulk', ['locals' => ['john'], 'action' => 'disable']],
]);

it('archives the stored maildir address, in its stored spelling, not the domain row', function () {
    GuideFixture::load('trixie', 'anomalies');
    domainAdmin();
    confirmStepUp();
    guide('virtual_users')->insert(['domain_id' => 11, 'email' => 'Bob@Example.com', 'password' => 'x']);
    Http::fake();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();
    $this->deleteJson('/api/v1/domains/example.com', ['confirm_name' => 'Example.com'])->assertNoContent();

    $tasks = HelperTask::query()->orderBy('address')->get(['address', 'domain_name'])
        ->map(fn ($t) => [$t->address, $t->domain_name])->all();
    expect($tasks)->toBe([
        ['Bob@Example.com', 'Example.com'],
        ['john@example.org', 'example.org'],
        ['jörg@example.org', 'example.org'],
        ['mary@example.org', 'example.org'],
        ['Mixed@example.org', 'example.org'],
        ['nohash@example.org', 'example.org'],
        ['weak@example.org', 'example.org'],
        ['wrong@example.net', 'example.net'],
    ]);
});

it('a legacy mixed-case mailbox of a deleted domain keeps the lowercase address archiving', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    guide('virtual_users')->insert(['domain_id' => 10, 'email' => 'Anna@Example.org', 'password' => 'x']);
    Http::fake();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();
    expect(HelperTask::where('address', 'anna@example.org')->sole()->address)->toBe('Anna@Example.org');

    guide('virtual_domains')->insert(['id' => 12, 'name' => 'example.org']);
    $this->postJson('/api/v1/domains/example.org/mailboxes', [
        'local' => 'anna', 'password' => 'correct-horse-9', 'quota_bytes' => 1048576,
    ])->assertStatus(409)->assertJsonPath('code', 'archiving');
});

it('a failing audit insert rolls the whole delete back', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    Http::fake();
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10]);

    Schema::rename('audit_log', 'audit_log_gone');
    try {
        $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertStatus(500);
    } finally {
        Schema::rename('audit_log_gone', 'audit_log');
    }

    expect(guide('virtual_domains')->where('name', 'example.org')->exists())->toBeTrue()
        ->and(guide('virtual_users')->count())->toBe(2)
        ->and(guide('virtual_aliases')->count())->toBe(1)
        ->and(MailboxSetting::count())->toBe(1)
        ->and(HelperTask::count())->toBe(0);
    Http::assertNothingSent();
});

it('a doveadm outage does not fail a delete', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();

    expect(guide('virtual_domains')->count())->toBe(0)
        ->and(AuditEntry::where('action', 'domain.delete')->count())->toBe(1);
});

it('returns 404 for a missing or malformed domain on delete', function (string $name) {
    GuideFixture::load('trixie');
    domainAdmin();

    $this->deleteJson("/api/v1/domains/{$name}", ['confirm_name' => $name])->assertNotFound()
        ->assertJsonPath('code', 'not_found');
})->with(['missing.example', 'not_a_domain']);

it('GET /domains/names returns every domain', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    foreach (range(1, 12) as $i) {
        guide('virtual_domains')->insert(['name' => sprintf('d%02d.example', $i)]);
    }

    $data = $this->getJson('/api/v1/domains/names')->assertOk()->json('data');

    expect($data)->toHaveCount(13)
        ->and($data[0])->toBe(['name' => 'd01.example', 'unicode_name' => 'd01.example', 'mailbox_count' => 0, 'role' => 'admin'])
        ->and(collect($data)->pluck('name')->all())->toBe(collect($data)->pluck('name')->sort()->values()->all())
        ->and(collect($data)->firstWhere('name', 'example.org')['mailbox_count'])->toBe(2);
});

it('shows a user without grants no domains, refuses them creating one and hides existing ones; guests get 401', function () {
    GuideFixture::load('trixie');

    $this->getJson('/api/v1/domains')->assertUnauthorized();

    $this->actingAs(User::factory()->create(['is_server_admin' => false]));
    $this->getJson('/api/v1/domains')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    $this->postJson('/api/v1/domains', ['name' => 'x.fi'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNotFound();
    expect(guide('virtual_domains')->count())->toBe(1);
});

it('asks for the typed name in Finnish for Accept-Language fi', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'x'], ['Accept-Language' => 'fi'])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.confirm_name', ['Vahvista kirjoittamalla example.org.']);
});

it('asks for the typed IDN name in Finnish with both spellings', function () {
    GuideFixture::load('trixie');
    domainAdmin();
    confirmStepUp();
    guide('virtual_domains')->insert(['id' => 11, 'name' => 'xn--exmple-cua.fi']);

    $this->deleteJson('/api/v1/domains/xn--exmple-cua.fi', ['confirm_name' => 'x'], ['Accept-Language' => 'fi'])->assertStatus(422)
        ->assertJsonPath('errors.confirm_name', ['Vahvista kirjoittamalla exämple.fi (tai xn--exmple-cua.fi).']);
});
