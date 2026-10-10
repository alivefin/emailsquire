<?php

namespace App\Console\Commands;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\AuditLog;
use App\MailServer\HelperTasks;
use App\MailServer\Queries\MailboxLookup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Queues a restore of an archived maildir. The helper does the filesystem work. */
final class RestoreMaildirCommand extends Command
{
    protected $signature = 'emailsquire:restore-maildir
        {address : The mailbox address to restore the archived maildir for}
        {--from= : Archive stamp YYYYmmddHHMMSS (default: the newest archive)}';

    protected $description = 'Queue a restore of an archived maildir into an existing mailbox';

    public function handle(AddressNormalizer $normalizer, MailboxLookup $lookup, HelperTasks $tasks, AuditLog $audit): int
    {
        $from = $this->option('from');

        if ($from !== null && preg_match('/^\d{14}$/D', $from) !== 1) {
            $this->components->error('--from must be an archive stamp of 14 digits (YYYYmmddHHMMSS).');

            return self::FAILURE;
        }

        try {
            $address = $normalizer->address((string) $this->argument('address'));
        } catch (InvalidAddress $e) {
            $this->components->error("Not a valid mailbox address ({$this->argument('address')}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $mailbox = $lookup->find($address);
        if ($mailbox === null) {
            $this->components->error("{$address->address()} has no mailbox. Re-create the mailbox first, then restore its maildir.");

            return self::FAILURE;
        }

        // The helper checks the guide row and the maildir by the stored spelling (a legacy Anna@Example.org
        // lives in Example.org/Anna), so queue that; restorePending() compares case-insensitively.
        $stored = $mailbox->email;
        $at = strrpos($stored, '@');
        $target = $at === false ? $address : new EmailAddress(substr($stored, 0, $at), new DomainName(substr($stored, $at + 1), $address->domain->unicode));

        if ($tasks->restorePending($target->address())) {
            $this->components->error("{$target->address()} already has a restore queued or running.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($tasks, $audit, $target, $address, $from): void {
            $tasks->queueRestore($target, $from);
            $audit->record('maildir.restore', 'mailbox', $target->address(), $address->domain->ascii, ['from' => $from]);
        });

        $this->components->info("Restore queued for {$target->address()}; the helper timer will pick it up within a minute or two.");

        return self::SUCCESS;
    }
}
