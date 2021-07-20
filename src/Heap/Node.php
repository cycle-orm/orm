<?php

declare(strict_types=1);

namespace Cycle\ORM\Heap;

use Cycle\ORM\Context\ConsumerInterface;
use Cycle\ORM\Heap\Traits\RelationTrait;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\RelationMap;
use JetBrains\PhpStorm\ExpectedValues;

/**
 * Node (metadata) carries meta information about entity state, changes forwards data to other points through
 * inner states.
 */
final class Node implements ConsumerInterface
{
    use RelationTrait;

    // Different entity states in a pool
    public const PROMISED         = 0;
    public const NEW              = 1;
    public const MANAGED          = 2;
    public const SCHEDULED_INSERT = 3;
    public const SCHEDULED_UPDATE = 4;
    public const SCHEDULED_DELETE = 5;
    public const DELETED          = 6;

    private string $role;

    private int $status;

    private array $data;
    private ?State $state = null;

    public function __construct(
        #[ExpectedValues(valuesFromClass: self::class)]
        int $status,
        array $data,
        string $role
    ) {
        $this->status = $status;
        $this->data = $data;
        $this->role = $role;
    }

    /**
     * Reset state.
     */
    public function __destruct()
    {
        $this->data = [];
        $this->state = null;
        $this->relations = [];
    }

    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Current point state (set of changes).
     */
    public function getState(): State
    {
        if ($this->state === null) {
            $this->state = new State($this->status, $this->data);
        }

        return $this->state;
    }

    public function hasState(): bool
    {
        return $this->state !== null;
    }

    /**
     * Set new state value.
     */
    public function setStatus(int $state): void
    {
        $this->getState()->setStatus($state);
    }

    /**
     * Get current state.
     */
    public function getStatus(): int
    {
        if ($this->state !== null) {
            return $this->state->getStatus();
        }

        return $this->status;
    }

    /**
     * Set new state data (will trigger state handlers).
     */
    public function setData(array $data): void
    {
        $this->getState()->setData($data);
    }

    /**
     * Get current state data. Mutalbe inside the transaction.
     */
    public function getData(): array
    {
        if ($this->state !== null) {
            return $this->state->getData();
        }

        return $this->data;
    }

    /**
     * The intial (post-load) node date. Does not change during the transaction.
     */
    public function getInitialData(): array
    {
        return $this->data;
    }

    public function register(string $key, mixed $value, int $stream = self::DATA): void
    {
        $this->getState()->register($key, $value, $stream);
    }

    /**
     * Sync the point state and return data diff.
     */
    public function syncState(RelationMap $relMap): array
    {
        if ($this->state === null) {
            return [];
        }

        $changes = array_udiff_assoc($this->state->getTransactionData(), $this->data, [self::class, 'compare']);

        $relations = $relMap->getRelations();
        foreach ($this->state->getRelations() as $name => $relation) {
            if (isset($relations[$name]) && (isset($this->relations[$name]) XOR $this->state->getRelation($name) !== null)) {
                $changes[$name] = $relation instanceof ReferenceInterface
                    ? $relation
                    : $relations[$name]->collect($relation);
            }
            $this->setRelation($name, $relation);
        }

        // DELETE handled separately
        $this->status = self::MANAGED;
        $this->data = $this->state->getTransactionData();
        $this->state->__destruct();
        $this->state = null;
        $this->relationStatus = [];

        return $changes;
    }

    public function hasChanges(): bool
    {
        return ($this->state !== null && $this->state->getStatus() === self::NEW)
            // || array_udiff_assoc($this->state->getData(), $this->state->getTransactionData(), [self::class, 'compare']) !== [];
            || $this->state->getChanges() !== [];
    }

    public function getChanges(): array
    {
        if ($this->state === null) {
            return $this->status === self::NEW ? ($this->data ?? []) : [];
        }
        // return array_udiff_assoc($this->state->getData(), $this->state->getTransactionData(), [self::class, 'compare']);
        return $this->state->getChanges();
    }

    /**
     * Reset point state and flush all the changes.
     */
    public function resetState(): void
    {
        if (isset($this->state)) {
            $this->state->__destruct();
        }
        $this->state = null;
        $this->relationStatus = [];
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    public static function compare($a, $b): int
    {
        // return $a <=> $b;
        // todo refactor and test this
        if ($a == $b) {
            if (($a === null) !== ($b === null)) {
                return 1;
            }

            return 0;
        }

        return ($a > $b) ? 1 : -1;
    }
}
