<?php

use App\Mail\Notify;
use App\Mail\ResetPasswordLink;
use App\Models\User;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// A Postfix restart or a full queue must not lose a reset link or a security notice for good: the worker
// tries each mail three times, 30 s and then 120 s apart, before it lands in failed_jobs.

it('retries a queued security notice three times with a backoff', function () {
    Queue::fake();
    $user = User::factory()->create();

    Notify::security($user, 'password_changed');

    Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
        return $job->tries === 3 && $job->backoff() === [30, 120];
    });
});

it('retries a queued reset link three times with a backoff', function () {
    Queue::fake();
    $user = User::factory()->create();

    Mail::to($user)->queue(new ResetPasswordLink($user, str_repeat('a', 64)));

    Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $job) {
        return $job->tries === 3 && $job->backoff() === [30, 120];
    });
});
