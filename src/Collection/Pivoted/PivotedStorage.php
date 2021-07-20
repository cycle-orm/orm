<?php

declare(strict_types=1);

namespace Cycle\ORM\Collection\Pivoted;

use IteratorAggregate;
use SplObjectStorage;

/**
 * Carry information about ordered list of entities and associated pivot context.
 */
class PivotedStorage implements IteratorAggregate, \Countable
{
    private array $elements;

    private SplObjectStorage $context;

    public function __construct(array $elements = [], SplObjectStorage $context = null)
    {
        $this->elements = $elements;
        $this->context = $context ?? new SplObjectStorage();
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    public function getIterator()
    {
        yield from $this->getElements();
    }

    public function getContext(): SplObjectStorage
    {
        return $this->context;
    }

    /**
     * Check if entity belongs to the storage.
     */
    public function has(object $entity): bool
    {
        return in_array($entity, $this->elements, true);
    }

    public function hasContext(object $entity): bool
    {
        return $this->context->offsetExists($entity);
    }

    /**
     * Get entity context.
     *
     * @return object|array
     */
    public function get(object $entity)
    {
        try {
            return $this->context->offsetGet($entity);
        } catch (\UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * Get entity context.
     *
     * @param  object|array $pivot
     */
    public function set(object $entity, object $pivot): void
    {
        $this->context->offsetSet($entity, $pivot);
    }

    public function count(): int
    {
        return count($this->elements);
    }
}
