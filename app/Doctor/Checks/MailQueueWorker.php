<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A queued email (or other job) that has been due for over ten minutes means the worker is not running. */
final class MailQueueWorker extends BaseCheck
{
    private const STALE_MINUTES = 10;

    public function __construct(private readonly AppMigrated $migrated) {}

    public function id(): string
    {
        return 'mail.queue_worker';
    }

    public function title(): string
    {
        return 'Queue worker';
    }

    public function run(): CheckResult
    {
        try {
            $ready = $this->migrated->pending() === [];
        } catch (Throwable) {
            $ready = false;
        }

        if (! $ready) {
            return $this->result(Status::Skip, 'Skipped: the app schema is not migrated.');
        }

        $due = DB::table('jobs')->where('available_at', '<=', now()->subMinutes(self::STALE_MINUTES)->getTimestamp())->count();

        if ($due === 0) {
            return $this->result(Status::Ok, 'No queued job has waited over '.self::STALE_MINUTES.' minutes.');
        }

        return $this->result(
            Status::Warn,
            "{$due} queued job(s) have waited over ".self::STALE_MINUTES.' minutes. Check that emailsquire-queue.service is running (journalctl -u emailsquire-queue).',
        );
    }
}
