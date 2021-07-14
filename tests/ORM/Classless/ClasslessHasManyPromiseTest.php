<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Classless;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\Reference\Promise;
use Cycle\ORM\Reference\ReferenceInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\BaseTest;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Traits\TableTrait;
use Doctrine\Common\Collections\ArrayCollection;

abstract class ClasslessHasManyPromiseTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->makeTable('comment', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'message' => 'string'
        ]);

        $this->makeFK('comment', 'user_id', 'user', 'id');

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'message'],
            [
                [1, 'msg 1'],
                [1, 'msg 2'],
                [1, 'msg 3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            'user'    => [
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => 'comment',
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id'
                        ],
                    ]
                ]
            ],
            'comment' => [
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => SortByIDScope::class
            ]
        ]));
    }

   public function testInitRelation()
   {
       $u = $this->orm->make('user');
       $this->assertInstanceOf(ArrayCollection::class, $u->comments);
   }

    public function testFetchRelation(): void
    {
        $selector = new Select($this->orm, 'user');
        $selector->load('comments')->orderBy('user.id');

        $this->assertEquals([
            [
                'id'       => 1,
                'email'    => 'hello@world.com',
                'balance'  => 100.0,
                'comments' => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'message' => 'msg 1',
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'message' => 'msg 2',
                    ],
                    [
                        'id'      => 3,
                        'user_id' => 1,
                        'message' => 'msg 3',
                    ],
                ],
            ],
            [
                'id'       => 2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [],
            ],
        ], $selector->fetchData());
    }

    public function testPromised(): void
    {
        $selector = new Select($this->orm, 'user');
        $u = $selector->wherePK(1)->fetchOne();

        $this->captureReadQueries();
        $this->assertInstanceOf(Promise::class, $u->comments);
        $this->assertFalse($u->comments->hasValue());
        $this->assertNumReads(0);

        // Resolve promise
        $resolved = $u->comments->getCollection();
        $this->assertNumReads(1);

        $this->assertTrue($u->comments->hasValue());
        $this->assertCount(3, $resolved);
    }

    public function testHasManyPromiseLoaded(): void
    {
        $selector = new Select($this->orm, 'user');
        $u = $selector->wherePK(1)->fetchOne();

        $this->captureReadQueries();
        $this->assertInstanceOf(Promise::class, $p = $u->comments);
        $this->assertNumReads(0);

        /** @var Promise $p */
        $this->assertFalse($p->hasValue());
        $p->resolve();
        $this->assertIsArray($p->getValue());
        $this->assertTrue($p->hasValue());
    }

    public function testHasManyPromiseRole(): void
    {
        $selector = new Select($this->orm, 'user');
        $u = $selector->wherePK(1)->fetchOne();

        $this->captureReadQueries();
        $this->assertInstanceOf(Promise::class, $p = $u->comments);
        $this->assertNumReads(0);

        /** @var Promise $p */
        $this->assertSame('comment', $p->getRole());
    }

    public function testHasManyPromiseScope(): void
    {
        $u = (new Select($this->orm, 'user'))
            ->wherePK(1)->fetchOne();

        $this->captureReadQueries();
        $this->assertInstanceOf(ReferenceInterface::class, $r = $u->comments);
        $this->assertNumReads(0);

        /** @var ReferenceInterface $r */
        $this->assertEquals([
            'user_id' => 1
        ], $r->getScope());
    }

    public function testPromisedEmpty(): void
    {
        $selector = new Select($this->orm, 'user');
        $u = $selector->wherePK(2)->fetchOne();

        $this->captureReadQueries();
        $this->assertInstanceOf(Promise::class, $u->comments);
        $this->assertFalse($u->comments->hasValue());
        $this->assertNumReads(0);

        $this->assertCount(0, $u->comments->getCollection());
        $this->assertNumReads(1);
    }

    public function testNoChanges(): void
    {
        $u = (new Select($this->orm, 'user'))->wherePK(1)->fetchOne();

        $this->captureReadQueries();
        $this->captureWriteQueries();
        $this->save($u);

        $this->assertNumWrites(0);
        $this->assertNumReads(0);
    }

    public function testNoChangesWithNoChildren(): void
    {
        $selector = new Select($this->orm, 'user');
        $u = $selector->wherePK(2)->fetchOne();

        $this->captureReadQueries();
        $this->captureWriteQueries();
        $this->save($u);
        $this->assertNumWrites(0);
        $this->assertNumReads(0);
    }

    public function testRemoveChildren(): void
    {
        $e = (new Select($this->orm, 'user'))->wherePK(1)->fetchOne();

        $e->comments = $e->comments->getCollection();
        $e->comments->remove(1);

        $this->save($e);

        $e = (new Select($this->orm->withHeap(new Heap()), 'user'))
            ->wherePK(1)->fetchOne();

        $e->comments = $e->comments->getCollection();
        $this->assertCount(2, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
    }

    public function testAddAndRemoveChildren(): void
    {
        $e = (new Select($this->orm, 'user'))->wherePK(1)->fetchOne();

        $e->comments = $e->comments->getCollection();
        $e->comments->remove(1);

        $c = $this->orm->make('comment');
        $c->message = 'msg 4';
        $e->comments->add($c);

        $this->save($e);

        $e = (new Select($this->orm->withHeap(new Heap()), 'user'))
            ->wherePK(1)->fetchOne();

        $e->comments = $e->comments->getCollection();
        $this->assertCount(3, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
        $this->assertSame('msg 4', $e->comments[2]->message);
    }

    public function testSliceAndSaveToAnotherParent(): void
    {
        $selector = new Select($this->orm, 'user');
        [$a, $b] = $selector->orderBy('user.id')->fetchAll();

        $a->comments = $a->comments->getCollection();
        $b->comments = $b->comments->getCollection();

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
        $this->save($a, $b);
        $this->assertNumWrites(2);

        // consecutive
        $this->captureWriteQueries();
        $this->save($a, $b);
        $this->assertNumWrites(0);

        [$a, $b] = (new Select($this->orm->withHeap(new Heap()), 'user'))
            ->load('comments', [
                'method' => Select\JoinableLoader::INLOAD,
                'as'     => 'comment'
            ])
            ->orderBy('user.id')->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertEquals(3, $a->comments[0]->id);
        $this->assertEquals(1, $b->comments[0]->id);
        $this->assertEquals(2, $b->comments[1]->id);

        $this->assertEquals('new b', $b->comments[0]->message);
    }
}
