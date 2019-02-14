<?php
declare(strict_types=1);
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Cycle;

use Spiral\Cycle\Exception\SchemaException;

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
    public const CONSTRAIN    = 12;
    public const TYPECAST     = 13;
    public const SCHEMA       = 14;

    /**
     * Return all roles defined withing the schema.
     *
     * @return array
     */
    public function getRoles(): array;

    /**
     * Check is given role has definition within the schema.
     *
     * @param string $role
     * @return bool
     */
    public function defines(string $role): bool;

    /**
     * Define schema value.
     *
     * Example: $schema->define(User::class, SchemaInterface::DATABASE);
     *
     * @param string $role
     * @param int    $property See ORM constants.
     * @return mixed
     *
     * @throws SchemaException
     */
    public function define(string $role, int $property);

    /**
     * Resolve the role name using entity class name.
     *
     * @param string $alias
     * @return null|string
     */
    public function resolveAlias(string $alias): ?string;

    /**
     * Define options associated with specific entity relation.
     *
     * @param string $class
     * @param string $relation
     * @return array
     *
     * @throws SchemaException
     */
    public function defineRelation(string $class, string $relation): array;
}