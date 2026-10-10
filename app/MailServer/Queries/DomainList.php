<?php

namespace App\MailServer\Queries;

use App\Auth\DomainScope;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the domain screens. Raw SQL for the counts lives here and nowhere else. Lists take the
 * reader's DomainScope; rows carry the guide `id`, which DomainResource turns into the reader's role.
 *
 * @phpstan-type Counts array{id: int, name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null, quota_bytes_allocated: int, max_mailboxes: int|null, max_quota_bytes_total: int|null}
 * @phpstan-type Row array{id: int, name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null, quota_bytes_allocated: int, max_mailboxes: int|null, max_quota_bytes_total: int|null, people_count: int, people: list<string>}
 */
class DomainList
{
    /** A mailbox's effective quota in bytes; the one placeholder is the configured default. */
    private const EFFECTIVE_QUOTA = 'case when m.quota = 0 then ? else m.quota end';

    public function __construct(private readonly AddressNormalizer $normalizer) {}

    /** @return LengthAwarePaginator<int, Row> */
    public function paginate(DomainScope $scope, ?string $q, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->withCounts()->orderBy('d.name');
        $scope->constrain($query, 'd.id');

        if ($q !== null && $q !== '') {
            $query->whereRaw('convert(d.name using utf8mb4) like ?', ['%'.addcslashes($q, '\\%_').'%']);
        }

        $result = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = array_map(fn (mixed $row): array => $this->row($row), $result->items());
        $people = $this->people(array_column($rows, 'id'));

        return new Paginator(
            array_map(fn (array $row): array => $this->withPeople($row, $people), $rows),
            $result->total(),
            $result->perPage(),
            $result->currentPage(),
        );
    }

    /** @return Row|null */
    public function find(string $asciiName): ?array
    {
        $row = $this->withCounts()->where('d.name', $asciiName)->first();
        if ($row === null) {
            return null;
        }
        $row = $this->row($row);

        return $this->withPeople($row, $this->people([$row['id']]));
    }

    /** @return list<array{id: int, name: string, unicode_name: string, mailbox_count: int}> */
    public function names(DomainScope $scope): array
    {
        $query = $this->domains()->select('d.id', 'd.name')
            ->selectSub($this->mailboxCount(), 'mailbox_count')
            ->orderBy('d.name');
        $scope->constrain($query, 'd.id');

        $names = [];
        foreach ($query->get() as $row) {
            $r = $this->fields($row);
            $names[] = [
                'id' => $this->number($r, 'id'),
                'name' => $this->text($r, 'name'),
                'unicode_name' => $this->unicode($this->text($r, 'name')),
                'mailbox_count' => $this->number($r, 'mailbox_count'),
            ];
        }

        return $names;
    }

    /**
     * The domains among $ids that $scope allows, keyed by id.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: string, unicode_name: string, mailbox_count: int}>
     */
    public function byIds(DomainScope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $this->domains()->whereIn('d.id', $ids)->select('d.id', 'd.name')->selectSub($this->mailboxCount(), 'mailbox_count');
        $scope->constrain($query, 'd.id');

        $out = [];
        foreach ($query->get() as $row) {
            $r = $this->fields($row);
            $name = $this->text($r, 'name');
            $out[$this->number($r, 'id')] = ['name' => $name, 'unicode_name' => $this->unicode($name), 'mailbox_count' => $this->number($r, 'mailbox_count')];
        }

        return $out;
    }

    /**
     * What a domain's mailboxes take against its limits: their count, and their quotas summed with
     * `quota = 0` counted as `quota_default_bytes`. DomainLimits calls it with the domain row locked.
     *
     * @return array{mailboxes: int, quota_bytes_allocated: int}
     */
    public function usage(int $domainId): array
    {
        $row = DB::table(GuideSchema::table('virtual_users').' as m')
            ->where('m.domain_id', $domainId)
            ->selectRaw('count(*) as mailboxes')
            ->selectRaw('coalesce(sum('.self::EFFECTIVE_QUOTA.'), 0) as allocated', [config()->integer('emailsquire.quota_default_bytes')])
            ->first();
        $r = $this->fields($row);

        return ['mailboxes' => $this->number($r, 'mailboxes'), 'quota_bytes_allocated' => $this->number($r, 'allocated')];
    }

    /**
     * How many people have a grant on each of the domains $ids, and the first three of their names
     * (the email when a name is empty) by name: one query for the whole page. Server admins have
     * every domain, so a grant left on one is not counted (as DomainPeople). A grant counts only
     * while the guide row still carries its name (DomainAccess); the schemas may differ in
     * collation, so the names are compared as bytes.
     *
     * @param  list<int>  $ids
     * @return array<int, array{count: int, names: list<string>}>
     */
    private function people(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $person = DomainPeople::PERSON;
        $ranked = DB::table('domain_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->join(GuideSchema::table('virtual_domains').' as d', fn (JoinClause $j) => $j->on('d.id', '=', 'g.domain_id')
                ->whereRaw('cast(lower(g.domain_name) as binary) = cast(lower(d.name) as binary)'))
            ->whereIn('g.domain_id', $ids)
            ->where('u.is_server_admin', false)
            ->select('g.domain_id')
            ->selectRaw("{$person} as person")
            ->selectRaw("row_number() over (partition by g.domain_id order by {$person}, u.id) as n")
            ->selectRaw('count(*) over (partition by g.domain_id) as total');

        $people = [];
        foreach (DB::query()->fromSub($ranked, 'p')->where('p.n', '<=', 3)->orderBy('p.domain_id')->orderBy('p.n')->get() as $row) {
            $r = $this->fields($row);
            $id = $this->number($r, 'domain_id');
            $people[$id]['count'] = $this->number($r, 'total');
            $people[$id]['names'][] = $this->text($r, 'person');
        }

        return $people;
    }

    /**
     * @param  Counts  $row
     * @param  array<int, array{count: int, names: list<string>}>  $people
     * @return Row
     */
    private function withPeople(array $row, array $people): array
    {
        return $row + [
            'people_count' => $people[$row['id']]['count'] ?? 0,
            'people' => $people[$row['id']]['names'] ?? [],
        ];
    }

    private function domains(): Builder
    {
        return DB::table(GuideSchema::table('virtual_domains').' as d');
    }

    private function mailboxCount(): Builder
    {
        return DB::table(GuideSchema::table('virtual_users').' as m')
            ->whereColumn('m.domain_id', 'd.id')
            ->selectRaw('count(*)');
    }

    private function withCounts(): Builder
    {
        // A source that only keeps a copy in its own mailbox (destination = source) is not forwarding.
        $forwarding = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->whereColumn('a.domain_id', 'd.id')
            ->whereColumn('a.destination', '<>', 'a.source')
            ->selectRaw('count(distinct a.source)');

        // mailbox_usage lives in the app schema, which may differ from the guide's in collation.
        $usedBytes = DB::table('mailbox_usage as u')
            ->join(GuideSchema::table('virtual_users').' as m', fn (JoinClause $j) => $j
                ->whereRaw('convert(m.email using utf8mb4) collate utf8mb4_unicode_ci = u.email'))
            ->whereColumn('m.domain_id', 'd.id')
            ->selectRaw('sum(u.used_kib) * 1024');

        $allocated = DB::table(GuideSchema::table('virtual_users').' as m')
            ->whereColumn('m.domain_id', 'd.id')
            ->selectRaw('coalesce(sum('.self::EFFECTIVE_QUOTA.'), 0)', [config()->integer('emailsquire.quota_default_bytes')]);

        // A settings row counts only while the guide row still carries its name (as DomainLimits reads
        // it); the schemas may differ in collation, so compare bytes.
        return $this->domains()
            ->leftJoin('domain_settings as ds', fn (JoinClause $j) => $j->on('ds.domain_id', '=', 'd.id')
                ->whereRaw('cast(lower(ds.domain_name) as binary) = cast(lower(d.name) as binary)'))
            ->select('d.id', 'd.name', 'ds.max_mailboxes', 'ds.max_quota_bytes_total')
            ->selectSub($this->mailboxCount(), 'mailbox_count')
            ->selectSub($forwarding, 'forwarding_count')
            ->selectSub($usedBytes, 'used_bytes')
            ->selectSub($allocated, 'quota_bytes_allocated');
    }

    /** @return Counts */
    private function row(mixed $row): array
    {
        $r = $this->fields($row);
        $name = $this->text($r, 'name');

        return [
            'id' => $this->number($r, 'id'),
            'name' => $name,
            'unicode_name' => $this->unicode($name),
            'mailbox_count' => $this->number($r, 'mailbox_count'),
            'forwarding_count' => $this->number($r, 'forwarding_count'),
            'used_bytes' => ($r['used_bytes'] ?? null) === null ? null : $this->number($r, 'used_bytes'),
            'quota_bytes_allocated' => $this->number($r, 'quota_bytes_allocated'),
            'max_mailboxes' => ($r['max_mailboxes'] ?? null) === null ? null : $this->number($r, 'max_mailboxes'),
            'max_quota_bytes_total' => ($r['max_quota_bytes_total'] ?? null) === null ? null : $this->number($r, 'max_quota_bytes_total'),
        ];
    }

    /** @return array<array-key, mixed> */
    private function fields(mixed $row): array
    {
        return is_object($row) ? get_object_vars($row) : [];
    }

    /** @param  array<array-key, mixed>  $r */
    private function text(array $r, string $key): string
    {
        return is_scalar($r[$key] ?? null) ? (string) $r[$key] : '';
    }

    /** @param  array<array-key, mixed>  $r */
    private function number(array $r, string $key): int
    {
        return is_numeric($r[$key] ?? null) ? (int) $r[$key] : 0;
    }

    private function unicode(string $name): string
    {
        try {
            return $this->normalizer->domain($name)->unicode;
        } catch (InvalidAddress) {
            return $name; // restored data may hold names we would not accept today
        }
    }
}
