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
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Fixtures\UserCredentials;
use Cycle\ORM\Tests\Traits\TableTrait;

abstract class EmbeddedLoaderTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'               => 'primary',
            'email'            => 'string',
            'balance'          => 'float',
            'creds_username'   => 'string',
            'creds_password'   => 'string',
            'creds_num_logins' => 'int'
        ]);

        $this->makeTable('comment', [
            'id'      => 'primary',
            'user_id' => 'integer,null',
            'message' => 'string'
        ]);

        $this->makeFK('comment', 'user_id', 'user', 'id');

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance', 'creds_username', 'creds_password', 'creds_num_logins'],
            [
                ['hello@world.com', 100, 'user1', 'pass1', 0],
                ['another@world.com', 200, 'user2', 'pass2', 1],
            ]
        );

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'message'],
            [
                [1, 'msg 1'],
                [1, 'msg 2'],
                [2, 'msg 3'],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            User::class            => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'credentials' => [
                        Relation::TYPE   => Relation::EMBEDDED,
                        Relation::TARGET => UserCredentials::class,
                        Relation::LOAD   => null,
                        Relation::SCHEMA => [],
                    ],
                    'comments'    => [
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
            UserCredentials::class => [
                Schema::ROLE        => 'user_credentials',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => [
                    'id'         => 'id',
                    'username'   => 'creds_username',
                    'password'   => 'creds_password',
                    'num_logins' => 'creds_num_logins'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ],
            Comment::class         => [
                Schema::ROLE        => 'comment',
                Schema::MAPPER      => Mapper::class,
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

    public function testLoadDataNoRelation(): void
    {
        $selector = new Select($this->orm, User::class);

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
            ],
            [
                'id'      => 2,
                'email'   => 'another@world.com',
                'balance' => 200.0,
            ]
        ], $selector->fetchData());
    }

    public function testLoadDataLoadRelation(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('credentials');

        $this->assertEquals([
            [
                'id'          => 1,
                'email'       => 'hello@world.com',
                'balance'     => 100.0,
                'credentials' => [
                    'username'   => 'user1',
                    'password'   => 'pass1',
                    'num_logins' => 0
                ]
            ],
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ]
            ]
        ], $selector->fetchData());
    }

    public function testLoadDataLoadAutomatically(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class            => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'credentials' => [
                        Relation::TYPE   => Relation::EMBEDDED,
                        Relation::TARGET => UserCredentials::class,
                        Relation::LOAD   => Relation::LOAD_EAGER,
                        Relation::SCHEMA => [
                        ],
                    ]
                ]
            ],
            UserCredentials::class => [
                Schema::ROLE        => 'user_credentials',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => [
                    'id'         => 'id',
                    'username'   => 'creds_username',
                    'password'   => 'creds_password',
                    'num_logins' => 'creds_num_logins'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ]));

        $selector = new Select($this->orm, User::class);

        $this->assertEquals([
            [
                'id'          => 1,
                'email'       => 'hello@world.com',
                'balance'     => 100.0,
                'credentials' => [
                    'username'   => 'user1',
                    'password'   => 'pass1',
                    'num_logins' => 0
                ]
            ],
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ]
            ]
        ], $selector->fetchData());
    }

    public function testLoadDataLoadTypecast(): void
    {
        $this->orm = $this->withSchema(new Schema([
            User::class            => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::TYPECAST    => [
                    'id'      => 'int',
                    'balance' => 'float'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'credentials' => [
                        Relation::TYPE   => Relation::EMBEDDED,
                        Relation::TARGET => UserCredentials::class,
                        Relation::LOAD   => Relation::LOAD_EAGER,
                        Relation::SCHEMA => [
                        ],
                    ]
                ]
            ],
            UserCredentials::class => [
                Schema::ROLE        => 'user:credentials',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => [
                    'id'         => 'int',
                    'username'   => 'creds_username',
                    'password'   => 'creds_password',
                    'num_logins' => 'creds_num_logins'
                ],
                Schema::TYPECAST    => [
                    'num_logins' => 'int'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ]));

        $selector = new Select($this->orm, User::class);

        $this->assertSame([
            [
                'id'          => 1,
                'email'       => 'hello@world.com',
                'balance'     => 100.0,
                'credentials' => [
                    'username'   => 'user1',
                    'password'   => 'pass1',
                    'num_logins' => 0
                ]
            ],
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ]
            ]
        ], $selector->fetchData());
    }

    public function testFilterByEmbeddedDoNotLoad(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->where('credentials.username', 'user1');

        $this->assertEquals([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
            ],
        ], $selector->fetchData());
    }

    public function testFilterAndLoad(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector
            ->where('credentials.username', 'user2')
            ->load('credentials');

        $this->assertEquals([
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ]
            ]
        ], $selector->fetchData());
    }

    public function testDeduplicate(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector
            ->load('comments')
            ->load('credentials');

        $this->assertEquals([
            [
                'id'          => 1,
                'email'       => 'hello@world.com',
                'balance'     => 100.0,
                'comments'    => [
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
                'credentials' => [
                    'username'   => 'user1',
                    'password'   => 'pass1',
                    'num_logins' => 0
                ],
            ],
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'comments'    => [
                    [
                        'id'      => 3,
                        'user_id' => 2,
                        'message' => 'msg 3',
                    ],
                ],
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ],
            ],
        ], $selector->fetchData());
    }

    public function testDeduplicateInload(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector
            ->load('comments', ['method' => Select\JoinableLoader::INLOAD])
            ->load('credentials');

        $this->assertEquals([
            [
                'id'          => 1,
                'email'       => 'hello@world.com',
                'balance'     => 100.0,
                'comments'    => [
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
                'credentials' => [
                    'username'   => 'user1',
                    'password'   => 'pass1',
                    'num_logins' => 0
                ],
            ],
            [
                'id'          => 2,
                'email'       => 'another@world.com',
                'balance'     => 200.0,
                'comments'    => [
                    [
                        'id'      => 3,
                        'user_id' => 2,
                        'message' => 'msg 3',
                    ],
                ],
                'credentials' => [
                    'username'   => 'user2',
                    'password'   => 'pass2',
                    'num_logins' => 1
                ],
            ],
        ], $selector->fetchData());
    }
}
