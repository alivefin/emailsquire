<?php

namespace App\MailServer;

use App\MailServer\Exceptions\SchemaExtensionMissing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whether the server admin has applied the optional schema extension of backend plan section 10: the
 * `enabled` ("stop receiving mail") and `sendonly` columns of the guide's `virtual_users`. The app never
 * adds them. It reads information_schema once per column and remembers the answer for 10 minutes, so a
 * server that gains the columns shows the switches within that time (or at once after `flush()`).
 */
class SchemaExtension
{
    public const ENABLED = 'enabled';

    public const SENDONLY = 'sendonly';

    public const COLUMNS = [self::ENABLED, self::SENDONLY];

    public const TTL_SECONDS = 600;

    private const CACHE_PREFIX = 'emailsquire:schema-extension:';

    /** @param  'enabled'|'sendonly'|string  $column */
    public function has(string $column): bool
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("{$column} is not a column of the schema extension.");
        }

        $cached = Cache::get(self::CACHE_PREFIX.$column);
        if (is_bool($cached)) {
            return $cached;
        }

        $present = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', GuideSchema::name())
            ->where('TABLE_NAME', 'virtual_users')
            ->where('COLUMN_NAME', $column)
            ->exists();
        Cache::put(self::CACHE_PREFIX.$column, $present, self::TTL_SECONDS);

        return $present;
    }

    /** Refuses with 422 `schema_extension_missing` on $field unless the column exists. */
    public function assertHas(string $column, string $field): void
    {
        if (! $this->has($column)) {
            throw new SchemaExtensionMissing($field, $column);
        }
    }

    /** Forgets the remembered answers, so the next read looks at the schema again. */
    public function flush(): void
    {
        foreach (self::COLUMNS as $column) {
            Cache::forget(self::CACHE_PREFIX.$column);
        }
    }

    /**
     * What the console may offer; the `schema_extension` part of GET /me's `capabilities`.
     * A schema that can't be read counts as "not extended".
     *
     * @return array{receiving: bool, sendonly: bool}
     */
    public function capabilities(): array
    {
        try {
            return ['receiving' => $this->has(self::ENABLED), 'sendonly' => $this->has(self::SENDONLY)];
        } catch (QueryException) {
            return ['receiving' => false, 'sendonly' => false];
        }
    }
}
