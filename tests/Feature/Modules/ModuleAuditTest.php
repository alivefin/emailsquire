<?php

use App\MailServer\AuditActions;
use App\MailServer\AuditVisibility;
use App\Models\AuditEntry;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthorizationMatrix as Matrix;

// A module's audit actions merge with the core's for the activity log's group filter, are for server
// admins only, and a module whose actions clash with the core's or another module's is refused.

beforeEach(function () {
    config([
        'emailsquire.modules_root' => 'tests/fixtures/modules',
        'emailsquire.modules_require_root_owner' => false,
    ]);
});

/** Runs $test with the example module loaded and its table migrated, then removes the table. */
function withExampleNotes(Closure $test): void
{
    bootWithModules(fixtureModule('example'));
    Schema::dropIfExists('example_notes');
    DB::table('migrations')->where('migration', 'like', '%example_notes%')->delete();
    Artisan::call('migrate', ['--force' => true]);
    try {
        $test(Matrix::seed());
    } finally {
        Schema::dropIfExists('example_notes');
        DB::table('migrations')->where('migration', 'like', '%example_notes%')->delete();
    }
}

describe('registration', function () {
    it('merges the module actions into groups() and all(), and leaves GROUPS core-only', function () {
        bootWithModules(fixtureModule('example'));

        expect(AuditActions::all())->toContain('example.noted')
            ->and(AuditActions::groups()['example'])->toBe(['example.noted'])
            ->and(AuditActions::group('example'))->toBe(['example.noted'])
            ->and(array_keys(AuditActions::groups()))->toBe([...array_keys(AuditActions::GROUPS), 'example'])
            ->and(AuditActions::GROUPS)->not->toHaveKey('example')
            ->and(AuditActions::all())->toContain('mailbox.create');
    });

    it('is the core\'s alone when no module is loaded', function () {
        expect(AuditActions::groups())->toBe(AuditActions::GROUPS)
            ->and(AuditActions::all())->not->toContain('example.noted');
    });
});

describe('visibility', function () {
    it('lists a module action for a server admin under its group', function () {
        withExampleNotes(function (array $principals) {
            $this->actingAs($principals['admin']);
            $this->postJson('/api/v1/modules/example/notes', ['body' => 'hello'])->assertCreated();

            $this->getJson('/api/v1/audit-log?action_group=example')->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.action', 'example.noted');
            expect(DB::table('example_notes')->count())->toBe(1);
        });
    });

    it('answers 422 for the group when the module is not loaded', function () {
        $this->actingAs(Matrix::seed()['admin']);

        $this->getJson('/api/v1/audit-log?action_group=example')->assertUnprocessable();
    });

    it('hides module rows from everyone but server admins, on the activity log and the dashboard', function () {
        withExampleNotes(function (array $principals) {
            AuditEntry::create(['occurred_at' => now(), 'actor_type' => 'cli', 'actor_id' => 'root', 'ip' => null, 'action' => 'example.noted', 'object_type' => 'note', 'object_key' => 'note-a', 'domain_name' => 'a.org']);
            AuditEntry::create(['occurred_at' => now(), 'actor_type' => 'cli', 'actor_id' => 'root', 'ip' => null, 'action' => 'mailbox.create', 'object_type' => 'mailbox', 'object_key' => 'mbox-a', 'domain_name' => 'a.org']);

            $this->actingAs($principals['manager']);
            expect(collect($this->getJson('/api/v1/audit-log')->assertOk()->json('data'))->pluck('object_key')->all())->toBe(['mbox-a']);
            expect(collect($this->getJson('/api/v1/dashboard')->assertOk()->json('recent_activity'))->pluck('object_key')->all())->toBe(['mbox-a']);

            $this->getJson('/api/v1/audit-log?action_group=example')->assertOk()->assertJsonCount(0, 'data');

            $this->actingAs($principals['admin']);
            expect(collect($this->getJson('/api/v1/audit-log')->assertOk()->json('data'))->pluck('object_key')->all())->toContain('note-a', 'mbox-a');
            expect(collect($this->getJson('/api/v1/dashboard')->assertOk()->json('recent_activity'))->pluck('object_key')->all())->toContain('note-a');
        });
    });

    it('treats module actions as admin-only', function () {
        bootWithModules(fixtureModule('example'));
        $v = app(AuditVisibility::class);

        expect($v->isAdminOnly('example.noted'))->toBeTrue()
            ->and($v->isAdminOnly('mailbox.create'))->toBeFalse()
            ->and($v->isAdminOnly('example.other'))->toBeFalse();
    });
});

describe('clashes', function () {
    it('refuses a module whose actions clash, and never registers its provider', function (string $module, string $class) {
        bootWithModules(fixtureModule('example').','.fixtureModule($module));

        $registry = app(ModuleRegistry::class);
        expect($registry->find('example'))->not->toBeNull()
            ->and($registry->refused())->toHaveCount(1)
            ->and($registry->refused()[0]->reason)->toBe('audit_clash')
            ->and($registry->refused()[0]->name)->toBe($module)
            ->and(app()->getProviders($class))->toBe([])
            ->and(AuditActions::all())->not->toContain('user.mine', 'domain.created', 'other.thing');
    })->with([
        'group named like a core group' => ['audit-group-clash', 'EmailSquire\\Modules\\AuditGroupClash\\AuditGroupClashServiceProvider'],
        'action named like a core action prefix' => ['audit-action-clash', 'EmailSquire\\Modules\\AuditActionClash\\AuditActionClashServiceProvider'],
        'action outside its prefix' => ['audit-prefix-missing', 'EmailSquire\\Modules\\AuditPrefixMissing\\AuditPrefixMissingServiceProvider'],
        'a core prefix as its own' => ['audit-core-prefix', 'EmailSquire\\Modules\\AuditCorePrefix\\AuditCorePrefixServiceProvider'],
        'an action another module has' => ['audit-dup-action', 'EmailSquire\\Modules\\AuditDupAction\\AuditDupActionServiceProvider'],
    ]);

    it('names what clashed in the refusal detail', function () {
        bootWithModules(fixtureModule('audit-prefix-missing'));

        $refusal = app(ModuleRegistry::class)->refused()[0];
        expect($refusal->detail)->toContain('other.thing');
    });
});
