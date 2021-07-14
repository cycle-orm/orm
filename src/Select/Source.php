<?php

declare(strict_types=1);

namespace Cycle\ORM\Select;

use Spiral\Database\DatabaseInterface;

final class Source implements SourceInterface
{
    private DatabaseInterface $database;

    private string $table;

    private ?ScopeInterface $scope = null;

    public function __construct(DatabaseInterface $database, string $table)
    {
        $this->database = $database;
        $this->table = $table;
    }

    public function getDatabase(): DatabaseInterface
    {
        return $this->database;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function withScope(?ScopeInterface $scope): SourceInterface
    {
        $source = clone $this;
        $source->scope = $scope;

        return $source;
    }

    public function getScope(): ?ScopeInterface
    {
        return $this->scope;
    }
}
