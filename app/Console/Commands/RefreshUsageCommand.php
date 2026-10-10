<?php

namespace App\Console\Commands;

use App\MailServer\Usage\RefreshUsage;
use Illuminate\Console\Command;

/** Refreshes the mailbox and server storage snapshots from doveadm. */
final class RefreshUsageCommand extends Command
{
    protected $signature = 'emailsquire:refresh-usage';

    protected $description = 'Refresh mailbox usage and server storage snapshots from doveadm';

    public function handle(RefreshUsage $refresh): int
    {
        $result = $refresh->run();

        if (! $result->complete) {
            $this->components->error("Usage refresh stopped early after {$result->checked} mailboxes: {$result->error}");

            return self::FAILURE;
        }

        $this->line("Checked {$result->checked} mailboxes, skipped {$result->skipped}.");

        return self::SUCCESS;
    }
}
