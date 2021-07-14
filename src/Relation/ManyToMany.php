<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Collection\Pivoted\PivotedCollectionInterface;
use Cycle\ORM\Collection\Pivoted\PivotedStorage;
use Cycle\ORM\Exception\ORMException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Iterator;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Parser\RootNode;
use Cycle\ORM\Reference\Reference;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Select\Loader\ManyToManyLoader;
use Cycle\ORM\Select\RootLoader;
use Cycle\ORM\Select\SourceProviderInterface;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;
use Doctrine\Common\Collections\Collection;
use SplObjectStorage;
use Traversable;

class ManyToMany extends Relation\AbstractRelation
{
    /** @var string[] */
    protected array $throughInnerKeys;

    /** @var string[] */
    protected array $throughOuterKeys;

    protected string $pivotEntity;

    protected ?string $mirrorRelation;

    public function __construct(ORMInterface $orm, string $role, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $role, $name, $target, $schema);
        $this->pivotEntity = $this->schema[Relation::THROUGH_ENTITY];
        $this->mirrorRelation = $this->schema[Relation::HANDSHAKE] ?? null;

        $this->throughInnerKeys = (array)$this->schema[Relation::THROUGH_INNER_KEY];
        $this->throughOuterKeys = (array)$this->schema[Relation::THROUGH_OUTER_KEY];
    }

    public function prepare(Pool $pool, Tuple $tuple, bool $load = true): void
    {
        $node = $tuple->node;

        /** @var PivotedStorage|ReferenceInterface|null $original */
        $original = $node->getRelation($this->getName());

        /** @var iterable|ReferenceInterface|PivotedCollectionInterface $related */
        $related = $tuple->state->getRelation($this->getName());

        if ($original instanceof ReferenceInterface) {
            if (!$load && $related === $original && !$original->hasValue()) {
                $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
                return;
            }
            $this->resolve($original, true);
            $original = $original->getValue();
            $node->setRelation($this->getName(), $original);
        }
        if (!$original instanceof PivotedStorage) {
            $original = $this->extract($original);
        }

        if ($related instanceof ReferenceInterface && $this->resolve($related, true) !== null) {
            $related = $related->getValue();
            $tuple->state->setRelation($this->getName(), $related);
        }
        $related = $this->extractRelated($related, $original);
        // $tuple->state->setStorage($this->pivotEntity, $related);
        $tuple->state->setRelation($this->getName(), $related);

        // un-link old elements
        foreach ($original as $item) {
            if (!$related->has($item)) {
                $pivot = $original->get($item);
                $this->deleteChild($pool, $pivot, $item);
                $original->getContext()->offsetUnset($item);
            }
        }

        if (count($related) === 0) {
            // $node->setRelation($this->getName(), $related);
            $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
            return;
        }
        $node->setRelationStatus($this->getName(), RelationInterface::STATUS_PROCESS);

        // link/sync new and existed elements
        foreach ($related->getElements() as $item) {
            $this->newLink($pool, $tuple, $related, $item);
        }

    }

    public function queue(Pool $pool, Tuple $tuple): void
    {
        // $related = $tuple->state->getStorage($this->pivotEntity);
        $related = $tuple->state->getRelation($this->getName());
        // $related = $this->extract($relatedSource);

        $node = $tuple->node;
        $node->setRelationStatus($this->getName(), RelationInterface::STATUS_RESOLVED);
        // $original = $node->getRelation($this->getName()) ?? new PivotedStorage();
        // $original ??= new Pivoted\PivotedStorage();

        if ($related instanceof ReferenceInterface && !$related->hasValue()) {
            return;
        }

        $relationName = $this->getTargetRelationName();
        foreach ($related as $item) {
            $pivot = $related->get($item);
            if ($pivot !== null) {
                $pTuple = $pool->offsetGet($pivot);
                $this->applyPivotChanges($tuple, $pTuple);
                $pTuple->node->setRelationStatus($relationName, RelationInterface::STATUS_RESOLVED);
            }
        }
    }

    public function init(Node $node, array $data): iterable
    {
        $elements = [];
        $pivotData = new SplObjectStorage();

        $iterator = new Iterator($this->orm, $this->target, $data, true);
        foreach ($iterator as $pivot => $entity) {
            if (!is_array($pivot)) {
                // skip partially selected entities (DB level filter)
                continue;
            }

            $pivotData[$entity] = $this->orm->make($this->pivotEntity, $pivot, Node::MANAGED);
            $elements[] = $entity;
        }
        $collection = new PivotedStorage($elements, $pivotData);
        $node->setRelation($this->name, $collection);

        return $this->collect($collection);
    }

    public function collect($data): iterable
    {
        return $this->orm->getFactory()->collection(
            $this->orm,
            $this->schema[Relation::COLLECTION_TYPE] ?? null
        )->collect($data);
    }

    public function extract(?iterable $data): PivotedStorage
    {
        if ($data instanceof PivotedStorage) {
            return $data;
        }

        if ($data instanceof PivotedCollectionInterface) {
            return new PivotedStorage($data->toArray(), $data->getPivotContext());
        }

        if ($data instanceof Collection) {
            return new PivotedStorage($data->toArray());
        }

        if ($data === null) {
            return new PivotedStorage();
        }

        return $data instanceof Traversable
            ? new PivotedStorage(iterator_to_array($data))
            : new PivotedStorage((array)$data);
    }

    public function extractRelated(?iterable $data, PivotedStorage $original): PivotedStorage
    {
        $related = $this->extract($data);
        if ($data instanceof PivotedStorage || $data instanceof PivotedCollectionInterface || count($original) === 0) {
            return $related;
        }
        foreach ($related as $item) {
            if ($original->hasContext($item)) {
                $related->set($item, $original->getContext()->offsetGet($item));
            }
        }
        return $related;
    }

    public function initReference(Node $node): ReferenceInterface
    {
        $scope = [];
        $nodeData = $node->getData();
        foreach ($this->innerKeys as $key) {
            if (!isset($nodeData[$key])) {
                $result = new \Cycle\ORM\Reference\DeferredReference($node->getRole(), []);
                $result->setValue(new PivotedStorage());
                return $result;
            }
            $scope[$key] = $nodeData[$key];
        }

        return new Reference($this->target, $scope);
    }

    public function resolve(ReferenceInterface $reference, bool $load)
    {
        if ($reference->hasValue()) {
            return $reference->getValue();
        }
        if ($load === false) {
            return null;
        }
        if (!$this->orm instanceof SourceProviderInterface) {
            throw new ORMException('PivotedPromise require ORM to implement SourceFactoryInterface');
        }
        $scope = $reference->getScope();
        if ($scope === []) {
            $result = new PivotedStorage();
            $reference->setValue($result);
            return $result;
        }

        // getting scoped query
        $query = (new RootLoader($this->orm, $this->target))->buildQuery();

        // responsible for all the scoping
        $loader = new ManyToManyLoader(
            $this->orm,
            $this->orm->getSource($this->target)->getTable(),
            $this->target,
            $this->schema
        );

        /** @var ManyToManyLoader $loader */
        $loader = $loader->withContext($loader, [
            'scope' => $this->orm->getSource($this->target)->getScope(),
            'as'        => $this->target,
            'method'    => JoinableLoader::POSTLOAD
        ]);

        $query = $loader->configureQuery($query, [$scope]);

        // we are going to add pivot node into virtual root node (only ID) to aggregate the results
        $root = new RootNode(
            (array)$this->schema[Relation::INNER_KEY],
            (array)$this->schema[Relation::INNER_KEY]
        );

        $node = $loader->createNode();
        $root->linkNode('output', $node);

        // emulate presence of parent entity
        $root->parseRow(0, $scope);

        $iterator = $query->getIterator();
        foreach ($iterator as $row) {
            $node->parseRow(0, $row);
        }
        $iterator->close();

        // load all eager relations, forbid loader to re-fetch data (make it think it was joined)
        $loader->withContext($loader, ['method' => JoinableLoader::INLOAD])->loadData($node);

        $elements = [];
        $pivotData = new SplObjectStorage();
        foreach (new Iterator($this->orm, $this->target, $root->getResult()[0]['output'], true) as $pivot => $entity) {
            $pivotData[$entity] = $this->orm->make(
                $this->schema[Relation::THROUGH_ENTITY],
                $pivot,
                Node::MANAGED
            );

            $elements[] = $entity;
        }
        $result = new PivotedStorage($elements, $pivotData);
        $reference->setValue($result);
        // $reference->setValue($elements);
        return $result;
    }

    protected function applyPivotChanges(Tuple $parentTuple, Tuple $tuple): void
    {
        foreach ($this->innerKeys as $i => $innerKey) {
            $tuple->node->register($this->throughInnerKeys[$i], $parentTuple->state->getValue($innerKey));
        }
    }

    private function deleteChild(Pool $pool, ?object $pivot, object $child, ?Node $relatedNode = null): void
    {
        // todo: add support for nullable pivot entities?
        if ($pivot !== null) {
            $pool->attachDelete($pivot, $this->isCascade());
        }
        $pool->attachStore($child, false);
    }

    protected function newLink(Pool $pool, Tuple $tuple, PivotedStorage $storage, object $related): void
    {
        $rNode = $this->getNode($related);
        $this->assertValid($rNode);

        $pivot = $storage->get($related);
        if (!is_object($pivot)) {
            // first time initialization
            # todo
            $pivot = $this->initPivot($tuple->entity, $storage, $related, $pivot);
            $storage->set($related, $pivot);
        }

        $pNode = $this->getNode($pivot);
        $pRelationName = $tuple->node->getRole() . ':' . $this->getName();
        // $pNode->setRelation($pRelationName, $tuple->state->getTransactionData());
        $pNode->setRelationStatus($pRelationName, RelationInterface::STATUS_RESOLVED);

        $pState = $pNode->getState();
        $rState = $rNode->getState();
        foreach ($this->throughInnerKeys as $i => $pInnerKey) {
            $pState->register($pInnerKey, $tuple->node->getState()->getTransactionData()[$this->innerKeys[$i]] ?? null, true);

            $rState->forward($this->outerKeys[$i], $pState, $this->throughOuterKeys[$i]);
        }

        $pool->attachStore($related, $this->isCascade(), $rNode, $rState);
        // defer the insert until pivot keys are resolved
        $pool->attachStore($pivot, $this->isCascade(), $pNode, $pState);
    }

    /**
     * Since many to many relation can overlap from two directions we have to properly resolve the pivot entity upon
     * it's generation. This is achieved using temporary mapping associated with each of the entity states.
     */
    protected function initPivot(object $parent, PivotedStorage $storage, object $related, ?array $pivot): ?object
    {
        if ($this->mirrorRelation !== null) {
            $relatedStorage = $this->getNode($related)->getState()->getRelation($this->mirrorRelation);
            if ($relatedStorage instanceof PivotedStorage && $relatedStorage->hasContext($parent)) {
                return $relatedStorage->get($parent);
            }
        }

        $entity = $this->orm->make($this->pivotEntity, $pivot ?? []);
        $storage->set($related, $entity);
        return $entity;
    }

    public function getHandshake(): ?string
    {
        return $this->mirrorRelation;
    }

    public function setHandshake(string $name): void
    {
        $this->mirrorRelation = $name;
    }
}
