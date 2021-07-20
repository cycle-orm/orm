<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Heap\Node;
use Cycle\ORM\Reference\DeferredReference;
use Cycle\ORM\Reference\Reference;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;
use Doctrine\Common\Collections\Collection;

/**
 * Provides the ability to own the collection of entities.
 */
class HasMany extends AbstractRelation
{
    public function prepare(Pool $pool, Tuple $tuple, $entityData, bool $load = true): void
    {
        $node = $tuple->node;
        $original = $node->getRelation($this->getName());
        // $related = $tuple->state->getRelation($this->getName());
        $related = $entityData;
        $tuple->state->setRelation($this->getName(), $related);

        if ($original instanceof ReferenceInterface) {
            if (!$load && $related === $original && !$original->hasValue()) {
                $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
                return;
            }
            $original = $this->resolve($original, true);
            $node->setRelation($this->getName(), $original);
        }

        if ($related instanceof ReferenceInterface) {
            $related = $this->resolve($related, true);
            $tuple->state->setRelation($this->getName(), $related);
        }
        foreach ($this->calcDeleted($related, $original ?? []) as $item) {
            $this->deleteChild($pool, $item);
        }

        if (count($related) === 0) {
            $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
            return;
        }
        $node->setRelationStatus($this->getName(), RelationInterface::STATUS_PROCESS);

        // $relationName = $this->getTargetRelationName()
        // Store new and existing items
        foreach ($related as $item) {
            $rNode = $this->getNode($item, +1);
            $this->assertValid($rNode);
            $pool->attachStore($item, true, $rNode);
            if ($this->isNullable()) {
                // todo?
                // $rNode->setRelationStatus($relationName, RelationInterface::STATUS_DEFERRED);
            }
        }

    }

    public function queue(Pool $pool, Tuple $tuple): void
    {
        if ($tuple->task === Tuple::TASK_STORE) {
            $this->queueStoreAll($pool, $tuple);
        } else {
            // todo
            // $this->queueDelete($pool, $tuple, $related);
        }
    }

    private function queueStoreAll(Pool $pool, Tuple $tuple): void
    {
        $node = $tuple->node;
        $related = $tuple->state->getRelation($this->getName());
        $related = $this->extract($related);

        $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);

        if ($related instanceof ReferenceInterface && !$related->hasValue()) {
            return;
        }

        $relationName = $this->getTargetRelationName();
        foreach ($related as $item) {
            $rTuple = $pool->offsetGet($item);
            $this->applyChanges($tuple, $rTuple);
            $rTuple->node->setRelationStatus($relationName, RelationInterface::STATUS_RESOLVED);
        }
    }

    protected function applyChanges(Tuple $parentTuple, Tuple $tuple): void
    {
        foreach ($this->innerKeys as $i => $innerKey) {
            $tuple->node->register($this->outerKeys[$i], $parentTuple->state->getValue($innerKey));
        }
    }

    /**
     * Delete original related entity of no other objects reference to it.
     */
    private function deleteChild(Pool $pool, object $child, ?Node $relatedNode = null): ?Tuple
    {
        $relatedNode = $relatedNode ?? $this->getNode($child);
        if ($relatedNode->getStatus() !== Node::MANAGED) {
            return null;
        }

        if ($this->isNullable()) {
            foreach ($this->outerKeys as $outerKey) {
                $relatedNode->getState()->register($outerKey, null);
            }
            // todo relation status
            return $pool->attachStore($child, false, $relatedNode, $relatedNode->getState());
        }

        return $pool->attachDelete($child, $this->isCascade(), $relatedNode);
    }

    /**
     * Init relation state and entity collection.
     */
    public function init(Node $node, array $data): iterable
    {
        $elements = [];
        foreach ($data as $item) {
            $elements[] = $this->orm->make($this->target, $item, Node::MANAGED);
        }

        $node->setRelation($this->getName(), $elements);
        return $this->collect($elements);
        // return [
        //     // new \Cycle\ORM\Reference\DeferredStatic($elements, [$this, 'collect']),
        //     $this->collect($elements),
        //     $elements
        // ];
    }

    public function initReference(Node $node): ReferenceInterface
    {
        $scope = $this->getReferenceScope($node);
        return $scope === null
            ? new DeferredReference($node->getRole(), [])
            : new Reference($this->target, $scope);
    }

    protected function getReferenceScope(Node $node): ?array
    {
        $scope = [];
        $nodeData = $node->getData();
        foreach ($this->innerKeys as $i => $key) {
            if (!isset($nodeData[$key])) {
                return null;
            }
            $scope[$this->outerKeys[$i]] = $nodeData[$key];
        }
        return $scope;
    }

    public function resolve(ReferenceInterface $reference, bool $load): ?iterable
    {
        if ($reference->hasValue()) {
            return $reference->getValue();
        }
        if ($reference->getScope() === []) {
            // nothing to proxy to
            $reference->setValue([]);
            return [];
        }
        if ($load === false) {
            return null;
        }

        $result = [];
        $query = array_merge($reference->getScope(), $this->schema[Relation::WHERE] ?? []);
        foreach ($this->orm->getRepository($this->target)->findAll($query) as $item) {
            $result[] = $item;
        }
        $reference->setValue($result);

        return $result;
    }

    public function collect($data): iterable
    {
        if (!is_iterable($data)) {
            throw new \InvalidArgumentException('Collected data in the HasMany relation should be iterable.');
        }
        return $this->orm->getFactory()->collection(
            $this->orm,
            $this->schema[Relation::COLLECTION_TYPE] ?? null
        )->collect($data);
    }

    /**
     * Convert entity data into array.
     *
     * @param mixed $data
     */
    public function extract($data): array
    {
        if ($data instanceof Collection) {
            return $data->toArray();
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Return objects which are subject of removal.
     */
    protected function calcDeleted(iterable $related, iterable $original): array
    {
        $related = $this->extract($related);
        $original = $this->extract($original);
        return array_udiff(
            $original ?? [],
            $related,
            // static fn(object $a, object $b): int => strcmp(spl_object_hash($a), spl_object_hash($b))
            static fn(object $a, object $b): int => (int)($a === $b) - 1
        );
    }
}
