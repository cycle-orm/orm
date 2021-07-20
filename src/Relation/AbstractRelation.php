<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Exception\RelationException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Heap\State;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Select\SourceInterface;
use JetBrains\PhpStorm\ExpectedValues;

abstract class AbstractRelation implements ActiveRelationInterface
{
    use Relation\Traits\NodeTrait;

    /** @internal */
    protected ORMInterface $orm;

    protected string $name;

    protected string $target;

    protected array $schema;

    /** @var string[] */
    protected array $innerKeys;

    /** @var string[] */
    protected array $outerKeys;

    private string $role;

    public function __construct(ORMInterface $orm, string $role, string $name, string $target, array $schema)
    {
        $this->orm = $orm;
        $this->role = $role;
        $this->name = $name;
        $this->target = $target;
        $this->schema = $schema;
        $this->innerKeys = (array)$schema[Relation::INNER_KEY];
        $this->outerKeys = (array)$schema[Relation::OUTER_KEY];
    }

    public function getInnerKeys(): array
    {
        return $this->innerKeys;
    }

    public function __toString(): string
    {
        // this is incorrect class
        return sprintf('`%s` (%s)->%s', $this->name, get_class($this), $this->target);
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
        return $this->schema[Relation::CASCADE] ?? false;
    }

    // todo move to OneToOne trait
    public function init(Node $node, array $data)
    {
        $item = $this->orm->make($this->target, $data, Node::MANAGED);
        $node->setRelation($this->getName(), $item);
        return $item;
    }

    protected function isNullable(): bool
    {
        return !empty($this->schema[Relation::NULLABLE]);
    }

    protected function getTargetRelationName(): string
    {
        return $this->role . '.' . $this->name . ':' . $this->target;
    }

    /**
     * Get the source associated with the role.
     */
    protected function getSource(string $role = null): SourceInterface
    {
        return $this->orm->getSource($role ?? $this->target);
    }

    /**
     * Assert that given entity is allowed for the relation.
     *
     * @throws RelationException
     */
    protected function assertValid(Node $related): void
    {
        if ($related->getRole() != $this->target) {
            throw new RelationException(sprintf('Unable to link %s, given `%s`.', $this, $related->getRole()));
        }
    }

    /**
     * Resolve the reference to the object.
     *
     * @return mixed|null
     */
    public function resolve(ReferenceInterface $reference, bool $load)
    {
        if ($reference->hasValue()) {
            return $reference->getValue();
        }

        $result = $this->orm->get($reference->getRole(), $reference->getScope(), $load);
        if ($load === true || $result !== null) {
            $reference->setValue($result);
        }
        return $result;
    }

    protected function isResolved(ReferenceInterface $reference): bool
    {
        // if ($reference instanceof PromiseInterface) {
        return $reference->hasValue();
        // }

        // return false;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    protected function registerWaitingFields(State $state, bool $required = true): void
    {
        foreach ($this->innerKeys as $key) {
            $state->waitField($key, $required);
        }
    }
}
