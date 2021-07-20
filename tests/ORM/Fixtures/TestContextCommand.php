<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Context\ConsumerInterface;
use Spiral\Database\DatabaseInterface;

class TestContextCommand implements CommandInterface, ConsumerInterface
{
    private $executed = false;

    public function isReady(): bool
    {
        return true;
    }

    public function isExecuted(): bool
    {
        return $this->executed;
    }

    public function execute(): void
    {
        $this->executed = true;
    }

    public function complete(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function waitContext(string $key, bool $required = true): void
    {
    }

    public function getContext(): array
    {
    }

    public function register(string $key, mixed $value, int $stream = self::DATA): void
    {
    }

    public function getDatabase(): ?DatabaseInterface
    {
        return null;
    }
}
