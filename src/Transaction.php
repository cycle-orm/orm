<?php

declare(strict_types=1);

namespace Cycle\ORM;

use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\Database\Insert;
use Cycle\ORM\Command\Database\Update;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Promise\PromiseInterface;
use Cycle\ORM\Promise\ReferenceInterface;
use Cycle\ORM\Relation\RelationInterface;
use Cycle\ORM\Relation\ReversedRelationInterface;
use Cycle\ORM\Relation\ShadowBelongsTo;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Runner;
use Cycle\ORM\Transaction\RunnerInterface;
use Cycle\ORM\Transaction\Tuple;
use SplObjectStorage;

/**
 * Transaction provides ability to define set of entities to be stored or deleted within one transaction. Transaction
 * can operate as UnitOfWork. Multiple transactions can co-exists in one application.
 *
 * Internally, upon "run", transaction will request mappers to generate graph of linked commands to create, update or
 * delete entities.
 */
final class Transaction implements TransactionInterface
{
    public const ACTION_STORE = 0;
    public const ACTION_DELETE = 1;
    private const RELATIONS_NOT_RESOLVED = 0;
    private const RELATIONS_RESOLVED = 1;
    private const RELATIONS_DEFERRED = 2;

    private ORMInterface $orm;

    private SplObjectStorage $known;

    private array $persist = [];

    private array $delete = [];

    private Pool $pool;

    private RunnerInterface $runner;

    private array $indexes = [];

    public function __construct(ORMInterface $orm, RunnerInterface $runner = null)
    {
        $this->orm = $orm;
        $this->known = new SplObjectStorage();
        $this->runner = $runner ?? new Runner();
        $this->pool = new Pool();
    }

    public function persist(object $entity, int $mode = self::MODE_CASCADE): self
    {
        if ($this->known->offsetExists($entity)) {
            return $this;
        }

        $this->pool->attachStore($entity, $mode === self::MODE_CASCADE);
        $this->known->offsetSet($entity, true);
        $this->persist[] = [$entity, $mode];

        return $this;
    }

    public function delete($entity, int $mode = self::MODE_CASCADE): self
    {
        if ($this->known->offsetExists($entity)) {
            return $this;
        }

        $this->pool->attach($entity, Tuple::TASK_FORCE_DELETE, $mode === self::MODE_CASCADE);
        $this->known->offsetSet($entity, true);
        $this->delete[] = [$entity, $mode];

        return $this;
    }

    public function run(): void
    {
        try {
            $this->walkPool();
        } catch (\Throwable $e) {
            $this->runner->rollback();

            // no calculations must be kept in node states, resetting
            // this will keep entity data as it was before transaction run
            $this->resetHeap();

            throw $e;
        } finally {
            if (!isset($e)) {
                // we are ready to commit all changes to our representation layer
                $this->syncHeap();
            }

            // resetting the scope
            $this->persist = $this->delete = [];
            $this->known = new SplObjectStorage();
        }

        $this->runner->complete();
    }

    /**
     * Sync all entity states with generated changes.
     */
    protected function syncHeap(): void
    {
        $heap = $this->orm->getHeap();
        foreach ($heap as $e) {
            // optimize to only scan over affected entities
            $node = $heap->get($e);

            if (!$node->hasState()) {
                continue;
            }

            // marked as being deleted and has no external claims (GC like approach)
            if (in_array($node->getStatus(), [Node::DELETED, Node::SCHEDULED_DELETE], true) && !$node->getState()->hasClaims()) {
                $heap->detach($e);
                continue;
            }

            // reindex the entity while it has old data
            $heap->attach($e, $node, $this->getIndexes($node->getRole()));

            // sync the current entity data with newly generated data
            $syncData = $node->syncState();
            $this->orm->getMapper($node->getRole())->hydrate(
                $e,
                $node->getRelations()
                + $syncData);
        }
    }

    /**
     * Reset heap to it's initial state and remove all the changes.
     */
    protected function resetHeap(): void
    {
        $heap = $this->orm->getHeap();
        foreach ($heap as $e) {
            $heap->get($e)->resetState();
        }
    }

    /**
     * Return flattened list of commands required to store and delete associated entities.
     */
    protected function walkPool(): void
    {
        $heap = $this->orm->getHeap();
        $pool = $this->pool;
        /**
         * @var object $entity
         * @var Tuple $tuple
         */
        ob_implicit_flush(true);
        foreach ($pool as $entity => $tuple) {
            flush();
            ob_flush();
            if ($entity instanceof PromiseInterface && $entity->__loaded()) {
                $entity = $entity->__resolve();
                if ($entity === null) {
                    echo "pool: skip unresolved promise\n";
                    continue;
                }
                $tuple->entity = $entity;
            }

            $node = $tuple->node = $tuple->node ?? $heap->get($entity);
            // if ($node !== null && $node->getReadyState() === Node::RESOLVED) {
            //     continue;
            // }
            // we do not expect to store promises
            if ($entity instanceof ReferenceInterface
                || ($tuple->task === Tuple::TASK_FORCE_DELETE && $node === null)) {
                $tuple->status = Tuple::STATUS_PROCESSED;
                // $pool->detach($entity);
                continue;
            }
            echo sprintf(
                "\npool: status: %s, \033[90m%s\033[0m, task: %s data: %s\n",
                $tuple->status,
                $node === null ? get_class($entity) : $node->getRole(),
                $tuple->task,
                $node === null || !$node->hasState() ? '(has no State)' : implode('|', $node->getState()->getData())
            );

            $tuple->mapper = $tuple->mapper ?? $this->orm->getMapper($entity);
            if ($tuple->task === Tuple::TASK_FORCE_DELETE && !$tuple->cascade) {
                // currently we rely on db to delete all nested records (or soft deletes)
                // todo delete cascaded
                $this->generateDeleteCommand($tuple);
                continue;
            }

            // Create new Node
            if ($node === null) {
                // automatic entity registration
                $node = $tuple->node = new Node(Node::NEW, [], $tuple->mapper->getRole());
                $heap->attach($entity, $node);
            }
            if (!$node->hasState()) {
                $tuple->state = $node->getState();
                $tuple->state->setData($tuple->mapper->fetchFields($entity));
            }

            if (!$tuple->cascade) {
                $this->runner->run($this->generateStoreCommand($tuple));
                $tuple->status = $tuple->status === Tuple::STATUS_DEFERRED ? Tuple::STATUS_DEFERRED : Tuple::STATUS_PROCESSED;
                continue;
            }

            // Walk relations
            $this->resolveRelations($tuple);
            if ($tuple->task === Tuple::TASK_STORE && in_array($tuple->status, [Tuple::STATUS_PREPROCESSED, Tuple::STATUS_DEFERRED], true)) {
                // if ($tuple->node->hasChanges()) {
                //     $this->generateStoreCommand($tuple);
                // } else {
                //     echo "No changes \n";
                // }
                continue;
            }
            if (in_array($tuple->task, [Tuple::TASK_DELETE, Tuple::TASK_FORCE_DELETE], true) && $tuple->status === Tuple::STATUS_PREPROCESSED) {
                // $this->generateDeleteCommand($tuple);
                continue;
            }

            // if ($node->getReadyState() === Node::READY) {
            //     yield $this->generateStoreCommand($tuple);
            // } elseif ($node->getReadyState() === Node::WAITING_DEFERRED && $node->hasChanges()) {
            //     yield $this->generateStoreCommand($tuple);
            // }
            // $pool->attachTuple($tuple);
        }
    }

    private function resolveMasterRelations(Tuple $tuple, RelationMap $map): int
    {
        if (!$map->hasDependencies()) {
            return self::RELATIONS_RESOLVED;
        }

        $entityData = $tuple->mapper->extract($tuple->entity);
        $deferred = false;
        $resolved = true;
        foreach ($map->getMasters() as $name => $relation) {
            echo "Master relation: {$name} " . get_class($relation);
            $relationStatus = $tuple->node->getRelationStatus($relation->getName());
            if (/*!$relation->isCascade() || */$relationStatus === RelationInterface::STATUS_RESOLVED) {
                echo " [skip] \n";
                continue;
            }
            echo " [process] \n";

            if ($relation instanceof ShadowBelongsTo) {
                # Check relation is connected
                # Connected -> $parentNode->getRelationStatus()
                # Disconnected -> WAIT if Tuple::STATUS_PREPARING
                $relation->newQueue($this->pool, $tuple, null);
                $relationStatus = $tuple->node->getRelationStatus($relation->getName());

                if ($tuple->status < Tuple::STATUS_PROPOSED) {
                    $resolved = $resolved && ($relationStatus !== RelationInterface::STATUS_PROCESSING || !$relation->isCascade());
                    $deferred = $deferred || $relationStatus === RelationInterface::STATUS_DEFERRED;
                }
            } else {
                $master = $relation->extract($entityData[$name] ?? null);
                $relation->newQueue($this->pool, $tuple, $master);
                $relationStatus = $tuple->node->getRelationStatus($relation->getName());
                $resolved = $resolved && $relationStatus !== RelationInterface::STATUS_PROCESSING;
                $deferred = $deferred || $relationStatus === RelationInterface::STATUS_DEFERRED;

                if ($resolved) {
                    $tuple->node->setRelation($name, $entityData[$name] ?? null);
                    if ($tuple->node->hasState()) {
                        $tuple->node->getState()->setRelation($name, $master);
                    }
                }
            }
        }

        return ($deferred ? self::RELATIONS_DEFERRED : 0) | ($resolved ? self::RELATIONS_RESOLVED : 0);
    }
    private function resolveSlaveRelations(Tuple $tuple, RelationMap $map): int
    {
        if (!$map->hasSlaves()) {
            return self::RELATIONS_RESOLVED;
        }

        // Attach children to pool
        $entityData = $tuple->mapper->extract($tuple->entity);
        $deferred = false;
        $resolved = true;
        foreach ($map->getSlaves() as $name => $relation) {
            echo "Slave relation: {$name}";
            if (!$relation->isCascade() || $tuple->node->getRelationStatus($relation->getName()) === RelationInterface::STATUS_RESOLVED) {
                // todo check changes for not cascaded relations?
                echo " [skip] \n";
                continue;
            }
            echo " [process] \n";
            $tuple->state === null or $tuple->state->markVisited($name); // ?

            if ($relation instanceof ReversedRelationInterface) {
                // $tuple->node->setReadyState(Node::WAITING_DEFERRED);
                $deferred = true;
                // $tuple->status = Tuple::STATUS_DEFERRED;
            } else {
                $child = $relation->extract($entityData[$name] ?? null);
                $relation->newQueue($this->pool, $tuple, $child);
                $relationStatus = $tuple->node->getRelationStatus($relation->getName());
                $resolved = $resolved && $relationStatus === RelationInterface::STATUS_RESOLVED;
                $deferred = $deferred || $relationStatus === RelationInterface::STATUS_DEFERRED;
                if ($resolved) {
                    $tuple->node->setRelation($name, $entityData[$name] ?? null);
                    if ($tuple->node->hasState()) {
                        $tuple->node->getState()->setRelation($name, $child);
                    }
                }
            }
        }

        return ($deferred ? self::RELATIONS_DEFERRED : 0) | ($resolved ? self::RELATIONS_RESOLVED : 0);
    }

    private function resolveSelfWithEmbedded(Tuple $tuple, RelationMap $map): void
    {
        if (!$map->hasEmbedded() && !$tuple->node->hasChanges()) {
            echo "No changes \n";
            $tuple->status = $tuple->status === Tuple::STATUS_PREPROCESSED ? Tuple::STATUS_PROCESSED : $tuple->status;
            return;
        }
        $command = $this->generateStoreCommand($tuple);

        if (!$map->hasEmbedded()) {
            $this->runner->run($command);
            $tuple->status = $tuple->status === Tuple::STATUS_DEFERRED ? Tuple::STATUS_DEFERRED : Tuple::STATUS_PROCESSED;
            return;
        }

        $entityData = $tuple->mapper->extract($tuple->entity);
        foreach ($map->getEmbedded() as $name => $relation) {
            $relationStatus = $tuple->node->getRelationStatus($relation->getName());
            if ($relationStatus === RelationInterface::STATUS_RESOLVED) {
                continue;
            }
            $embedded = $relation->extract($entityData[$name] ?? null);
            $relation->newQueue($this->pool, $tuple, $embedded, $command);
            $relationStatus = $tuple->node->getRelationStatus($relation->getName());

            if ($relationStatus !== RelationInterface::STATUS_PROCESSING) {
                $tuple->node->setRelation($name, $entityData[$name] ?? null);
                if ($tuple->node->hasState()) {
                    $tuple->node->getState()->setRelation($name, $embedded);
                }
            }
        }
        if ($command->hasData()) {
            $this->runner->run($command);
        }
        $tuple->status = $tuple->status === Tuple::STATUS_DEFERRED ? Tuple::STATUS_DEFERRED : Tuple::STATUS_PROCESSED;
    }
    private function resolveRelations(Tuple $tuple): void
    {
        $map = $this->orm->getRelationMap(isset($tuple->node) ? $tuple->node->getRole() : get_class($tuple->entity));
        // Init relations status
        if ($tuple->status === Tuple::STATUS_PREPARING) {
            $map->setRelationsStatus($tuple->node, RelationInterface::STATUS_PROCESSING);
        }

        // Dependency relations
        $result = $tuple->task === Tuple::TASK_STORE
            ? $this->resolveMasterRelations($tuple, $map)
            : $this->resolveSlaveRelations($tuple, $map);
        $isDependenciesResolved = (bool)($result & self::RELATIONS_RESOLVED);
        $deferred = (bool)($result & self::RELATIONS_DEFERRED);

        // Self
        if ($deferred && $tuple->status !== Tuple::STATUS_PROPOSED) {
            $tuple->status = Tuple::STATUS_DEFERRED;
            // $this->pool->attachTuple($tuple);
        }
        if ($isDependenciesResolved) {
            if ($tuple->task === Tuple::TASK_STORE) {
                $tuple->status === Tuple::STATUS_DEFERRED or $tuple->status = Tuple::STATUS_PREPROCESSED;
                $this->resolveSelfWithEmbedded($tuple, $map);
            } elseif ($tuple->status === Tuple::STATUS_PREPARING) {
                $tuple->status = Tuple::STATUS_WAITING;
            } else {
                $tuple->status = Tuple::STATUS_PREPROCESSED;
                $this->generateDeleteCommand($tuple);
            }
        }

        // Slave relations relations
        $tuple->task === Tuple::TASK_STORE
            ? $this->resolveSlaveRelations($tuple, $map)
            : $this->resolveMasterRelations($tuple, $map);

        if (!$isDependenciesResolved) {
            ++$tuple->status;
        }
    }

    public function generateStoreCommand(Tuple $tuple): ?CommandInterface
    {
        $tuple->state = $tuple->state ?? $tuple->node->getState();

        echo sprintf("Store with status %s\n", $tuple->status);
        if ($tuple->node->getStatus() === Node::NEW) {
            $tuple->state->setStatus(Node::SCHEDULED_INSERT);
            echo "Its a CREATE command;\n";
            /** @var Insert $command */
            $command = $tuple->mapper->queueCreate($tuple->entity, $tuple->node, $tuple->state);
            return $command;

            // $this->runner->run($command);
            //
            // $tuple->status = $tuple->status === Tuple::STATUS_DEFERRED ? Tuple::STATUS_DEFERRED : Tuple::STATUS_PROCESSED;
            // return;
        }
        $tuple->state->setStatus(Node::SCHEDULED_UPDATE);

        echo "Its a UPDATE command;\n";

        /** @var Update $command */
        $command = $tuple->mapper->queueUpdate($tuple->entity, $tuple->node, $tuple->state);
        return $command;

        // $this->runner->run($command);
        // $tuple->status = $tuple->status === Tuple::STATUS_DEFERRED ? Tuple::STATUS_DEFERRED : Tuple::STATUS_PROCESSED;
    }
    public function generateDeleteCommand(Tuple $tuple): void
    {
        // currently we rely on db to delete all nested records (or soft deletes)
        $command = $tuple->mapper->queueDelete($tuple->entity, $tuple->node, $tuple->node->getState());

        $tuple->status = Tuple::STATUS_PROCESSED;
        $this->runner->run($command);
        $tuple->node->setStatus(Node::DELETED);
    }

    /**
     * Fetch commands which are ready for the execution. Provide ready commands
     * as generated value and delayed commands as the key.
     */
    protected function sort(iterable $commands): \Generator
    {
        /** @var CommandInterface $command */
        foreach ($commands as $command) {
            if (!$command->isReady()) {
                // command or command branch is not ready
                yield $command => null;
                continue;
            }

            if ($command instanceof \Traversable) {
                // deepening (cut-off on first not-ready level)
                yield from $this->sort($command);
                continue;
            }

            yield null => $command;
        }
    }

    private function listCommands(array $commands): string
    {
        $errors = [];
        foreach ($commands as $command) {
            // i miss you Go
            if (method_exists($command, '__toError')) {
                $errors[] = $command->__toError();
            } else {
                $errors[] = get_class($command);
            }
        }

        return implode(', ', $errors);
    }

    /**
     * Indexable node fields.
     */
    private function getIndexes(string $role): array
    {
        if (isset($this->indexes[$role])) {
            return $this->indexes[$role];
        }

        $pk = $this->orm->getSchema()->define($role, Schema::PRIMARY_KEY);
        $keys = $this->orm->getSchema()->define($role, Schema::FIND_BY_KEYS) ?? [];

        return $this->indexes[$role] = array_merge([$pk], $keys);
    }
}
