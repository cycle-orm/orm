<?php

declare(strict_types=1);

namespace Cycle\ORM\Command\Database;

use Cycle\ORM\Command\ScopeCarrierInterface;
use Cycle\ORM\Command\StoreCommand;
use Cycle\ORM\Command\Traits\ErrorTrait;
use Cycle\ORM\Command\Traits\ScopeTrait;
use Cycle\ORM\Exception\CommandException;
use Cycle\ORM\Heap\State;
use Spiral\Database\DatabaseInterface;

/**
 * Update data CAN be modified by parent commands using context.
 *
 * This is conditional command, it would not be executed when no fields are given!
 */
final class Update extends StoreCommand implements ScopeCarrierInterface
{
    use ScopeTrait;
    use ErrorTrait;

    /** @var null|callable */
    private $mapper;

    public function __construct(
        DatabaseInterface $db,
        string $table,
        State $state,
        array $primaryKeys = [],
        callable $mapper = null
    ) {
        parent::__construct($db, $table, $state);
        $this->waitScope(...$primaryKeys);
        $this->mapper = $mapper;
    }

    /**
     * Avoid opening transaction when no changes are expected.
     */
    public function getDatabase(): ?DatabaseInterface
    {
        if ($this->scope === [] || $this->state->getChanges() === []) {
            return null;
        }

        return parent::getDatabase();
    }

    public function isReady(): bool
    {
        return $this->isScopeReady();
    }

    public function hasData(): bool
    {
        return $this->columns !== [] || $this->state->getChanges() !== [];
    }

    public function getStoreData(): array
    {
        if ($this->appendix !== []) {
            $this->state->setData($this->appendix);
        }
        $data = $this->state->getChanges();
        return array_merge($this->columns, $this->mapper === null ? $data : ($this->mapper)($data));
    }

    /**
     * Update data in associated table.
     */
    public function execute(): void
    {
        if ($this->scope === []) {
            throw new CommandException('Unable to execute update command without a scope.');
        }

        if ($this->appendix !== []) {
            $this->state->setData($this->appendix);
        }
        if ($this->db === null) {
            $this->state->updateTransactionData();
            return;
        }
        $data = $this->state->getChanges();
        if ($data !== [] || $this->columns !== []) {
            $this->db
                ->update(
                    $this->table,
                    array_merge($this->columns, $this->mapper === null ? $data : ($this->mapper)($data)),
                    $this->mapper === null ? $this->scope : ($this->mapper)($this->scope)
                )
                ->run();
        }
        $this->state->updateTransactionData();

        parent::execute();
    }

    public function register(string $key, mixed $value, int $stream = self::DATA): void
    {
        if ($stream === self::SCOPE) {
            if (empty($value)) {
                return;
            }

            $this->freeScope($key);
            $this->setScope($key, $value);

            return;
        }
        $this->state->register($key, $value);
    }
}
