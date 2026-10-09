<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

/**
 * The guide's tables, columns and lengths as the ISPmail guide creates
 * them. The other guide.* checks skip unless problems() is empty.
 */
final class GuideSchemaShape extends BaseCheck
{
    /** table => column => [data type, varchar length or null] */
    public const EXPECTED = [
        'virtual_domains' => [
            'id' => ['int', null],
            'name' => ['varchar', 50],
        ],
        'virtual_users' => [
            'id' => ['int', null],
            'domain_id' => ['int', null],
            'email' => ['varchar', 100],
            'password' => ['varchar', 150],
            'quota' => ['bigint', null],
        ],
        'virtual_aliases' => [
            'id' => ['int', null],
            'domain_id' => ['int', null],
            'source' => ['varchar', 100],
            'destination' => ['varchar', 100],
        ],
    ];

    public function id(): string
    {
        return 'guide.schema';
    }

    public function title(): string
    {
        return 'Guide schema shape';
    }

    public function run(): CheckResult
    {
        $problems = $this->problems();

        if ($problems === []) {
            return $this->result(Status::Ok, 'Schema '.GuideSchema::name().' matches the ISPmail guide.');
        }

        return $this->result(
            Status::Fail,
            'Schema '.GuideSchema::name().' does not match the ISPmail guide; the other guide checks are skipped.',
            $problems,
        );
    }

    /**
     * Everything that differs from EXPECTED; empty when the schema is usable.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $schema = GuideSchema::name();

        $exists = DB::selectOne('SELECT 1 AS present FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$schema]);

        if ($exists === null) {
            return ["schema {$schema} is missing"];
        }

        $rows = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS len
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ?
              ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$schema],
        );

        $columns = [];
        foreach ($rows as $row) {
            /** @var object{t: string, c: string, type: string, len: int|string|null} $row */
            $columns[] = [
                'table' => (string) $row->t,
                'column' => (string) $row->c,
                'type' => strtolower((string) $row->type),
                'length' => $row->len === null ? null : (int) $row->len,
            ];
        }

        return self::diff($columns);
    }

    /**
     * @param  iterable<array{table: string, column: string, type: string, length: int|null}>  $columns
     * @return list<string>
     */
    public static function diff(iterable $columns): array
    {
        $actual = [];
        foreach ($columns as $column) {
            $actual[$column['table']][$column['column']] = [$column['type'], $column['length']];
        }

        $problems = [];

        foreach (self::EXPECTED as $table => $expectedColumns) {
            if (! isset($actual[$table])) {
                $problems[] = "table {$table} is missing";

                continue;
            }

            foreach ($expectedColumns as $column => [$type, $length]) {
                if (! isset($actual[$table][$column])) {
                    $problems[] = "column {$table}.{$column} is missing";

                    continue;
                }

                [$actualType, $actualLength] = $actual[$table][$column];
                $want = self::describe($type, $length);
                $have = self::describe($actualType, $type === 'varchar' ? $actualLength : null);

                if ($have !== $want) {
                    $problems[] = "{$table}.{$column} is {$have}, expected {$want}";
                }
            }
        }

        return $problems;
    }

    private static function describe(string $type, ?int $length): string
    {
        return $length === null ? $type : "{$type}({$length})";
    }
}
