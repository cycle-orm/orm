<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Select\ScopeInterface;
use Cycle\ORM\Select\SourceInterface;
use Spiral\Database\DatabaseInterface;

class DifferentSource implements SourceInterface
{

    /**
     * @inheritDoc
     */
    public function getDatabase(): DatabaseInterface
    {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * @inheritDoc
     */
    public function getTable(): string
    {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * @inheritDoc
     */
    public function withScope(?ScopeInterface $scope): SourceInterface
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getScope(): ?ScopeInterface
    {
        return null;
    }
}
