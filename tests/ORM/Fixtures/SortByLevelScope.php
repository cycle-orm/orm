<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Select\ScopeInterface;
use Cycle\ORM\Select\QueryBuilder;

class SortByLevelScope implements ScopeInterface
{
    public function apply(QueryBuilder $query): void
    {
        $query->orderBy('level', 'ASC');
    }
}
