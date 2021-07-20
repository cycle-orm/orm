<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Heap\Node;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{
    public function testPush(): void
    {
        $s = new Node(Node::MANAGED, [], 'parent');

        $s->register('user_id', 1);

        $this->assertSame(1, $s->getData()['user_id']);
    }
}
