<?php

declare(strict_types=1);

namespace Cycle\ORM\Command\Traits;

trait ScopeTrait
{
    /** @var array */
    protected $scope = [];

    /** @var string[] */
    protected $waitScope = [];

    /**
     * Wait for the context value.
     */
    public function waitScope(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->waitScope[$key] = true;
        }
    }

    public function getScope(): array
    {
        return $this->scope;
    }

    /**
     * Set scope value.
     *
     * @param mixed  $value
     */
    protected function setScope(string $key, $value): void
    {
        $this->scope[$key] = $value;
    }

    /**
     * Indicate that context value is not required anymore.
     */
    protected function freeScope(string $key): void
    {
        unset($this->waitScope[$key]);
    }
}
