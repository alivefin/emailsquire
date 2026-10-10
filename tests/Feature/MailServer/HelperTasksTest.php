<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\HelperTasks;
use App\Models\HelperTask;

function taskAddress(): EmailAddress
{
    return (new AddressNormalizer)->address('a@x.fi');
}

it('queues an archive task and reports it pending', function () {
    app(HelperTasks::class)->queueArchive(taskAddress());

    $task = HelperTask::sole();
    expect($task->type)->toBe('maildir.archive')
        ->and($task->address)->toBe('a@x.fi')
        ->and($task->domain_name)->toBe('x.fi')
        ->and($task->status)->toBe('pending')
        ->and($task->run_after)->not->toBeNull()
        ->and(app(HelperTasks::class)->archivePending('a@x.fi'))->toBeTrue()
        ->and(app(HelperTasks::class)->archivePending('b@x.fi'))->toBeFalse();
});

it('treats done as not pending and running or failed as pending', function (string $status, bool $expected) {
    app(HelperTasks::class)->queueArchive(taskAddress());
    HelperTask::query()->update(['status' => $status]);

    expect(app(HelperTasks::class)->archivePending('a@x.fi'))->toBe($expected);
})->with([['done', false], ['running', true], ['failed', true], ['pending', true]]);

it('queues a restore task with its source', function () {
    app(HelperTasks::class)->queueRestore(taskAddress(), '2026-01-01');

    $task = HelperTask::sole();
    expect($task->type)->toBe('maildir.restore')->and($task->status)->toBe('pending');
});
