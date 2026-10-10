<?php

namespace App\MailServer\Queries;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Read side of the domain screens. Raw SQL for the counts lives here and nowhere else. */
class DomainList
{
    public function __construct(private readonly AddressNormalizer $normalizer) {}

    /** @return LengthAwarePaginator<int, array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null}> */
    public function paginate(?string $q, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->withCounts()->orderBy('d.name');

        if ($q !== null && $q !== '') {
            $query->whereRaw('convert(d.name using utf8mb4) like ?', ['%'.addcslashes($q, '\\%_').'%']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (mixed $row): array => $this->row($row));
    }

    /**
     * @return array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null}|null
     */
    public function find(string $asciiName): ?array
    {
        $row = $this->withCounts()->where('d.name', $asciiName)->first();

        return $row === null ? null : $this->row($row);
    }

    /** @return list<array{name: string, unicode_name: string, mailbox_count: int}> */
    public function names(): array
    {
        $rows = $this->domains()->select('d.name')
            ->selectSub($this->mailboxCount(), 'mailbox_count')
            ->orderBy('d.name')
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $r = $this->fields($row);
            $names[] = [
                'name' => $this->text($r, 'name'),
                'unicode_name' => $this->unicode($this->text($r, 'name')),
                'mailbox_count' => $this->number($r, 'mailbox_count'),
            ];
        }

        return $names;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: string, mailbox_count: int}>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($this->domains()->whereIn('d.id', $ids)->select('d.id', 'd.name')->selectSub($this->mailboxCount(), 'mailbox_count')->get() as $row) {
            $r = $this->fields($row);
            $out[$this->number($r, 'id')] = ['name' => $this->text($r, 'name'), 'mailbox_count' => $this->number($r, 'mailbox_count')];
        }

        return $out;
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

        return $this->domains()->select('d.name')
            ->selectSub($this->mailboxCount(), 'mailbox_count')
            ->selectSub($forwarding, 'forwarding_count')
            ->selectSub($usedBytes, 'used_bytes');
    }

    /** @return array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null} */
    private function row(mixed $row): array
    {
        $r = $this->fields($row);
        $name = $this->text($r, 'name');

        return [
            'name' => $name,
            'unicode_name' => $this->unicode($name),
            'mailbox_count' => $this->number($r, 'mailbox_count'),
            'forwarding_count' => $this->number($r, 'forwarding_count'),
            'used_bytes' => ($r['used_bytes'] ?? null) === null ? null : $this->number($r, 'used_bytes'),
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
