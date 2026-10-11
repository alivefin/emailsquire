<?php

use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\ServerStorage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->admin = User::factory()->create(['is_server_admin' => true, 'name' => 'Ada Admin']);
    $this->actingAs($this->admin);
    $this->guideTable = fn (string $t) => DB::table(GuideSchema::table($t));
    $this->audit = fn (array $over = []) => AuditEntry::create($over + [
        'occurred_at' => now(), 'actor_type' => 'user', 'actor_id' => (string) $this->admin->id, 'ip' => '127.0.0.1',
        'action' => 'mailbox.create', 'object_type' => 'mailbox', 'object_key' => 'x@example.org', 'domain_name' => 'example.org',
    ]);
});

it('the dashboard counts domains, mailboxes and forwarding split local/external', function () {
    ($this->guideTable)('virtual_domains')->insert(['id' => 11, 'name' => 'second.example']);
    ($this->guideTable)('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'mixed@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'mixed@example.org', 'destination' => 'out@gmail.com'],
        ['domain_id' => 10, 'source' => 'ext@example.org', 'destination' => 'out@gmail.com'],
        ['domain_id' => 10, 'source' => 'srv@example.org', 'destination' => 'someone@second.example'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
    ]);
    ($this->audit)(['action' => 'domain.create', 'object_type' => 'domain', 'object_key' => 'example.org']);
    ($this->audit)(['action' => 'domain.create', 'object_type' => 'domain', 'object_key' => 'second.example', 'occurred_at' => now()->subMonths(2)]);
    ($this->audit)(['action' => 'domain.delete', 'object_type' => 'domain', 'object_key' => 'gone.example']);

    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('counts', [
        'domains' => 2,
        'domains_added_this_month' => 1,
        'mailboxes' => 2,
        // jack (fixture, local), mixed (local + external), ext, srv; john only keeps a copy.
        'forwarding' => 4,
        'forwarding_local' => 2,
        'forwarding_external' => 2,
    ]);
});

it('near_full lists active mailboxes at or above 80 % from the snapshot, fullest first, and excludes disabled ones', function () {
    $kib = 5242880;
    ($this->guideTable)('virtual_users')->insert([
        ['domain_id' => 10, 'email' => 'big@example.org', 'password' => 'x', 'quota' => 1048576 * 10],
        ['domain_id' => 10, 'email' => 'off@example.org', 'password' => 'x', 'quota' => 0],
    ]);
    DisabledMailbox::create(['mailbox_email' => 'off@example.org', 'domain_id' => 10, 'saved_password' => 'x', 'disabled_at' => now()]);
    DB::table('mailbox_usage')->insert([
        ['email' => 'john@example.org', 'used_kib' => (int) ($kib * 0.85), 'limit_kib' => $kib, 'checked_at' => '2026-10-10 12:00:00'],
        ['email' => 'mary@example.org', 'used_kib' => (int) ($kib * 0.5), 'limit_kib' => $kib, 'checked_at' => '2026-10-10 11:00:00'],
        ['email' => 'big@example.org', 'used_kib' => 9728, 'limit_kib' => 10240, 'checked_at' => '2026-10-10 13:00:00'],
        ['email' => 'off@example.org', 'used_kib' => $kib, 'limit_kib' => $kib, 'checked_at' => '2026-10-10 13:00:00'],
    ]);

    $response = $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('near_full.count', 2)
        ->assertJsonPath('usage_checked_at', '2026-10-10T11:00:00+00:00');

    expect(collect($response->json('near_full.items'))->pluck('address')->all())->toBe(['big@example.org', 'john@example.org']);
    expect($response->json('near_full.items.1'))->toBe([
        'local' => 'john', 'address' => 'john@example.org', 'display_name' => null,
        'quota_bytes' => 5368709120, 'quota_is_default' => true, 'status' => 'active',
        'used_bytes' => (int) ($kib * 0.85) * 1024, 'usage_checked_at' => '2026-10-10T12:00:00+00:00', 'forwarding_count' => 1, 'receiving' => null, 'sendonly' => null,
        'domain' => 'example.org',
    ]);
});

it('near_full is capped at ten items but counts them all', function () {
    foreach (range(1, 12) as $i) {
        $email = sprintf('f%02d@example.org', $i);
        ($this->guideTable)('virtual_users')->insert(['domain_id' => 10, 'email' => $email, 'password' => 'x', 'quota' => 1048576]);
        DB::table('mailbox_usage')->insert(['email' => $email, 'used_kib' => 1024, 'limit_kib' => 1024, 'checked_at' => now()]);
    }

    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('near_full.count', 12)->assertJsonCount(10, 'near_full.items');
});

it('recent activity shows the last five audit entries with You for the requester', function () {
    $other = User::factory()->create(['name' => 'Bob Other']);
    foreach (range(1, 4) as $i) {
        ($this->audit)(['object_key' => "old{$i}@example.org", 'occurred_at' => now()->subHours(10 - $i)]);
    }
    ($this->audit)(['object_key' => 'bob@example.org', 'actor_id' => (string) $other->id, 'occurred_at' => now()->subMinutes(5), 'changes' => ['quota_bytes' => [1, 2]]]);
    ($this->audit)(['object_key' => 'cli@example.org', 'actor_type' => 'cli', 'actor_id' => 'root', 'occurred_at' => now()->subMinutes(1)]);

    $items = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(5, 'recent_activity')->json('recent_activity');

    expect(collect($items)->pluck('object_key')->all())->toBe(['cli@example.org', 'bob@example.org', 'old4@example.org', 'old3@example.org', 'old2@example.org'])
        ->and($items[0]['actor_name'])->toBe('Command line')
        ->and($items[1]['actor_name'])->toBe('Bob Other')
        ->and($items[1]['changes'])->toBe(['quota_bytes' => [1, 2]])
        ->and($items[2])->toHaveKeys(['occurred_at', 'actor_name', 'action', 'object_type', 'object_key', 'domain_name', 'changes'])
        ->and($items[2]['actor_name'])->toBe('You');
});

it('recent activity gives each actor a stable kind for the console to word', function () {
    $other = User::factory()->create(['name' => 'Bob Other']);
    ($this->audit)(['object_key' => 'you@example.org', 'occurred_at' => now()->subMinutes(5)]);
    ($this->audit)(['object_key' => 'bob@example.org', 'actor_id' => (string) $other->id, 'occurred_at' => now()->subMinutes(4)]);
    ($this->audit)(['object_key' => 'gone@example.org', 'actor_id' => '999999', 'occurred_at' => now()->subMinutes(3)]);
    ($this->audit)(['object_key' => 'cli@example.org', 'actor_type' => 'cli', 'actor_id' => 'root', 'occurred_at' => now()->subMinutes(2)]);
    ($this->audit)(['object_key' => 'sys@example.org', 'actor_type' => 'system', 'actor_id' => null, 'occurred_at' => now()->subMinutes(1)]);

    // A Finnish requester gets the same values: the console words them, the API does not.
    $this->admin->forceFill(['locale' => 'fi'])->save();
    $items = $this->getJson('/api/v1/dashboard')->assertOk()->json('recent_activity');

    expect(collect($items)->map(fn (array $i) => [$i['object_key'], $i['actor_kind'], $i['actor_name']])->all())->toBe([
        ['sys@example.org', 'system', 'System'],
        ['cli@example.org', 'cli', 'Command line'],
        ['gone@example.org', 'deleted_user', 'Deleted user'],
        ['bob@example.org', 'user', 'Bob Other'],
        ['you@example.org', 'you', 'You'],
    ]);
});

it('server_storage is null until the usage job has run', function () {
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('server_storage', null)->assertJsonPath('usage_checked_at', null);

    ServerStorage::create(['id' => 1, 'total_bytes' => 1000, 'free_bytes' => 400, 'checked_at' => '2026-10-10 12:00:00']);

    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('server_storage', [
        'total_bytes' => 1000, 'free_bytes' => 400, 'used_bytes' => 600, 'checked_at' => '2026-10-10T12:00:00+00:00',
    ]);
});

it('works against the restored bookworm (utf8mb3) guide tables', function () {
    GuideFixture::load('restored-bookworm');
    ($this->guideTable)('virtual_aliases')->insert(['domain_id' => 10, 'source' => 'Ext@example.org', 'destination' => 'Out@gmail.com']);
    DB::table('mailbox_usage')->insert(['email' => 'john@example.org', 'used_kib' => 5242880, 'limit_kib' => 5242880, 'checked_at' => now()]);

    $this->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('counts.forwarding_external', 1)
        ->assertJsonPath('near_full.count', 1)
        ->assertJsonPath('near_full.items.0.domain', 'example.org');
});
