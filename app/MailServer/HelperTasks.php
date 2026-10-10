<?php

namespace App\MailServer;

use App\MailServer\Address\EmailAddress;
use App\Models\HelperTask;

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
