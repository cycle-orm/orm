<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Command\Helper;

use Cycle\ORM\Command\DatabaseCommand;
use Cycle\ORM\Command\Traits\ErrorTrait;
use Cycle\ORM\Context\ConsumerInterface;
use Spiral\Database\DatabaseInterface;

class TestInsert extends DatabaseCommand implements ConsumerInterface
{
    use ErrorTrait;

    // Special identifier to forward insert key into
    public const INSERT_ID = '@lastInsertID';

    /** @var array */
    protected $data;

    /** @var ConsumerInterface[] */
    protected $consumers = [];

    /**
     * @param DatabaseInterface $db
     * @param string            $table
     * @param array             $data
     */
    public function __construct(DatabaseInterface $db, string $table, array $data = [], callable $generateID = null)
    {
        parent::__construct($db, $table);
        $this->data = $data;
    }

    /**
     * @inheritdoc
     */
    public function isReady(): bool
    {
        return true;
    }

    public function register(string $key, mixed $value, int $stream = self::DATA): void
    {
    }

    /**
     * Insert values, context not included.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Insert data into associated table.
     */
    public function execute(): void
    {
        $data = $this->getData();
        $insertID = $this->db->insert($this->table)->values($data)->run();

        foreach ($this->consumers as $key => $consumers) {
            foreach ($consumers as $id => $consumer) {
                /** @var ConsumerInterface $cn */
                $cn = $consumer[0];

                if ($key == self::INSERT_ID) {
                    $value = $insertID;
                } else {
                    $value = $data[$key] ?? null;
                }

                $cn->register($consumer[1], $value, $consumer[2]);
            }
        }

        parent::execute();
    }
}
