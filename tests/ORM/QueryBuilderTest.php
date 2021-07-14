<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Spiral\Database\Injection\Parameter;

abstract class QueryBuilderTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id_int'        => 'primary',
            'email_str'     => 'string',
            'balance_float' => 'float'
        ]);

        $this->makeTable('comment', [
            'id_int'      => 'primary',
            'user_id_int' => 'integer',
            'message_str' => 'string'
        ]);

        $this->makeFK('comment', 'user_id_int', 'user', 'id_int');

        $this->getDatabase()->table('user')->insertMultiple(
            ['email_str', 'balance_float'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id_int', 'message_str'],
            [
                [1, 'msg 1'],
                [1, 'msg 2'],
                [2, 'msg 3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id' => 'id_int', 'email' => 'email_str', 'balance' => 'balance_float'],
                Schema::SCHEMA      => [],
                Schema::TYPECAST    => [
                    'id'      => 'int',
                    'active'  => 'bool',
                    'balance' => 'float'
                ],
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
                Schema::COLUMNS     => ['id' => 'id_int', 'user_id' => 'user_id_int', 'message' => 'message_str'],
                Schema::TYPECAST    => [
                    'id'      => 'int',
                    'user_id' => 'int'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => SortByIDScope::class
            ]
        ]));
    }

    public function testFetchRelation(): void
    {
        $selector = new Select($this->orm, User::class);
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
                ],
            ],
            [
                'id'       => 2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [
                    [
                        'id'      => 3,
                        'user_id' => 2,
                        'message' => 'msg 3',
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    public function testSimpleWhere(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select->where('id', 2)->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testOrderBy(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testOrderByArray(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->orderBy([
            'id' => 'DESC'
        ])->fetchAll();

        $this->assertSame(2, $a->id);
        $this->assertSame(1, $b->id);
    }

    public function testOrWhere(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->where('id', 2)->orWhere('id', 1)->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereIN(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->where('id', new Parameter([1, 2]))->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereShortSyntax(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select->where([
            'id' => 2
        ])->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testWhereShortSyntaxIn(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->where([
            'id' => new Parameter([1, 2])
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testInComplexArray(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->where([
            'id' => ['>' => 0, '<' => 3]
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testInComplexArrayVerify(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select->where([
            'user.id' => [
                '>'  => 1,
                '<=' => 2
            ]
        ])->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testRelationOr(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where('comments.message', 'msg 3')
            ->orWhere('comments.message', 'msg 1')
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testRelationComplexParameter(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where([
                'comments.message' => new Parameter(['msg 3', 'msg 1'])
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testRelationAliased(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments', ['as' => 'c'])
            ->where([
                'c.message' => new Parameter(['msg 3', 'msg 1'])
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testRelationComplexArray(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where([
                'comments.id' => [
                    '>=' => 1,
                    '<'  => 4
                ]
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }


    public function testRelationComplexArrayAliased(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments', ['as' => 'cc'])
            ->where([
                'cc.id' => [
                    '>=' => 1,
                    '<'  => 4
                ]
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testRelationComplexArrayVerify(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select
            ->with('comments')
            ->where([
                'comments.id' => [
                    '>=' => 3,
                    '<'  => 4
                ]
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testWhereClosure(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select
            ->where(function (Select\QueryBuilder $q): void {
                $q->where('id', 2);
            })
            ->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testWhereClosureRelation(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select
            ->with('comments')
            ->where(function (Select\QueryBuilder $q): void {
                $q->where('comments.message', 'msg 3');
            })
            ->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testRelationComplexArrayOR(): void
    {
        $select = new Select($this->orm, User::class);

        [$a, $b] = $select
            ->with('comments')
            ->where([
                '@or' => [
                    ['comments.message' => 'msg 1'],
                    ['comments.message' => 'msg 3'],
                ],
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testRelationComplexArrayORAliased(): void
    {
        $select = new Select($this->orm, User::class);

        [$a, $b] = $select
            ->with('comments', ['as' => 'comment_relation'])
            ->where([
                '@or' => [
                    ['comments.message' => 'msg 1'],
                    ['comments.message' => 'msg 3'],
                ],
            ])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereClosureOr(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->where(function (Select\QueryBuilder $q): void {
                $q->where('id', 2);
            })
            ->orWhere(function (Select\QueryBuilder $q): void {
                $q->where('id', 1);
            })
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereClosureRelationWithOr(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where(function (Select\QueryBuilder $q): void {
                $q->where('comments.message', 'msg 3')->orWhere('id', 1);
            })
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testBetween(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where('comments.id', 'between', 1, 4)
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testBetweenArray(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where(['comments.id' => ['between' => [1, 4]]])
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereNestedClosure(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select
            ->with('comments')
            ->where(function (Select\QueryBuilder $q): void {
                $q->where('comments.message', 'msg 3')->orWhere(function (Select\QueryBuilder $q): void {
                    $q->where('id', 1);
                });
            })
            ->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereForRelation(): void
    {
        $select = new Select($this->orm, User::class);
        [$a] = $select->with('comments', [
            'where' => function (Select\QueryBuilder $q): void {
                $q->where('message', 'msg 3');
            }
        ])->orderBy('id')->fetchAll();

        $this->assertSame(2, $a->id);
    }

    public function testWhereForRelationVerify(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->with('comments', [
            'where' => function (Select\QueryBuilder $q): void {
                $q->where('message', 'msg 3')->orWhere('message', 'msg 1');
            }
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testWhereForRelationArray(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->with('comments', [
            'where' => ['id' => ['in' => new Parameter([1, 3])]]
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
    }

    public function testLoadRelationsBuilder(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->load('comments', [
            'where' => ['message' => 'msg 1']
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);

        $this->assertCount(1, $a->comments);
        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertCount(0, $b->comments);
    }

    public function testLoadRelationsBuilderMultiple(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->load('comments', [
            'where' => function ($q): void {
                $q->where('message', new Parameter(['msg 1', 'msg 3']));
            }
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);

        $this->assertCount(1, $a->comments);
        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertCount(1, $b->comments);
        $this->assertSame('msg 3', $b->comments[0]->message);
    }

    public function testLoadRelationsBuilderInload(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->load('comments', [
            'where'  => ['message' => 'msg 1'],
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);

        $this->assertCount(1, $a->comments);
        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertCount(0, $b->comments);
    }

    public function testLoadRelationsBuilderInloadVerify(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->load('comments', [
            'where'  => ['message' => 'msg 3'],
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);

        $this->assertCount(0, $a->comments);
        $this->assertCount(1, $b->comments);
        $this->assertSame('msg 3', $b->comments[0]->message);
    }

    public function testLoadRelationsBuilderMultipleInload(): void
    {
        $select = new Select($this->orm, User::class);
        [$a, $b] = $select->load('comments', [
            'where'  => function (Select\QueryBuilder $q): void {
                $q->where('message', new Parameter(['msg 1', 'msg 3']));
            },
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('id')->fetchAll();

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);


        $this->assertCount(1, $a->comments);
        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertCount(1, $b->comments);
        $this->assertSame('msg 3', $b->comments[0]->message);
    }
}
