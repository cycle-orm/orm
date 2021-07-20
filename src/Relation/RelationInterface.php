<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;
use JetBrains\PhpStorm\ExpectedValues;

/**
 * Manages single branch type between parent entity and other objects.
 */
interface RelationInterface
{
    public const STATUS_PREPARE = 0;
    public const STATUS_PROCESS = 1;
    public const STATUS_DEFERRED = 2;
    public const STATUS_RESOLVED = 3;

    public function getInnerKeys(): array;

    /**
     * Relation name.
     */
    public function getName(): string;

    /**
     * Target entity role.
     */
    public function getTarget(): string;

    /**
     * Must return true to trigger queue.
     */
    public function isCascade(): bool;

    /**
     * @param mixed $entityData
     */
    public function prepare(Pool $pool, Tuple $tuple, $entityData, bool $load = true): void;

    public function queue(Pool $pool, Tuple $tuple): void;
}
