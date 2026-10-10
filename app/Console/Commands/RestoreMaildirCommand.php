<?php

namespace App\Console\Commands;

use App\MailServer\Address\AddressNormalizer;
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

        if ($lookup->find($address) === null) {
            $this->components->error("{$address->address()} has no mailbox. Re-create the mailbox first, then restore its maildir.");

            return self::FAILURE;
        }

        if ($tasks->restorePending($address->address())) {
            $this->components->error("{$address->address()} already has a restore queued or running.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($tasks, $audit, $address, $from): void {
            $tasks->queueRestore($address, $from);
            $audit->record('maildir.restore', 'mailbox', $address->address(), $address->domain->ascii, ['from' => $from]);
        });

        $this->components->info("Restore queued for {$address->address()}; the helper timer will pick it up within a minute or two.");

        return self::SUCCESS;
    }
}
