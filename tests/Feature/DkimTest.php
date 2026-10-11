<?php

use App\Doctor\Checks\AppOrphans;
use App\Doctor\Status;
use App\MailServer\Dkim;
use App\MailServer\Dns\DnsHealth;
use App\MailServer\Dns\DnsResolver;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\Models\AuditEntry;
use App\Models\DomainDkim;
use App\Models\HelperTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\GuideFixture;
use Tests\Support\StubDnsResolver;
use Tests\TestCase;

// Phase 4, Task 2: a domain's DKIM keys managed from the console (spec decisions 2 to 5). The console
// queues dkim.* helper tasks and reads their results back into domain_dkim; the helper itself is
// faked here by finishing the task rows the way helpers/dkim.php does.

const DKIM_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAdkimtestkeydkimtestkeydkimtestkeyIDAQAB';
const DKIM_TXT = 'v=DKIM1; k=rsa; p='.DKIM_KEY;
const DKIM_OTHER_TXT = 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAotherotherotherIDAQAB';

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off', 'emailsquire.mail_host' => 'mail.example.org']);
    GuideFixture::load('trixie'); // example.org, id 10
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'example.net']);

    $this->admin = User::factory()->create(['is_server_admin' => true, 'password_set_at' => now()]);
    $this->manager = User::factory()->create(['password_set_at' => now()]);
    $this->viewer = User::factory()->create(['password_set_at' => now()]);
    $this->outsider = User::factory()->create(['password_set_at' => now()]);
    AuthorizationMatrix::grant($this->manager, 'example.org', 'manager');
    AuthorizationMatrix::grant($this->viewer, 'example.org', 'viewer');
});

/** Signed in as the admin with a fresh step-up. */
function dkimAdmin(): TestCase
{
    $test = test()->actingAs(test()->admin);
    confirmStepUp();

    return $test;
}

function dkimRow(string $selector, string $state, string $txt = DKIM_TXT, int $domainId = 10, string $domainName = 'example.org'): DomainDkim
{
    return DomainDkim::query()->forceCreate([
        'domain_id' => $domainId, 'domain_name' => $domainName, 'selector' => $selector, 'txt' => $txt, 'state' => $state,
    ]);
}

/** The newest dkim.* task. */
function consoleDkimTask(): HelperTask
{
    return HelperTask::query()->where('type', 'like', 'dkim.%')->orderByDesc('id')->firstOrFail();
}

/** Finishes $task as the helper does: done with a result, at $at (now by default). */
function dkimFinish(HelperTask $task, array $result, ?Carbon $at = null): void
{
    DB::table('helper_tasks')->where('id', $task->id)->update([
        'status' => 'done', 'result' => json_encode($result), 'note' => 'done', 'updated_at' => $at ?? now(),
    ]);
}

/** A stored DNS check of example.org in which $selector's TXT is (or is not) published. */
function dkimDnsCheck(string $selector, bool $published, string $txt = DKIM_TXT): void
{
    $stub = app(DnsResolver::class);
    assert($stub instanceof StubDnsResolver);
    $stub->set("{$selector}._domainkey.example.org", 'TXT', $published ? [$txt] : []);
    $domain = Domain::named('example.org');
    assert($domain instanceof Domain);
    app(DnsHealth::class)->run($domain);
}

/** A task queued directly, as another admin's request would have. */
function dkimQueued(string $type, ?string $selector = null, string $status = 'pending'): HelperTask
{
    $task = new HelperTask;
    $task->forceFill([
        'type' => $type, 'address' => null, 'domain_name' => 'example.org', 'status' => $status, 'run_after' => now(),
        'payload' => array_filter(['domain' => 'example.org', 'domain_id' => 10, 'selector' => $selector]),
    ])->save();

    return $task;
}

describe('reading', function () {
    it('shows the keys, the active selector and no task to any role on the domain', function (string $who) {
        dkimRow('es20261001', 'active');
        dkimRow('es20261011', 'pending', DKIM_OTHER_TXT);
        dkimRow('es20250101', 'retired');

        $response = $this->actingAs($this->{$who})->getJson('/api/v1/domains/example.org/dkim')->assertOk();

        expect($response->json('active'))->toBe('es20261001')
            ->and($response->json('pending_task'))->toBeNull()
            ->and(array_column($response->json('keys'), 'selector'))->toBe(['es20250101', 'es20261011', 'es20261001'])
            ->and(array_column($response->json('keys'), 'state'))->toBe(['retired', 'pending', 'active'])
            ->and($response->json('keys.2.txt'))->toBe(DKIM_TXT)
            ->and($response->json('keys.2.created_at'))->toBeString();
    })->with(['admin', 'manager', 'viewer']);

    it('is a 404 to a user with no role on the domain, as for a domain that does not exist', function () {
        $ungranted = $this->actingAs($this->outsider)->getJson('/api/v1/domains/example.org/dkim')->assertNotFound();
        $missing = $this->actingAs($this->outsider)->getJson('/api/v1/domains/nowhere.org/dkim')->assertNotFound();

        expect($ungranted->getContent())->toBe($missing->getContent());
    });

    it('ignores rows of a reused domain id under another name', function () {
        dkimRow('es20261001', 'active', domainName: 'old.example');

        $response = $this->actingAs($this->admin)->getJson('/api/v1/domains/example.org/dkim')->assertOk();

        expect($response->json('active'))->toBeNull()->and($response->json('keys'))->toBe([]);
    });

    it('shows a pending or running task, and a failed one until another task runs', function () {
        $task = dkimQueued('dkim.generate');
        $this->actingAs($this->viewer)->getJson('/api/v1/domains/example.org/dkim')
            ->assertJsonPath('pending_task', ['type' => 'dkim.generate', 'status' => 'pending']);

        $task->forceFill(['status' => 'running'])->save();
        $this->getJson('/api/v1/domains/example.org/dkim')->assertJsonPath('pending_task', ['type' => 'dkim.generate', 'status' => 'running']);

        $task->forceFill(['status' => 'failed', 'last_error' => 'refused'])->save();
        $this->getJson('/api/v1/domains/example.org/dkim')->assertJsonPath('pending_task', ['type' => 'dkim.generate', 'status' => 'failed']);

        dkimFinish(dkimQueued('dkim.inspect'), ['active_selector' => null, 'keys' => []]);
        $this->getJson('/api/v1/domains/example.org/dkim')->assertJsonPath('pending_task', null);
    });
});

describe('writing', function () {
    it('lets only admins write: managers and viewers get 403, no role 404', function (string $method, string $uri) {
        dkimRow('es20261011', 'pending');
        foreach (['manager' => 403, 'viewer' => 403, 'outsider' => 404] as $who => $status) {
            $this->actingAs($this->{$who});
            confirmStepUp();
            $this->json($method, $uri, ['force' => true])->assertStatus($status);
        }

        expect(HelperTask::query()->count())->toBe(0)->and(AuditEntry::query()->count())->toBe(0);
    })->with([
        ['POST', '/api/v1/domains/example.org/dkim/generate'],
        ['POST', '/api/v1/domains/example.org/dkim/inspect'],
        ['POST', '/api/v1/domains/example.org/dkim/es20261011/activate'],
        ['POST', '/api/v1/domains/example.org/dkim/es20261011/retire'],
    ]);

    it('needs step-up for every write (423), and queues nothing without it', function (string $uri) {
        dkimRow('es20261011', 'pending');

        $this->actingAs($this->admin)->postJson($uri, ['force' => true])
            ->assertStatus(423)->assertJsonPath('code', 'step_up_required');

        expect(HelperTask::query()->count())->toBe(0);
    })->with([
        '/api/v1/domains/example.org/dkim/generate',
        '/api/v1/domains/example.org/dkim/inspect',
        '/api/v1/domains/example.org/dkim/es20261011/activate',
        '/api/v1/domains/example.org/dkim/es20261011/retire',
    ]);

    it('queues dkim.generate with the domain, and audits dkim.generated with the admin as actor', function () {
        $response = dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/generate')->assertStatus(202);

        $task = consoleDkimTask();
        expect($task->type)->toBe('dkim.generate')
            ->and($task->status)->toBe('pending')
            ->and($task->domain_name)->toBe('example.org')
            ->and($task->address)->toBeNull()
            ->and($task->payload)->toBe(['domain' => 'example.org', 'domain_id' => 10])
            ->and($response->json('pending_task'))->toBe(['type' => 'dkim.generate', 'status' => 'pending']);

        $entry = AuditEntry::query()->where('action', 'dkim.generated')->sole();
        expect($entry->actor_type)->toBe('user')
            ->and($entry->actor_id)->toBe((string) $this->admin->id)
            ->and($entry->object_type)->toBe('domain')
            ->and($entry->object_key)->toBe('example.org')
            ->and($entry->domain_name)->toBe('example.org')
            ->and($entry->changes)->toBeNull();
    });

    it('queues dkim.inspect without an audit row', function () {
        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/inspect')->assertStatus(202);

        expect(consoleDkimTask()->type)->toBe('dkim.inspect')
            ->and(consoleDkimTask()->payload)->toBe(['domain' => 'example.org', 'domain_id' => 10])
            ->and(AuditEntry::query()->count())->toBe(0);
    });

    it('refuses a second task while one is pending or running for the domain (409 dkim_busy)', function (string $status, string $uri) {
        dkimRow('es20261011', 'pending');
        dkimQueued('dkim.generate', status: $status);

        dkimAdmin()->postJson($uri, ['force' => true])
            ->assertStatus(409)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'dkim_busy');

        expect(HelperTask::query()->count())->toBe(1)->and(AuditEntry::query()->count())->toBe(0);
    })->with(['pending', 'running'])->with([
        '/api/v1/domains/example.org/dkim/generate',
        '/api/v1/domains/example.org/dkim/inspect',
        '/api/v1/domains/example.org/dkim/es20261011/activate',
        '/api/v1/domains/example.org/dkim/es20261011/retire',
    ]);

    it('is not busy because of another domain, or a failed or finished task', function () {
        dkimQueued('dkim.generate', status: 'failed');
        dkimFinish(dkimQueued('dkim.inspect'), ['active_selector' => null, 'keys' => []]);
        $other = new HelperTask;
        $other->forceFill(['type' => 'dkim.generate', 'domain_name' => 'example.net', 'payload' => ['domain' => 'example.net', 'domain_id' => 11], 'status' => 'pending', 'run_after' => now()])->save();

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/generate')->assertStatus(202);
    });

    it('answers 404 for a selector the console does not know, a retired one, or another domain\'s', function (string $selector) {
        dkimRow('es20250101', 'retired');
        dkimRow('es20261011', 'pending', domainId: 11, domainName: 'example.net');

        dkimAdmin()->postJson("/api/v1/domains/example.org/dkim/{$selector}/activate", ['force' => true])->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson("/api/v1/domains/example.org/dkim/{$selector}/retire")->assertNotFound()->assertJsonPath('code', 'not_found');

        expect(HelperTask::query()->count())->toBe(0);
    })->with(['es20990101', 'es20250101', 'es20261011', 'dkim', 'ES20261011', 'es2026101']);
});

describe('activate', function () {
    it('is refused with 409 dkim_not_published while the latest DNS check does not show the key', function (?bool $published) {
        dkimRow('es20261011', 'pending');
        if ($published !== null) {
            dkimDnsCheck('es20261011', $published, $published ? DKIM_OTHER_TXT : DKIM_TXT); // a different record is not "published"
        }

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261011/activate')
            ->assertStatus(409)->assertJsonPath('code', 'dkim_not_published');
        $this->postJson('/api/v1/domains/example.org/dkim/es20261011/activate', ['force' => false])->assertStatus(409);

        expect(HelperTask::query()->count())->toBe(0)->and(AuditEntry::query()->count())->toBe(0);
    })->with(['no check yet' => null, 'nothing published' => false, 'another record published' => true]);

    it('queues dkim.activate once DNS shows the key, audited as dkim.activated', function () {
        dkimRow('es20261011', 'pending');
        dkimDnsCheck('es20261011', true);

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261011/activate')->assertStatus(202)
            ->assertJsonPath('pending_task', ['type' => 'dkim.activate', 'status' => 'pending']);

        expect(consoleDkimTask()->type)->toBe('dkim.activate')
            ->and(consoleDkimTask()->payload)->toBe(['domain' => 'example.org', 'domain_id' => 10, 'selector' => 'es20261011']);
        $entry = AuditEntry::query()->where('action', 'dkim.activated')->sole();
        expect($entry->object_type)->toBe('domain')
            ->and($entry->object_key)->toBe('example.org')
            ->and($entry->domain_name)->toBe('example.org')
            ->and($entry->actor_id)->toBe((string) $this->admin->id)
            ->and($entry->changes)->toBe(['selector' => 'es20261011', 'forced' => false]);
    });

    it('queues dkim.activate without DNS when the admin forces it, and the audit row says so', function () {
        dkimRow('es20261011', 'pending');

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261011/activate', ['force' => true])->assertStatus(202);

        expect(consoleDkimTask()->type)->toBe('dkim.activate')
            ->and(AuditEntry::query()->where('action', 'dkim.activated')->sole()->changes)->toBe(['selector' => 'es20261011', 'forced' => true]);
    });

    it('records forced: false when force is sent but DNS already shows the key', function () {
        dkimRow('es20261011', 'pending');
        dkimDnsCheck('es20261011', true);

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261011/activate', ['force' => true])->assertStatus(202);

        expect(AuditEntry::query()->where('action', 'dkim.activated')->sole()->changes)->toBe(['selector' => 'es20261011', 'forced' => false]);
    });

    it('refuses the key that is already active (409 dkim_active)', function () {
        dkimRow('es20261001', 'active');

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261001/activate', ['force' => true])
            ->assertStatus(409)->assertJsonPath('code', 'dkim_active');
    });

    it('validates force as a boolean', function () {
        dkimRow('es20261011', 'pending');

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261011/activate', ['force' => 'yes please'])
            ->assertStatus(422)->assertJsonValidationErrors('force');
    });
});

describe('retire', function () {
    it('refuses the active key (409 dkim_active)', function () {
        dkimRow('es20261001', 'active');

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20261001/retire')
            ->assertStatus(409)->assertJsonPath('code', 'dkim_active');

        expect(HelperTask::query()->count())->toBe(0);
    });

    it('queues dkim.retire for a key that is not active, audited as dkim.retired', function () {
        dkimRow('es20261001', 'active');
        dkimRow('es20250101', 'pending');

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20250101/retire')->assertStatus(202);

        expect(consoleDkimTask()->type)->toBe('dkim.retire')
            ->and(consoleDkimTask()->payload)->toBe(['domain' => 'example.org', 'domain_id' => 10, 'selector' => 'es20250101']);
        $entry = AuditEntry::query()->where('action', 'dkim.retired')->sole();
        expect($entry->object_key)->toBe('example.org')
            ->and($entry->changes)->toBe(['selector' => 'es20250101']);
    });

    it('holds retire for 15 minutes after the latest activation (409 dkim_recently_activated with retry_after)', function () {
        Carbon::setTestNow('2026-10-11 12:00:00');
        dkimRow('es20250101', 'active');
        dkimRow('es20261011', 'pending');
        $activate = dkimQueued('dkim.activate', 'es20261011');
        dkimFinish($activate, ['selector' => 'es20261011', 'txt' => DKIM_TXT], Carbon::parse('2026-10-11 12:00:00'));

        Carbon::setTestNow('2026-10-11 12:05:00');
        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20250101/retire')
            ->assertStatus(409)
            ->assertJsonPath('code', 'dkim_recently_activated')
            ->assertJsonPath('retry_after', 600)
            ->assertHeader('Retry-After', '600');

        Carbon::setTestNow('2026-10-11 12:14:59');
        confirmStepUp(); // the step-up window is 5 minutes
        $this->postJson('/api/v1/domains/example.org/dkim/es20250101/retire')->assertStatus(409)->assertJsonPath('retry_after', 1);
        expect(HelperTask::query()->where('type', 'dkim.retire')->count())->toBe(0);

        Carbon::setTestNow('2026-10-11 12:15:00');
        confirmStepUp();
        $this->postJson('/api/v1/domains/example.org/dkim/es20250101/retire')->assertStatus(202);
        Carbon::setTestNow();
    });

    it('measures the hold from the domain\'s own latest activation only', function () {
        Carbon::setTestNow('2026-10-11 12:00:00');
        dkimRow('es20261001', 'active');
        dkimRow('es20250101', 'pending');
        $other = new HelperTask;
        $other->forceFill(['type' => 'dkim.activate', 'domain_name' => 'example.net', 'payload' => ['domain' => 'example.net', 'domain_id' => 11, 'selector' => 'es20261011'], 'status' => 'done', 'run_after' => now()])->save();
        $old = dkimQueued('dkim.activate', 'es20261001');
        dkimFinish($old, ['selector' => 'es20261001', 'txt' => DKIM_TXT], Carbon::parse('2026-10-11 11:00:00'));

        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/es20250101/retire')->assertStatus(202);
        Carbon::setTestNow();
    });
});

describe('reading results back', function () {
    it('fills domain_dkim from a generate result on the next read, once', function () {
        dkimAdmin()->postJson('/api/v1/domains/example.org/dkim/generate')->assertStatus(202);
        dkimFinish(consoleDkimTask(), ['selector' => 'es20261011', 'txt' => DKIM_TXT]);

        $response = $this->getJson('/api/v1/domains/example.org/dkim')->assertOk();
        $this->getJson('/api/v1/domains/example.org/dkim')->assertOk();

        expect($response->json('keys'))->toHaveCount(1)
            ->and($response->json('keys.0'))->toMatchArray(['selector' => 'es20261011', 'state' => 'pending', 'txt' => DKIM_TXT])
            ->and($response->json('pending_task'))->toBeNull()
            ->and(DomainDkim::query()->count())->toBe(1)
            ->and(DomainDkim::query()->sole()->domain_name)->toBe('example.org')
            ->and(consoleDkimTask()->applied_at)->not->toBeNull();
    });

    it('applies results in task order: activate makes one key active and the old one pending, retire retires it', function () {
        dkimRow('es20250101', 'active', DKIM_OTHER_TXT);
        dkimFinish(dkimQueued('dkim.generate'), ['selector' => 'es20261011', 'txt' => DKIM_TXT]);
        dkimFinish(dkimQueued('dkim.activate', 'es20261011'), ['selector' => 'es20261011', 'txt' => DKIM_TXT]);

        $domain = Domain::named('example.org');
        app(Dkim::class)->sync($domain);
        expect(DomainDkim::query()->orderBy('selector')->pluck('state', 'selector')->all())->toBe(['es20250101' => 'pending', 'es20261011' => 'active']);

        dkimFinish(dkimQueued('dkim.retire', 'es20250101'), ['selector' => 'es20250101']);
        $this->actingAs($this->viewer)->getJson('/api/v1/domains/example.org/dkim')
            ->assertJsonPath('active', 'es20261011');

        expect(DomainDkim::query()->orderBy('selector')->pluck('state', 'selector')->all())->toBe(['es20250101' => 'retired', 'es20261011' => 'active']);
    });

    it('brings a retired row back as pending when the helper reuses its selector', function () {
        dkimRow('es20261011', 'retired', DKIM_OTHER_TXT);
        dkimFinish(dkimQueued('dkim.generate'), ['selector' => 'es20261011', 'txt' => DKIM_TXT]);

        app(Dkim::class)->sync(Domain::named('example.org'));

        expect(DomainDkim::query()->sole())->state->toBe('pending')->txt->toBe(DKIM_TXT);
    });

    it('imports the keys an inspect finds, the map\'s selector active, and retires rows whose key file is gone', function () {
        dkimRow('es20240101', 'pending'); // its file is gone
        dkimRow('es20250101', 'active', DKIM_OTHER_TXT);
        dkimFinish(dkimQueued('dkim.inspect'), [
            'active_selector' => 'dkim',
            'keys' => [
                ['selector' => 'dkim', 'txt' => DKIM_TXT], // the installer's key
                ['selector' => 'es20250101', 'txt' => DKIM_OTHER_TXT],
                ['selector' => 'ed', 'txt' => null], // a key PHP can't read (ed25519)
                ['selector' => str_repeat('x', 40), 'txt' => DKIM_TXT], // too long for the column: skipped
                ['selector' => '../evil', 'txt' => DKIM_TXT], // not a DNS label: skipped
            ],
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/domains/example.org/dkim')->assertOk();

        expect($response->json('active'))->toBe('dkim')
            ->and(DomainDkim::query()->orderBy('selector')->get(['selector', 'state', 'txt'])->toArray())->toBe([
                ['selector' => 'dkim', 'state' => 'active', 'txt' => DKIM_TXT],
                ['selector' => 'ed', 'state' => 'pending', 'txt' => ''],
                ['selector' => 'es20240101', 'state' => 'retired', 'txt' => DKIM_TXT],
                ['selector' => 'es20250101', 'state' => 'pending', 'txt' => DKIM_OTHER_TXT],
            ])
            ->and(collect($response->json('keys'))->firstWhere('selector', 'ed')['txt'])->toBeNull();
    });

    it('marks a failed task read without changing any key', function () {
        dkimRow('es20261001', 'active');
        $task = dkimQueued('dkim.activate', 'es20261001', status: 'failed');

        app(Dkim::class)->sync(Domain::named('example.org'));

        expect($task->fresh()?->applied_at)->not->toBeNull()
            ->and(DomainDkim::query()->sole()->state)->toBe('active');
    });

    it('never applies a result of another domain, or of an earlier domain with the same name', function () {
        $earlier = new HelperTask;
        $earlier->forceFill(['type' => 'dkim.generate', 'domain_name' => 'example.org', 'payload' => ['domain' => 'example.org', 'domain_id' => 99], 'status' => 'pending', 'run_after' => now()])->save();
        dkimFinish($earlier, ['selector' => 'es20261011', 'txt' => DKIM_TXT]);
        $other = new HelperTask;
        $other->forceFill(['type' => 'dkim.generate', 'domain_name' => 'example.net', 'payload' => ['domain' => 'example.net', 'domain_id' => 11], 'status' => 'pending', 'run_after' => now()])->save();
        dkimFinish($other, ['selector' => 'es20261012', 'txt' => DKIM_TXT]);

        $this->actingAs($this->admin)->getJson('/api/v1/domains/example.org/dkim')->assertJsonPath('keys', []);

        expect(DomainDkim::query()->where('domain_id', 10)->count())->toBe(0);
    });

    it('ignores a malformed result', function (array $result) {
        dkimFinish(dkimQueued('dkim.generate'), $result);

        app(Dkim::class)->sync(Domain::named('example.org'));

        expect(DomainDkim::query()->count())->toBe(0)->and(consoleDkimTask()->applied_at)->not->toBeNull();
    })->with([
        'no selector' => [['txt' => DKIM_TXT]],
        'bad selector' => [['selector' => 'a b', 'txt' => DKIM_TXT]],
        'no txt' => [['selector' => 'es20261011']],
        'not a DKIM record' => [['selector' => 'es20261011', 'txt' => '<script>']],
    ]);
});

describe('domain delete', function () {
    it('removes the domain\'s domain_dkim rows and leaves other domains\' alone', function () {
        dkimRow('es20261001', 'active');
        dkimRow('es20261001', 'active', domainId: 11, domainName: 'example.net');
        fakeDoveadmBatch(fn () => ['doveadmResponse', []]); // the deleted mailboxes' sessions are kicked

        dkimAdmin()->deleteJson('/api/v1/domains/example.org', ['confirm_name' => 'example.org'])->assertNoContent();

        expect(DomainDkim::query()->pluck('domain_name')->all())->toBe(['example.net']);
    });

    it('is listed by the doctor\'s orphan check when a domain went outside the console, noting the key files', function () {
        isolateDoctorFromHost();
        dkimRow('es20261001', 'active', domainId: 99, domainName: 'gone.test');
        dkimRow('es20261002', 'active');

        $result = app(AppOrphans::class)->run();

        expect($result->status)->toBe(Status::Warn)
            ->and($result->items)->toHaveCount(1)
            ->and($result->items[0])->toContain('domain_dkim')->toContain('es20261001')->toContain('gone.test')->toContain('key file');
    });
});
