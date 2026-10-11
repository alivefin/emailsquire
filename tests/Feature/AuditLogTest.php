<?php

use App\Http\Requests\AuditLogRequest;
use App\MailServer\AuditActions;
use App\MailServer\AuditVisibility;
use App\MailServer\GuideSchema;
use App\MailServer\Queries\AuditLog;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Finder\Finder;
use Tests\Support\AuthorizationMatrix as Matrix;

// The activity log's API: which audit rows each user may read (AuditVisibility), and its filters.

/** @param  array<string, mixed>  $over */
function auditRow(string $action, ?string $domain, string $key, ?User $actor, array $over = []): AuditEntry
{
    return AuditEntry::create($over + [
        'occurred_at' => now(),
        'actor_type' => $actor === null ? 'cli' : 'user',
        'actor_id' => $actor === null ? 'root' : (string) $actor->id,
        'ip' => null,
        'action' => $action,
        'object_type' => explode('.', $action)[0],
        'object_key' => $key,
        'domain_name' => $domain,
    ]);
}

/** @return list<string> the object keys of a page, in order */
function auditKeys(mixed $test, string $query = ''): array
{
    return collect($test->getJson('/api/v1/audit-log'.$query)->assertOk()->json('data'))->pluck('object_key')->all();
}

beforeEach(function () {
    $this->freezeSecond();
    $this->principals = Matrix::seed();
    ['admin' => $admin, 'manager' => $manager, 'viewer' => $viewer] = $this->principals;
    $admin->forceFill(['name' => 'Ada Admin'])->save();
    $manager->forceFill(['name' => 'Max Manager'])->save();
    $viewer->forceFill(['name' => 'Vera Viewer'])->save();

    // One row of each kind, newest first in this order.
    $at = fn (int $minutesAgo) => ['occurred_at' => now()->subMinutes($minutesAgo)];
    auditRow('mailbox.create', 'a.org', 'mbox-a', $manager, $at(1));
    auditRow('mailbox.create', 'b.org', 'mbox-b', $admin, $at(2));
    auditRow('grant.created', 'a.org', 'grant-a', $admin, $at(3));
    auditRow('user.invited', 'a.org', 'invite-a', $manager, $at(4));
    auditRow('user.invited', null, 'invite-server', $admin, $at(5));
    auditRow('user.password_changed', null, 'password', $viewer, $at(6));
    auditRow('admin.create', null, 'admin-create', null, $at(7));
    auditRow('maildir.restore', 'a.org', 'restore-a', null, $at(8));
});

describe('visibility', function () {
    it('shows each user exactly the rows the rule allows, on the activity log and the dashboard', function (string $principal, array $expected) {
        $this->actingAs($this->principals[$principal]);

        expect(auditKeys($this))->toBe($expected);
        $this->getJson('/api/v1/audit-log')->assertJsonPath('meta.total', count($expected));
        expect(collect($this->getJson('/api/v1/dashboard')->assertOk()->json('recent_activity'))->pluck('object_key')->all())
            ->toBe(array_slice($expected, 0, 5));
    })->with([
        'admin' => ['admin', ['mbox-a', 'mbox-b', 'grant-a', 'invite-a', 'invite-server', 'password', 'admin-create', 'restore-a']],
        'manager of a.org' => ['manager', ['mbox-a', 'grant-a', 'invite-a']],
        'viewer of a.org' => ['viewer', ['mbox-a', 'grant-a', 'invite-a']],
        'no grant' => ['none', []],
    ]);

    it('hides admin-only actions from a domain\'s people even when the row names their domain', function () {
        auditRow('user.role_changed', 'a.org', 'role-a', $this->principals['admin']);
        auditRow('admin.setup', 'a.org', 'setup-a', $this->principals['admin']);
        auditRow('maildir.retry', 'a.org', 'retry-a', null);
        $this->actingAs($this->principals['manager']);

        expect(auditKeys($this))->toBe(['mbox-a', 'grant-a', 'invite-a']);
    });

    it('matches domains by name, case-insensitively', function () {
        auditRow('domain.limits_changed', 'A.ORG', 'upper-a', $this->principals['admin'], ['occurred_at' => now()->addMinute()]);
        $this->actingAs($this->principals['viewer']);

        expect(auditKeys($this))->toBe(['upper-a', 'mbox-a', 'grant-a', 'invite-a']);
    });

    it('names the admin-only actions', function () {
        $v = app(AuditVisibility::class);

        expect($v->isAdminOnly('admin.create'))->toBeTrue()
            ->and($v->isAdminOnly('maildir.restore'))->toBeTrue()
            ->and($v->isAdminOnly('user.deleted'))->toBeTrue()
            ->and($v->isAdminOnly('user.invited'))->toBeFalse()
            ->and($v->isAdminOnly('grant.created'))->toBeFalse()
            ->and($v->isAdminOnly('mailbox.create'))->toBeFalse();
    });

    it('shows a deactivated admin nothing', function () {
        $this->principals['admin']->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        expect(app(AuditLog::class)->paginate($this->principals['admin'], [], 1, 10)->total())->toBe(0);
    });
});

describe('action groups', function () {
    it('puts every action in exactly one group', function () {
        $grouped = array_merge(...array_values(AuditActions::GROUPS));

        expect(array_keys(AuditActions::GROUPS))->toBe(['mailboxes', 'forwarding', 'domains', 'blocked', 'access', 'account'])
            ->and($grouped)->toBe(array_values(array_unique($grouped)))
            ->and(collect(AuditActions::all())->sort()->values()->all())->toBe(collect($grouped)->sort()->values()->all());
    });

    it('lists every action the app writes, and nothing else', function () {
        $written = [];
        $unreadable = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            preg_match_all('/->record\(\s*([^,]+),/', $file->getContents(), $calls);
            foreach ($calls[1] as $first) {
                preg_match_all("/'([a-z0-9_]+\\.[a-z0-9_]+)'/", $first, $names);
                if ($names[1] === []) {
                    $unreadable[] = $file->getRelativePathname().': '.$first;
                }
                array_push($written, ...$names[1]);
            }
        }
        $written = array_values(array_unique($written));
        sort($written);
        $all = AuditActions::all();
        sort($all);

        // Actions picked by a condition rather than written as the only argument.
        expect($written)->toContain('user.reactivated', 'user.deactivated', 'user.two_factor_disabled', 'user.two_factor_reset')
            ->and($unreadable)->toBe([])
            ->and($all)->toBe($written);
    });

    it('groups the admin-only actions under account and mailboxes only', function () {
        $v = app(AuditVisibility::class);
        foreach (AuditActions::GROUPS as $group => $actions) {
            foreach ($actions as $action) {
                if ($v->isAdminOnly($action)) {
                    expect($group)->toBeIn(['account', 'mailboxes'], $action);
                }
            }
        }
        expect(AuditActions::GROUPS['access'])->toContain('user.invited')
            ->and(AuditActions::GROUPS['mailboxes'])->toContain('maildir.restore', 'maildir.retry')
            ->and(AuditActions::GROUPS['account'])->toContain('admin.create', 'admin.setup', 'user.deleted');
    });
});

describe('filters', function () {
    beforeEach(function () {
        auditRow('forwarding.delete', 'a.org', 'sys-fwd', null, ['actor_type' => 'system', 'actor_id' => null, 'occurred_at' => now()->subMinutes(9)]);
        $this->actingAs($this->principals['admin']);
    });

    it('filters by domain, ignoring case', function () {
        expect(auditKeys($this, '?domain=a.org'))->toBe(['mbox-a', 'grant-a', 'invite-a', 'restore-a', 'sys-fwd'])
            ->and(auditKeys($this, '?domain=B.ORG'))->toBe(['mbox-b'])
            ->and(auditKeys($this, '?domain=nowhere.example'))->toBe([]);
    });

    it('filters by actor: a user id, the command line or the system', function () {
        expect(auditKeys($this, '?actor='.$this->principals['manager']->id))->toBe(['mbox-a', 'invite-a'])
            ->and(auditKeys($this, '?actor=cli'))->toBe(['admin-create', 'restore-a'])
            ->and(auditKeys($this, '?actor=system'))->toBe(['sys-fwd'])
            ->and(auditKeys($this, '?actor=999999'))->toBe([]);
    });

    it('filters by action group', function () {
        expect(auditKeys($this, '?action_group=mailboxes'))->toBe(['mbox-a', 'mbox-b', 'restore-a'])
            ->and(auditKeys($this, '?action_group=access'))->toBe(['grant-a', 'invite-a', 'invite-server'])
            ->and(auditKeys($this, '?action_group=account'))->toBe(['password', 'admin-create'])
            ->and(auditKeys($this, '?action_group=forwarding'))->toBe(['sys-fwd'])
            ->and(auditKeys($this, '?action_group=domains'))->toBe([])
            ->and(auditKeys($this, '?action_group=blocked'))->toBe([]);
    });

    it('filters by date, from inclusive and to exclusive', function () {
        $from = urlencode(now()->subMinutes(6)->toIso8601String());
        $to = urlencode(now()->subMinutes(3)->toIso8601String());

        expect(auditKeys($this, "?from={$from}&to={$to}"))->toBe(['invite-a', 'invite-server', 'password'])
            ->and(auditKeys($this, "?from={$from}"))->toBe(['mbox-a', 'mbox-b', 'grant-a', 'invite-a', 'invite-server', 'password'])
            ->and(auditKeys($this, "?to={$to}"))->toBe(['invite-a', 'invite-server', 'password', 'admin-create', 'restore-a', 'sys-fwd']);
    });

    it('combines filters', function () {
        $manager = $this->principals['manager']->id;

        expect(auditKeys($this, '?domain=a.org&action_group=mailboxes&actor=cli'))->toBe(['restore-a'])
            ->and(auditKeys($this, "?domain=a.org&actor={$manager}"))->toBe(['mbox-a', 'invite-a'])
            ->and(auditKeys($this, "?action_group=access&actor={$manager}&to=".urlencode(now()->subMinutes(2)->toIso8601String())))->toBe(['invite-a'])
            ->and(auditKeys($this, '?domain=b.org&action_group=access'))->toBe([]);
    });

    it('refuses invalid filter values with 422', function (string $query, string $field) {
        $this->getJson('/api/v1/audit-log?'.$query)->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([$field]);
    })->with([
        ['action_group=everything', 'action_group'],
        ['actor=root', 'actor'],
        ['actor=0', 'actor'],
        ['actor=-3', 'actor'],
        ['actor[]=1', 'actor'],
        ['domain[]=a.org', 'domain'],
        ['from=2026-10-10', 'from'],
        ['from=2026-10-10T00:00:00', 'from'],
        ['from=yesterday', 'from'],
        ['to=2026-02-30T00:00:00Z', 'to'],
        ['to=2026-10-10T25:00:00%2B03:00', 'to'],
        ['actor=cl%0Ai', 'actor'],
        // The console's domain rules: two or more labels of lowercase ASCII (punycode), at most 50 characters.
        ['domain=not%20a%20domain', 'domain'],
        ['domain=localhost', 'domain'],
        ['domain=-bad.org', 'domain'],
        ['domain='.str_repeat('a', 47).'.org', 'domain'],
        ['before_id=abc', 'before_id'],
        ['before_id=0', 'before_id'],
        ['before_id=1.5', 'before_id'],
        ['before_id[]=1', 'before_id'],
    ]);

    it('refuses a trailing newline in the patterns, which TrimStrings strips from a query string first', function () {
        $rules = (new AuditLogRequest)->rules();
        foreach (['actor' => "cli\n", 'from' => "2026-10-10T00:00:00Z\n", 'to' => "2026-10-10T00:00:00+03:00\n"] as $field => $value) {
            expect(Validator::make([$field => $value], $rules)->errors()->has($field))->toBeTrue($field);
        }
        expect(Validator::make(['actor' => 'cli', 'from' => '2026-10-10T00:00:00Z'], $rules)->passes())->toBeTrue();

        expect(auditKeys($this, '?actor=cli%0A'))->toBe(auditKeys($this, '?actor=cli'))
            ->and(auditKeys($this, '?domain=a.org%0A'))->toBe(auditKeys($this, '?domain=a.org'));
    });

    it('takes a well-formed domain in any case or in Unicode, and an unknown one gives an empty page', function () {
        auditRow('domain.create', 'xn--kl-wia2h.fi', 'kyla', null, ['occurred_at' => now()->subMinutes(10)]);

        expect(auditKeys($this, '?domain=k%C3%BDl%C3%A4.fi'))->toBe(['kyla'])
            ->and(auditKeys($this, '?domain=XN--KL-WIA2H.FI'))->toBe(['kyla'])
            ->and(auditKeys($this, '?domain=unknown.example'))->toBe([]);
    });
});

describe('probing from another domain', function () {
    beforeEach(function () {
        $this->elsewhere = User::factory()->create(['email' => 'bea@console.test', 'name' => 'Bea Elsewhere', 'is_server_admin' => true]);
        auditRow('domain.limits_changed', 'b.org', 'limits-b', $this->elsewhere);
        auditRow('user.deleted', null, 'deleted-user', $this->elsewhere);
    });

    it('gives a manager, a viewer and a no-grant user no foreign rows, whatever the filter', function (string $principal) {
        $this->actingAs($this->principals[$principal]);
        $bea = $this->elsewhere->id;

        foreach (['?domain=b.org', "?actor={$bea}", '?action_group=account', '?domain=b.org&action_group=domains', "?actor={$bea}&domain=b.org", '?actor=cli'] as $query) {
            $this->getJson('/api/v1/audit-log'.$query)->assertOk()
                ->assertJsonPath('data', [])
                ->assertJsonPath('meta.total', 0);
        }
        foreach (['', '?domain=a.org', '?action_group=mailboxes', '?action_group=access'] as $query) {
            foreach ($this->getJson('/api/v1/audit-log'.$query)->assertOk()->json('data') as $row) {
                expect($row['domain_name'])->toBe('a.org')
                    ->and(app(AuditVisibility::class)->isAdminOnly($row['action']))->toBeFalse();
            }
        }
        expect(collect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))->pluck('name')->all())
            ->not->toContain('Bea Elsewhere');
    })->with(['manager', 'viewer', 'none']);

    it('an admin sees the same rows and actor', function () {
        $this->actingAs($this->principals['admin']);

        expect(auditKeys($this, '?actor='.$this->elsewhere->id))->toBe(['deleted-user', 'limits-b'])
            ->and(collect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))->pluck('name')->all())->toContain('Bea Elsewhere');
    });
});

describe('actors', function () {
    it('lists the distinct actors among the rows the user may see', function () {
        auditRow('mailbox.update', 'a.org', 'gone-a', null, ['actor_type' => 'user', 'actor_id' => '999999']);
        auditRow('forwarding.replace', 'a.org', 'sys-a', null, ['actor_type' => 'system', 'actor_id' => null]);
        auditRow('mailbox.disable', 'a.org', 'cli-a', null, ['actor_id' => 'someone-else']);
        ['admin' => $admin, 'manager' => $manager, 'viewer' => $viewer] = $this->principals;

        $this->actingAs($manager);
        expect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))->toBe([
            ['key' => (string) $admin->id, 'kind' => 'user', 'name' => 'Ada Admin'],
            ['key' => (string) $manager->id, 'kind' => 'user', 'name' => 'Max Manager'],
            ['key' => '999999', 'kind' => 'deleted_user', 'name' => 'Deleted user'],
            ['key' => 'cli', 'kind' => 'cli', 'name' => 'Command line'],
            ['key' => 'system', 'kind' => 'system', 'name' => 'System'],
        ]);

        $this->actingAs($admin);
        expect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))->toBe([
            ['key' => (string) $admin->id, 'kind' => 'user', 'name' => 'Ada Admin'],
            ['key' => (string) $manager->id, 'kind' => 'user', 'name' => 'Max Manager'],
            ['key' => (string) $viewer->id, 'kind' => 'user', 'name' => 'Vera Viewer'],
            ['key' => '999999', 'kind' => 'deleted_user', 'name' => 'Deleted user'],
            ['key' => 'cli', 'kind' => 'cli', 'name' => 'Command line'],
            ['key' => 'system', 'kind' => 'system', 'name' => 'System'],
        ]);

        $this->actingAs($this->principals['none']);
        $this->getJson('/api/v1/audit-log/actors')->assertOk()->assertExactJson(['data' => []]);
    });

    it('does not list the command line to a manager when it acted only on admin-only rows', function () {
        $this->actingAs($this->principals['manager']);

        expect(collect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))->pluck('key')->all())->not->toContain('cli');
    });
});

describe('rows written under another domain', function () {
    // Intended: AliasRepository::removePointing() audits each unhooked source under the source's own domain,
    // so a.org's people see who removed their forwarding, even when the actor has no role on a.org.
    it('shows a.org\'s manager the forwarding row a b.org mailbox delete wrote under a.org, and its actor', function () {
        Http::fake();
        $bManager = User::factory()->create(['name' => 'Bea Bmanager']);
        Matrix::grant($bManager, 'b.org', 'manager');
        DB::table(GuideSchema::table('virtual_aliases'))->insert(
            ['domain_id' => Matrix::A_ID, 'source' => 'cross@a.org', 'destination' => 'john@b.org'],
        );

        $this->actingAs($bManager)->deleteJson('/api/v1/domains/b.org/mailboxes/john')->assertOk();

        $this->actingAs($this->principals['manager']);
        $rows = collect($this->getJson('/api/v1/audit-log?domain=a.org')->assertOk()->json('data'))
            ->filter(fn (array $r) => str_starts_with($r['action'], 'forwarding.'));
        expect($rows->pluck('object_key')->all())->toBe(['cross@a.org'])
            ->and($rows->first()['domain_name'])->toBe('a.org')
            ->and($rows->first()['actor_name'])->toBe('Bea Bmanager');
        $actors = collect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'));
        expect($actors->pluck('key')->all())->toContain((string) $bManager->id);
    });
});

describe('dates', function () {
    it('includes a row before the end of the last day in the browser\'s zone and excludes one at midnight', function () {
        AuditEntry::query()->delete();
        $this->actingAs($this->principals['admin']);
        auditRow('mailbox.create', 'a.org', 'late-evening', null, ['occurred_at' => Carbon::parse('2026-10-10T20:30:00Z')]);
        auditRow('mailbox.create', 'a.org', 'next-midnight', null, ['occurred_at' => Carbon::parse('2026-10-10T21:00:00Z')]);
        auditRow('mailbox.create', 'a.org', 'day-start', null, ['occurred_at' => Carbon::parse('2026-10-09T21:00:00Z')]);
        auditRow('mailbox.create', 'a.org', 'day-before', null, ['occurred_at' => Carbon::parse('2026-10-09T20:59:59.999999Z')]);

        $from = urlencode('2026-10-10T00:00:00+03:00');
        $to = urlencode('2026-10-11T00:00:00+03:00');

        expect(auditKeys($this, "?from={$from}&to={$to}"))->toBe(['late-evening', 'day-start'])
            ->and(auditKeys($this, '?from=2026-10-10T21:00:00Z'))->toBe(['next-midnight']);
    });
});

describe('dates across daylight saving time', function () {
    it('takes each bound with its own offset when the range spans the 2026-10-25 change in Helsinki', function () {
        AuditEntry::query()->delete();
        $this->actingAs($this->principals['admin']);
        foreach ([
            'before-start' => '2026-10-23T20:59:59Z', // 23:59:59 EEST on the 23rd
            'start' => '2026-10-23T21:00:00Z',        // 00:00 EEST on the 24th
            'repeated-hour' => '2026-10-25T00:30:00Z', // 03:30 EEST, before the clocks go back
            'repeated-again' => '2026-10-25T01:30:00Z', // 03:30 EET, after
            'last-evening' => '2026-10-25T21:30:00Z', // 23:30 EET on the 25th
            'next-midnight' => '2026-10-25T22:00:00Z', // 00:00 EET on the 26th
        ] as $key => $at) {
            auditRow('mailbox.create', 'a.org', $key, null, ['occurred_at' => Carbon::parse($at)]);
        }

        $from = urlencode('2026-10-24T00:00:00+03:00');
        $to = urlencode('2026-10-26T00:00:00+02:00');

        expect(auditKeys($this, "?from={$from}&to={$to}&per_page=50"))->toBe(['last-evening', 'repeated-again', 'repeated-hour', 'start']);
    });
});

describe('paging', function () {
    it('keeps page 2 in place with a before_id anchor while new rows arrive', function () {
        AuditEntry::query()->delete();
        $this->actingAs($this->principals['admin']);
        foreach (['one', 'two', 'three', 'four', 'five'] as $i => $key) {
            auditRow('mailbox.create', 'a.org', $key, null, ['occurred_at' => now()->subMinutes(10 - $i)]);
        }

        $first = $this->getJson('/api/v1/audit-log?per_page=2')->assertOk();
        expect(collect($first->json('data'))->pluck('object_key')->all())->toBe(['five', 'four']);
        $anchor = $first->json('data.0.id');

        auditRow('mailbox.create', 'a.org', 'newer', null);

        // Without the anchor the new row pushes "four" onto page 2; with it, page 2 is what it was.
        expect(auditKeys($this, '?per_page=2&page=2'))->toBe(['four', 'three'])
            ->and(auditKeys($this, "?per_page=2&page=2&before_id={$anchor}"))->toBe(['three', 'two'])
            ->and(auditKeys($this, "?per_page=2&page=3&before_id={$anchor}"))->toBe(['one']);
        $this->getJson("/api/v1/audit-log?per_page=2&before_id={$anchor}")->assertOk()->assertJsonPath('meta.total', 5);
    });

    it('reads actor names with a constant number of queries, however long the page', function () {
        AuditEntry::query()->delete();
        $this->actingAs($this->principals['admin']);
        $people = User::factory()->count(25)->create();
        $count = function (int $rows): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/audit-log?per_page={$rows}")->assertOk()->assertJsonCount($rows, 'data');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 50) as $i) {
            auditRow('mailbox.update', 'a.org', "row{$i}", $people[$i % 25], ['occurred_at' => now()->subSeconds($i)]);
        }
        // Some deleted users, the command line and the system too.
        foreach (range(1, 3) as $i) {
            auditRow('mailbox.update', 'a.org', "gone{$i}", null, ['actor_type' => 'user', 'actor_id' => (string) (900000 + $i), 'occurred_at' => now()->subSeconds(60 + $i)]);
        }
        auditRow('mailbox.update', 'a.org', 'sys', null, ['actor_type' => 'system', 'actor_id' => null, 'occurred_at' => now()->subSeconds(70)]);

        $one = $count(1);
        expect($count(50))->toBe($one)
            ->and($count(54))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(12);
    });

    it('reads the dashboard\'s actor names with a constant number of queries', function () {
        $this->actingAs($this->principals['admin']);
        $people = User::factory()->count(5)->create();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/dashboard')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        AuditEntry::query()->delete();
        auditRow('mailbox.update', 'a.org', 'only', $people[0]);
        $one = $count();
        foreach ($people as $i => $person) {
            auditRow('mailbox.update', 'a.org', "row{$i}", $person, ['occurred_at' => now()->addSeconds($i + 1)]);
        }

        expect($count())->toBe($one);
    });

    it('orders rows with the same time by id, newest first, and pages through them stably', function () {
        AuditEntry::query()->delete();
        $this->actingAs($this->principals['admin']);
        $at = Carbon::parse('2026-10-10T12:00:00Z');
        $ids = [];
        foreach (['one', 'two', 'three', 'four', 'five'] as $key) {
            $ids[] = auditRow('mailbox.create', 'a.org', $key, null, ['occurred_at' => $at])->id;
        }

        $page1 = $this->getJson('/api/v1/audit-log?per_page=2')->assertOk()
            ->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
        expect(collect($page1->json('data'))->pluck('id')->all())->toBe([$ids[4], $ids[3]])
            ->and(auditKeys($this, '?per_page=2&page=2'))->toBe(['three', 'two'])
            ->and(auditKeys($this, '?per_page=2&page=3'))->toBe(['one']);
    });

    it('defaults to ten rows a page and caps per_page at 200', function () {
        $this->actingAs($this->principals['admin']);

        $this->getJson('/api/v1/audit-log')->assertOk()->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v1/audit-log?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 200);
        $this->getJson('/api/v1/audit-log?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    });

    it('returns each row as the dashboard does, plus its id', function () {
        $this->actingAs($this->principals['admin']);
        $row = $this->getJson('/api/v1/audit-log?per_page=1')->assertOk()->json('data.0');

        expect(array_keys($row))->toBe(['id', 'occurred_at', 'actor_kind', 'actor_name', 'action', 'object_type', 'object_key', 'domain_name', 'changes'])
            ->and($row['id'])->toBeInt()
            ->and($row['actor_name'])->toBe('Max Manager');
    });
});
