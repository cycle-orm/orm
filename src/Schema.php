<?php

declare(strict_types=1);

namespace Cycle\ORM;

use Cycle\ORM\Exception\SchemaException;

/**
 * Static schema with automatic class name => role aliasing.
 */
final class Schema implements SchemaInterface
{
    private array $aliases;

    private array $schema = [];

    public function __construct(array $schema)
    {
        // split into two?
        [$this->schema, $this->aliases] = $this->normalize($schema);
    }

    public static function __set_state(array $an_array): Schema
    {
        $schema = new self([]);
        $schema->schema = $an_array['schema'];
        $schema->aliases = $an_array['aliases'];

        return $schema;
    }

    public function getRoles(): array
    {
        return array_keys($this->schema);
    }

    public function getRelations(string $role): array
    {
        return array_keys($this->define($role, self::RELATIONS));
    }

    /**
     * @return array [role => [relation name => relation schema]]
     */
    public function getOuterRelations(string $role): array
    {
        // return null;
        $result = [];
        foreach ($this->schema as $roleName => $entitySchema) {
            foreach ($entitySchema[SchemaInterface::RELATIONS] ?? [] as $relName => $item) {
                if ($item[Relation::TARGET] === $role) {
                    $result[$roleName][$relName] = $item;
                } elseif ($item[Relation::TYPE] === Relation::MANY_TO_MANY) {
                    $through = $this->resolveAlias($item[Relation::SCHEMA][Relation::THROUGH_ENTITY]);
                    if ($through !== $role) {
                        continue;
                    }
                    $handshake = $item[Relation::SCHEMA][Relation::HANDSHAKE] ?? null;
                    $target = $item[Relation::TARGET];
                    $result[$roleName][$relName] = [
                        Relation::TYPE => Relation::HAS_MANY,
                        Relation::TARGET => $role,
                        Relation::SCHEMA => [
                            Relation::CASCADE => $item[Relation::SCHEMA][Relation::CASCADE] ?? null,
                            Relation::INNER_KEY => $item[Relation::SCHEMA][Relation::INNER_KEY],
                            Relation::OUTER_KEY => $item[Relation::SCHEMA][Relation::THROUGH_INNER_KEY],
                        ],
                    ];
                    $result[$target][$handshake ?? ($roleName . '.' . $relName . ':' . $target)] = [
                        Relation::TYPE => Relation::HAS_MANY,
                        Relation::TARGET => $role,
                        Relation::SCHEMA => [
                            Relation::CASCADE => $item[Relation::SCHEMA][Relation::CASCADE] ?? null,
                            Relation::INNER_KEY => $item[Relation::SCHEMA][Relation::OUTER_KEY],
                            Relation::OUTER_KEY => $item[Relation::SCHEMA][Relation::THROUGH_OUTER_KEY],
                        ],
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * @return array [relation name => relation schema]
     */
    public function getInnerRelations(string $role): array
    {
        return $this->schema[$role][SchemaInterface::RELATIONS] ?? [];
    }

    public function defines(string $role): bool
    {
        return isset($this->schema[$role]) || isset($this->aliases[$role]);
    }

    public function define(string $role, int $property)
    {
        $role = $this->resolveAlias($role) ?? $role;

        if (!isset($this->schema[$role])) {
            throw new SchemaException("Undefined schema `{$role}`, not found");
        }

        return $this->schema[$role][$property] ?? null;
    }

    public function defineRelation(string $role, string $relation): array
    {
        $relations = $this->define($role, self::RELATIONS);

        if (!isset($relations[$relation])) {
            throw new SchemaException("Undefined relation `{$role}`.`{$relation}`");
        }

        return $relations[$relation];
    }

    public function resolveAlias(string $role): ?string
    {
        // walk throught all children until parent entity found
        while (isset($this->aliases[$role])) {
            $role = $this->aliases[$role];
        }

        return $role;
    }

    /**
     * Automatically replace class names with their aliases.
     *
     * @return array Pair of [schema, aliases]
     */
    private function normalize(array $schema): array
    {
        $result = $aliases = [];

        foreach ($schema as $key => $item) {
            $role = $key;
            if (!isset($item[self::ENTITY])) {
                // legacy type of declaration (class => schema)
                $item[self::ENTITY] = $key;
            }

            if (class_exists($key)) {
                $role = $item[self::ROLE] ?? $key;
                if ($role !== $key) {
                    $aliases[$key] = $role;
                }
            }

            if ($item[self::ENTITY] !== $role && class_exists($item[self::ENTITY])) {
                $aliases[$item[self::ENTITY]] = $role;
            }

            unset($item[self::ROLE]);
            $result[$role] = $item;
        }

        // normalizing relation associations
        foreach ($result as &$item) {
            if (isset($item[self::RELATIONS])) {
                $item[self::RELATIONS] = iterator_to_array($this->normalizeRelations(
                    $item[self::RELATIONS],
                    $aliases
                ));
            }

            unset($item);
        }

        $result = $this->linkRelations($result, $aliases);

        return [$result, $aliases];
    }

    private function normalizeRelations(array $relations, array $aliases): \Generator
    {
        foreach ($relations as $name => &$rel) {
            $target = $rel[Relation::TARGET];
            while (isset($aliases[$target])) {
                $target = $aliases[$target];
            }

            $rel[Relation::TARGET] = $target;

            $nullable = $rel[Relation::SCHEMA][Relation::NULLABLE] ?? null;
            // // Transform nullable BelongsTo to RefersTo
            // if ($rel[Relation::TYPE] === Relation::BELONGS_TO && $nullable === true) {
            //     $rel[Relation::TYPE] = Relation::REFERS_TO;
            // }
            // // Transform not nullable RefersTo to BelongsTo
            // if ($rel[Relation::TYPE] === Relation::REFERS_TO && $nullable === false) {
            //     $rel[Relation::TYPE] = Relation::BELONGS_TO;
            // }

            // Normalize THROUGH_ENTITY value
            if ($rel[Relation::TYPE] === Relation::MANY_TO_MANY) {
                $through = $rel[Relation::SCHEMA][Relation::THROUGH_ENTITY];
                while (isset($aliases[$through])) {
                    $through = $aliases[$through];
                }
                $rel[Relation::SCHEMA][Relation::THROUGH_ENTITY] = $through;
            }

            yield $name => $rel;
        }
    }

    private function linkRelations(array $schemaArray, array $aliases): array
    {
        $result = $schemaArray;
        foreach ($result as $role => $item) {
            foreach ($item[self::RELATIONS] ?? [] as $container => $relation) {
                $target = $relation[Relation::TARGET];
                if (!array_key_exists($target, $result)) {
                    continue;
                }
                $targetSchema = $result[$target];
                $targetRelations = $targetSchema[self::RELATIONS];
                $handshake = $relation[Relation::SCHEMA][Relation::HANDSHAKE] ?? null;
                if ($handshake !== null) {
                    if (!array_key_exists($handshake, $targetRelations)) {
                        throw new SchemaException(
                            sprintf(
                                'Relation `%s` for handshake with `%s.%s` not found in the `%s` role.',
                                $handshake,
                                $role,
                                $container,
                                $target
                            )
                        );
                    }
                    $targetHandshake = $targetRelations[$handshake][Relation::SCHEMA][Relation::HANDSHAKE] ?? null;
                    if ($targetHandshake !== null && $container !== $targetHandshake) {
                        throw new SchemaException(
                            sprintf(
                                'Relation `%s.%s` links to `%s.%s` for handshake but `%s.%s` have different handshake value.',
                                $role,
                                $container,
                                $target,
                                $handshake,
                                $target,
                                $handshake,
                            )
                        );
                    }
                    // $targetSchema[self::RELATIONS][$handshake][Relation::SCHEMA][Relation::HANDSHAKE] = $container;
                    $result[$target][self::RELATIONS][$handshake][Relation::SCHEMA][Relation::HANDSHAKE] = $container;
                    continue;
                }
                // find relation for handshake
                $handshake = $this->findRelationForHandshake($role, $container, $relation, $targetRelations);
                if ($handshake === null) {
                    continue;
                }
                $result[$role][self::RELATIONS][$container][Relation::SCHEMA][Relation::HANDSHAKE] = $handshake;
                $result[$target][self::RELATIONS][$handshake][Relation::SCHEMA][Relation::HANDSHAKE] = $container;
            }
        }

        return $result;
    }

    private function findRelationForHandshake(
        string $role,
        string $container,
        array $relation,
        array $targetRelations
    ): ?string {
        $nullable = $relation[Relation::SCHEMA][Relation::NULLABLE] ?? null;
        /** @var callable $compareCallback */
        $compareCallback = match($relation[Relation::TYPE]) {
            Relation::MANY_TO_MANY => [$this, 'compareManyToMany'],
            // Relation::HAS_ONE, Relation::HAS_MANY => $nullable === true ? Relation::REFERS_TO : Relation::BELONGS_TO,
            default => null,
        };
        if ($compareCallback === null) {
            return null;
        }
        foreach ($targetRelations as $targetContainer => $targetRelation) {
            $targetSchema = $targetRelation[Relation::SCHEMA];
            if ($role !== $targetRelation[Relation::TARGET]) {
                continue;
            }
            if (isset($targetSchema[Relation::HANDSHAKE])) {
                if ($targetSchema[Relation::HANDSHAKE] === $container) {
                    // This target relation will be checked in the linkRelations() method
                    return null;
                }
                continue;
            }
            if ($compareCallback($relation, $targetRelation)) {
                return $targetContainer;
            }
        }
        return null;
    }

    private function compareManyToMany(array $relation, array $targetRelation): bool
    {
        $schema = $relation[Relation::SCHEMA];
        $targetSchema = $targetRelation[Relation::SCHEMA];
        // MTM connects with MTM only
        if ($targetRelation[Relation::TYPE] !== Relation::MANY_TO_MANY){
            return false;
        }
        // Pivot entity should be same
        if ($schema[Relation::THROUGH_ENTITY] !== $targetSchema[Relation::THROUGH_ENTITY]) {
            return false;
        }
        // Same keys
        if ((array)$schema[Relation::INNER_KEY] !== (array)$targetSchema[Relation::OUTER_KEY]
            || (array)$schema[Relation::OUTER_KEY] !== (array)$targetSchema[Relation::INNER_KEY]) {
            return false;
        }
        // Optional fields
        if (($schema[Relation::WHERE] ?? []) !== ($targetSchema[Relation::WHERE] ?? [])
            || ($schema[Relation::THROUGH_WHERE] ?? []) !== ($targetSchema[Relation::THROUGH_WHERE] ?? [])) {
            return false;
        }
        return true;
    }
}
