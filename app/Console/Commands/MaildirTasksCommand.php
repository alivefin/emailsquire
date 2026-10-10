<?php

namespace App\Console\Commands;

use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\HelperTasks;
use App\Models\HelperTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Lists the open maildir helper tasks and resets a failed one. */
final class MaildirTasksCommand extends Command
{
    protected $signature = 'emailsquire:maildir-tasks
        {--retry= : Id of a failed task to reset to pending}';

    protected $description = 'List pending, running and failed maildir helper tasks, or retry a failed one';

    public function handle(HelperTasks $tasks, AuditLog $audit): int
    {
        $retry = $this->option('retry');

        if ($retry !== null) {
            if (preg_match('/^\d{1,18}$/D', $retry) !== 1) {
                $this->components->error('--retry must be a task id.');

                return self::FAILURE;
            }

            try {
                $task = DB::transaction(function () use ($tasks, $audit, $retry) {
                    $task = $tasks->retry((int) $retry);
                    $audit->record('maildir.retry', 'helper_task', (string) $task->id, $task->domain_name, [
                        'type' => $task->type,
                        'address' => $task->address,
                    ]);

                    return $task;
                });
            } catch (Conflict $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->components->info("Task {$task->id} is pending again.");

            return self::SUCCESS;
        }

        $open = $tasks->open();

        if ($open->isEmpty()) {
            $this->line('No open maildir tasks.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Type', 'Address', 'Status', 'Attempts', 'Run after', 'Last error'],
            $open->map(fn (HelperTask $t) => [$t->id, $t->type, $t->address, $t->status, $t->attempts, $t->run_after->toDateTimeString(), $t->last_error])->all(),
        );

        return self::SUCCESS;
    }
}
