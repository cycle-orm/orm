<?php

declare(strict_types=1);

namespace Cycle\ORM\Select;

/**
 * Provides the ability to modify the selector and/or entity loader. Can be used to implement multi-table inheritance.
 */
interface ScopeInterface
{
    /**
     * Configure query and loader pair using proxy strategy.
     */
    public function apply(QueryBuilder $query): void;
}
