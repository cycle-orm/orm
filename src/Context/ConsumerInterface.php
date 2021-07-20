<?php

declare(strict_types=1);

namespace Cycle\ORM\Context;

use JetBrains\PhpStorm\ExpectedValues;

/**
 * Provides the ability to accept the forwarded key value.
 */
interface ConsumerInterface
{
    // Value destinations.
    public const DATA  = 1;
    public const SCOPE = 2;

    /**
     * Accept the value forwarded by another object.
     *
     * @param string $key    Key name to accept the value.
     * @param mixed  $value  The key value.
     * @param int    $stream One of the context types (data context, scope context).
     */
    public function register(
        string $key,
        mixed $value,
        #[ExpectedValues(valuesFromClass: self::class)]
        int $stream = self::DATA
    ): void;
}
