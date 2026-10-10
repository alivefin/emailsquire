<?php

namespace App\MailServer\Queries;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
use App\MailServer\QuotaFormat;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Every address on the server: mailboxes and forwarding sources, one row each. A mailbox that is also a
 * source is a mailbox, and lists its forwarding targets without its own keep-copy row. Compares on guide
 * columns are lowercased and binary, so they hold on utf8mb3 tables too.
 *
 * @phpstan-type Row array{address: string, type: string, domain: string, display_name: string|null, quota_bytes: int|null, used_bytes: int|null, targets: list<string>|null}
 */
class AddressList
{
    private const ADDR = 'convert(lower(%s) using utf8mb4) collate utf8mb4_bin';

    public function __construct(private readonly AddressNormalizer $normalizer) {}

    /**
     * @param  'all'|'mailbox'|'forward'|string  $type
     * @return LengthAwarePaginator<int, Row>
     */
    public function paginate(?string $q, string $type, ?string $domain, int $page, int $perPage): LengthAwarePaginator
    {
        $domainId = null;
        if ($domain !== null && $domain !== '') {
            $domainId = $this->domainId($domain);
            if ($domainId === null) {
                return new LengthAwarePaginator([], 0, $perPage, $page);
            }
        }

        $query = $this->union($type, $domainId);
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($like) {
                $w->whereRaw('convert(x.addr using utf8mb4) like ?', [$like])
                    ->orWhereExists(fn (Builder $t) => $t->selectRaw('1')->from(GuideSchema::table('virtual_aliases').' as t')
                        ->whereRaw(sprintf(self::ADDR, 't.source').' = x.addr')
                        ->whereRaw('convert(t.destination using utf8mb4) like ?', [$like]));
            });
        }

        $page = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = $this->rows($page->items());

        return new LengthAwarePaginator($rows, $page->total(), $perPage, $page->currentPage());
    }

    /**
     * Header search: addresses containing $q, at most $limit.
     *
     * @return list<array{address: string, type: 'mailbox'|'forwarding', domain: string}>
     */
    public function search(string $q, int $limit): array
    {
        $like = '%'.addcslashes($q, '\\%_').'%';
        $items = $this->union('all', null)->whereRaw('convert(x.addr using utf8mb4) like ?', [$like])->limit($limit)->get();

        $out = [];
        foreach ($items as $item) {
            $r = get_object_vars($item);
            $out[] = [
                'address' => $this->text($r, 'addr'),
                'type' => ($r['type'] ?? null) === 'mailbox' ? 'mailbox' : 'forwarding',
                'domain' => $this->text($r, 'domain_name'),
            ];
        }

        return $out;
    }

    /** One row per lowercased address, joined to what the console shows about it. */
    private function union(string $type, ?int $domainId): Builder
    {
        $mailboxes = DB::table(GuideSchema::table('virtual_users').' as m')
            ->selectRaw(sprintf(self::ADDR, 'm.email').' as addr')
            ->selectRaw("'mailbox' as type")
            ->selectRaw('m.domain_id as domain_id')
            ->selectRaw('m.quota as quota');
        $forwards = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->selectRaw(sprintf(self::ADDR, 'a.source').' as addr')
            ->selectRaw("'forward' as type")
            ->selectRaw('a.domain_id as domain_id')
            ->selectRaw('null as quota')
            ->whereNotExists(fn (Builder $e) => $e->selectRaw('1')->from(GuideSchema::table('virtual_users').' as u')
                ->whereRaw(sprintf(self::ADDR, 'u.email').' = '.sprintf(self::ADDR, 'a.source')));

        if ($domainId !== null) {
            $mailboxes->where('m.domain_id', $domainId);
            $forwards->where('a.domain_id', $domainId);
        }

        // union (distinct) folds a source with several rows into one.
        $rows = match ($type) {
            'mailbox' => $mailboxes,
            'forward' => $forwards,
            default => $mailboxes->union($forwards),
        };

        return DB::query()->fromSub($rows, 'x')
            ->join(GuideSchema::table('virtual_domains').' as dm', 'dm.id', '=', 'x.domain_id')
            ->leftJoin('mailbox_settings as s', fn (JoinClause $j) => $j->whereRaw('x.addr collate utf8mb4_unicode_ci = s.mailbox_email'))
            ->leftJoin('mailbox_usage as u', fn (JoinClause $j) => $j->whereRaw('x.addr collate utf8mb4_unicode_ci = u.email'))
            ->select('x.addr', 'x.type', 'x.quota', 'dm.name as domain_name', 's.display_name', 'u.used_kib')
            ->orderBy('x.addr');
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<Row>
     */
    private function rows(array $items): array
    {
        $fields = array_map(fn (mixed $i): array => is_object($i) ? get_object_vars($i) : [], $items);
        $targets = $this->targets(array_values(array_map(fn (array $r): string => $this->text($r, 'addr'), $fields)));

        $rows = [];
        foreach ($fields as $r) {
            $address = $this->text($r, 'addr');
            $isMailbox = ($r['type'] ?? null) === 'mailbox';
            $used = is_numeric($r['used_kib'] ?? null) ? (int) $r['used_kib'] * 1024 : null;
            $mailboxTargets = $targets[$address] ?? [];
            $rows[] = [
                'address' => $address,
                'type' => $isMailbox ? 'mailbox' : 'forward',
                'domain' => $this->text($r, 'domain_name'),
                'display_name' => $isMailbox && is_string($r['display_name'] ?? null) ? $r['display_name'] : null,
                'quota_bytes' => $isMailbox ? QuotaFormat::effectiveBytes(is_numeric($r['quota'] ?? null) ? (int) $r['quota'] : 0) : null,
                'used_bytes' => $isMailbox ? $used : null,
                // A mailbox with no forwarding has no targets to show.
                'targets' => $isMailbox && $mailboxTargets === [] ? null : $mailboxTargets,
            ];
        }

        return $rows;
    }

    /**
     * Targets per lowercased source, in row order, without a source's own keep-copy row.
     *
     * @param  list<string>  $addresses
     * @return array<string, list<string>>
     */
    private function targets(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $query = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->whereRaw(sprintf(self::ADDR, 'a.source').' in ('.implode(',', array_fill(0, count($addresses), '?')).')', $addresses)
            ->orderBy('a.id')
            ->select('a.source', 'a.destination');

        $out = [];
        foreach ($query->get() as $row) {
            $source = strtolower(is_scalar($row->source) ? (string) $row->source : '');
            $dest = strtolower(is_scalar($row->destination) ? (string) $row->destination : '');
            if ($dest !== $source) {
                $out[$source][] = $dest;
            }
        }

        return $out;
    }

    private function domainId(string $domain): ?int
    {
        try {
            $ascii = $this->normalizer->domain($domain)->ascii;
        } catch (InvalidAddress) {
            return null;
        }

        $id = DB::table(GuideSchema::table('virtual_domains').' as dm')
            ->whereRaw(sprintf(self::ADDR, 'dm.name').' = ?', [$ascii])
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param  array<array-key, mixed>  $r */
    private function text(array $r, string $key): string
    {
        return is_scalar($r[$key] ?? null) ? (string) $r[$key] : '';
    }
}
