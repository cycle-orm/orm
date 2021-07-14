<?php

declare(strict_types=1);

namespace Cycle\ORM\Select;

use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Parser\AbstractNode;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\Traits\ColumnsTrait;
use Cycle\ORM\Select\Traits\ScopeTrait;
use Spiral\Database\Query\SelectQuery;
use Spiral\Database\StatementInterface;

/**
 * Provides ability to load relation data in a form of JOIN or external query.
 */
abstract class JoinableLoader extends AbstractLoader implements JoinableInterface
{
    use ColumnsTrait;
    use ScopeTrait;

    /**
     * Default set of relation options. Child implementation might defined their of default options.
     */
    protected array $options = [
        // load relation data
        'load'      => false,

        // true or instance to enable, false or null to disable
        'scope' => true,

        // scope to be used for the relation
        'method'    => null,

        // load method, see AbstractLoader constants
        'minify'    => true,

        // when true all loader columns will be minified (only for loading)
        'as'        => null,

        // table alias
        'using'     => null,

        // alias used by another relation
        'where'     => null,

        // where conditions (if any)
    ];

    protected string $name;

    protected array $schema;

    public function __construct(ORMInterface $orm, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $target);

        $this->name = $name;
        $this->schema = $schema;
    }

    /**
     * Relation table alias.
     */
    public function getAlias(): string
    {
        if ($this->options['using'] !== null) {
            //We are using another relation (presumably defined by with() to load data).
            return $this->options['using'];
        }

        if ($this->options['as'] !== null) {
            return $this->options['as'];
        }

        throw new LoaderException('Unable to resolve loader alias');
    }

    public function withContext(LoaderInterface $parent, array $options = []): LoaderInterface
    {
        /**
         * @var AbstractLoader $parent todo: should move withContext into LoaderInterface?
         * @var self $loader
         */
        $loader = parent::withContext($parent, $options);

        if ($loader->getSource()->getDatabase() !== $parent->getSource()->getDatabase()) {
            if ($loader->isJoined()) {
                throw new LoaderException('Unable to join tables located in different databases');
            }

            // loader is not joined, let's make sure that POSTLOAD is used
            if ($this->isLoaded()) {
                $loader->options['method'] = self::POSTLOAD;
            }
        }

        //Calculate table alias
        $loader->options['as'] = $loader->calculateAlias($parent);

        if (array_key_exists('scope', $options)) {
            if ($loader->options['scope'] instanceof ScopeInterface) {
                $loader->setScope($loader->options['scope']);
            } elseif (is_string($loader->options['scope'])) {
                $loader->setScope($this->orm->getFactory()->make($loader->options['scope']));
            }
        } else {
            $loader->setScope($this->getSource()->getScope());
        }

        if ($loader->isLoaded()) {
            foreach ($loader->getEagerRelations() as $relation) {
                $loader->loadRelation($relation, [], false, true);
            }
        }

        return $loader;
    }

    public function loadData(AbstractNode $node): void
    {
        if ($this->isJoined() || !$this->isLoaded()) {
            // load data for all nested relations
            parent::loadData($node);

            return;
        }

        // Get list of reference key values aggregated by parent.
        $references = $node->getReferenceValues();
        if ($references === []) {
            // nothing found at parent level, unable to create sub query
            return;
        }

        //Ensure all nested relations
        $statement = $this->configureQuery($this->initQuery(), $references)->run();

        foreach ($statement->fetchAll(StatementInterface::FETCH_NUM) as $row) {
            try {
                $node->parseRow(0, $row);
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        $statement->close();

        // load data for all nested relations
        parent::loadData($node);
    }

    /**
     * Indicated that loaded must generate JOIN statement.
     */
    public function isJoined(): bool
    {
        if (!empty($this->options['using'])) {
            return true;
        }

        return in_array($this->getMethod(), [self::INLOAD, self::JOIN, self::LEFT_JOIN], true);
    }

    /**
     * Indication that loader want to load data.
     */
    public function isLoaded(): bool
    {
        return $this->options['load'] || in_array($this->getMethod(), [self::INLOAD, self::POSTLOAD]);
    }

    /**
     * Configure query with conditions, joins and columns.
     *
     * @param array $outerKeys Set of OUTER_KEY values collected by parent loader.
     */
    public function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
        if ($this->isLoaded()) {
            if ($this->isJoined()) {
                // mounting the columns to parent query
                $this->mountColumns($query, $this->options['minify']);
            } else {
                // this is initial set of columns (remove all existed)
                $this->mountColumns($query, $this->options['minify'], '', true);
            }

            if ($this->options['load'] instanceof ScopeInterface) {
                $this->options['load']->apply($this->makeQueryBuilder($query));
            }

            if (is_callable($this->options['load'], true)) {
                ($this->options['load'])($this->makeQueryBuilder($query));
            }
        }

        return parent::configureQuery($query);
    }

    protected function applyScope(SelectQuery $query): SelectQuery
    {
        if ($this->scope !== null) {
            $this->scope->apply($this->makeQueryBuilder($query));
        }

        return $query;
    }

    /**
     * Get load method.
     */
    protected function getMethod(): int
    {
        return $this->options['method'];
    }

    /**
     * Create relation specific select query.
     */
    protected function initQuery(): SelectQuery
    {
        return $this->getSource()->getDatabase()->select()->from($this->getJoinTable());
    }

    /**
     * Calculate table alias.
     */
    protected function calculateAlias(AbstractLoader $parent): string
    {
        if (!empty($this->options['as'])) {
            return $this->options['as'];
        }

        $alias = $parent->getAlias() . '_' . $this->name;

        if ($this->isLoaded() && $this->isJoined()) {
            // to avoid collisions
            return 'l_' . $alias;
        }

        return $alias;
    }

    /**
     * Generate sql identifier using loader alias and value from relation definition. Key name to be
     * fetched from schema.
     *
     * Example:
     * $this->getKey(Relation::OUTER_KEY);
     *
     * @param string|int $key
     */
    protected function localKey($key): ?string
    {
        if (empty($this->schema[$key])) {
            return null;
        }

        return $this->getAlias() . '.' . $this->fieldAlias($this->schema[$key]);
    }

    /**
     * Get parent identifier based on relation configuration key.
     *
     * @param string|int $key
     */
    protected function parentKey($key): string
    {
        return $this->parent->getAlias() . '.' . $this->parent->fieldAlias($this->schema[$key]);
    }

    protected function getJoinMethod(): string
    {
        return $this->getMethod() == self::JOIN ? 'INNER' : 'LEFT';
    }

    /**
     * Joined table name and alias.
     */
    protected function getJoinTable(): string
    {
        return "{$this->define(Schema::TABLE)} AS {$this->getAlias()}";
    }

    /**
     * Relation columns.
     */
    protected function getColumns(): array
    {
        return $this->define(Schema::COLUMNS);
    }

    private function makeQueryBuilder(SelectQuery $query): QueryBuilder
    {
        $builder = new QueryBuilder($query, $this);
        if ($this->isJoined()) {
            return $builder->withForward('onWhere');
        }

        return $builder;
    }
}
