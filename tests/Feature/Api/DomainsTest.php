<?php

use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\HelperTask;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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
        'mailbox_count' => 2, 'forwarding_count' => 1, 'used_bytes' => 15 * 1024,
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

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'EXAMPLE.ORG'])->assertStatus(422)
        ->assertJsonPath('errors.confirm_name', ['Type example.org to confirm.']);
    $this->deleteJson('/api/v1/domains/example.org')->assertStatus(422)
        ->assertJsonPath('errors.confirm_name', ['Type example.org to confirm.']);

    expect(guide('virtual_domains')->count())->toBe(1);
});

it('deleting removes the domain, its mailboxes, aliases and app rows in one transaction', function (string $fixture) {
    GuideFixture::load($fixture);
    domainAdmin();
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
        ->and($audit->changes)->toBe(['mailboxes' => 2, 'forwarding' => 1]);
    expect(collect($kicked)->flatten()->sort()->values()->all())->toBe(['john@example.org', 'mary@example.org']);
})->with(['trixie', 'restored-nofk']);

it('archives the stored maildir address, lowercased, not the domain row', function () {
    GuideFixture::load('trixie', 'anomalies');
    domainAdmin();
    guide('virtual_users')->insert(['domain_id' => 11, 'email' => 'Bob@Example.com', 'password' => 'x']);
    Http::fake();

    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();
    $this->deleteJson('/api/v1/domains/example.com', ['confirm_name' => 'Example.com'])->assertNoContent();

    $tasks = HelperTask::query()->orderBy('address')->get(['address', 'domain_name'])
        ->map(fn ($t) => [$t->address, $t->domain_name])->all();
    expect($tasks)->toBe([
        ['bob@example.com', 'example.com'],
        ['john@example.org', 'example.org'],
        ['jörg@example.org', 'example.org'],
        ['mary@example.org', 'example.org'],
        ['mixed@example.org', 'example.org'],
        ['nohash@example.org', 'example.org'],
        ['weak@example.org', 'example.org'],
        ['wrong@example.net', 'example.net'],
    ]);
});

it('a failing audit insert rolls the whole delete back', function () {
    GuideFixture::load('trixie');
    domainAdmin();
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
        ->and($data[0])->toBe(['name' => 'd01.example', 'unicode_name' => 'd01.example', 'mailbox_count' => 0])
        ->and(collect($data)->pluck('name')->all())->toBe(collect($data)->pluck('name')->sort()->values()->all())
        ->and(collect($data)->firstWhere('name', 'example.org')['mailbox_count'])->toBe(2);
});

it('hides domains from non-admins with 404 and from guests with 401', function () {
    GuideFixture::load('trixie');

    $this->getJson('/api/v1/domains')->assertUnauthorized();

    $this->actingAs(User::factory()->create(['is_server_admin' => false]));
    $this->getJson('/api/v1/domains')->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->postJson('/api/v1/domains', ['name' => 'x.fi'])->assertNotFound();
    $this->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNotFound();
    expect(guide('virtual_domains')->count())->toBe(1);
});
