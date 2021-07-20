<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Exception\Relation\NullException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Reference\Reference;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation\Traits\PromiseOneTrait;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;

/**
 * Provides ability to link to the parent object.
 * Will claim branch up to the parent object and it's relations. To disable
 * branch walk-through use RefersTo relation.
 */
class BelongsTo extends AbstractRelation implements DependencyInterface
{
    use PromiseOneTrait;

    private function checkNullValuePossibility(Tuple $tuple): bool
    {
        if ($tuple->status < Tuple::STATUS_WAITED) {
            return true;
        }

        if ($tuple->status < Tuple::STATUS_PREPROCESSED && array_intersect($this->innerKeys, $tuple->state->getWaitingFields(false)) !== []) {
            return true;
        }
        // Check
        $values = [];
        // $data = $tuple->node->getChanges();
        $data = $tuple->node->getData();
        foreach ($this->innerKeys as $innerKey) {
            if (!isset($data[$innerKey])) {
                return false;
            }
            $values[$innerKey] = $data[$innerKey];
        }

        $tuple->state->setRelation($this->getName(), new Reference($this->target, $values));
        $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
        return true;
    }

    /**
     * todo: deduplicate with {@see \Cycle\ORM\Relation\RefersTo::prepare()}
     */
    public function prepare(Pool $pool, Tuple $tuple, $entityData, bool $load = true): void
    {
        $node = $tuple->node;
        $original = $node->getRelation($this->getName());
        $related = $tuple->state->getRelation($this->getName());
        $related = $entityData;
        $tuple->state->setRelation($this->getName(), $related);

        if ($related === null) {
            if (!$this->isNullable()) {
                // set null unchanged fields
                $changes = $tuple->state->getChanges();
                foreach ($this->innerKeys as $innerKey) {
                    if (!isset($changes[$innerKey])) {
                        $tuple->state->register($innerKey, null);
                    }
                }
                $this->registerWaitingFields($tuple->state);

                // if ($this->checkNullValuePossibility($tuple)) {
                    return;
                // }
                // throw new NullException("Relation {$this} can not be null.");
            }

            if ($original !== null) {
                // reset keys
                $state = $node->getState();
                foreach ($this->innerKeys as $innerKey) {
                    $state->register($innerKey, null);
                }
            }
            $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
            return;
        }
        $this->registerWaitingFields($tuple->state);
        if ($related instanceof ReferenceInterface && $this->resolve($related, false) !== null) {
            $related = $related->getValue();
            $tuple->state->setRelation($this->getName(), $related);
        }
        $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_PROCESS);
        if ($related instanceof ReferenceInterface) {
            return;
        }
        $rTuple = $pool->offsetGet($related);
        if ($rTuple === null && $this->isCascade()) {
            $pool->attachStore($related, false, null, null, false);
        }
    }

    public function queue(Pool $pool, Tuple $tuple): void
    {
        if ($tuple->task !== $tuple::TASK_STORE) {
            return;
        }
        $node = $tuple->node;
        $related = $tuple->state->getRelation($this->getName());

        if ($related === null && !$this->isNullable()) {
            if ($this->checkNullValuePossibility($tuple)) {
                return;
            }
            throw new NullException(sprintf('Relation `%s`.%s can not be null.', $node->getRole(), (string)$this));
        }
        if ($related instanceof ReferenceInterface && $related->hasValue()) {
            $related = $related->getValue();
            $tuple->state->setRelation($this->getName(), $related);
        }
        if ($related instanceof ReferenceInterface) {
            $scope = $related->getScope();
            if (array_intersect($this->outerKeys, array_keys($scope))) {
                foreach ($this->outerKeys as $i => $outerKey) {
                    $node->register($this->innerKeys[$i], $scope[$outerKey]);
                }
                $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
                return;
            }
        }
        $rTuple = $pool->offsetGet($related) ?? $pool->attachStore($related, true, null, null, true);

        if ($this->shouldPull($tuple, $rTuple)) {
            $this->pullValues($node, $rTuple->node);
            $node->getState()->setRelation($this->getName(), $related);
            $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
        }
    }

    private function shouldPull(Tuple $tuple, Tuple $rTuple): bool
    {
        if ($rTuple->status <= Tuple::STATUS_PROPOSED || count(array_intersect($this->outerKeys, $rTuple->state->getWaitingFields())) > 0) {
            return false;
        }
        // Check bidirected relation: when related entity has been removed from HasSome relation
        $oldData = $tuple->node->getInitialData();
        $newData = $rTuple->state->getTransactionData();
        $current = $tuple->state->getData();
        $noChanges = true;
        foreach ($this->outerKeys as $i => $outerKey) {
            $innerKey = $this->innerKeys[$i];
            if (!array_key_exists($innerKey, $oldData) || $oldData[$innerKey] !== $newData[$outerKey]) {
                return true;
            }
            $noChanges = $noChanges && $current[$innerKey] === $oldData[$innerKey];
        }
        // If no changes
        if ($noChanges) {
            $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
            return false;
        }
        // Nullable relation and null values
        if ($this->isNullable()) {
            foreach ($this->innerKeys as $innerKey) {
                if (!array_key_exists($innerKey, $current) || $current[$innerKey] !== null) {
                    return false;
                }
            }
            $tuple->node->getState()->setRelation($this->getName(), null);
            $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
        }
        return false;
    }

    private function pullValues(Node $node, Node $related): void
    {
        $changes = $related->getState()->getTransactionData();
        foreach ($this->outerKeys as $i => $outerKey) {
            if (isset($changes[$outerKey])) {
                $node->register($this->innerKeys[$i], $changes[$outerKey]);
            }
        }
    }
}
