<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Cycle\ORM\Transaction;

abstract class TypecastTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'active'  => 'bool',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'active', 'balance'],
            [
                ['hello@world.com', true, 100],
                ['another@world.com', false, 200],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'active', 'balance'],
                Schema::TYPECAST    => [
                    'id'      => 'int',
                    'active'  => 'bool',
                    'balance' => 'float'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => SortByIDScope::class
            ]
        ]));
    }

    public function testFetchAll(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->fetchAll();

        $this->assertInstanceOf(User::class, $result[0]);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame('hello@world.com', $result[0]->email);
        $this->assertSame(100.0, $result[0]->balance);
        $this->assertSame(true, $result[0]->active);

        $this->assertInstanceOf(User::class, $result[1]);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('another@world.com', $result[1]->email);
        $this->assertSame(200.0, $result[1]->balance);
        $this->assertSame(false, $result[1]->active);
    }

    public function testAssertRole(): void
    {
        $selector = new Select($this->orm, 'user');
        $result = $selector->fetchOne();

        $this->assertSame('user', $this->orm->getHeap()->get($result)->getRole());
    }

    public function testMakeByRole(): void
    {
        $this->assertInstanceOf(User::class, $this->orm->make('user'));
    }

    public function testMakeByClass(): void
    {
        $this->assertInstanceOf(User::class, $this->orm->make(User::class));
    }

    public function testAssertRoleViaClass(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->fetchOne();

        $this->assertSame('user', $this->orm->getHeap()->get($result)->getRole());
    }

    public function testFetchOne(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->wherePK(1)->fetchOne();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertSame('hello@world.com', $result->email);
        $this->assertSame(100.0, $result->balance);
    }

    public function testWhere(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->where('id', 2)->fetchOne();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(2, $result->id);
        $this->assertSame('another@world.com', $result->email);
        $this->assertSame(200.0, $result->balance);
    }

    public function testDelete(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->where('id', 2)->fetchOne();

        $tr = new Transaction($this->orm);
        $tr->delete($result);
        $tr->run();

        $selector = new Select($this->orm->withHeap(new Heap()), User::class);
        $this->assertNull($selector->where('id', 2)->fetchOne());

        $selector = new Select($this->orm, User::class);
        $this->assertNull($selector->where('id', 2)->fetchOne());

        $this->assertFalse($this->orm->getHeap()->has($result));
    }

    public function testHeap(): void
    {
        $selector = new Select($this->orm, User::class);
        $result = $selector->wherePK(1)->fetchOne();

        $this->assertEquals(1, $result->id);

        $this->assertTrue($this->orm->getHeap()->has($result));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($result)->getStatus());

        $this->assertSame(
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'active'  => true,
                'balance' => 100.0,
            ],
            $this->orm->getHeap()->get($result)->getData()
        );
    }

    public function testStore(): void
    {
        $e = new User();
        $e->email = 'test@email.com';
        $e->balance = 300;
        $e->active = true;

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($e);
        $tr->run();
        $this->assertNumWrites(1);

        $this->captureWriteQueries();
        $tr = new Transaction($this->orm);
        $tr->persist($e);
        $tr->run();
        $this->assertNumWrites(0);

        $this->assertSame(3, $e->id);

        $this->assertTrue($this->orm->getHeap()->has($e));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($e)->getStatus());
    }

    public function testStoreWithUpdate(): void
    {
        $e = new User();
        $e->email = 'test@email.com';
        $e->balance = 300;
        $e->active = true;

        $this->captureWriteQueries();

        $tr = new Transaction($this->orm);
        $tr->persist($e);

        $e->balance = 400;

        $tr->run();

        $this->assertNumWrites(1);

        $this->assertSame(3, $e->id);
        $this->assertTrue($this->orm->getHeap()->has($e));
        $this->assertSame(Node::MANAGED, $this->orm->getHeap()->get($e)->getStatus());

        $this->orm = $this->orm->withHeap(new Heap());
        $selector = new Select($this->orm, User::class);
        $result = $selector->where('id', 3)->fetchOne();
        $this->assertEquals(400, $result->balance);
        $this->assertSame(true, $result->active);
    }

    public function testRepositoryFindAll(): void
    {
        $r = $this->orm->getRepository(User::class);
        $result = $r->findAll();

        $this->assertInstanceOf(User::class, $result[0]);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame('hello@world.com', $result[0]->email);
        $this->assertSame(100.0, $result[0]->balance);

        $this->assertInstanceOf(User::class, $result[1]);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('another@world.com', $result[1]->email);
        $this->assertSame(200.0, $result[1]->balance);
    }

    public function testRepositoryFindOne(): void
    {
        $r = $this->orm->getRepository(User::class);
        $result = $r->findOne();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertSame('hello@world.com', $result->email);
        $this->assertSame(100.0, $result->balance);
    }

    public function testRepositoryFindOneWithWhere(): void
    {
        $r = $this->orm->getRepository(User::class);
        $result = $r->findOne(['id' => 2]);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(2, $result->id);
        $this->assertSame('another@world.com', $result->email);
        $this->assertSame(200.0, $result->balance);
    }
}
