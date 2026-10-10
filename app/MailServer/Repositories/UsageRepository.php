<?php

namespace App\MailServer\Repositories;

use App\MailServer\Dovecot\QuotaUsage;
use App\MailServer\GuideSchema;
use App\Models\MailboxUsage;
use App\Models\ServerStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Writes the app's usage snapshots: per-mailbox storage and the mail root's disk figures. */
class UsageRepository
{
    /** @param  array<string, QuotaUsage>  $byEmail */
    public function saveMailboxes(array $byEmail, CarbonImmutable $at): void
    {
        $rows = [];
        foreach ($byEmail as $email => $usage) {
            $rows[strtolower($email)] = [
                'email' => strtolower($email),
                'used_kib' => $usage->usedKib,
                'limit_kib' => $usage->limitKib,
                'checked_at' => $at,
            ];
        }

        if ($rows !== []) {
            MailboxUsage::query()->upsert(array_values($rows), ['email'], ['used_kib', 'limit_kib', 'checked_at']);
        }
    }

    /** Deletes snapshots of mailboxes that no longer exist in the guide table; returns the row count. */
    public function pruneOrphans(): int
    {
        $orphans = DB::table('mailbox_usage as r')
            ->leftJoin(GuideSchema::table('virtual_users').' as u', fn (JoinClause $join) => $join->whereRaw(
                // The app and guide schemas may use different collations; compare bytes.
                'CAST(LOWER(u.email) AS BINARY) = CAST(LOWER(r.email) AS BINARY)',
            ))
            ->whereNull('u.id')
            ->pluck('r.email')
            ->all();

        $deleted = 0;
        foreach (array_chunk($orphans, 500) as $chunk) {
            $deleted += DB::table('mailbox_usage')->whereIn('email', $chunk)->delete();
        }

        return $deleted;
    }

    public function saveServerStorage(int $total, int $free, CarbonImmutable $at): void
    {
        ServerStorage::query()->upsert(
            [['id' => 1, 'total_bytes' => $total, 'free_bytes' => $free, 'checked_at' => $at]],
            ['id'],
            ['total_bytes', 'free_bytes', 'checked_at'],
        );
    }
}
