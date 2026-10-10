<?php

namespace App\MailServer\Queries;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the Users screen: every console user with how many domains they manage and view, and
 * one user's grants. A grant counts only while the guide row at its `domain_id` still carries its
 * `domain_name` (as DomainAccess counts it); a server admin has every domain, so a grant left on one is
 * neither counted nor listed (as on the Access tab, DomainPeople).
 *
 * @phpstan-type Row array{id: int, name: string, email: string, is_server_admin: bool, status: string, two_factor_confirmed: bool, manager_of: int, viewer_of: int, last_login_at: string|null}
 * @phpstan-type Grant array{domain: string, domain_unicode: string, role: string, granted_by_name: string|null, granted_at: string|null}
 */
class UserList
{
    public function __construct(private readonly AddressNormalizer $normalizer) {}

    /**
     * Sorted by name (the email when the name is empty), then id; $q matches part of the name or email.
     *
     * @return LengthAwarePaginator<int, Row>
     */
    public function paginate(?string $q, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->users()->orderByRaw(DomainPeople::PERSON)->orderBy('u.id');
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn (Builder $w) => $w->where('u.name', 'like', $like)->orWhere('u.email', 'like', $like));
        }

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        return new Paginator(
            array_map(fn (mixed $row): array => $this->row($row), $result->items()),
            $result->total(),
            $result->perPage(),
            $result->currentPage(),
        );
    }

    /** @return Row */
    public function find(User $user): array
    {
        return $this->row($this->users()->where('u.id', $user->id)->sole());
    }

    /**
     * $user's live grants sorted by domain name, or only the one on $domainId; none for a server admin.
     *
     * @return list<Grant>
     */
    public function grants(User $user, ?int $domainId = null): array
    {
        $query = $this->liveGrants()
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('users as b', 'b.id', '=', 'g.granted_by')
            ->where('g.user_id', $user->id)
            ->where('u.is_server_admin', false)
            ->select('g.domain_name', 'g.role', 'g.created_at as granted_at')
            // granted_by is set null when the granter is deleted, so the name is null then.
            ->selectRaw("coalesce(nullif(b.name, ''), b.email) as granted_by_name")
            ->orderBy('g.domain_name');
        if ($domainId !== null) {
            $query->where('g.domain_id', $domainId);
        }

        $grants = [];
        foreach ($query->get() as $row) {
            $r = get_object_vars($row);
            $domain = is_string($r['domain_name']) ? $r['domain_name'] : '';
            $grants[] = [
                'domain' => $domain,
                'domain_unicode' => $this->unicode($domain),
                'role' => is_string($r['role']) ? $r['role'] : '',
                'granted_by_name' => is_string($r['granted_by_name']) ? $r['granted_by_name'] : null,
                'granted_at' => is_string($r['granted_at']) ? Carbon::parse($r['granted_at'])->toIso8601String() : null,
            ];
        }

        return $grants;
    }

    /** Each user with their counts from one grouped subquery (no query per user). */
    private function users(): Builder
    {
        $counts = $this->liveGrants()
            ->select('g.user_id')
            ->selectRaw("sum(g.role = 'manager') as manager_of")
            ->selectRaw("sum(g.role = 'viewer') as viewer_of")
            ->groupBy('g.user_id');

        return DB::table('users as u')
            ->leftJoinSub($counts, 'c', 'c.user_id', '=', 'u.id')
            ->select('u.id', 'u.name', 'u.email', 'u.is_server_admin', 'u.status', 'u.last_login_at')
            ->selectRaw('u.two_factor_secret is not null and u.two_factor_confirmed_at is not null as two_factor_confirmed')
            ->selectRaw('case when u.is_server_admin then 0 else coalesce(c.manager_of, 0) end as manager_of')
            ->selectRaw('case when u.is_server_admin then 0 else coalesce(c.viewer_of, 0) end as viewer_of');
    }

    /** `domain_grants as g` joined to the guide row that still carries the grant's name. */
    private function liveGrants(): Builder
    {
        return DB::table('domain_grants as g')
            ->join(GuideSchema::table('virtual_domains').' as d', 'd.id', '=', 'g.domain_id')
            ->whereRaw('cast(lower(d.name) as binary) = cast(lower(g.domain_name) as binary)');
    }

    /** @return Row */
    private function row(mixed $row): array
    {
        $r = is_object($row) ? get_object_vars($row) : [];

        return [
            'id' => is_numeric($r['id'] ?? null) ? (int) $r['id'] : 0,
            'name' => is_string($r['name'] ?? null) ? $r['name'] : '',
            'email' => is_string($r['email'] ?? null) ? $r['email'] : '',
            'is_server_admin' => (bool) ($r['is_server_admin'] ?? false),
            'status' => is_string($r['status'] ?? null) ? $r['status'] : '',
            'two_factor_confirmed' => (bool) ($r['two_factor_confirmed'] ?? false),
            'manager_of' => is_numeric($r['manager_of'] ?? null) ? (int) $r['manager_of'] : 0,
            'viewer_of' => is_numeric($r['viewer_of'] ?? null) ? (int) $r['viewer_of'] : 0,
            'last_login_at' => is_string($r['last_login_at'] ?? null) ? Carbon::parse($r['last_login_at'])->toIso8601String() : null,
        ];
    }

    private function unicode(string $ascii): string
    {
        try {
            return $this->normalizer->domain($ascii)->unicode;
        } catch (InvalidAddress) {
            return $ascii;
        }
    }
}
