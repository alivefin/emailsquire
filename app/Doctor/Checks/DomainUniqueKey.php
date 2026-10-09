<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

/** Trixie's guide has UNIQUE KEY (name); restored Bookworm data may not. */
final class DomainUniqueKey extends GuideCheck
{
    public function id(): string
    {
        return 'guide.domain_unique_key';
    }

    public function title(): string
    {
        return 'Unique domain names';
    }

    protected function check(): CheckResult
    {
        $table = GuideSchema::table('virtual_domains');

        // Grouped with the column's own collation, the same way a unique key would compare.
        $duplicates = self::strings(DB::table($table)
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('name')
            ->pluck('name'));

        if ($duplicates !== []) {
            return $this->result(
                Status::Fail,
                'Duplicate domain names; merge them, then run: ALTER TABLE '.$table.' ADD UNIQUE KEY (name);',
                $duplicates,
            );
        }

        // Only a single-column unique index on `name` enforces uniqueness.
        $key = DB::selectOne(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'virtual_domains' AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME
             HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'name'",
            [GuideSchema::name()],
        );

        if ($key === null) {
            return $this->result(Status::Warn, 'ALTER TABLE '.$table.' ADD UNIQUE KEY (name);');
        }

        return $this->result(Status::Ok, 'virtual_domains.name has a unique key.');
    }
}
