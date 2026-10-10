<?php

namespace App\Auth;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The guide domains one user may see, as DomainAccess::scope() hands it to every list query: all of
 * them (a server admin) or a list of `virtual_domains.id`s, possibly empty.
 */
final readonly class DomainScope
{
    /** @param  list<int>|null  $ids  null means every domain */
    public function __construct(private ?array $ids) {}

    public static function all(): self
    {
        return new self(null);
    }

    /** @param  array<array-key, int>  $ids */
    public static function only(array $ids): self
    {
        return new self(array_values(array_unique($ids)));
    }

    public function isAll(): bool
    {
        return $this->ids === null;
    }

    /** @return list<int>|null null means every domain */
    public function ids(): ?array
    {
        return $this->ids;
    }

    public function allows(int $domainId): bool
    {
        return $this->ids === null || in_array($domainId, $this->ids, true);
    }

    /**
     * Keeps only rows whose $column (a domain id) is in scope. An empty list compiles to an always-false
     * condition (the query builder renders `whereIn` with no values as `0 = 1`), so it matches nothing.
     *
     * @template TModel of Model
     *
     * @param  QueryBuilder|EloquentBuilder<TModel>  $q
     */
    public function constrain(QueryBuilder|EloquentBuilder $q, string $column): void
    {
        if ($this->ids !== null) {
            $q->whereIn($column, $this->ids);
        }
    }
}
