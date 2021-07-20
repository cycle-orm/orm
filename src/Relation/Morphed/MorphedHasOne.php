<?php

declare(strict_types=1);

namespace Cycle\ORM\Relation\Morphed;

use Cycle\ORM\Exception\RelationException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Relation\HasOne;
use Cycle\ORM\Transaction\Pool;
use Cycle\ORM\Transaction\Tuple;

/**
 * Inverted version of belongs to morphed.
 */
class MorphedHasOne extends HasOne
{
    private string $morphKey;

    public function __construct(ORMInterface $orm, string $role, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $role, $name, $target, $schema);
        $this->morphKey = $schema[Relation::MORPH_KEY];
    }

    public function initPromise(Node $node): array
    {
        $innerValues = [];
        $nodeData = $node->getData();
        foreach ($this->innerKeys as $innerKey) {
            if (!isset($nodeData[$innerKey])) {
                return [null, null];
            }
            $innerValues[] = $nodeData[$innerKey];
        }

        $scope = array_combine($this->outerKeys, $innerValues) + [$this->morphKey => $node->getRole()];

        $r = $this->orm->promise($this->target, $scope);
        return [$r, $r];
    }
    protected function getReferenceScope(Node $node): ?array
    {
        return parent::getReferenceScope($node) + [$this->morphKey => $node->getRole()];
    }

    public function queue(Pool $pool, Tuple $tuple): void
    {
        parent::queue($pool, $tuple);
        $related = $tuple->state->getRelation($this->getName());
        $node = $tuple->node;
        if ($related !== null) {
            $rNode = $this->getNode($related);
            $nodeData = $rNode->getData();

            if (($nodeData[$this->morphKey] ?? null) !== $node->getRole()) {
                // $rStore->register($this->morphKey, $node->getRole(), true);
                $rNode->register($this->morphKey, $node->getRole());
            }
        }

    }

    protected function getTargetRelationName(): string
    {
        return '~morphed~:' . $this->name;
    }

    /**
     * Assert that given entity is allowed for the relation.
     *
     * @throws RelationException
     */
    protected function assertValid(Node $related): void
    {
        // no need to validate morphed relation yet
    }
}
