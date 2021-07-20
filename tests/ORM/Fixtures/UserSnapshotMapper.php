<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Command\Branch\Sequence;
use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\StoreCommandInterface;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Heap\State;
use Cycle\ORM\Mapper\Mapper;

class UserSnapshotMapper extends Mapper
{
    public function queueCreate($entity, Node $node, State $state): CommandInterface
    {
        $cc = parent::queueCreate($entity, $node, $state);

        $cs = new Sequence();
        $cs->addCommand($cc);
        $cs->addCommand($this->snap($node, 'create'));

        return $cs;
    }

    public function queueUpdate($entity, Node $node, State $state): CommandInterface
    {
        $cc = parent::queueUpdate($entity, $node, $state);

        $cs = new Sequence();
        $cs->addCommand($cc);
        $cs->addCommand($this->snap($node, 'update'));

        return $cs;
    }

    protected function snap(Node $node, string $action): StoreCommandInterface
    {
        $data = $node->getData();
        unset($data['id']);
        $state = new State(Node::SCHEDULED_INSERT, $data + [
            'at'     => new \DateTimeImmutable(),
            'action' => $action
        ]);

        return WrappedStoreCommand::createInsert(
            $this->source->getDatabase(),
            'user_snapshots',
            $state
        )->withBeforeExecute(static function (WrappedStoreCommand $command) use ($node, $state): void {
            $state->register('user_id', $node->getData()['id']);
        });
    }
}
