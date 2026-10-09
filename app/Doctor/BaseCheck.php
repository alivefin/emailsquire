<?php

namespace App\Doctor;

/**
 * Convenience base for checks: not a server check by default, and a
 * `result()` helper that fills in the id and title.
 */
abstract class BaseCheck implements Check
{
    public function isServerCheck(): bool
    {
        return false;
    }

    /** @param  array<array-key, string>  $items */
    protected function result(Status $status, ?string $detail = null, array $items = []): CheckResult
    {
        return new CheckResult($this->id(), $status, $this->title(), $detail, $items);
    }

    /**
     * Query results as strings (the query builder returns mixed values).
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    protected static function strings(iterable $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }
}
