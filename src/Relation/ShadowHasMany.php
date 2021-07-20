<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Heap\State;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;
use JetBrains\PhpStorm\ExpectedValues;

class ShadowHasMany implements ReversedRelationInterface, DependencyInterface
{
    private string $name;
    private string $target;
    private string $targetContainer;

    private array $innerKeys;
    private array $outerKeys;

    public function __construct(string $role, string $container, string $target, array $innerKeys, array $outerKeys)
    {
        // $this->role = $role;
        $this->name = $container;
        $this->target = $target;
        $this->targetContainer = $container . ':' . $target;
        $this->innerKeys = $innerKeys;
        $this->outerKeys = $outerKeys;
    }

    public function getInnerKeys(): array
    {
        return $this->innerKeys;
    }

    public function prepare(Pool $pool, Tuple $tuple, $entityData, bool $load = true): void
    {
        $related = $tuple->state->getRelation($this->getName());
        $tuple->state->setRelation($this->getName(), $related ?? []);
        $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_PROCESS);
    }

    public function queue(Pool $pool, Tuple $tuple): void
    {
        if ($tuple->status <= Tuple::STATUS_WAITED) {
            return;
        }
        $tuple->node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);

        $related = $tuple->state->getRelation($this->getName());

        if (count($related) === 0) {
            return;
        }

        foreach ($related as $item) {
            $rTuple = $pool->offsetGet($item);
            $this->applyChanges($tuple->state, $rTuple->node->getState());
            $rTuple->node->setRelationStatus($this->targetContainer, RelationInterface::STATUS_RESOLVED);
        }
    }

    private function applyChanges(State $from, State $to): void
    {
        foreach ($this->innerKeys as $i => $innerKey) {
            $to->register($this->outerKeys[$i], $from->getValue($innerKey));
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function isCascade(): bool
    {
        return true;
    }
}
