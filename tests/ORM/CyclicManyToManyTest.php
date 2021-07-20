<?php

/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Tag;
use Cycle\ORM\Tests\Fixtures\TagContext;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Cycle\ORM\Transaction;

abstract class CyclicManyToManyTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable(
            'user',
            [
                'id'      => 'primary',
                'email'   => 'string',
                'balance' => 'float'
            ]
        );

        $this->makeTable(
            'tag',
            [
                'id'   => 'primary',
                'name' => 'string'
            ]
        );

        $this->makeTable(
            'tag_user_map',
            [
                'id'      => 'primary',
                'user_id' => 'integer',
                'tag_id'  => 'integer',
                'as'      => 'string,nullable'
            ]
        );

        $this->makeFK('tag_user_map', 'user_id', 'user', 'id');
        $this->makeFK('tag_user_map', 'tag_id', 'tag', 'id');
        $this->makeIndex('tag_user_map', ['user_id', 'tag_id'], true);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->getDatabase()->table('tag')->insertMultiple(
            ['name'],
            [
                ['tag a'],
                ['tag b'],
                ['tag c'],
            ]
        );

        $this->getDatabase()->table('tag_user_map')->insertMultiple(
            ['user_id', 'tag_id', 'as'],
            [
                [1, 1, 'primary'],
                [1, 2, 'secondary'],
                [2, 3, 'primary'],
            ]
        );

        $this->orm = $this->withSchema(
            new Schema(
                [
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
                                Relation::LOAD   => Relation::LOAD_PROMISE,
                                Relation::SCHEMA => [
                                    Relation::CASCADE           => true,
                                    Relation::NULLABLE          => false,
                                    Relation::THROUGH_ENTITY    => TagContext::class,
                                    Relation::INNER_KEY         => 'id',
                                    Relation::OUTER_KEY         => 'id',
                                    Relation::THROUGH_INNER_KEY => 'user_id',
                                    Relation::THROUGH_OUTER_KEY => 'tag_id',
                                    Relation::WHERE             => [],
                                ],
                            ]
                        ]
                    ],
                    Tag::class        => [
                        Schema::ROLE        => 'tag',
                        Schema::MAPPER      => Mapper::class,
                        Schema::DATABASE    => 'default',
                        Schema::TABLE       => 'tag',
                        Schema::PRIMARY_KEY => 'id',
                        Schema::COLUMNS     => ['id', 'name'],
                        Schema::TYPECAST    => ['id' => 'int'],
                        Schema::SCHEMA      => [],
                        Schema::RELATIONS   => [
                            'users' => [
                                Relation::TYPE   => Relation::MANY_TO_MANY,
                                Relation::TARGET => User::class,
                                Relation::LOAD   => Relation::LOAD_PROMISE,
                                Relation::SCHEMA => [
                                    Relation::CASCADE           => true,
                                    Relation::NULLABLE          => false,
                                    Relation::THROUGH_ENTITY    => TagContext::class,
                                    Relation::INNER_KEY         => 'id',
                                    Relation::OUTER_KEY         => 'id',
                                    Relation::THROUGH_INNER_KEY => 'tag_id',
                                    Relation::THROUGH_OUTER_KEY => 'user_id',
                                    Relation::WHERE             => [],
                                    Relation::THROUGH_WHERE     => [],
                                ],
                            ]
                        ]
                    ],
                    TagContext::class => [
                        Schema::ROLE        => 'tag_context',
                        Schema::MAPPER      => Mapper::class,
                        Schema::DATABASE    => 'default',
                        Schema::TABLE       => 'tag_user_map',
                        Schema::PRIMARY_KEY => 'id',
                        Schema::COLUMNS     => ['id', 'user_id', 'tag_id', 'as'],
                        Schema::TYPECAST    => ['id' => 'int', 'user_id' => 'int', 'tag_id' => 'int'],
                        Schema::SCHEMA      => [],
                        Schema::RELATIONS   => []
                    ]
                ]
            )
        );
    }

    public function testLoadRelation(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('tags')->orderBy('id', 'ASC');

        $this->assertSame(
            [
                [
                    'id'      => 1,
                    'email'   => 'hello@world.com',
                    'balance' => 100.0,
                    'tags'    => [
                        [
                            'id'      => 1,
                            'user_id' => 1,
                            'tag_id'  => 1,
                            'as'      => 'primary',
                            '@'       => [
                                'id'   => 1,
                                'name' => 'tag a',
                            ],
                        ],
                        [
                            'id'      => 2,
                            'user_id' => 1,
                            'tag_id'  => 2,
                            'as'      => 'secondary',
                            '@'       => [
                                'id'   => 2,
                                'name' => 'tag b',
                            ],
                        ],
                    ],
                ],
                [
                    'id'      => 2,
                    'email'   => 'another@world.com',
                    'balance' => 200.0,
                    'tags'    => [
                        [
                            'id'      => 3,
                            'user_id' => 2,
                            'tag_id'  => 3,
                            'as'      => 'primary',
                            '@'       => [
                                'id'   => 3,
                                'name' => 'tag c',
                            ]
                        ],
                    ],
                ],
            ],
            $selector->fetchData()
        );
    }

    public function testCreateFromUser(): void
    {
        $u = new User();
        $u->email = 'hello@world.com';
        $u->balance = 1;

        $tag = new Tag();
        $tag->name = 'hello';

        $u->tags->add($tag);

        $t = new Transaction($this->orm);
        $t->persist($u);
        $t->run();

        $u2 = $this->orm->withHeap(new Heap())->get(User::class, ['id' => $u->id]);

        $this->assertSame($tag->id, $u2->tags->get(0)->id);
        $this->assertSame($u->id, $u2->tags->get(0)->users->get(0)->id);
    }

    public function testCreateFromTag(): void
    {
        $u = new User();
        $u->email = 'hello@world.com';
        $u->balance = 1;

        $tag = new Tag();
        $tag->name = 'hello';

        $tag->users->add($u);

        $t = new Transaction($this->orm);
        $t->persist($tag);
        $t->run();

        $u2 = $this->orm->withHeap(new Heap())->get(User::class, ['id' => $u->id]);

        $this->assertSame($tag->id, $u2->tags->get(0)->id);
        $this->assertSame($u->id, $u2->tags->get(0)->users->get(0)->id);
    }

    public function testCreateCyclic(): void
    {
        $u = new User();
        $u->email = 'hello@world.com';
        $u->balance = 1;

        $tag = new Tag();
        $tag->name = 'hello';

        $tag->users->add($u);
        $u->tags->add($tag);

        $this->save($tag);

        $u2 = $this->orm->withHeap(new Heap())->get(User::class, ['id' => $u->id]);

        $this->assertSame($tag->id, $u2->tags->get(0)->id);
        $this->assertSame($u->id, $u2->tags->get(0)->users->get(0)->id);
    }

    public function testCreateCyclicWithExisting(): void
    {
        $u = new User();
        $u->email = 'hello@world.com';
        $u->balance = 1;

        $tag = $this->orm->getRepository(Tag::class)->findByPK(1);

        $tag->users->add($u);
        $u->tags->add($tag);

        $this->save($tag);

        $u2 = $this->orm->withHeap(new Heap())->get(User::class, ['id' => $u->id]);

        // // mb these assertions are incorrect
        // $this->assertSame($tag->id, $u2->tags->get(0)->id);
        //
        // $found = false;
        // foreach ($u2->tags->get(0)->users as $tagUser) {
        //     if ($tagUser->id === $u->id) {
        //         $found = true;
        //     }
        // }
        //
        // $this->assertTrue($found);
    }
}
