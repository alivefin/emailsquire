<?php

namespace App\MailServer\Usage;

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\QuotaUsage;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\UsageRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Snapshots every mailbox's storage from doveadm, then the mail root's disk figures.
 * Each batch is saved as it arrives, so a run that stops early keeps the figures it got.
 */
class RefreshUsage
{
    /** Guide rows read per page. */
    public const PAGE = 200;

    /** Commands per doveadm request. */
    public const BATCH = 20;

    public function __construct(
        private readonly DoveadmClient $doveadm,
        private readonly UsageRepository $usage,
        private readonly int $page = self::PAGE,
        private readonly int $batch = self::BATCH,
    ) {}

    public function run(): UsageRefreshResult
    {
        $checked = 0;
        $skipped = 0;

        try {
            Mailbox::query()->select(['id', 'email'])->chunkById($this->page, function (Collection $mailboxes) use (&$checked, &$skipped) {
                $emails = [];
                foreach ($mailboxes as $mailbox) {
                    $emails[] = $mailbox->email;
                }

                foreach (array_chunk($emails, max(1, $this->batch)) as $group) {
                    $commands = [];
                    foreach ($group as $email) {
                        $commands[] = ['quotaGet', ['user' => $email]];
                    }

                    $rows = [];
                    foreach ($this->doveadm->callMany($commands) as $i => $result) {
                        $quota = $result instanceof DoveadmException ? null : QuotaUsage::fromReply($result);
                        if ($quota === null) {
                            $skipped++;

                            continue;
                        }
                        $rows[$group[$i]] = $quota;
                    }

                    $this->usage->saveMailboxes($rows, CarbonImmutable::now());
                    $checked += count($rows);
                }
            });
        } catch (DoveadmException $e) {
            // A per-command failure never reaches here; this is transport, auth or a malformed reply.
            Log::warning('Usage refresh stopped early: '.$e->getMessage(), ['reason' => $e->reason->name]);

            return new UsageRefreshResult($checked, $skipped, false, $e->getMessage());
        }

        $this->usage->pruneOrphans();
        $this->snapshotServerStorage();

        return new UsageRefreshResult($checked, $skipped, true);
    }

    private function snapshotServerStorage(): void
    {
        $root = config()->string('emailsquire.paths.mail_root');
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        if ($total === false || $free === false) {
            Log::info("Usage refresh: no disk figures for {$root}, server storage row left as it was.");

            return;
        }

        $this->usage->saveServerStorage((int) $total, (int) $free, CarbonImmutable::now());
    }
}
