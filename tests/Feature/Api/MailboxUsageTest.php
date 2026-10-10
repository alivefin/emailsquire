<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
});

it('live usage parses doveadm quotaGet and stores the snapshot', function () {
    $this->freezeTime();
    fakeDoveadm('doveadmResponse', [
        ['root' => 'User quota', 'type' => 'STORAGE', 'value' => '1048576', 'limit' => '5242880', 'percent' => '20'],
        ['type' => 'MESSAGE', 'value' => '10', 'limit' => '-'],
    ]);

    $this->getJson('/api/v1/domains/example.org/mailboxes/john/usage')->assertOk()
        ->assertJsonPath('used_bytes', 1073741824)
        ->assertJsonPath('limit_bytes', 5368709120)
        ->assertJsonPath('checked_at', now()->toIso8601String());

    Http::assertSent(fn (Request $r) => $r->data()[0][0] === 'quotaGet' && (array) $r->data()[0][1] === ['user' => 'john@example.org']);
    $row = DB::table('mailbox_usage')->where('email', 'john@example.org')->first();
    expect($row->used_kib)->toBe(1048576)->and($row->limit_kib)->toBe(5242880);
});

it('live usage reports no limit as null and overwrites the earlier snapshot', function () {
    DB::table('mailbox_usage')->insert(['email' => 'john@example.org', 'used_kib' => 1, 'limit_kib' => 2, 'checked_at' => '2026-01-01 00:00:00']);
    fakeDoveadm('doveadmResponse', [['type' => 'STORAGE', 'value' => '2048', 'limit' => '-']]);

    $this->getJson('/api/v1/domains/example.org/mailboxes/john/usage')->assertOk()
        ->assertJsonPath('used_bytes', 2097152)->assertJsonPath('limit_bytes', null);

    expect(DB::table('mailbox_usage')->count())->toBe(1)
        ->and(DB::table('mailbox_usage')->value('limit_kib'))->toBeNull()
        ->and(DB::table('mailbox_usage')->value('used_kib'))->toBe(2048);
});

it('live usage is 503 usage_unavailable when doveadm is down', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->getJson('/api/v1/domains/example.org/mailboxes/john/usage')->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'usage_unavailable')
        ->assertJsonPath('detail', 'Storage usage is unavailable right now.');
    expect(DB::table('mailbox_usage')->count())->toBe(0);
});

it('live usage is 503 when doveadm answers without a STORAGE entry', function () {
    fakeDoveadm('doveadmResponse', [['type' => 'MESSAGE', 'value' => '10', 'limit' => '-']]);

    $this->getJson('/api/v1/domains/example.org/mailboxes/john/usage')->assertStatus(503)->assertJsonPath('code', 'usage_unavailable');
});

it('live usage is 404 for a mailbox that does not exist', function () {
    $this->getJson('/api/v1/domains/example.org/mailboxes/nobody/usage')->assertNotFound();
});
