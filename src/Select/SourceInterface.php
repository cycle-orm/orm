<?php

declare(strict_types=1);

namespace Cycle\ORM\Select;

use Spiral\Database\DatabaseInterface;

/**
 * Defines the access to the SQL database.
 */
interface SourceInterface
{
    /**
     * Get database associated with the entity.
     */
    public function getDatabase(): DatabaseInterface;

    /**
     * Get table associated with the entity.
     */
    public function getTable(): string;

    /**
     * Associate query scope (or remove association).
     */
    public function withScope(?ScopeInterface $scope): SourceInterface;

    /**
     * Return associated query scope.
     */
    public function getScope(): ?ScopeInterface;
}
