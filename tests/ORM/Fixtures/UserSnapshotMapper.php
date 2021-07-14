<?php

/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

declare(strict_types=1);

namespace Cycle\ORM\Tests\Fixtures;

use Cycle\ORM\Command\Branch\Sequence;
use Cycle\ORM\Command\CommandInterface;
use Cycle\ORM\Command\Database\Insert;
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
        $cs->addCommand($this->snap($node, 'create', $cc));

        return $cs;
    }

    public function queueUpdate($entity, Node $node, State $state): CommandInterface
    {
        $cc = parent::queueUpdate($entity, $node, $state);

        $cs = new Sequence();
        $cs->addCommand($cc);
        $cs->addCommand($this->snap($node, 'update', $cc));

        return $cs;
    }

    protected function snap(Node $node, string $action, CommandInterface $cc): Insert
    {
        $data = $node->getData();
        unset($data['id']);
        $state = new State(Node::SCHEDULED_INSERT, $data + [
            'at'     => new \DateTimeImmutable(),
            'action' => $action
        ]);

        $snap = new Insert(
            $this->source->getDatabase(),
            'user_snapshots',
            $state
        );

        if ($cc instanceof Insert) {
            $state->waitField('user_id', true);
            // todo remove forwarding
            $node->forward('id', $state, 'user_id');
        } else {
            $state->register('user_id', $node->getData()['id'], true);
        }

        return $snap;
    }
}
