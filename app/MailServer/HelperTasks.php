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

    public function queueRestore(EmailAddress $a, ?string $from): void
    {
        // helper_tasks has no column for the source yet; the restore task (a later plan) will define one.
        $this->insert('maildir.restore', $a);
    }

    public function archivePending(string $address): bool
    {
        return HelperTask::query()
            ->where('type', 'maildir.archive')
            ->where('address', $address)
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->exists();
    }

    private function insert(string $type, EmailAddress $a): void
    {
        $task = new HelperTask;
        $task->forceFill([
            'type' => $type,
            'address' => $a->address(),
            'domain_name' => $a->domain->ascii,
            'status' => 'pending',
            'run_after' => now(),
        ]);
        $task->save();
    }
}
