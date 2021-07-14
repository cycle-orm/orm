<?php

declare(strict_types=1);

namespace Cycle\ORM\Select\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Select\Traits\WhereTrait;
use Spiral\Database\Query\SelectQuery;

/**
 * Loads given entity table without any specific condition.
 */
class PivotLoader extends JoinableLoader
{
    use WhereTrait;

    /**
     * Default set of relation options. Child implementation might defined their of default options.
     */
    protected array $options = [
        'load'      => false,
        'scope'     => true,
        'method'    => self::JOIN,
        'minify'    => true,
        'as'        => null,
        'using'     => null
    ];

    public function getTable(): string
    {
        return $this->define(Schema::TABLE);
    }

    public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
        // user specified WHERE conditions
        $this->setWhere(
            $query,
            $this->getAlias(),
            $this->isJoined() ? 'onWhere' : 'where',
            $this->options['where'] ?? $this->schema[Relation::THROUGH_WHERE] ?? []
        );

        return parent::configureQuery($query, $outerKeys);
    }

    protected function initNode(): AbstractNode
    {
        $node = new ArrayNode(
            $this->columnNames(),
            (array)$this->define(Schema::PRIMARY_KEY),
            (array)$this->schema[Relation::THROUGH_INNER_KEY],
            (array)$this->schema[Relation::INNER_KEY]
        );

        $typecast = $this->define(Schema::TYPECAST);
        if ($typecast !== null) {
            $node->setTypecast(new Typecast($typecast, $this->getSource()->getDatabase()));
        }

        return $node;
    }
}
