<?php

use App\MailServer\GuideSchema;
use App\MailServer\HelperTasks;
use App\Models\AuditEntry;
use App\Models\HelperTask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(fn () => GuideFixture::load('trixie'));

function helperTaskRow(string $type, string $status, string $address = 'x@example.org', array $extra = []): HelperTask
{
    $task = new HelperTask;
    $task->forceFill([
        'type' => $type, 'address' => $address, 'domain_name' => explode('@', $address)[1],
        'status' => $status, 'run_after' => now(), ...$extra,
    ])->save();

    return $task;
}

it('queues a restore of the newest archive and audits it as cli', function () {
    expect(Artisan::call('emailsquire:restore-maildir', ['address' => 'john@example.org']))->toBe(0);

    $task = HelperTask::query()->sole();
    $audit = AuditEntry::query()->where('action', 'maildir.restore')->sole();

    expect($task->type)->toBe('maildir.restore')
        ->and($task->address)->toBe('john@example.org')
        ->and($task->domain_name)->toBe('example.org')
        ->and($task->status)->toBe('pending')
        ->and($task->payload)->toBe(['from' => null])
        ->and($audit->actor_type)->toBe('cli')
        ->and($audit->object_type)->toBe('mailbox')
        ->and($audit->object_key)->toBe('john@example.org')
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toBe(['from' => null]);
});

it('queues the stored spelling of a legacy mixed-case mailbox', function () {
    DB::table(GuideSchema::table('virtual_users'))->insert(['domain_id' => 10, 'email' => 'Anna@Example.org', 'password' => 'x', 'quota' => 0]);

    expect(Artisan::call('emailsquire:restore-maildir', ['address' => 'anna@example.org']))->toBe(0);

    // The helper checks the guide row and the maildir by the exact stored spelling.
    expect(HelperTask::query()->sole()->address)->toBe('Anna@Example.org')
        ->and(AuditEntry::query()->where('action', 'maildir.restore')->sole()->object_key)->toBe('Anna@Example.org')
        ->and(Artisan::call('emailsquire:restore-maildir', ['address' => 'anna@example.org']))->toBe(1)
        ->and(Artisan::output())->toContain('already has a restore');
});

it('stores the --from archive stamp', function () {
    expect(Artisan::call('emailsquire:restore-maildir', ['address' => 'john@example.org', '--from' => '20261001000000']))->toBe(0);

    expect(HelperTask::query()->sole()->payload)->toBe(['from' => '20261001000000'])
        ->and(AuditEntry::query()->where('action', 'maildir.restore')->sole()->changes)->toBe(['from' => '20261001000000']);
});

it('refuses a bad --from, a missing mailbox, a duplicate restore and a bad address', function (array $arguments, string $message) {
    helperTaskRow('maildir.restore', 'running', 'john@example.org', ['payload' => ['from' => null]]);

    expect(Artisan::call('emailsquire:restore-maildir', $arguments))->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and(HelperTask::query()->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'maildir.restore')->count())->toBe(0);
})->with([
    'short --from' => [['address' => 'john@example.org', '--from' => '2026'], '--from'],
    'no mailbox' => [['address' => 'nobody@example.org'], 'Re-create the mailbox first'],
    'unknown domain' => [['address' => 'john@nowhere.test'], 'Re-create the mailbox first'],
    'already pending' => [['address' => 'John@example.org'], 'already has a restore'],
    'not an address' => [['address' => 'not-an-address'], 'not-an-address'],
]);

it('lists the open tasks', function () {
    $failed = helperTaskRow('maildir.archive', 'failed', 'x@example.org', ['attempts' => 5, 'last_error' => 'rename failed']);
    helperTaskRow('maildir.archive', 'done', 'old@example.org');

    expect(Artisan::call('emailsquire:maildir-tasks'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain((string) $failed->id)->toContain('maildir.archive')->toContain('x@example.org')
        ->toContain('failed')->toContain('rename failed')->not->toContain('old@example.org');
});

it('says so when no task is open', function () {
    expect(Artisan::call('emailsquire:maildir-tasks'))->toBe(0)
        ->and(Artisan::output())->toContain('No open maildir tasks');
});

it('resets a failed task with --retry and audits it', function () {
    $failed = helperTaskRow('maildir.archive', 'failed', 'x@example.org', ['attempts' => 5, 'last_error' => 'boom', 'run_after' => now()->subHour()]);

    expect(Artisan::call('emailsquire:maildir-tasks', ['--retry' => (string) $failed->id]))->toBe(0);

    $failed->refresh();
    $audit = AuditEntry::query()->where('action', 'maildir.retry')->sole();

    expect($failed->status)->toBe('pending')
        ->and($failed->attempts)->toBe(0)
        ->and($failed->last_error)->toBeNull()
        ->and($failed->run_after->isAfter(now()->subMinute()))->toBeTrue()
        ->and($audit->actor_type)->toBe('cli')
        ->and($audit->object_type)->toBe('helper_task')
        ->and($audit->object_key)->toBe((string) $failed->id)
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toBe(['type' => 'maildir.archive', 'address' => 'x@example.org']);
});

it('refuses to retry a task that has not failed or does not exist', function () {
    $pending = helperTaskRow('maildir.archive', 'pending');

    expect(Artisan::call('emailsquire:maildir-tasks', ['--retry' => (string) $pending->id]))->toBe(1)
        ->and(Artisan::call('emailsquire:maildir-tasks', ['--retry' => '99999']))->toBe(1)
        ->and(Artisan::call('emailsquire:maildir-tasks', ['--retry' => 'abc']))->toBe(1)
        ->and($pending->refresh()->status)->toBe('pending')
        ->and(AuditEntry::query()->where('action', 'maildir.retry')->count())->toBe(0);
});

it('opens only maildir tasks that are pending, running or failed, by id', function () {
    $a = helperTaskRow('maildir.archive', 'failed');
    helperTaskRow('maildir.archive', 'done');
    $b = helperTaskRow('maildir.restore', 'pending');
    helperTaskRow('other.thing', 'pending');

    expect(app(HelperTasks::class)->open()->pluck('id')->all())->toBe([$a->id, $b->id]);
});
