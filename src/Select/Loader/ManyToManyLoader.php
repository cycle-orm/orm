<?php

declare(strict_types=1);

namespace Cycle\ORM\Select\Loader;

use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Select\LoaderInterface;
use Cycle\ORM\Select\Traits\OrderByTrait;
use Cycle\ORM\Select\Traits\WhereTrait;
use Spiral\Database\Injection\Parameter;
use Spiral\Database\Query\SelectQuery;
use Spiral\Database\StatementInterface;

class ManyToManyLoader extends JoinableLoader
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
        'pivot'     => null,
    ];

    protected PivotLoader $pivot;

    public function __construct(ORMInterface $orm, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $name, $target, $schema);
        $this->pivot = new PivotLoader($orm, 'pivot', $schema[Relation::THROUGH_ENTITY], $schema);
        $this->options['where'] = $schema[Relation::WHERE] ?? [];
        $this->options['orderBy'] = $schema[Relation::ORDER_BY] ?? [];
    }

    /**
     * Make sure that pivot loader is always carried with parent relation.
     */
    public function __clone()
    {
        parent::__clone();
        $this->pivot = clone $this->pivot;
    }

    public function withContext(LoaderInterface $parent, array $options = []): LoaderInterface
    {
        /** @var ManyToManyLoader $loader */
        $loader = parent::withContext($parent, $options);
        $loader->pivot = $loader->pivot->withContext(
            $loader,
            [
                'load'   => $loader->isLoaded(),
                'method' => $options['method'] ?? self::JOIN,
            ] + ($options['pivot'] ?? [])
        );

        return $loader;
    }

    public function loadRelation(
        string $relation,
        array $options,
        bool $join = false,
        bool $load = false
    ): LoaderInterface {
        if ($relation === '@' || $relation === '@.@') {
            unset($options['method']);
            if ($options !== []) {
                // re-configure
                $this->pivot = $this->pivot->withContext($this, $options);
            }

            return $this->pivot;
        }

        return parent::loadRelation($relation, $options, $join, $load);
    }

    public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
        if ($this->isLoaded() && $this->isJoined() && (int) $query->getLimit() !== 0) {
            throw new LoaderException('Unable to load data using join with limit on parent query');
        }

        if ($this->options['using'] !== null) {
            // use pre-defined query
            return parent::configureQuery($this->pivot->configureQuery($query), $outerKeys);
        }


        $localPrefix = $this->getAlias() . '.';
        $pivotPrefix = $this->pivot->getAlias() . '.';

        // Manually join pivoted table
        if ($this->isJoined()) {
            $parentKeys = (array)$this->schema[Relation::INNER_KEY];
            $throughOuterKeys = (array)$this->pivot->schema[Relation::THROUGH_OUTER_KEY];
            $parentPrefix = $this->parent->getAlias() . '.';
            $on = [];
            foreach ((array)$this->pivot->schema[Relation::THROUGH_INNER_KEY] as $i => $key) {
                $field = $pivotPrefix . $this->pivot->fieldAlias($key);
                $on[$field] = $parentPrefix . $this->parent->fieldAlias($parentKeys[$i]);
            }

            $query->join(
                $this->getJoinMethod(),
                $this->pivot->getJoinTable()
            )->on($on);

            $on = [];
            foreach ((array)$this->schema[Relation::OUTER_KEY] as $i => $key) {
                $field = $localPrefix . $this->fieldAlias($key);
                $on[$field] = $pivotPrefix . $this->pivot->fieldAlias($throughOuterKeys[$i]);
            }

            $query->join(
                $this->getJoinMethod(),
                $this->getJoinTable()
            )->on($on);
        } else {
            // reset all the columns when query is isolated (we have to do it manually
            // since underlying loader believes it's loaded)
            $query->columns([]);

            $outerKeyList = (array)$this->schema[Relation::OUTER_KEY];
            $on = [];
            foreach ((array)$this->pivot->schema[Relation::THROUGH_OUTER_KEY] as $i => $key) {
                $field = $pivotPrefix . $this->pivot->fieldAlias($key);
                $on[$field] = $localPrefix . $this->fieldAlias($outerKeyList[$i]);
            }

            $query->join(
                $this->getJoinMethod(),
                $this->pivot->getJoinTable()
            )->on($on);

            $fields = array_map(
                fn (string $key) => $pivotPrefix . $this->pivot->fieldAlias($key),
                (array)$this->pivot->schema[Relation::THROUGH_INNER_KEY]
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

        return parent::configureQuery($this->pivot->configureQuery($query));
    }

    public function createNode(): AbstractNode
    {
        $node = $this->pivot->createNode();
        $node->joinNode('@', parent::createNode());

        return $node;
    }

    protected function loadChild(AbstractNode $node): void
    {
        $rootNode = $node->getNode('@');
        foreach ($this->load as $relation => $loader) {
            $loader->loadData($rootNode->getNode($relation));
        }

        $this->pivot->loadChild($node);
    }

    protected function mountColumns(
        SelectQuery $query,
        bool $minify = false,
        string $prefix = '',
        bool $overwrite = false
    ): SelectQuery {
        // columns are reset on earlier stage to allow pivot loader mount it's own aliases
        return parent::mountColumns($query, $minify, $prefix, false);
    }

    protected function initNode(): AbstractNode
    {
        $node = new SingularNode(
            $this->columnNames(),
            (array)$this->define(Schema::PRIMARY_KEY),
            (array)$this->schema[Relation::OUTER_KEY],
            (array)$this->schema[Relation::THROUGH_OUTER_KEY]
        );

        $typecast = $this->define(Schema::TYPECAST);
        if ($typecast !== null) {
            $node->setTypecast(new Typecast($typecast, $this->getSource()->getDatabase()));
        }

        return $node;
    }
}
