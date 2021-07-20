<?php

declare(strict_types=1);

namespace Cycle\ORM;

use Cycle\ORM\Heap\Node;
use Cycle\ORM\Relation\ActiveRelationInterface;
use Cycle\ORM\Relation\DependencyInterface;
use Cycle\ORM\Relation\ManyToMany;
use Cycle\ORM\Relation\RelationInterface;
use Cycle\ORM\Relation\SameRowRelationInterface;
use Cycle\ORM\Relation\ShadowBelongsTo;
use Cycle\ORM\Relation\ShadowHasMany;
use JetBrains\PhpStorm\ExpectedValues;

use function count;

/**
 * Manages the position of node in the relation graph and provide access to neighbours.
 */
final class RelationMap
{
    /** @var ActiveRelationInterface[] */
    private array $innerRelations;

    /** @var DependencyInterface[] */
    private array $dependencies = [];
    /** @var RelationInterface[] */
    private array $slaves = [];
    /** @var SameRowRelationInterface[] */
    private array $embedded = [];

    private function __construct(array $innerRelations, array $outerRelations)
    {
        $this->innerRelations = $innerRelations;

        foreach ($innerRelations as $name => $relation) {
            if ($relation instanceof DependencyInterface) {
                $this->dependencies[$name] = $relation;
            } elseif ($relation instanceof SameRowRelationInterface) {
                $this->embedded[$name] = $relation;
            } else {
                $this->slaves[$name] = $relation;
            }
        }
    }

    public static function build(OrmInterface $orm, string $role): self
    {
        $factory = $orm->getFactory();
        $ormSchema = $orm->getSchema();

        $outerRelations = $ormSchema->getOuterRelations($role);
        $innerRelations = $ormSchema->getInnerRelations($role);

        // Build relations
        $relations = [];
        foreach ($innerRelations as $relName => $relSchema) {
            $relations[$relName] = $factory->relation($orm, $ormSchema, $role, $relName);
        }
        $result = new self($relations, $outerRelations);

        foreach ($outerRelations as $outerRole => $relations) {
            foreach ($relations as $container => $relationSchema) {
                $result->registerOuterRelation($outerRole, $container, $relationSchema);
            }
        }
        return $result;
    }

    private function registerOuterRelation(string $role, string $container, array $relationSchema): void
    {
        // todo: it better to check instanceOf \Cycle\ORM\Relation\DependencyInterface instead of int
        $relationType = $relationSchema[Relation::TYPE];
        // skip dependencies
        if ($relationType === Relation::BELONGS_TO || $relationType === Relation::REFERS_TO) {
            return;
        }
        if ($relationType === Relation::MANY_TO_MANY) {
            $handshaked = is_string($relationSchema[Relation::SCHEMA][Relation::HANDSHAKE] ?? null);
            // Create ShadowHasMany
            if (!$handshaked) {
                $relation = new ShadowHasMany(
                    $relationSchema[Relation::TARGET],
                    $role . '.' . $container . ':' . $relationSchema[Relation::TARGET],
                    $relationSchema[Relation::SCHEMA][Relation::THROUGH_ENTITY],
                    (array)$relationSchema[Relation::SCHEMA][Relation::OUTER_KEY],
                    (array)$relationSchema[Relation::SCHEMA][Relation::THROUGH_OUTER_KEY]
                );
                $this->slaves[$relation->getName()] = $relation;
            }
            return;
        }
        if ($relationType === Relation::MORPHED_HAS_ONE || $relationType === Relation::MORPHED_HAS_MANY) {
            // todo: find morphed collisions, decide handshake
            $relation = new ShadowBelongsTo('~morphed~' . $container, $role, $relationSchema);
            $this->dependencies[$relation->getName()] ??= $relation;
            return;
        }

        $relation = new ShadowBelongsTo($container, $role, $relationSchema);
        $this->dependencies[$relation->getName()] = $relation;
    }

    public function hasDependencies(): bool
    {
        return count($this->dependencies) > 0;
    }

    public function hasSlaves(): bool
    {
        return count($this->slaves) > 0;
    }

    public function hasEmbedded(): bool
    {
        return count($this->embedded) > 0;
    }

    /**
     * Init relation data in entity data and entity state.
     */
    public function init(Node $node, array $data): array
    {
        foreach ($this->innerRelations as $name => $relation) {
            if (!array_key_exists($name, $data)) {
                if ($node->hasRelation($name)) {
                    continue;
                }

                // $data[$name] = $relation->initDeferred($node);
                $data[$name] = $relation->initReference($node);
                $node->setRelation($name, $data[$name]);
                continue;
            }

            $item = $data[$name];
            if (is_object($item) || $item === null) {
                // cyclic initialization
                $node->setRelation($name, $item);
                continue;
            }

            // init relation for the entity and for state and the same time
            $data[$name] = $relation->init($node, $item);
        }

        return $data;
    }

    /**
     * @return RelationInterface[]
     */
    public function getSlaves(): array
    {
        return $this->slaves;
    }

    /**
     * @return DependencyInterface[]
     */
    public function getMasters(): array
    {
        return $this->dependencies;
    }

    /**
     * @return SameRowRelationInterface[]
     */
    public function getEmbedded(): array
    {
        return $this->embedded;
    }

    /**
     * @return ActiveRelationInterface[]
     */
    public function getRelations(): array
    {
        return $this->innerRelations;
    }
}
