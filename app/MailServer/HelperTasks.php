<?php

namespace App\MailServer;

use App\MailServer\Address\EmailAddress;
use App\MailServer\Exceptions\Conflict;
use App\Models\HelperTask;
use Illuminate\Database\Eloquent\Collection;

class HelperTasks
{
    public function queueArchive(EmailAddress $a): void
    {
        $this->insert('maildir.archive', $a);
    }

    public function queueRestore(EmailAddress $a, ?string $from): HelperTask
    {
        return $this->insert('maildir.restore', $a, ['from' => $from]);
    }

    public function archivePending(string $address): bool
    {
        return HelperTask::query()
            ->where('type', 'maildir.archive')
            ->where('address', $address)
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->exists();
    }

    /** @return Collection<int, HelperTask> */
    public function open(): Collection
    {
        return HelperTask::query()
            ->where('type', 'like', 'maildir.%')
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->orderBy('id')
            ->get();
    }

    /** Whether an open restore already exists for the address (addresses are stored normalised, in lowercase). */
    public function restorePending(string $address): bool
    {
        return HelperTask::query()
            ->where('type', 'maildir.restore')
            ->whereIn('status', ['pending', 'running'])
            ->where('address', $address)
            ->exists();
    }

    /** Must run inside the caller's transaction when it audits the reset. */
    public function retry(int $id): HelperTask
    {
        $task = HelperTask::query()->where('type', 'like', 'maildir.%')->lockForUpdate()->find($id);

        if ($task === null || $task->status !== 'failed') {
            throw new Conflict('not_failed', "Task {$id} is not a failed maildir task.");
        }

        $task->forceFill(['status' => 'pending', 'attempts' => 0, 'run_after' => now(), 'last_error' => null])->save();

        return $task;
    }

    /** @param array<string, mixed>|null $payload */
    private function insert(string $type, EmailAddress $a, ?array $payload = null): HelperTask
    {
        $task = new HelperTask;
        $task->forceFill([
            'type' => $type,
            'address' => $a->address(),
            'domain_name' => $a->domain->ascii,
            'payload' => $payload,
            'status' => 'pending',
            'run_after' => now(),
        ]);
        $task->save();

        return $task;
    }
}
