<?php

namespace App\Http\Controllers;

use App\Http\Problem;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\QuotaUsage;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\UsageRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** Live storage figure for the mailbox drawer; refreshes that mailbox's usage snapshot as a side effect. */
class MailboxUsageController extends Controller
{
    public function __invoke(Domain $domain, Mailbox $mailbox, DoveadmClient $doveadm, UsageRepository $repository): JsonResponse
    {
        try {
            $entries = $doveadm->call('quotaGet', ['user' => $mailbox->email]);
        } catch (DoveadmException) {
            return $this->unavailable();
        }

        $usage = QuotaUsage::fromReply($entries);
        if ($usage === null) {
            return $this->unavailable();
        }

        $checkedAt = CarbonImmutable::now();
        $repository->saveMailboxes([$mailbox->email => $usage], $checkedAt);

        return response()->json([
            'used_bytes' => $usage->usedKib * 1024,
            'limit_bytes' => $usage->limitKib === null ? null : $usage->limitKib * 1024,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return Problem::response(503, 'Service Unavailable', 'Storage usage is unavailable right now.', 'usage_unavailable');
    }
}
