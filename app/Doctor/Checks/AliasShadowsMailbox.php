<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Postfix resolves virtual_alias_maps before delivering to a mailbox, so an
 * alias whose source is a mailbox takes that mailbox's mail away, unless a
 * `source = destination` row keeps a copy (the guide's forward-and-keep).
 */
final class AliasShadowsMailbox extends GuideCheck
{
    public function id(): string
    {
        return 'guide.alias_shadows_mailbox';
    }

    public function title(): string
    {
        return 'Aliases that shadow mailboxes';
    }

    protected function check(): CheckResult
    {
        $aliases = GuideSchema::table('virtual_aliases');

        $items = self::strings(DB::table($aliases.' as a')
            ->join(GuideSchema::table('virtual_users').' as u', 'u.email', '=', 'a.source')
            ->whereColumn('a.source', '<>', 'a.destination')
            ->whereNotExists(fn (Builder $q) => $q
                ->selectRaw('1')
                ->from($aliases.' as self')
                ->whereColumn('self.source', 'a.source')
                ->whereColumn('self.destination', 'a.source'))
            ->distinct()
            ->orderBy('a.source')
            ->pluck('a.source'));

        if ($items === []) {
            return $this->result(Status::Ok, 'No alias takes mail away from a mailbox.');
        }

        return $this->result(
            Status::Warn,
            count($items).' mailbox(es) receive no mail because an alias forwards it; add a source → source alias to keep a copy.',
            $items,
        );
    }
}
