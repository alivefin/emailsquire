<?php

use App\MailServer\GuideSchema;
use App\MailServer\Models\Mailbox;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\HelperTask;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    $this->lifecycleGuide = fn (string $t) => DB::table(GuideSchema::table($t));
    $this->lifecycleHash = fn (string $email) => ($this->lifecycleGuide)('virtual_users')->where('email', $email)->value('password');
});

it('disable swaps the hash, keeps the original and kicks', function () {
    $kicked = [];
    Http::fake(function (Request $request) use (&$kicked) {
        $call = $request->data()[0];
        $kicked[] = $call[1]['mask'];

        return Http::response([['doveadmResponse', [], $call[2]]]);
    });
    $old = ($this->lifecycleHash)('john@example.org');

    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();

    $live = ($this->lifecycleHash)('john@example.org');
    expect($live)->not->toBe($old)->toStartWith('{BLF-CRYPT}$2y$')
        ->and(DisabledMailbox::findOrFail('john@example.org')->saved_password)->toBe($old)
        ->and(DisabledMailbox::findOrFail('john@example.org')->domain_id)->toBe(10)
        ->and(DisabledMailbox::findOrFail('john@example.org')->disabled_by)->toBe((int) auth()->id());
    expect($kicked)->toBe([['john@example.org']]);
    $this->getJson('/api/v1/domains/example.org/mailboxes/john')->assertOk()->assertJsonPath('status', 'disabled');

    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('mailbox.disable')->and($audit->object_key)->toBe('john@example.org')
        ->and(json_encode($audit->toArray()))->not->toContain($old);
});

it('disabling twice is idempotent (204, one row)', function () {
    Http::fake();
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();
    $dead = ($this->lifecycleHash)('john@example.org');
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();

    expect(DisabledMailbox::count())->toBe(1)
        ->and(($this->lifecycleHash)('john@example.org'))->toBe($dead)
        ->and(AuditEntry::count())->toBe(1);
});

it('enable restores the exact original hash and removes the row', function () {
    Http::fake();
    $old = ($this->lifecycleHash)('john@example.org');
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/enable')->assertNoContent();

    expect(($this->lifecycleHash)('john@example.org'))->toBe($old)->and(DisabledMailbox::count())->toBe(0)
        ->and(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['mailbox.disable', 'mailbox.enable']);
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/enable')->assertNoContent();
    expect(AuditEntry::count())->toBe(2);
});

it('a password reset on a disabled mailbox updates the saved hash only and it stays disabled', function () {
    Http::fake();
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();
    $dead = ($this->lifecycleHash)('john@example.org');

    $password = $this->postJson('/api/v1/domains/example.org/mailboxes/john/password', ['generate' => true])->assertOk()->json('password');

    expect(($this->lifecycleHash)('john@example.org'))->toBe($dead)
        ->and(password_verify($password, substr(DisabledMailbox::findOrFail('john@example.org')->saved_password, 11)))->toBeTrue();
    $this->getJson('/api/v1/domains/example.org/mailboxes/john')->assertJsonPath('status', 'disabled');
});

it('deleting a disabled mailbox leaves no disabled_mailboxes row', function () {
    Http::fake();
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();
    $this->deleteJson('/api/v1/domains/example.org/mailboxes/john')->assertOk();

    expect(DisabledMailbox::count())->toBe(0)->and(Mailbox::where('email', 'john@example.org')->exists())->toBeFalse();
});

it('delete removes aliases pointing to and from the mailbox and reports the count', function () {
    Http::fake();
    $aliases = ($this->lifecycleGuide)('virtual_aliases');
    $aliases->delete();
    $aliases->insert([
        ['domain_id' => 10, 'source' => 'info@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'info@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'sales@example.org', 'destination' => 'John@Example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'x@gmail.com'],
        ['domain_id' => 10, 'source' => 'other@example.org', 'destination' => 'mary@example.org'],
    ]);
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'J']);
    MailboxUsage::create(['email' => 'john@example.org', 'used_kib' => 1, 'limit_kib' => 2, 'checked_at' => now()]);

    $this->deleteJson('/api/v1/domains/example.org/mailboxes/john')->assertOk()->assertExactJson(['forwarding_removed' => 2]);

    expect($aliases->orderBy('id')->get(['source', 'destination'])->map(fn ($r) => [$r->source, $r->destination])->all())
        ->toBe([['info@example.org', 'mary@example.org'], ['other@example.org', 'mary@example.org']]);
    expect(MailboxSetting::count())->toBe(0)->and(MailboxUsage::count())->toBe(0);
    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('mailbox.delete')->and($audit->changes)->toBe(['forwarding_removed' => 2]);
});

it('delete queues one archive task and kicks after commit', function () {
    $kicked = [];
    Http::fake(function (Request $request) use (&$kicked) {
        $call = $request->data()[0];
        $kicked[] = [$call[1]['mask'], HelperTask::count()];

        return Http::response([['doveadmResponse', [], $call[2]]]);
    });

    $this->deleteJson('/api/v1/domains/example.org/mailboxes/john')->assertOk();

    $task = HelperTask::sole();
    expect($task->type)->toBe('maildir.archive')->and($task->address)->toBe('john@example.org')
        ->and($task->domain_name)->toBe('example.org')->and($task->status)->toBe('pending');
    expect($kicked)->toBe([[['john@example.org'], 1]]);
});

it('re-creating the address right after delete is 409 archiving', function () {
    Http::fake();
    $this->deleteJson('/api/v1/domains/example.org/mailboxes/john')->assertOk();

    $this->postJson('/api/v1/domains/example.org/mailboxes', [
        'local' => 'john', 'password' => 'correct-horse-9', 'quota_bytes' => 1048576,
    ])->assertStatus(409)->assertJsonPath('code', 'archiving');
});

it('a doveadm outage during disable still returns 204', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->postJson('/api/v1/domains/example.org/mailboxes/john/disable')->assertNoContent();
    expect(DisabledMailbox::count())->toBe(1);
});

it('bulk quota changes three mailboxes in one transaction with three audit rows', function () {
    Http::fake();
    foreach (['a', 'b', 'c'] as $l) {
        insertMailbox("{$l}@example.org", 'x');
    }

    $this->postJson('/api/v1/domains/example.org/mailboxes/bulk', [
        'locals' => ['A', 'b', 'c'], 'action' => 'quota', 'quota_bytes' => 2097152,
    ])->assertOk()->assertExactJson(['changed' => 3]);

    expect(($this->lifecycleGuide)('virtual_users')->whereIn('email', ['a@example.org', 'b@example.org', 'c@example.org'])->pluck('quota')->all())
        ->toBe([2097152, 2097152, 2097152]);
    expect(AuditEntry::where('action', 'mailbox.update')->count())->toBe(3);
});

it('bulk with an unknown local changes nothing and is 422', function () {
    Http::fake();
    $this->postJson('/api/v1/domains/example.org/mailboxes/bulk', [
        'locals' => ['john', 'nobody'], 'action' => 'quota', 'quota_bytes' => 2097152,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['locals']]);

    expect(($this->lifecycleGuide)('virtual_users')->where('email', 'john@example.org')->value('quota'))->toBe(0)
        ->and(AuditEntry::count())->toBe(0);
});

it('bulk disable skips already-disabled mailboxes and reports changed=2 of 3', function () {
    Http::fake();
    insertMailbox('c@example.org', 'x');
    $this->postJson('/api/v1/domains/example.org/mailboxes/mary/disable')->assertNoContent();

    $this->postJson('/api/v1/domains/example.org/mailboxes/bulk', [
        'locals' => ['john', 'mary', 'c'], 'action' => 'disable',
    ])->assertOk()->assertExactJson(['changed' => 2]);

    expect(DisabledMailbox::count())->toBe(3);
    $this->postJson('/api/v1/domains/example.org/mailboxes/bulk', [
        'locals' => ['john', 'mary', 'c'], 'action' => 'enable',
    ])->assertOk()->assertExactJson(['changed' => 3]);
    expect(DisabledMailbox::count())->toBe(0);
});

it('bulk validates its body', function (array $body) {
    $this->postJson('/api/v1/domains/example.org/mailboxes/bulk', $body)->assertStatus(422);
})->with([
    'no locals' => [['locals' => [], 'action' => 'disable']],
    'too many' => [['locals' => array_map(fn ($i) => "u{$i}", range(1, 201)), 'action' => 'disable']],
    'bad action' => [['locals' => ['john'], 'action' => 'delete']],
    'quota without bytes' => [['locals' => ['john'], 'action' => 'quota']],
    'quota too small' => [['locals' => ['john'], 'action' => 'quota', 'quota_bytes' => 5]],
]);

it('a mailbox of another domain is 404 for disable, enable and delete', function () {
    ($this->lifecycleGuide)('virtual_domains')->insert(['id' => 11, 'name' => 'other.example']);
    ($this->lifecycleGuide)('virtual_users')->insert(['domain_id' => 11, 'email' => 'zoe@other.example', 'password' => 'x']);

    $this->postJson('/api/v1/domains/example.org/mailboxes/zoe/disable')->assertNotFound();
    $this->postJson('/api/v1/domains/example.org/mailboxes/zoe/enable')->assertNotFound();
    $this->deleteJson('/api/v1/domains/example.org/mailboxes/zoe')->assertNotFound();
});
