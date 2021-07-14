<?php

declare(strict_types=1);

namespace Cycle\ORM\Select\Loader;

use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Parser\SingularNode;
use Cycle\ORM\Parser\Typecast;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Select\Traits\WhereTrait;
use Spiral\Database\Injection\Parameter;
use Spiral\Database\Query\SelectQuery;

/**
 * Dedicated to load HAS_ONE relations, by default loader will prefer to join data into query.
 * Loader support MORPH_KEY.
 *
 * Please note that OUTER and INNER keys defined from perspective of parent (reversed for our
 * purposes).
 */
class HasOneLoader extends JoinableLoader
{
    use WhereTrait;

    /**
     * Default set of relation options. Child implementation might defined their of default options.
     */
    protected array $options = [
        'load'      => false,
        'scope'     => true,
        'method'    => self::INLOAD,
        'minify'    => true,
        'as'        => null,
        'using'     => null,
        'where'     => null
    ];

    public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
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

        return parent::configureQuery($query);
    }

    protected function initNode(): AbstractNode
    {
        $node = new SingularNode(
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
