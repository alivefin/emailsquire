<?php

namespace App\Http\Controllers;

use App\Http\Problem;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\MailboxUsage;
use Illuminate\Http\JsonResponse;

/** Live storage figure for the mailbox drawer; refreshes that mailbox's usage snapshot as a side effect. */
class MailboxUsageController extends Controller
{
    public function __invoke(Domain $domain, Mailbox $mailbox, DoveadmClient $doveadm): JsonResponse
    {
        try {
            $entries = $doveadm->call('quotaGet', ['user' => $mailbox->email]);
        } catch (DoveadmException) {
            return $this->unavailable();
        }

        $storage = null;
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'STORAGE') {
                $storage = $entry;
                break;
            }
        }
        if ($storage === null || ! is_numeric($storage['value'] ?? null)) {
            return $this->unavailable();
        }

        $usedKib = (int) $storage['value'];
        $limit = $storage['limit'] ?? null;
        $limitKib = is_numeric($limit) ? (int) $limit : null; // "-" means no limit

        $checkedAt = now();
        MailboxUsage::query()->updateOrCreate(
            ['email' => strtolower($mailbox->email)],
            ['used_kib' => $usedKib, 'limit_kib' => $limitKib, 'checked_at' => $checkedAt],
        );

        return response()->json([
            'used_bytes' => $usedKib * 1024,
            'limit_bytes' => $limitKib === null ? null : $limitKib * 1024,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return Problem::response(503, 'Service Unavailable', 'Storage usage is unavailable right now.', 'usage_unavailable');
    }
}
