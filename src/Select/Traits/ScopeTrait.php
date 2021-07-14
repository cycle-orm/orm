<?php

declare(strict_types=1);

namespace Cycle\ORM\Select\Traits;

use Cycle\ORM\Select\AbstractLoader;
use Cycle\ORM\Select\ScopeInterface;
use Cycle\ORM\Select\QueryBuilder;
use Spiral\Database\Query\SelectQuery;

/**
 * Provides the ability to assign the scope to the AbstractLoader.
 */
trait ScopeTrait
{
    protected ?ScopeInterface $scope = null;

    /**
     * Associate scope with the selector.
     *
     * @return AbstractLoader|$this
     */
    public function setScope(ScopeInterface $scope = null): self
    {
        $this->scope = $scope;

        return $this;
    }

    abstract public function getAlias(): string;

    protected function applyScope(SelectQuery $query): SelectQuery
    {
        if ($this->scope !== null) {
            $this->scope->apply(new QueryBuilder($query, $this));
        }

        return $query;
    }
}
