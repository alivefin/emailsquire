<?php

use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_server_admin' => true, 'name' => 'Ada Admin']));
});

it('normalises the address when blocking', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => ' Promo@Example.COM ', 'reason' => 'spam'])
        ->assertCreated()
        ->assertJsonPath('address', 'promo@example.com')
        ->assertJsonPath('reason', 'spam')
        ->assertJsonPath('created_by_name', 'Ada Admin');

    expect(BlockedAddress::query()->pluck('address')->all())->toBe(['promo@example.com']);
    expect(AuditEntry::query()->where('action', 'blocked.create')->count())->toBe(1);
});

it('stores an empty reason as null', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'a@example.com', 'reason' => ''])
        ->assertCreated()->assertJsonPath('reason', null);
});

it('refuses a reason over 255 characters and an invalid address', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'a@example.com', 'reason' => str_repeat('x', 256)])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'a b@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('address');
});

it('blocking twice is 409 already_blocked', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'promo@example.com'])->assertCreated();

    $this->postJson('/api/v1/blocked-addresses', ['address' => 'PROMO@example.com'])
        ->assertStatus(409)->assertJsonPath('code', 'already_blocked');
});

it('allows blocking an address on a domain not on this server', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'known@spammed.biz'])
        ->assertCreated()->assertJsonPath('address', 'known@spammed.biz');
});

it('unblock writes blocked.delete', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'promo@example.com']);

    $this->deleteJson('/api/v1/blocked-addresses/Promo@Example.com')->assertNoContent();

    expect(BlockedAddress::query()->count())->toBe(0);
    expect(AuditEntry::query()->where('action', 'blocked.delete')->where('object_key', 'promo@example.com')->count())->toBe(1);
});

it('unblocking an absent or malformed address is 404', function () {
    $this->deleteJson('/api/v1/blocked-addresses/nobody@example.com')->assertNotFound();
    $this->deleteJson('/api/v1/blocked-addresses/not-an-address')->assertNotFound();
});

it('lists newest first, with search and paging', function () {
    foreach (['first@a.com', 'second@a.com', 'third@b.com'] as $a) {
        $this->postJson('/api/v1/blocked-addresses', ['address' => $a])->assertCreated();
    }

    $this->getJson('/api/v1/blocked-addresses')->assertOk()
        ->assertJsonPath('data.0.address', 'third@b.com')
        ->assertJsonPath('data.2.address', 'first@a.com')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 3, 'last_page' => 1]);

    $this->getJson('/api/v1/blocked-addresses?q=@a.com')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/blocked-addresses?limit=1&page=2')->assertJsonPath('data.0.address', 'second@a.com');
});

it('shows null created_by_name when the creator is gone', function () {
    $this->postJson('/api/v1/blocked-addresses', ['address' => 'a@example.com']);
    BlockedAddress::query()->update(['created_by' => null]);

    $this->getJson('/api/v1/blocked-addresses')->assertJsonPath('data.0.created_by_name', null);
});
