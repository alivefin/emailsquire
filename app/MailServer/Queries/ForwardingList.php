<?php

namespace App\MailServer\Queries;

use App\Auth\DomainScope;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the forwarding screen: virtual_aliases rows grouped by (lowercased) source.
 * Every compare on a guide column is lowercased and binary, so it holds on utf8mb3 tables too.
 * A target's kind is judged within the reader's DomainScope: a mailbox or domain on the server that
 * the reader has no role on reads as `external`, so the screen never confirms that it exists.
 *
 * @phpstan-type Target array{address: string, kind: string}
 * @phpstan-type Row array{local: string, source: string, source_is_mailbox: bool, catch_all: bool, keep_copy: bool, targets: list<Target>}
 */
class ForwardingList
{
    public const SRC = 'convert(lower(a.source) using utf8mb4) collate utf8mb4_bin';

    public const DEST = 'convert(lower(a.destination) using utf8mb4) collate utf8mb4_bin';

    /**
     * @param  'all'|'local'|'external'|string  $kind  local: any target is a mailbox here; external: any target is off-server (or out of scope)
     * @param  string|null  $target  normalised address; keeps only sources with this destination (a keep-copy row does not count)
     * @return LengthAwarePaginator<int, Row>
     */
    public function paginate(DomainScope $scope, Domain $d, ?string $q, string $kind, int $page, int $perPage, ?string $target = null): LengthAwarePaginator
    {
        // One row per alias with its flags, then one group per source. Flags are computed per row so the
        // grouped query stays a plain sum() and the group filters see the whole source, not just the match.
        $isMailbox = DB::table(GuideSchema::table('virtual_users').' as u')->selectRaw('1')
            ->whereRaw('convert(lower(u.email) using utf8mb4) collate utf8mb4_bin = '.self::DEST)->limit(1);
        $scope->constrain($isMailbox, 'u.domain_id');
        $isOnServer = self::onServer($scope, self::DEST);

        $like = '%'.addcslashes($q ?? '', '\\%_').'%';
        $rows = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->where('a.domain_id', $d->id)
            ->selectRaw(self::SRC.' as src')
            ->selectRaw('(convert(a.source using utf8mb4) like ? or convert(a.destination using utf8mb4) like ?) as q_hit', [$like, $like])
            // The mailbox's own keep-copy row (source = destination) is not a target.
            ->selectRaw(self::SRC.' <> '.self::DEST.' as is_target')
            ->selectRaw('('.self::SRC.' <> '.self::DEST.' and '.self::DEST.' = ?) as t_hit', [strtolower($target ?? '')])
            ->selectSub($isMailbox, 'is_mbx')
            ->selectSub($isOnServer, 'is_srv');

        $groups = DB::query()->fromSub($rows, 'r')->select('r.src')->groupBy('r.src')->orderBy('r.src');

        if ($q !== null && $q !== '') {
            $groups->havingRaw('sum(r.q_hit) > 0');
        }

        if ($target !== null && $target !== '') {
            $groups->havingRaw('sum(r.t_hit) > 0');
        }

        match ($kind) {
            'local' => $groups->havingRaw('sum(r.is_target and r.is_mbx is not null) > 0'),
            'external' => $groups->havingRaw('sum(r.is_target and r.is_srv is null) > 0'),
            default => null,
        };

        $total = DB::query()->fromSub((clone $groups)->reorder(), 'g')->count();
        $items = $groups->forPage($page, $perPage)->get();
        $sources = [];
        foreach ($items as $item) {
            $src = $item->src ?? null;
            if (is_string($src)) {
                $sources[] = $src;
            }
        }

        $rows = $this->rows($scope, $d, $sources);

        return new LengthAwarePaginator(
            array_map(fn (mixed $item): array => $rows[is_string($item->src ?? null) ? $item->src : ''] ?? throw new \LogicException('Group vanished.'), $items->all()),
            $total,
            $perPage,
            $page,
        );
    }

    /** @return Row|null */
    public function find(DomainScope $scope, Domain $d, string $address): ?array
    {
        $address = strtolower($address);

        return $this->rows($scope, $d, [$address])[$address] ?? null;
    }

    /**
     * `select 1` when the domain part of $address (a lowercased binary SQL expression) is a domain on the
     * server within $scope; for a correlated subquery.
     *
     * @param  literal-string  $address
     */
    public static function onServer(DomainScope $scope, string $address): Builder
    {
        $query = DB::table(GuideSchema::table('virtual_domains').' as dm')->selectRaw('1')
            ->whereRaw('convert(lower(dm.name) using utf8mb4) collate utf8mb4_bin = substring_index('.$address.", '@', -1)")->limit(1);
        $scope->constrain($query, 'dm.id');

        return $query;
    }

    /**
     * @param  list<string>  $sources  lowercased
     * @return array<string, Row>
     */
    private function rows(DomainScope $scope, Domain $d, array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $query = DB::table(GuideSchema::table('virtual_aliases').' as a')
            ->where('a.domain_id', $d->id)
            ->whereRaw(self::SRC.' in ('.implode(',', array_fill(0, count($sources), '?')).')', $sources)
            ->orderBy('a.id')
            ->select('a.source', 'a.destination');

        $grouped = [];
        foreach ($query->get() as $row) {
            $grouped[strtolower(is_scalar($row->source) ? (string) $row->source : '')][] = strtolower(is_scalar($row->destination) ? (string) $row->destination : '');
        }

        $destinations = array_values(array_unique(array_merge(...array_values($grouped))));
        $mailboxes = $this->mailboxSet($scope, array_merge($destinations, array_keys($grouped)));
        $domainQuery = DB::table(GuideSchema::table('virtual_domains'));
        $scope->constrain($domainQuery, 'id');
        $domains = $domainQuery->pluck('name')->map(fn (mixed $n): string => strtolower(is_scalar($n) ? (string) $n : ''))->flip();

        $out = [];
        foreach ($grouped as $source => $dests) {
            $isMailbox = isset($mailboxes[$source]);
            $keepCopy = $isMailbox && in_array($source, $dests, true);
            $targets = [];
            foreach ($dests as $dest) {
                if ($dest === $source) {
                    continue;
                }
                $at = strrpos($dest, '@');
                $kind = match (true) {
                    isset($mailboxes[$dest]) => 'mailbox',
                    $at !== false && isset($domains[substr($dest, $at + 1)]) => 'server',
                    default => 'external',
                };
                $targets[] = ['address' => $dest, 'kind' => $kind];
            }

            $catchAll = str_starts_with($source, '@');
            $at = strrpos($source, '@');
            $out[$source] = [
                'local' => $catchAll || $at === false ? '' : substr($source, 0, $at),
                'source' => $source,
                'source_is_mailbox' => $isMailbox,
                'catch_all' => $catchAll,
                'keep_copy' => $keepCopy,
                'targets' => $targets,
            ];
        }

        return $out;
    }

    /**
     * The mailboxes among $addresses on domains within $scope.
     *
     * @param  list<string>  $addresses  lowercased
     * @return array<string, true>
     */
    private function mailboxSet(DomainScope $scope, array $addresses): array
    {
        $addresses = array_values(array_unique($addresses));
        $found = [];
        foreach (array_chunk($addresses, 500) as $chunk) {
            $query = DB::table(GuideSchema::table('virtual_users'))
                ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin in ('.implode(',', array_fill(0, count($chunk), '?')).')', $chunk);
            $scope->constrain($query, 'domain_id');
            $emails = $query->pluck('email');
            foreach ($emails as $email) {
                $found[strtolower(is_scalar($email) ? (string) $email : '')] = true;
            }
        }

        return $found;
    }
}
