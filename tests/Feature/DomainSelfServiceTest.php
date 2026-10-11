<?php

use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

// Phase 3, Task 2: the switches that say what a domain's mailbox owners may change. Any role reads
// them, admins and managers set them; every change is audited (`domain.self_service_changed`).

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off']);
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 60, 'name' => 'ss.org']);
    $this->admin = User::factory()->create(['is_server_admin' => true, 'password_set_at' => now()]);
});

function ssUser(string $role): User
{
    $user = User::factory()->create(['password_set_at' => now()]);
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => 60, 'domain_name' => 'ss.org', 'role' => $role]);

    return $user;
}

function ssAudit(): ?AuditEntry
{
    return AuditEntry::query()->where('action', 'domain.self_service_changed')->latest('id')->first();
}

it('reads the defaults for a domain without a settings row', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/domains/ss.org/self-service')
        ->assertOk()->assertExactJson(['forwarding' => true, 'aliases' => false, 'external_forwarding' => false]);
    expect(DB::table('domain_settings')->count())->toBe(0);
});

it('lets an admin and a manager set the switches, and a viewer only read them', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => true])
        ->assertOk()->assertExactJson(['forwarding' => true, 'aliases' => true, 'external_forwarding' => false]);

    $this->actingAs(ssUser('manager'))->patchJson('/api/v1/domains/ss.org/self-service', ['forwarding' => false, 'external_forwarding' => true])
        ->assertOk()->assertExactJson(['forwarding' => false, 'aliases' => true, 'external_forwarding' => true]);

    $viewer = ssUser('viewer');
    $this->actingAs($viewer)->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => false])->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/v1/domains/ss.org/self-service')->assertOk()->assertJson(['aliases' => true]);

    $this->actingAs(User::factory()->create(['password_set_at' => now()]))
        ->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => false])->assertNotFound();
});

it('audits only the fields that changed, as old and new values', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/domains/ss.org/self-service', ['forwarding' => true, 'aliases' => true, 'external_forwarding' => false])->assertOk();

    $entry = ssAudit();
    expect($entry)->not->toBeNull()
        ->and($entry->object_type)->toBe('domain')
        ->and($entry->object_key)->toBe('ss.org')
        ->and($entry->domain_name)->toBe('ss.org')
        ->and($entry->changes)->toBe(['aliases' => [false, true]]);
});

it('writes no audit row and no settings row for a PATCH that changes nothing', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/domains/ss.org/self-service', ['forwarding' => true, 'aliases' => false])->assertOk();
    $this->patchJson('/api/v1/domains/ss.org/self-service', [])->assertOk();

    expect(ssAudit())->toBeNull()->and(DB::table('domain_settings')->count())->toBe(0);

    $this->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => true])->assertOk();
    $this->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => true])->assertOk();
    expect(AuditEntry::query()->where('action', 'domain.self_service_changed')->count())->toBe(1);
});

it('keeps the limits when the switches change, and the switches when the limits change', function () {
    $this->actingAs($this->admin)->patchJson('/api/v1/domains/ss.org/settings', ['max_mailboxes' => 7])->assertOk();
    $this->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => true])->assertOk();
    $this->patchJson('/api/v1/domains/ss.org/settings', ['max_mailboxes' => 8])->assertOk();

    $row = DB::table('domain_settings')->where('domain_id', 60)->first();
    expect($row->max_mailboxes)->toBe(8)->and((bool) $row->allow_user_aliases)->toBeTrue()->and($row->domain_name)->toBe('ss.org');
});

it('treats a settings row left by a renamed domain as the defaults and rewrites it', function () {
    DB::table('domain_settings')->insert(['domain_id' => 60, 'domain_name' => 'old.org', 'allow_user_forwarding' => false, 'allow_user_aliases' => true, 'allow_external_forwarding' => true]);

    $this->actingAs($this->admin)->getJson('/api/v1/domains/ss.org/self-service')
        ->assertExactJson(['forwarding' => true, 'aliases' => false, 'external_forwarding' => false]);
    $this->patchJson('/api/v1/domains/ss.org/self-service', ['external_forwarding' => true])
        ->assertOk()->assertExactJson(['forwarding' => true, 'aliases' => false, 'external_forwarding' => true]);

    expect(DB::table('domain_settings')->where('domain_id', 60)->value('domain_name'))->toBe('ss.org')
        ->and(ssAudit()->changes)->toBe(['external_forwarding' => [false, true]]);
});

it('refuses a value that is not a boolean', function (mixed $value) {
    $this->actingAs($this->admin)->patchJson('/api/v1/domains/ss.org/self-service', ['aliases' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('aliases');
    expect(ssAudit())->toBeNull();
})->with([['yes'], [2], [null], ['true'], [[]]]);
