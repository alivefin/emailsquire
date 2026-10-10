<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\HelperTasks;
use App\Models\BlockedAddress;
use App\Models\User;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
});

it('reports a fresh local as available', function () {
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=fresh')->assertOk()
        ->assertJsonPath('address', 'fresh@example.org')
        ->assertJsonPath('status', 'available')
        ->assertJsonPath('message', 'fresh@example.org is available.')
        ->assertJsonPath('reason', null)
        ->assertJsonStructure(['address', 'status', 'reason', 'message']);
});

it('reports a fixture mailbox', function () {
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=John')
        ->assertOk()->assertJsonPath('status', 'mailbox')->assertJsonPath('address', 'john@example.org')
        ->assertJsonPath('message', 'john@example.org is already a mailbox.')
        ->assertJsonPath('reason', null)
        ->assertJsonStructure(['address', 'status', 'reason', 'message']);
});

it('reports a fixture alias source as forwarding', function () {
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=jack')
        ->assertOk()->assertJsonPath('status', 'forwarding')
        ->assertJsonPath('message', 'jack@example.org already forwards mail.')
        ->assertJsonPath('reason', null)
        ->assertJsonStructure(['address', 'status', 'reason', 'message']);
});

it('reports a blocked address with its reason, case-insensitively', function () {
    BlockedAddress::query()->create(['address' => 'promo@example.org', 'reason' => 'newsletter']);

    $this->getJson('/api/v1/domains/example.org/addresses/check?local=Promo')->assertOk()
        ->assertJsonPath('status', 'blocked')
        ->assertJsonPath('reason', 'newsletter')
        ->assertJsonPath('message', 'promo@example.org is blocked — reason: “newsletter”.');
});

it('reports archiving while an archive task is pending', function () {
    $domain = app(AddressNormalizer::class)->domain('example.org');
    app(HelperTasks::class)->queueArchive(app(AddressNormalizer::class)->addressIn('gone', $domain));

    $this->getJson('/api/v1/domains/example.org/addresses/check?local=gone')->assertOk()
        ->assertJsonPath('status', 'archiving');
});

it('reports an invalid local inline with 200', function () {
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=a%20b')->assertOk()
        ->assertJsonPath('status', 'invalid')
        ->assertJsonPath('message', 'Only letters, numbers, dots, hyphens and underscores — no spaces.');
    $this->getJson('/api/v1/domains/example.org/addresses/check')->assertOk()
        ->assertJsonPath('status', 'invalid');
});

it('is 404 for a domain not on this server', function () {
    $this->getJson('/api/v1/domains/nope.org/addresses/check?local=a')->assertNotFound();
});

it('answers the check in Finnish for Accept-Language fi', function () {
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=fresh', ['Accept-Language' => 'fi'])->assertOk()
        ->assertJsonPath('message', 'fresh@example.org on vapaana.');
    $this->getJson('/api/v1/domains/example.org/addresses/check?local=a%20b', ['Accept-Language' => 'fi'])->assertOk()
        ->assertJsonPath('status', 'invalid')
        ->assertJsonPath('message', 'Käytä vain kirjaimia, numeroita, pisteitä, yhdysmerkkejä ja alaviivoja – ei välilyöntejä.');
});
