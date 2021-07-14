<?php

declare(strict_types=1);

namespace Cycle\ORM\Select\Loader;

use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\ArrayNode;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Select\Traits\OrderByTrait;
use Cycle\ORM\Select\Traits\WhereTrait;
use Spiral\Database\Injection\Parameter;
use Spiral\Database\Query\SelectQuery;

class HasManyLoader extends JoinableLoader
{
    use OrderByTrait;
    use WhereTrait;

    /**
     * Default set of relation options. Child implementation might defined their of default options.
     */
    protected array $options = [
        'load'      => false,
        'scope'     => true,
        'method'    => self::POSTLOAD,
        'minify'    => true,
        'as'        => null,
        'using'     => null,
        'where'     => null,
        'orderBy'   => null,
    ];

    public function __construct(ORMInterface $orm, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $name, $target, $schema);
        $this->options['where'] = $schema[Relation::WHERE] ?? [];
        $this->options['orderBy'] = $schema[Relation::ORDER_BY] ?? [];
    }

    public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
        if ($this->isLoaded() && $this->isJoined() && (int) $query->getLimit() !== 0) {
            throw new LoaderException('Unable to load data using join with limit on parent query');
        }

        if ($this->options['using'] !== null) {
            // use pre-defined query
            return parent::configureQuery($query, $outerKeys);
        }

        $localPrefix = $this->getAlias() . '.';
        if ($this->isJoined()) {
            $parentKeys = (array)$this->schema[Relation::INNER_KEY];
            $parentPrefix = $this->parent->getAlias() . '.';
            $on = [];
            foreach ((array)$this->schema[Relation::OUTER_KEY] as $i => $key) {
                $field = $localPrefix . $this->fieldAlias($key);
                $on[$field] = $parentPrefix . $this->parent->fieldAlias($parentKeys[$i]);
            }
            $query->join(
                $this->getJoinMethod(),
                $this->getJoinTable()
            )->on($on);
        } else {
            // relation is loaded using external query
            $fields = array_map(
                fn (string $key) => $localPrefix . $this->fieldAlias($key),
                (array)$this->schema[Relation::OUTER_KEY]
            );

            if (count($fields) === 1) {
                $query->andWhere($fields[0], 'IN', new Parameter(array_column($outerKeys, key($outerKeys[0]))));
            } else {
                $query->andWhere(
                    static function (SelectQuery $select) use ($outerKeys, $fields) {
                        foreach ($outerKeys as $set) {
                            $select->orWhere(array_combine($fields, array_values($set)));
                        }
                    }
                );
            }
        }

        // user specified WHERE conditions
        $this->setWhere(
            $query,
            $this->getAlias(),
            $this->isJoined() ? 'onWhere' : 'where',
            $this->options['where'] ?? $this->schema[Relation::WHERE] ?? []
        );

        // user specified ORDER_BY rules
        $this->setOrderBy(
            $query,
            $this->getAlias(),
            $this->options['orderBy'] ?? $this->schema[Relation::ORDER_BY] ?? []
        );

        return parent::configureQuery($query);
    }

    protected function initNode(): AbstractNode
    {
        $node = new ArrayNode(
            $this->columnNames(),
            (array)$this->define(Schema::PRIMARY_KEY),
            (array)$this->schema[Relation::OUTER_KEY],
            (array)$this->schema[Relation::INNER_KEY]
        );

        $typecast = $this->define(Schema::TYPECAST);
        if ($typecast !== null) {
            $node->setTypecast(new Typecast($typecast, $this->getSource()->getDatabase()));
        }

        return $node;
    }
}
