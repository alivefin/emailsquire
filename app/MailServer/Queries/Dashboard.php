<?php

namespace App\MailServer\Queries;

use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\MailboxUsage;
use App\Models\ServerStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read side of the dashboard: counts, near-full mailboxes, recent activity and storage. */
class Dashboard
{
    public function __construct(private readonly MailboxList $mailboxes) {}

    /** @return array{domains: int, domains_added_this_month: int, mailboxes: int, forwarding: int, forwarding_local: int, forwarding_external: int} */
    public function counts(): array
    {
        $forwarding = $this->forwardingCounts();

        return [
            'domains' => DB::table(GuideSchema::table('virtual_domains'))->count(),
            'domains_added_this_month' => AuditEntry::query()->where('action', 'domain.create')->where('occurred_at', '>=', now()->startOfMonth())->count(),
            'mailboxes' => DB::table(GuideSchema::table('virtual_users'))->count(),
            'forwarding' => $forwarding['total'],
            'forwarding_local' => $forwarding['total'] - $forwarding['external'],
            'forwarding_external' => $forwarding['external'],
        ];
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function nearFull(int $limit): array
    {
        return $this->mailboxes->nearFull($limit);
    }

    /** @return list<AuditEntry> */
    public function recentActivity(int $limit): array
    {
        return array_values(AuditEntry::query()->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->all());
    }

    /** @return array{total_bytes: int, free_bytes: int, used_bytes: int, checked_at: string}|null */
    public function serverStorage(): ?array
    {
        $storage = ServerStorage::query()->find(1);

        return $storage === null ? null : [
            'total_bytes' => $storage->total_bytes,
            'free_bytes' => $storage->free_bytes,
            'used_bytes' => max(0, $storage->total_bytes - $storage->free_bytes),
            'checked_at' => Carbon::parse($storage->checked_at)->toIso8601String(),
        ];
    }

    /** The oldest per-mailbox snapshot: the figure to show as "as of". */
    public function usageCheckedAt(): ?string
    {
        $oldest = MailboxUsage::query()->min('checked_at');

        return is_string($oldest) ? Carbon::parse($oldest)->toIso8601String() : null;
    }

    /**
     * A source forwards when at least one of its rows points somewhere other than itself (a keep-copy row
     * does not count); it is external when any such target is off this server.
     *
     * @return array{total: int, external: int}
     */
    private function forwardingCounts(): array
    {
        $dest = ForwardingList::DEST;
        $isOnServer = DB::table(GuideSchema::table('virtual_domains').' as dm')->selectRaw('1')
            ->whereRaw('convert(lower(dm.name) using utf8mb4) collate utf8mb4_bin = substring_index('.$dest.", '@', -1)")->limit(1);

        $rows = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->selectRaw(ForwardingList::SRC.' as src')
            ->selectRaw(ForwardingList::SRC.' <> '.$dest.' as is_target')
            ->selectSub($isOnServer, 'is_srv');

        $sources = DB::query()->fromSub($rows, 'r')
            ->select('r.src')
            ->selectRaw('max(r.is_target and r.is_srv is null) as is_ext')
            ->groupBy('r.src')
            ->havingRaw('max(r.is_target) = 1');

        $totals = DB::query()->fromSub($sources, 'g')->selectRaw('count(*) as total, coalesce(sum(g.is_ext), 0) as external')->first();
        $t = is_object($totals) ? get_object_vars($totals) : [];

        return [
            'total' => is_numeric($t['total'] ?? null) ? (int) $t['total'] : 0,
            'external' => is_numeric($t['external'] ?? null) ? (int) $t['external'] : 0,
        ];
    }
}
