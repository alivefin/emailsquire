<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

final class DuplicateAliases extends GuideCheck
{
    public function id(): string
    {
        return 'guide.duplicate_aliases';
    }

    public function title(): string
    {
        return 'Duplicate aliases';
    }

    protected function check(): CheckResult
    {
        $items = self::strings(DB::table(GuideSchema::table('virtual_aliases'))
            ->selectRaw("CONCAT(MIN(source), ' → ', MIN(destination)) AS pair")
            ->groupBy('source', 'destination')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('MIN(source), MIN(destination)')
            ->pluck('pair'));

        if ($items === []) {
            return $this->result(Status::Ok, 'No repeated (source, destination) pairs.');
        }

        return $this->result(
            Status::Warn,
            count($items).' (source, destination) pair(s) appear more than once.',
            $items,
        );
    }
}
