<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\SortByLevelDESCScope;
use Cycle\ORM\Tests\Fixtures\Tag;
use Cycle\ORM\Tests\Fixtures\TagContext;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;

abstract class ManyToManyScopedPivotTest extends BaseTest
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

        $this->makeTable('tag', [
            'id'    => 'primary',
            'level' => 'integer',
            'name'  => 'string'
        ]);

        $this->makeTable('tag_user_map', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'tag_id'  => 'integer',
            'as'      => 'string,nullable',
            'level'   => 'int'
        ]);

        $this->makeFK('tag_user_map', 'user_id', 'user', 'id');
        $this->makeFK('tag_user_map', 'tag_id', 'tag', 'id');

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->getDatabase()->table('tag')->insertMultiple(
            ['name', 'level'],
            [
                ['tag a', 1],
                ['tag b', 2],
                ['tag c', 3],
                ['tag d', 4],
                ['tag e', 5],
                ['tag f', 6],
            ]
        );

        $this->getDatabase()->table('tag_user_map')->insertMultiple(
            ['user_id', 'tag_id', 'level'],
            [
                [1, 1, 1],
                [1, 2, 2],
                [2, 3, 1],

                [1, 4, 3],
                [1, 5, 4],

                [2, 4, 2],
                [2, 6, 3],
            ]
        );
    }

    public function testLoadRelation(): void
    {
        $this->orm = $this->withPivotSchema([

        ]);

        $selector = new Select($this->orm, User::class);
        $selector->load('tags')
                 ->orderBy('id')
                 ->orderBy('tags.id');

        $this->assertSame([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'tags'    => [

                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'tag_id'  => 1,
                        'as'      => null,
                        'level'   => 1,
                        '@'       => [
                            'id'    => 1,
                            'name'  => 'tag a',
                            'level' => 1,
                        ],
                    ],

                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'tag_id'  => 2,
                        'as'      => null,
                        'level'   => 2,
                        '@'       => [
                            'id'    => 2,
                            'name'  => 'tag b',
                            'level' => 2,
                        ],
                    ],
                    [
                        'id'      => 4,
                        'user_id' => 1,
                        'tag_id'  => 4,
                        'as'      => null,
                        'level'   => 3,
                        '@'       => [
                            'id'    => 4,
                            'name'  => 'tag d',
                            'level' => 4,
                        ],
                    ],
                    [
                        'id'      => 5,
                        'user_id' => 1,
                        'tag_id'  => 5,
                        'as'      => null,
                        'level'   => 4,
                        '@'       => [
                            'id'    => 5,
                            'name'  => 'tag e',
                            'level' => 5,
                        ],
                    ],
                ],
            ],
            1 =>
                [
                    'id'      => 2,
                    'email'   => 'another@world.com',
                    'balance' => 200.0,
                    'tags'    => [
                        [
                            'id'      => 3,
                            'user_id' => 2,
                            'tag_id'  => 3,
                            'as'      => null,
                            'level'   => 1,
                            '@'       => [
                                'id'    => 3,
                                'name'  => 'tag c',
                                'level' => 3,
                            ],
                        ],
                        [
                            'id'      => 6,
                            'user_id' => 2,
                            'tag_id'  => 4,
                            'as'      => null,
                            'level'   => 2,
                            '@'       => [
                                'id'    => 4,
                                'name'  => 'tag d',
                                'level' => 4,
                            ],
                        ],
                        [
                            'id'      => 7,
                            'user_id' => 2,
                            'tag_id'  => 6,
                            'as'      => null,
                            'level'   => 3,
                            '@'       => [
                                'id'    => 6,
                                'name'  => 'tag f',
                                'level' => 6,
                            ],
                        ],
                    ],
                ],
        ], $selector->fetchData());
    }

    public function testLoadRelationOrderByPivotColumn(): void
    {
        $this->orm = $this->withPivotSchema([
            Schema::SCOPE => SortByLevelDESCScope::class,
        ]);

        $selector = new Select($this->orm, User::class);
        $selector->load('tags')->orderBy('id');

        $this->assertSame([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'tags'    => [
                    [
                        'id'      => 5,
                        'user_id' => 1,
                        'tag_id'  => 5,
                        'as'      => null,
                        'level'   => 4,
                        '@'       => [
                            'id'    => 5,
                            'name'  => 'tag e',
                            'level' => 5,
                        ],
                    ],
                    [
                        'id'      => 4,
                        'user_id' => 1,
                        'tag_id'  => 4,
                        'as'      => null,
                        'level'   => 3,
                        '@'       => [
                            'id'    => 4,
                            'name'  => 'tag d',
                            'level' => 4,
                        ],
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'tag_id'  => 2,
                        'as'      => null,
                        'level'   => 2,
                        '@'       => [
                            'id'    => 2,
                            'name'  => 'tag b',
                            'level' => 2,
                        ],
                    ],
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'tag_id'  => 1,
                        'as'      => null,
                        'level'   => 1,
                        '@'       => [
                            'id'    => 1,
                            'name'  => 'tag a',
                            'level' => 1,
                        ],
                    ],
                ],
            ],
            1 =>
                [
                    'id'      => 2,
                    'email'   => 'another@world.com',
                    'balance' => 200.0,
                    'tags'    => [
                        [
                            'id'      => 7,
                            'user_id' => 2,
                            'tag_id'  => 6,
                            'as'      => null,
                            'level'   => 3,
                            '@'       => [
                                'id'    => 6,
                                'name'  => 'tag f',
                                'level' => 6,
                            ],
                        ],
                        [
                            'id'      => 6,
                            'user_id' => 2,
                            'tag_id'  => 4,
                            'as'      => null,
                            'level'   => 2,
                            '@'       => [
                                'id'    => 4,
                                'name'  => 'tag d',
                                'level' => 4,
                            ],
                        ],
                        [
                            'id'      => 3,
                            'user_id' => 2,
                            'tag_id'  => 3,
                            'as'      => null,
                            'level'   => 1,
                            '@'       => [
                                'id'    => 3,
                                'name'  => 'tag c',
                                'level' => 3,
                            ],
                        ],
                    ],
                ],
        ], $selector->fetchData());
    }

    public function testLoaderRelationWithScope(): void
    {
        $this->orm = $this->withPivotSchema([
            Schema::SCOPE => new Select\QueryScope(
                ['@.level' => ['>' => 3]],
                ['@.level' => 'DESC']
            ),
        ]);

        $selector = new Select($this->orm, User::class);
        $selector
            ->with('tags')
            ->load('tags')
            ->orderBy('id');

        $this->assertSame([
            [
                'id'      => 1,
                'email'   => 'hello@world.com',
                'balance' => 100.0,
                'tags'    => [
                    [
                        'id'      => 5,
                        'user_id' => 1,
                        'tag_id'  => 5,
                        'as'      => null,
                        'level'   => 4,
                        '@'       => [
                            'id'    => 5,
                            'name'  => 'tag e',
                            'level' => 5,
                        ],
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    protected function withPivotSchema(array $pivotSchema, array $relSchema = [])
    {
        return $this->withSchema(new Schema([
            User::class       => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::TYPECAST    => ['id' => 'int', 'balance' => 'float'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'tags' => [
                        Relation::TYPE   => Relation::MANY_TO_MANY,
                        Relation::TARGET => Tag::class,
                        Relation::SCHEMA => [
                                Relation::CASCADE           => true,
                                Relation::THROUGH_ENTITY    => TagContext::class,
                                Relation::INNER_KEY         => 'id',
                                Relation::OUTER_KEY         => 'id',
                                Relation::THROUGH_INNER_KEY => 'user_id',
                                Relation::THROUGH_OUTER_KEY => 'tag_id',
                            ] + $relSchema,
                    ]
                ]
            ],
            Tag::class        => [
                Schema::ROLE        => 'tag',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'tag',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'name', 'level'],
                Schema::TYPECAST    => ['id' => 'int', 'level' => 'int'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ],
            TagContext::class => [
                    Schema::ROLE        => 'tag_context',
                    Schema::MAPPER      => Mapper::class,
                    Schema::DATABASE    => 'default',
                    Schema::TABLE       => 'tag_user_map',
                    Schema::PRIMARY_KEY => 'id',
                    Schema::COLUMNS     => ['id', 'user_id', 'tag_id', 'as', 'level'],
                    Schema::TYPECAST    => ['id' => 'int', 'user_id' => 'int', 'tag_id' => 'int', 'level' => 'int'],
                    Schema::SCHEMA      => [],
                    Schema::RELATIONS   => [],
                ] + $pivotSchema
        ]));
    }
}
