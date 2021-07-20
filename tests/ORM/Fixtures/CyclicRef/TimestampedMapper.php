<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures\CyclicRef;

use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\Database\Insert;
use Cycle\ORM\Command\Database\Update;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Heap\State;
use Cycle\ORM\Mapper\Mapper;
use DateTimeImmutable;

class TimestampedMapper extends Mapper
{
    public function queueCreate($entity, Node $node, State $state): CommandInterface
    {
        /** @var Insert $cmd */
        $cmd = parent::queueCreate($entity, $node, $state);

        $dt = new DateTimeImmutable();
        $state->register('created_at', $dt);
        $state->register('updated_at', $dt);

        return $cmd;
    }

    public function queueUpdate($entity, Node $node, State $state): CommandInterface
    {
        /** @var Update $cmd */
        $cmd = parent::queueUpdate($entity, $node, $state);

        $cmd->registerAppendix('updated_at', new DateTimeImmutable());

        return $cmd;
    }
}
