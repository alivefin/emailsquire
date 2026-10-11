<?php

namespace App\MailServer\Queries;

use App\Auth\DomainScope;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\MailServer\SchemaExtension;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the mailbox screens. Joins guide tables (utf8mb4_uca1400_ai_ci) to app tables
 * (utf8mb4_unicode_ci), so every cross-schema comparison names its collation. A mailbox's
 * `forwarding_count` counts only sources within the reader's DomainScope, so forwarding from a domain
 * they have no role on is never counted.
 *
 * @phpstan-type Row array{local: string, address: string, display_name: string|null, quota: int, status: string, used_bytes: int|null, usage_checked_at: string|null, forwarding_count: int, receiving: bool|null, sendonly: bool|null}
 */
class MailboxList
{
    public function __construct(private readonly SchemaExtension $extension) {}

    /** Effective quota in bytes; the one placeholder is the configured default. */
    private const QUOTA = '(case when m.quota = 0 then ? else m.quota end)';

    /**
     * @param  'all'|'active'|'disabled'|'full'|string  $status
     * @param  'asc'|'desc'|string  $sort
     * @return LengthAwarePaginator<int|string, Row>
     */
    public function paginate(DomainScope $scope, Domain $d, ?string $q, string $status, string $sort, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->base($scope, $d)->orderBy('m.email', $sort === 'desc' ? 'desc' : 'asc');

        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('convert(m.email using utf8mb4) like ?', [$like])->orWhere('s.display_name', 'like', $like));
        }

        match ($status) {
            'disabled' => $query->whereNotNull('x.mailbox_email'),
            'active' => $query->whereNull('x.mailbox_email'),
            'full' => $query->whereNotNull('u.email')->whereRaw(
                'u.used_kib * 1024 >= 0.8 * '.self::QUOTA,
                [config()->integer('emailsquire.quota_default_bytes')],
            ),
            default => null,
        };

        return $query->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (mixed $row): array => $this->row($row));
    }

    /** @return Row|null */
    public function find(DomainScope $scope, Domain $d, string $address): ?array
    {
        $row = $this->base($scope, $d)->where('m.email', $address)->first();

        return $row === null ? null : $this->row($row);
    }

    /**
     * Active mailboxes at or above 80 % of their quota per the usage snapshot, fullest first, across the
     * domains in $scope.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function nearFull(DomainScope $scope, int $limit): array
    {
        $default = config()->integer('emailsquire.quota_default_bytes');
        $query = $this->base($scope, null)
            ->whereNull('x.mailbox_email')
            ->whereRaw('u.used_kib * 1024 >= 0.8 * '.self::QUOTA, [$default]);
        $scope->constrain($query, 'm.domain_id');
        $count = (clone $query)->count();
        $items = $query
            ->orderByRaw('u.used_kib * 1024 / '.self::QUOTA.' desc', [$default])
            ->orderBy('m.email')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $domain = is_string($item->domain_name ?? null) ? $item->domain_name : '';
            $rows[] = $this->row($item) + ['domain' => $domain];
        }

        return ['count' => $count, 'items' => $rows];
    }

    private function base(DomainScope $scope, ?Domain $d): Builder
    {
        // Forwards into this mailbox; the mailbox's own keep-copy row (source = destination) is not one.
        $forwarding = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->whereColumn('a.destination', 'm.email')
            ->whereColumn('a.source', '<>', 'm.email')
            ->selectRaw('count(distinct a.source)');
        $scope->constrain($forwarding, 'a.domain_id');

        $query = DB::table(GuideSchema::table('virtual_users').' as m')
            ->leftJoin('mailbox_settings as s', fn (JoinClause $j) => $j->whereRaw('convert(m.email using utf8mb4) collate utf8mb4_unicode_ci = s.mailbox_email'))
            ->leftJoin('disabled_mailboxes as x', fn (JoinClause $j) => $j->whereRaw('convert(m.email using utf8mb4) collate utf8mb4_unicode_ci = x.mailbox_email'))
            ->leftJoin('mailbox_usage as u', fn (JoinClause $j) => $j->whereRaw('convert(m.email using utf8mb4) collate utf8mb4_unicode_ci = u.email'))
            ->select('m.email', 'm.quota', 's.display_name', 'u.used_kib', 'u.checked_at')
            ->selectRaw('x.mailbox_email is not null as disabled')
            ->selectSub($forwarding, 'forwarding_count');

        // The optional columns of backend plan section 10; read only where the server has them.
        if ($this->extension->has(SchemaExtension::ENABLED)) {
            $query->addSelect('m.enabled');
        }
        if ($this->extension->has(SchemaExtension::SENDONLY)) {
            $query->addSelect('m.sendonly');
        }

        if ($d !== null) {
            return $query->where('m.domain_id', $d->id);
        }

        return $query
            ->join(GuideSchema::table('virtual_domains').' as dm', 'dm.id', '=', 'm.domain_id')
            ->addSelect('dm.name as domain_name');
    }

    /** @return Row */
    private function row(mixed $row): array
    {
        $r = is_object($row) ? get_object_vars($row) : [];
        $email = is_scalar($r['email'] ?? null) ? (string) $r['email'] : '';
        $at = strrpos($email, '@');
        $usedKib = is_numeric($r['used_kib'] ?? null) ? (int) $r['used_kib'] : null;
        $checked = is_scalar($r['checked_at'] ?? null) ? (string) $r['checked_at'] : null;

        return [
            'local' => $at === false ? $email : substr($email, 0, $at),
            'address' => $email,
            'display_name' => is_string($r['display_name'] ?? null) ? $r['display_name'] : null,
            'quota' => is_numeric($r['quota'] ?? null) ? (int) $r['quota'] : 0,
            'status' => (int) (is_numeric($r['disabled'] ?? null) ? $r['disabled'] : 0) === 1 ? 'disabled' : 'active',
            'used_bytes' => $usedKib === null ? null : $usedKib * 1024,
            'usage_checked_at' => $checked === null ? null : Carbon::parse($checked)->toIso8601String(),
            'forwarding_count' => is_numeric($r['forwarding_count'] ?? null) ? (int) $r['forwarding_count'] : 0,
            'receiving' => is_numeric($r['enabled'] ?? null) ? (int) $r['enabled'] === 1 : null,
            'sendonly' => is_numeric($r['sendonly'] ?? null) ? (int) $r['sendonly'] === 1 : null,
        ];
    }
}
