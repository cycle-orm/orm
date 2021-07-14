<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\SortByMsgScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Fixtures\UUIDMapper;
use Cycle\ORM\Tests\Traits\TableTrait;
use Cycle\ORM\Transaction;
use Doctrine\Common\Collections\Collection;
use Ramsey\Uuid\Uuid;

abstract class UUIDTest extends BaseTest
{
    use TableTrait;

    private $u1;
    private $u2;
    private $c1;
    private $c2;
    private $c3;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'string(36),primary',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->makeTable('comment', [
            'id'      => 'string(36),primary',
            'user_id' => 'string(36)',
            'message' => 'string'
        ]);

        $this->makeFK('comment', 'user_id', 'user', 'id');

        // seed
        $this->u1 = Uuid::uuid4()->toString();
        $this->u2 = Uuid::uuid4()->toString();
        $this->c1 = Uuid::uuid4()->toString();
        $this->c2 = Uuid::uuid4()->toString();
        $this->c3 = Uuid::uuid4()->toString();

        $this->getDatabase()->table('user')->insertMultiple(
            ['id', 'email', 'balance'],
            [
                [$this->u1, 'hello@world.com', 100],
                [$this->u2, 'another@world.com', 200],
            ]
        );

        $this->getDatabase()->table('comment')->insertMultiple(
            ['id', 'user_id', 'message'],
            [
                [$this->c1, $this->u1, 'msg 1'],
                [$this->c2, $this->u1, 'msg 2'],
                [$this->c3, $this->u1, 'msg 3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => UUIDMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id',
                        ],
                    ]
                ]
            ],
            Comment::class => [
                Schema::ROLE        => 'comment',
                Schema::MAPPER      => UUIDMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => SortByMsgScope::class
            ]
        ]));
    }

    public function testFetchRelation(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('comments')->orderBy('user.email');

        $this->assertEquals([
            [
                'id'       => $this->u2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [],
            ],
            [
                'id'       => $this->u1,
                'email'    => 'hello@world.com',
                'balance'  => 100.0,
                'comments' => [
                    [
                        'id'      => $this->c1,
                        'user_id' => $this->u1,
                        'message' => 'msg 1',
                    ],
                    [
                        'id'      => $this->c2,
                        'user_id' => $this->u1,
                        'message' => 'msg 2',
                    ],
                    [
                        'id'      => $this->c3,
                        'user_id' => $this->u1,
                        'message' => 'msg 3',
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    public function testFetchRelationInload(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('comments', ['method' => Select\JoinableLoader::INLOAD])->orderBy('user.email');

        $this->assertEquals([
            [
                'id'       => $this->u2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [],
            ],
            [
                'id'       => $this->u1,
                'email'    => 'hello@world.com',
                'balance'  => 100.0,
                'comments' => [
                    [
                        'id'      => $this->c1,
                        'user_id' => $this->u1,
                        'message' => 'msg 1',
                    ],
                    [
                        'id'      => $this->c2,
                        'user_id' => $this->u1,
                        'message' => 'msg 2',
                    ],
                    [
                        'id'      => $this->c3,
                        'user_id' => $this->u1,
                        'message' => 'msg 3',
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    public function testAccessRelated(): void
    {
        $selector = new Select($this->orm, User::class);
        /**
         * @var User $a
         * @var User $b
         */
        [$b, $a] = $selector->load('comments')->orderBy('user.email')->fetchAll();

        $this->assertInstanceOf(Collection::class, $a->comments);
        $this->assertInstanceOf(Collection::class, $b->comments);

        $this->assertCount(3, $a->comments);
        $this->assertCount(0, $b->comments);

        $this->assertEquals('msg 1', $a->comments[0]->message);
        $this->assertEquals('msg 2', $a->comments[1]->message);
        $this->assertEquals('msg 3', $a->comments[2]->message);
    }

    public function testNoWrite(): void
    {
        $selector = new Select($this->orm, User::class);
        /**
         * @var User $a
         * @var User $b
         */
        [$a, $b] = $selector->load('comments')->orderBy('user.email')->fetchAll();

        $this->captureWriteQueries();
        $this->save($a, $b);
        $this->assertNumWrites(0);
    }

    public function testCreateWithRelations(): void
    {
        $e = new User();
        $e->email = 'test@email.com';
        $e->balance = 300;
        $e->comments->add(new Comment());
        $e->comments->add(new Comment());

        $e->comments[0]->message = 'msg A';
        $e->comments[1]->message = 'msg B';

        $this->captureWriteQueries();
        $this->save($e);
        $this->assertNumWrites(3);

        // consecutive test
        $this->captureWriteQueries();
        $this->save($e);
        $this->assertNumWrites(0);

        $this->assertIsString($e->id);
        $this->assertEquals(36, strlen($e->id));

        $this->assertTrue($this->orm->getHeap()->has($e));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($e)->getStatus());

        $this->assertTrue($this->orm->getHeap()->has($e->comments[0]));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($e->comments[0])->getStatus());
        $this->assertSame($e->id, $this->orm->getHeap()->get($e->comments[0])->getData()['user_id']);

        $this->assertTrue($this->orm->getHeap()->has($e->comments[1]));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($e->comments[1])->getStatus());
        $this->assertSame($e->id, $this->orm->getHeap()->get($e->comments[1])->getData()['user_id']);

        $selector = new Select($this->orm, User::class);
        $selector->load('comments');

        $this->assertEquals([
            [
                'id'       => $e->id,
                'email'    => 'test@email.com',
                'balance'  => 300.0,
                'comments' => [
                    [
                        'id'      => $e->comments[0]->id,
                        'user_id' => $e->id,
                        'message' => 'msg A',
                    ],
                    [
                        'id'      => $e->comments[1]->id,
                        'user_id' => $e->id,
                        'message' => 'msg B',
                    ],
                ],
            ],
        ], $selector->wherePK($e->id)->fetchData());
    }

    public function testRemoveChildren(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->orderBy('user.email')->load('comments');

        /** @var User $e */
        $e = $selector->wherePK($this->u1)->fetchOne();

        $e->comments->remove(1);

        $tr = new Transaction($this->orm);
        $tr->persist($e);
        $tr->run();

        $selector = new Select($this->orm->withHeap(new Heap()), User::class);
        $selector->orderBy('user.id')->load('comments');

        /** @var User $e */
        $e = $selector->wherePK($this->u1)->fetchOne();

        $this->assertCount(2, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
    }

    public function testAddAndRemoveChildren(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK($this->u1)->fetchOne();

        $e->comments->remove(1);

        $c = new Comment();
        $c->message = 'msg 4';
        $e->comments->add($c);

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($e);
        $tr->run();
        $this->assertNumWrites(2);

        // consecutive test
        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($e);
        $tr->run();
        $this->assertNumWrites(0);

        $selector = new Select($this->orm->withHeap(new Heap()), User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK($this->u1)->fetchOne();

        $this->assertCount(3, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
        $this->assertSame('msg 4', $e->comments[2]->message);
    }

    public function testSliceAndSaveToAnotherParent(): void
    {
        $selector = new Select($this->orm, User::class);
        /**
         * @var User $a
         * @var User $b
         */
        [$b, $a] = $selector->load('comments')->orderBy('user.email')->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(0, $b->comments);

        $b->comments = $a->comments->slice(0, 2);
        foreach ($b->comments as $c) {
            $a->comments->removeElement($c);
        }

        $b->comments[0]->message = 'new b';

        $this->assertCount(1, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($a);
        $tr->persist($b);
        $tr->run();
        $this->assertNumWrites(2);

        // consecutive
        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($a);
        $tr->persist($b);
        $tr->run();
        $this->assertNumWrites(0);

        $selector = new Select($this->orm->withHeap(new Heap()), User::class);

        /**
         * @var User $a
         * @var User $b
         */
        [$b, $a] = $selector->load('comments', [
            'method' => Select\JoinableLoader::INLOAD,
            'as'     => 'comment'
        ])->orderBy('user.email')->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertEquals($this->c3, $a->comments[0]->id);

        $this->assertEquals($this->c2, $b->comments[0]->id);
        $this->assertEquals($this->c1, $b->comments[1]->id);

        $this->assertEquals('new b', $b->comments[1]->message);
    }
}
