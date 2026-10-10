<?php

use App\MailServer\GuideSchema;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->user = User::factory()->create(['is_server_admin' => true]);
    $this->actingAs($this->user);
    DB::table(GuideSchema::table('virtual_domains'))->insert([['id' => 11, 'name' => 'second.example'], ['id' => 12, 'name' => 'third.example']]);
});

it('pins are per user, ordered, idempotent and capped at 10', function () {
    $this->getJson('/api/v1/me/pinned-domains')->assertOk()->assertExactJson(['data' => []]);

    $this->putJson('/api/v1/me/pinned-domains/third.example')->assertNoContent();
    $this->putJson('/api/v1/me/pinned-domains/example.org')->assertNoContent();
    $this->putJson('/api/v1/me/pinned-domains/third.example')->assertNoContent();

    $this->getJson('/api/v1/me/pinned-domains')->assertOk()->assertExactJson(['data' => [
        ['name' => 'third.example', 'unicode_name' => 'third.example', 'mailbox_count' => 0],
        ['name' => 'example.org', 'unicode_name' => 'example.org', 'mailbox_count' => 2],
    ]]);
    expect(PinnedDomain::query()->orderBy('position')->pluck('position')->all())->toBe([1, 2]);

    $other = User::factory()->create(['is_server_admin' => true]);
    PinnedDomain::create(['user_id' => $other->id, 'domain_id' => 11, 'domain_name' => 'second.example', 'position' => 1]);
    $this->getJson('/api/v1/me/pinned-domains')->assertJsonCount(2, 'data');

    $this->deleteJson('/api/v1/me/pinned-domains/third.example')->assertNoContent();
    $this->deleteJson('/api/v1/me/pinned-domains/third.example')->assertNoContent();
    expect(PinnedDomain::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(PinnedDomain::query()->where('user_id', $other->id)->count())->toBe(1);

    // Fill up to 10, then the 11th is refused; re-pinning an existing one still works at the cap.
    foreach (range(1, 9) as $i) {
        DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 100 + $i, 'name' => "d{$i}.example"]);
        $this->putJson("/api/v1/me/pinned-domains/d{$i}.example")->assertNoContent();
    }
    $this->putJson('/api/v1/me/pinned-domains/example.org')->assertNoContent();
    $this->putJson('/api/v1/me/pinned-domains/second.example')->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrorFor('domain', 'errors');
    expect(PinnedDomain::query()->where('user_id', $this->user->id)->count())->toBe(10);

    $this->putJson('/api/v1/me/pinned-domains/nope.example')->assertNotFound();
});

it('deleting a domain removes its pins', function () {
    $this->putJson('/api/v1/me/pinned-domains/second.example')->assertNoContent();
    $this->putJson('/api/v1/me/pinned-domains/third.example')->assertNoContent();

    $this->deleteJson('/api/v1/domains/second.example', ['confirm_name' => 'second.example'])->assertNoContent();

    expect(PinnedDomain::query()->pluck('domain_name')->all())->toBe(['third.example']);
    $this->getJson('/api/v1/me/pinned-domains')->assertJsonCount(1, 'data');
});

it('pinned rows carry the unicode name, like /domains/names', function () {
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 13, 'name' => 'xn--exmple-cua.fi']);
    $this->putJson('/api/v1/me/pinned-domains/xn--exmple-cua.fi')->assertNoContent();

    $this->getJson('/api/v1/me/pinned-domains')->assertOk()->assertExactJson(['data' => [
        ['name' => 'xn--exmple-cua.fi', 'unicode_name' => 'exämple.fi', 'mailbox_count' => 0],
    ]]);
});
