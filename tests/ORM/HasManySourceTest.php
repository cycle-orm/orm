<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\LeveledHasManyScope;
use Cycle\ORM\Tests\Fixtures\ShortLeveledHasManyScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;

abstract class HasManySourceTest extends BaseTest
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
            'level'   => 'integer',
            'message' => 'string'
        ]);

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'level', 'message'],
            [
                [1, 1, 'msg 1'],
                [1, 2, 'msg 2'],
                [1, 3, 'msg 3'],
                [1, 4, 'msg 4'],
                [2, 1, 'msg 2.1'],
                [2, 2, 'msg 2.2'],
                [2, 3, 'msg 2.3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
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
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ]));
    }

    public function testSelectUsers(): void
    {
        $s = new Select($this->orm, User::class);
        $res = $s->orderBy('user.id')->fetchAll();

        $this->assertCount(2, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
        $this->assertSame('another@world.com', $res[1]->email);
    }

    public function testSelectUsersWithScope(): void
    {
        $s = new Select($this->orm, User::class);
        $res = $s->scope(new Select\QueryScope(['@.balance' => 100]))->fetchAll();

        $this->assertCount(1, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
    }

    public function testSelectUserScopeCanNotBeOverwritten(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope(['@.balance' => 100]));

        $res = $s->where('user.balance', 200)->fetchAll();

        $this->assertCount(0, $res);
    }

    public function testSelectUserScopeCanNotBeOverwritten2(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope(['@.balance' => 100]));

        $res = $s->orWhere('user.balance', 200)->fetchAll();

        $this->assertCount(0, $res);
    }

    public function testScopeWithOrderBy(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->fetchAll();

        $this->assertCount(2, $res);
        $this->assertSame('another@world.com', $res[0]->email);
        $this->assertSame('hello@world.com', $res[1]->email);
    }

    public function testRelated(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments')->fetchAll();

        [$b, $a] = $res;

        $this->captureReadQueries();
        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);
        $this->assertNumReads(0);

        $this->assertSame('msg 4', $a->comments[3]->message);
        $this->assertSame('msg 3', $a->comments[2]->message);
        $this->assertSame('msg 2', $a->comments[1]->message);
        $this->assertSame('msg 1', $a->comments[0]->message);

        $this->assertSame('msg 2.3', $b->comments[2]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testRelatedScope(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments', [
            'scope' => new Select\QueryScope(['@.level' => 4])
        ])->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(1, $a->comments);
        $this->assertCount(0, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
    }

    public function testRelatedScopeInload(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments', [
            'method'    => Select\JoinableLoader::INLOAD,
            'scope' => new Select\QueryScope(['@.level' => 4])
        ])->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(1, $a->comments);
        $this->assertCount(0, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
    }

    public function testRelatedScopeOrdered(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments', [
            'scope' => new Select\QueryScope(['@.level' => ['>=' => 3]], ['@.level' => 'DESC'])
        ])->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }

    public function testRelatedScopeOrderedInload(): void
    {
        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments', [
            'method'    => Select\JoinableLoader::INLOAD,
            'scope' => new Select\QueryScope(['@.level' => ['>=' => 3]], ['@.level' => 'DESC'])
        ])->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }

    public function testScopeViaMapperRelation(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
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
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => LeveledHasManyScope::class
            ]
        ]));

        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments')->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }

    public function testScopeViaMapperRelationInload(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
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
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => LeveledHasManyScope::class
            ]
        ]));

        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->load('comments', [
            'method' => Select\JoinableLoader::INLOAD,
        ])->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }

    public function testScopeViaMapperRelationPromise(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
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
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => LeveledHasManyScope::class
            ]
        ]));

        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }

    public function testScopeViaMapperRelationPromiseShort(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
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
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => ShortLeveledHasManyScope::class
            ]
        ]));

        $s = new Select($this->orm, User::class);
        $s->scope(new Select\QueryScope([], ['@.balance' => 'DESC']));

        $res = $s->fetchAll();

        [$b, $a] = $res;

        $this->assertCount(2, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
    }
}
