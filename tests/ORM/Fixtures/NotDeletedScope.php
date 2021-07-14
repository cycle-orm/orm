<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Select\ScopeInterface;
use Cycle\ORM\Select\QueryBuilder;

class NotDeletedScope implements ScopeInterface
{
    public function apply(QueryBuilder $query): void
    {
        $query->where('deleted_at', '=', null);
    }
}
