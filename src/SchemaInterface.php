<?php

declare(strict_types=1);

namespace Cycle\ORM;

use Cycle\ORM\Exception\SchemaException;

interface SchemaInterface
{
    /*
     * Various segments of schema.
     */
    public const ROLE         = 0;
    public const ENTITY       = 1;
    public const MAPPER       = 2;
    public const SOURCE       = 3;
    public const REPOSITORY   = 4;
    public const DATABASE     = 5;
    public const TABLE        = 6;
    public const PRIMARY_KEY  = 7;
    public const FIND_BY_KEYS = 8;
    public const COLUMNS      = 9;
    public const RELATIONS    = 10;
    public const CHILDREN     = 11;
    public const SCOPE        = 12;
    public const TYPECAST     = 13;
    public const SCHEMA       = 14;

    /**
     * Return all roles defined within the schema.
     */
    public function getRoles(): array;

    /**
     * Get name of relations associated with given entity role.
     */
    public function getRelations(string $role): array;

    /**
     * Check if the given role has a definition within the schema.
     */
    public function defines(string $role): bool;

    /**
     * Define schema value.
     *
     * Example: $schema->define(User::class, SchemaInterface::DATABASE);
     *
     * @param int $property See ORM constants.
     * @return mixed
     *
     * @throws SchemaException
     */
    public function define(string $role, int $property);

    /**
     * Define options associated with specific entity relation.
     *
     * @throws SchemaException
     */
    public function defineRelation(string $role, string $relation): array;

    /**
     * Resolve the role name using entity class name.
     */
    public function resolveAlias(string $role): ?string;
}
