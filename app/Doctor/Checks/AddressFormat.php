<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

/** Postfix lookups are exact-match: addresses and domains must be lowercase ASCII. */
final class AddressFormat extends GuideCheck
{
    public function id(): string
    {
        return 'guide.address_format';
    }

    public function title(): string
    {
        return 'Lowercase ASCII addresses';
    }

    protected function check(): CheckResult
    {
        $items = [
            ...self::strings(DB::table(GuideSchema::table('virtual_domains'))
                ->whereRaw(self::notLowerAscii('name'))->orderBy('id')->pluck('name')),
            ...self::strings(DB::table(GuideSchema::table('virtual_users'))
                ->whereRaw(self::notLowerAscii('email'))->orderBy('id')->pluck('email')),
            ...self::strings(DB::table(GuideSchema::table('virtual_aliases'))
                ->whereRaw('('.self::notLowerAscii('source').' OR '.self::notLowerAscii('destination').')')
                ->orderBy('id')
                ->selectRaw("CONCAT(source, ' → ', destination) AS alias")
                ->pluck('alias')),
        ];

        if ($items === []) {
            return $this->result(Status::Ok, 'All domains and addresses are lowercase ASCII.');
        }

        return $this->result(
            Status::Warn,
            count($items).' domain(s) or address(es) contain uppercase or non-ASCII characters; Postfix lookups may not match them.',
            $items,
        );
    }

    /**
     * SQL that is true when the column holds uppercase or any multi-byte (non-ASCII) character.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function notLowerAscii(string $column): string
    {
        return "(CAST({$column} AS BINARY) <> CAST(LOWER({$column}) AS BINARY) OR LENGTH({$column}) <> CHAR_LENGTH({$column}))";
    }
}
