<?php

/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\Post;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Spiral\Database\Injection\Expression;

abstract class HasManyNestedConditionTest extends BaseTest
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


        $this->makeTable('post', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'title'   => 'string'
        ]);

        $this->makeTable('comment', [
            'id'      => 'primary',
            'user_id' => 'integer,null',
            'post_id' => 'integer',
            'message' => 'string'
        ]);

        $this->makeFK('post', 'user_id', 'user', 'id', 'NO ACTION', 'NO ACTION');
        $this->makeFK('comment', 'user_id', 'user', 'id', 'NO ACTION', 'NO ACTION');
        $this->makeFK('comment', 'post_id', 'post', 'id', 'NO ACTION', 'NO ACTION');

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->getDatabase()->table('post')->insertMultiple(
            ['user_id', 'title'],
            [
                [1, 'post 1'],
                [1, 'post 2'],
                [1, 'post 3'],
                [2, 'post 4'],
            ]
        );

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'post_id', 'message'],
            [
                [1, 1, 'msg 1'],
                [2, 1, 'msg 2'],
                [2, 2, 'msg 3'],
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
                    'posts' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Post::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id',
                        ],
                    ]
                ]
            ],
            Post::class    => [
                Schema::ROLE        => 'post',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'post',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'title'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'post_id',
                        ],
                    ]
                ],
                Schema::SCOPE   => SortByIDScope::class
            ],
            Comment::class => [
                Schema::ROLE        => 'comment',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'post_id', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
            ]
        ]));
    }

    public function testFetchRelation(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('posts.comments')->orderBy('user.id');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'posts'   => [
                    [
                        'id'       => 1,
                        'user_id'  => 1,
                        'title'    => 'post 1',
                        'comments' => [
                            [
                                'id'      => 1,
                                'user_id' => 1,
                                'post_id' => 1,
                                'message' => 'msg 1',
                            ],
                            [
                                'id'      => 2,
                                'user_id' => 2,
                                'post_id' => 1,
                                'message' => 'msg 2',
                            ],
                        ],
                    ],
                    [
                        'id'       => 2,
                        'user_id'  => 1,
                        'title'    => 'post 2',
                        'comments' => [
                            [
                                'id'      => 3,
                                'user_id' => 2,
                                'post_id' => 2,
                                'message' => 'msg 3',
                            ],
                        ],
                    ],
                    [
                        'id'       => 3,
                        'user_id'  => 1,
                        'title'    => 'post 3',
                        'comments' =>
                            [
                            ],
                    ],
                ],
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
                'posts'   => [
                    [
                        'id'       => 4,
                        'user_id'  => 2,
                        'title'    => 'post 4',
                        'comments' => []
                    ],
                ]
            ],
        ], $selector->fetchData());
    }

    // only load posts with comments
    public function testFetchFiltered(): void
    {
        $users = new Select($this->orm, User::class);
        $users->load('posts', [
            'where' => function (Select\QueryBuilder $qb): void {
                $qb->distinct()->where('comments.id', '!=', null);
            }
        ])->orderBy('user.id');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'posts'   => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'title'   => 'post 1'
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'title'   => 'post 2',
                    ],
                ],
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
                'posts'   => [],
            ],
        ], $users->fetchData());
    }

    // only load posts without comments
    public function testFetchFilteredNoComments(): void
    {
        $users = new Select($this->orm, User::class);
        $users->load('posts', [
            'where' => function (Select\QueryBuilder $qb): void {
                $qb->distinct()
                   ->with('comments', ['method' => Select\JoinableLoader::LEFT_JOIN])
                   ->where('comments.id', '=', null);
            }
        ])->orderBy('user.id');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'posts'   => [
                    [
                        'id'      => 3,
                        'user_id' => 1,
                        'title'   => 'post 3',
                    ],
                ],
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
                'posts'   => [
                    [
                        'id'      => 4,
                        'user_id' => 2,
                        'title'   => 'post 4',
                    ],
                ],
            ],
        ], $users->fetchData());
    }

    // only load posts with comments left by post author
    public function testFetchCrossLinked(): void
    {
        $users = new Select($this->orm, User::class);
        $users->load('posts', [
            'where' => function (Select\QueryBuilder $qb): void {
                $qb->distinct()
                   ->where(
                       'comments.user_id',
                       '=',
                       new Expression($qb->resolve('user_id'))
                   );
            }
        ])->orderBy('user.id');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'posts'   => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'title'   => 'post 1'
                    ],
                ],
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
                'posts'   => [],
            ],
        ], $users->fetchData());
    }

    // find all users which have posts without comments and load all posts with comments
    public function testFindUsersWithoutPostCommentsLoadPostsWithComments(): void
    {
        $users = new Select($this->orm, User::class);
        $users
            ->with('posts.comments', [
                'method' => Select\JoinableLoader::LEFT_JOIN,
            ])
            ->where('posts.comments.id', null)
            ->load('posts', [
                'where' => function (Select\QueryBuilder $qb): void {
                    $qb->distinct()->where('comments.id', '!=', null);
                }
            ])->orderBy('user.id');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'posts'   => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'title'   => 'post 1'
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'title'   => 'post 2'
                    ],
                ],
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
                'posts'   => [],
            ],
        ], $users->fetchData());
    }
}
