<?php

declare(strict_types=1);

namespace Cycle\ORM\Mapper;

use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\Database\Delete;
use Cycle\ORM\Command\Database\Insert;
use Cycle\ORM\Command\Database\Update;
use Cycle\ORM\Context\ConsumerInterface;
use Cycle\ORM\Exception\MapperException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Heap\State;
use Cycle\ORM\MapperInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Select\SourceInterface;

/**
 * Provides basic capabilities to work with entities persisted in SQL databases.
 */
abstract class DatabaseMapper implements MapperInterface
{
    protected SourceInterface $source;

    protected ORMInterface $orm;

    protected string $role;

    protected array $columns;

    /** @var array */
    protected $fields;

    /** @var string[] */
    protected array $primaryColumns = [];

    /** @var string[] */
    protected array $primaryKeys;

    public function __construct(ORMInterface $orm, string $role)
    {
        if (!$orm instanceof Select\SourceProviderInterface) {
            throw new MapperException('Source factory is missing');
        }

        $this->orm = $orm;
        $this->role = $role;

        $this->source = $orm->getSource($role);
        $this->columns = $orm->getSchema()->define($role, Schema::COLUMNS);

        $this->primaryKeys = (array)$orm->getSchema()->define($role, Schema::PRIMARY_KEY);
        foreach ($this->primaryKeys as $PK) {
            $this->primaryColumns[] = $this->columns[$PK] ?? $PK;
        }

        // Resolve field names
        foreach ($this->columns as $name => $column) {
            $this->fields[] = is_string($name) ? $name : $column;
        }
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function queueCreate(object $entity, Node $node, State $state): CommandInterface
    {
        $values = $node->getData();

        // sync the state
        $state->setStatus(Node::SCHEDULED_INSERT);

        foreach ($this->primaryKeys as $key) {
            if (!isset($values[$key])) {
                foreach ($this->nextPrimaryKey() ?? [] as $pk => $value) {
                    $state->register($pk, $value);
                }
                break;
            }
        }

        return new Insert(
            $this->source->getDatabase(),
            $this->source->getTable(),
            $state,
            $this->primaryKeys,
            count($this->primaryColumns) === 1 ? $this->primaryColumns[0] : null,
            [$this, 'mapColumns']
        );
    }

    public function queueUpdate(object $entity, Node $node, State $state): CommandInterface
    {
        $fromData = $node->getState()->getTransactionData();
        \Cycle\ORM\Transaction\Pool::DEBUG AND print "changes count: " . count($node->getChanges()) . "\n";

        $update = new Update(
            $this->source->getDatabase(),
            $this->source->getTable(),
            $state,
            $this->primaryKeys,
            [$this, 'mapColumns']
        );

        foreach ($this->primaryKeys as $pk) {
            // set update criteria right now
            $update->register($pk, $fromData[$pk], ConsumerInterface::SCOPE);
        }

        return $update;
    }

    public function queueDelete(object $entity, Node $node, State $state): CommandInterface
    {
        $delete = new Delete($this->source->getDatabase(), $this->source->getTable(), $state, [$this, 'mapColumns']);
        $state->setStatus(Node::SCHEDULED_DELETE);

        $delete->waitScope(...$this->primaryKeys);
        $fromData = $node->getInitialData();
        foreach ($this->primaryKeys as $pk) {
            // set update criteria right now
            $delete->register($pk, $fromData[$pk], ConsumerInterface::SCOPE);
        }

        return $delete;
    }

    /**
     * Generate next sequential entity ID. Return null to use autoincrement value.
     */
    protected function nextPrimaryKey(): ?array
    {
        return null;
    }

    public function mapColumns(array $values): array
    {
        $result = [];
        foreach ($values as $column => $value) {
            $result[$this->columns[$column] ?? $column] = $value;
        }

        return $result;
    }
}
