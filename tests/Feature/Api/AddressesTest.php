<?php

use App\MailServer\GuideSchema;
use App\Models\MailboxSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    $this->guideTable = fn (string $t) => DB::table(GuideSchema::table($t));
});

it('search returns prefix domain matches first and at most 5 domains and 10 addresses', function () {
    foreach (['xmail.test', 'amail.test', 'mail.test', 'mailer.test', 'zmail.test', 'qmail.test', 'mail2.test'] as $i => $name) {
        ($this->guideTable)('virtual_domains')->insert(['id' => 20 + $i, 'name' => $name]);
    }
    foreach (range(1, 12) as $i) {
        ($this->guideTable)('virtual_users')->insert(['domain_id' => 10, 'email' => sprintf('box%02d@example.org', $i), 'password' => 'x']);
    }
    ($this->guideTable)('virtual_aliases')->insert(['domain_id' => 10, 'source' => 'box-alias@example.org', 'destination' => 'x@gmail.com']);

    $domains = $this->getJson('/api/v1/search?q=mail')->assertOk()->json('domains');
    expect($domains)->toHaveCount(5)
        ->and(array_column($domains, 'name'))->toBe(['mail.test', 'mail2.test', 'mailer.test', 'amail.test', 'qmail.test']);

    $result = $this->getJson('/api/v1/search?q=box')->assertOk();
    expect($result->json('addresses'))->toHaveCount(10)->and($result->json('addresses.0'))->toBe(['address' => 'box-alias@example.org', 'type' => 'forwarding', 'domain' => 'example.org'])
        ->and($result->json('addresses.1.type'))->toBe('mailbox');

    $this->getJson('/api/v1/search?q=b')->assertOk()->assertExactJson(['domains' => [], 'addresses' => []]);
    $this->getJson('/api/v1/search')->assertOk()->assertExactJson(['domains' => [], 'addresses' => []]);
    $this->getJson('/api/v1/search?q=%25%25')->assertOk()->assertExactJson(['domains' => [], 'addresses' => []]);
});

it('all addresses lists mailboxes and forwarding sorted, each source once', function () {
    ($this->guideTable)('virtual_domains')->insert(['id' => 11, 'name' => 'other.example']);
    ($this->guideTable)('virtual_users')->insert(['domain_id' => 11, 'email' => 'zed@other.example', 'password' => 'x', 'quota' => 1048576]);
    ($this->guideTable)('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'jo@gmail.com'],
        ['domain_id' => 10, 'source' => 'info@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'info@example.org', 'destination' => 'boss@gmail.com'],
    ]);
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'John Doe']);
    DB::table('mailbox_usage')->insert(['email' => 'john@example.org', 'used_kib' => 10, 'limit_kib' => 5242880, 'checked_at' => now()]);

    $all = $this->getJson('/api/v1/addresses?limit=200')->assertOk()
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 200, 'total' => 5, 'last_page' => 1]);
    $rows = collect($all->json('data'))->keyBy('address');

    expect($rows->keys()->all())->toBe(['info@example.org', 'jack@example.org', 'john@example.org', 'mary@example.org', 'zed@other.example'])
        ->and($rows['john@example.org'])->toBe([
            'address' => 'john@example.org', 'type' => 'mailbox', 'domain' => 'example.org', 'display_name' => 'John Doe',
            'quota_bytes' => 5368709120, 'used_bytes' => 10240, 'targets' => ['jo@gmail.com'],
        ])
        ->and($rows['info@example.org'])->toBe([
            'address' => 'info@example.org', 'type' => 'forwarding', 'domain' => 'example.org', 'display_name' => null,
            'quota_bytes' => null, 'used_bytes' => null, 'targets' => ['mary@example.org', 'boss@gmail.com'],
        ])
        ->and($rows['mary@example.org']['targets'])->toBeNull()
        ->and($rows['mary@example.org']['used_bytes'])->toBeNull()
        ->and($rows['zed@other.example']['quota_bytes'])->toBe(1048576);
});

it('all addresses filters by type and domain and matches targets', function () {
    ($this->guideTable)('virtual_domains')->insert(['id' => 11, 'name' => 'other.example']);
    ($this->guideTable)('virtual_users')->insert(['domain_id' => 11, 'email' => 'zed@other.example', 'password' => 'x']);
    ($this->guideTable)('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'info@example.org', 'destination' => 'boss@gmail.com'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
    ]);
    $get = fn (string $qs) => collect($this->getJson("/api/v1/addresses?{$qs}")->assertOk()->json('data'))->pluck('address')->all();

    $types = collect($this->getJson('/api/v1/addresses?type=forward')->json('data'))->pluck('type')->unique()->all();
    expect($types)->toBe(['forwarding']);

    expect($get('type=mailbox'))->toBe(['john@example.org', 'mary@example.org', 'zed@other.example'])
        ->and($get('type=forward'))->toBe(['info@example.org', 'jack@example.org'])
        ->and($get('domain=other.example'))->toBe(['zed@other.example'])
        ->and($get('domain=OTHER.example&type=forward'))->toBe([])
        ->and($get('domain=nope.example'))->toBe([])
        ->and($get('q=boss'))->toBe(['info@example.org'])
        ->and($get('q=mar'))->toBe(['mary@example.org'])
        ->and($get('q=john&type=forward'))->toBe(['jack@example.org'])
        ->and($get('q=%25'))->toBe([]);

    $this->getJson('/api/v1/addresses?limit=2&page=2')->assertOk()->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3])->assertJsonCount(2, 'data');
});

it('works against the restored bookworm (utf8mb3) guide tables', function () {
    GuideFixture::load('restored-bookworm');
    ($this->guideTable)('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'Info@example.org', 'destination' => 'Boss@gmail.com'],
        ['domain_id' => 10, 'source' => 'John@example.org', 'destination' => 'john@example.org'],
    ]);

    $rows = collect($this->getJson('/api/v1/addresses?q=boss')->assertOk()->json('data'));
    expect($rows->pluck('address')->all())->toBe(['info@example.org']);
    expect(collect($this->getJson('/api/v1/addresses')->assertOk()->json('data'))->where('address', 'john@example.org')->count())->toBe(1);
    $this->getJson('/api/v1/addresses?q=😀')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/addresses?domain=example.org&type=mailbox')->assertOk();
    $this->getJson('/api/v1/search?q=😀')->assertOk()->assertExactJson(['domains' => [], 'addresses' => []]);
    $this->getJson('/api/v1/search?q=exam')->assertOk()->assertJsonPath('domains.0.name', 'example.org');
});
