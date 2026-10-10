<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Failed maildir tasks, and pending ones the helper's timer should long since have picked up. */
final class MaildirQueue extends BaseCheck
{
    private const STALE_MINUTES = 10;

    public function __construct(private readonly AppMigrated $migrated) {}

    public function id(): string
    {
        return 'helper.maildir_queue';
    }

    public function title(): string
    {
        return 'Maildir helper queue';
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

        $rows = DB::table('helper_tasks')
            ->where('type', 'like', 'maildir.%')
            ->where(fn (Builder $q) => $q
                ->where('status', 'failed')
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', 'pending')
                    ->where('run_after', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->orderBy('id')
            ->get(['id', 'type', 'address', 'status', 'last_error']);

        if ($rows->isEmpty()) {
            return $this->result(Status::Ok, 'No failed or stuck maildir tasks.');
        }

        $items = [];
        foreach ($rows as $row) {
            [$id, $type, $address, $error] = self::strings([$row->id, $row->type, $row->address, $row->last_error ?? '']);
            $items[] = $row->status === 'failed'
                ? "#{$id} {$type} {$address}: {$error}"
                : "#{$id} {$type} {$address}: pending for over ".self::STALE_MINUTES.' minutes';
        }

        return $this->result(
            Status::Warn,
            count($items).' maildir task(s) failed or are stuck. Retry failed ones with emailsquire:maildir-tasks --retry=ID; if pending ones stay put, check that emailsquire-maildir.timer is running.',
            $items,
        );
    }
}
