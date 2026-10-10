<?php

namespace App\MailServer\Queries;

use App\Auth\DomainScope;
use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\MailboxUsage;
use App\Models\ServerStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the dashboard: counts, near-full mailboxes, recent activity and storage, each within the
 * reader's DomainScope. Audit rows count by their `domain_name`: a row without one (users, sign-ins) and
 * the server's storage are only for a reader who sees every domain.
 */
class Dashboard
{
    public function __construct(private readonly MailboxList $mailboxes) {}

    /** @return array{domains: int, domains_added_this_month: int, mailboxes: int, forwarding: int, forwarding_local: int, forwarding_external: int} */
    public function counts(DomainScope $scope): array
    {
        $forwarding = $this->forwardingCounts($scope);
        $domains = DB::table(GuideSchema::table('virtual_domains'));
        $scope->constrain($domains, 'id');
        $mailboxes = DB::table(GuideSchema::table('virtual_users'));
        $scope->constrain($mailboxes, 'domain_id');

        return [
            'domains' => $domains->count(),
            'domains_added_this_month' => $this->audit($scope)->where('action', 'domain.create')->where('occurred_at', '>=', now()->startOfMonth())->count(),
            'mailboxes' => $mailboxes->count(),
            'forwarding' => $forwarding['total'],
            'forwarding_local' => $forwarding['total'] - $forwarding['external'],
            'forwarding_external' => $forwarding['external'],
        ];
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function nearFull(DomainScope $scope, int $limit): array
    {
        return $this->mailboxes->nearFull($scope, $limit);
    }

    /** @return list<AuditEntry> */
    public function recentActivity(DomainScope $scope, int $limit): array
    {
        return array_values($this->audit($scope)->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->all());
    }

    /**
     * The mail volume's size and free space: a server-wide figure, so null for a reader who does not see
     * every domain.
     *
     * @return array{total_bytes: int, free_bytes: int, used_bytes: int, checked_at: string}|null
     */
    public function serverStorage(DomainScope $scope): ?array
    {
        if (! $scope->isAll()) {
            return null;
        }

        $storage = ServerStorage::query()->find(1);

        return $storage === null ? null : [
            'total_bytes' => $storage->total_bytes,
            'free_bytes' => $storage->free_bytes,
            'used_bytes' => max(0, $storage->total_bytes - $storage->free_bytes),
            'checked_at' => Carbon::parse($storage->checked_at)->toIso8601String(),
        ];
    }

    /** The oldest per-mailbox snapshot of the mailboxes in $scope: the figure to show as "as of". */
    public function usageCheckedAt(DomainScope $scope): ?string
    {
        if ($scope->isAll()) {
            $oldest = MailboxUsage::query()->min('checked_at');
        } else {
            $query = DB::table('mailbox_usage as u')
                ->join(GuideSchema::table('virtual_users').' as m', fn (JoinClause $j) => $j
                    ->whereRaw('convert(m.email using utf8mb4) collate utf8mb4_unicode_ci = u.email'));
            $scope->constrain($query, 'm.domain_id');
            $oldest = $query->min('u.checked_at');
        }

        return is_string($oldest) ? Carbon::parse($oldest)->toIso8601String() : null;
    }

    /**
     * A source forwards when at least one of its rows points somewhere other than itself (a keep-copy row
     * does not count); it is external when any such target is off this server, or on a domain outside
     * $scope (as ForwardingList judges it).
     *
     * @return array{total: int, external: int}
     */
    private function forwardingCounts(DomainScope $scope): array
    {
        $dest = ForwardingList::DEST;
        $isOnServer = ForwardingList::onServer($scope, $dest);

        $rows = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->selectRaw(ForwardingList::SRC.' as src')
            ->selectRaw(ForwardingList::SRC.' <> '.$dest.' as is_target')
            ->selectSub($isOnServer, 'is_srv');
        $scope->constrain($rows, 'a.domain_id');

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

    /**
     * Audit rows the reader may see: every row for a reader who sees every domain, otherwise only rows
     * naming one of their domains.
     *
     * @return Builder<AuditEntry>
     */
    private function audit(DomainScope $scope): Builder
    {
        $query = AuditEntry::query();
        $ids = $scope->ids();
        if ($ids !== null) {
            $names = $ids === [] ? [] : DB::table(GuideSchema::table('virtual_domains'))->whereIn('id', $ids)->pluck('name')
                ->map(fn (mixed $n): string => strtolower(is_scalar($n) ? (string) $n : ''))->all();
            $query->whereIn('domain_name', $names);
        }

        return $query;
    }
}
