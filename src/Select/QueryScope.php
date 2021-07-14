<?php

declare(strict_types=1);

namespace Cycle\ORM\Select;

/**
 * Provides the ability to scope query and load necessary relations into the loader.
 */
final class QueryScope implements ScopeInterface
{
    private array $where;
    private array $orderBy;

    public function __construct(array $where, array $orderBy = [])
    {
        $this->where = $where;
        $this->orderBy = $orderBy;
    }

    public function apply(QueryBuilder $query): void
    {
        $query->where($this->where)->orderBy($this->orderBy);
    }
}
