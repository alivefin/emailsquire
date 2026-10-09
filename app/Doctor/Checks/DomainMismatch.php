<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A mailbox's or alias source's domain part must be the name of its
 * domain_id, or a domain delete leaves orphans or removes the wrong rows.
 * Case differences are address_format's job, so this compares lowercased.
 */
final class DomainMismatch extends GuideCheck
{
    public function id(): string
    {
        return 'guide.domain_mismatch';
    }

    public function title(): string
    {
        return 'Address domain matches domain_id';
    }

    protected function check(): CheckResult
    {
        $items = [
            ...$this->mismatches('virtual_users', 'email'),
            ...$this->mismatches('virtual_aliases', 'source'),
        ];

        if ($items === []) {
            return $this->result(Status::Ok, 'Every address belongs to its domain_id.');
        }

        return $this->result(
            Status::Warn,
            count($items).' address(es) whose domain differs from the name of their domain_id.',
            $items,
        );
    }

    /**
     * @param  literal-string  $column
     * @return list<string>
     */
    private function mismatches(string $table, string $column): array
    {
        $rows = DB::table(GuideSchema::table($table).' as r')
            ->leftJoin(GuideSchema::table('virtual_domains').' as d', 'd.id', '=', 'r.domain_id')
            ->where(fn (Builder $q) => $q
                ->whereNull('d.id')
                // A catch-all `@example.org` compares `example.org`, like any other address.
                ->orWhereRaw("CAST(LOWER(SUBSTRING_INDEX(r.{$column}, '@', -1)) AS BINARY) <> CAST(LOWER(d.name) AS BINARY)"))
            ->orderBy('r.id')
            ->get(["r.{$column} as address", 'd.id as domain', 'r.domain_id']);

        $items = [];
        foreach ($rows as $row) {
            [$address, $domainId] = self::strings([$row->address ?? '', $row->domain_id ?? '']);
            $items[] = $row->domain === null ? "{$address} (domain_id {$domainId} does not exist)" : $address;
        }

        return $items;
    }
}
