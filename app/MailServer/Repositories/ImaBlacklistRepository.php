<?php

namespace App\MailServer\Repositories;

use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

/**
 * Reads and drops ISPmail Admin's `blacklist_email` table. That table belongs to
 * the old tool, not to the guide schema, so this is the one place that may DROP it.
 */
final class ImaBlacklistRepository
{
    private const TABLE = 'blacklist_email';

    public function __construct(private readonly string $connection) {}

    public function exists(): bool
    {
        $row = DB::connection($this->connection)->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [GuideSchema::name(), self::TABLE],
        );

        /** @var object{c: int|string}|null $row */
        return $row !== null && (int) $row->c > 0;
    }

    /** @return list<array{id: int, address: string, reason: string}> */
    public function rows(): array
    {
        $rows = DB::connection($this->connection)->select(
            'SELECT id, address, reason FROM '.GuideSchema::table(self::TABLE).' ORDER BY id',
        );

        $result = [];
        foreach ($rows as $row) {
            /** @var object{id: int|string, address: string, reason: string} $row */
            $result[] = ['id' => (int) $row->id, 'address' => (string) $row->address, 'reason' => (string) $row->reason];
        }

        return $result;
    }

    public function drop(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE '.GuideSchema::table(self::TABLE));
    }
}
