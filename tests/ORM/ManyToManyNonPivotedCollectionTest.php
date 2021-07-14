<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Config\RelationConfig;
use Cycle\ORM\Factory;
use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Tag;
use Cycle\ORM\Tests\Fixtures\TagContext;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;

abstract class ManyToManyNonPivotedCollectionTest extends BaseTest
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
            'id'   => 'primary',
            'name' => 'string'
        ]);

        $this->makeTable('tag_user_map', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'tag_id'  => 'integer',
            'as'      => 'string,nullable'
        ]);

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

        $this->orm = $this->createOrm()
            ->withSchema(new Schema($this->getSchemaArray()));
    }

    public function testInitRelation(): void
    {
        $u = $this->orm->make(User::class);
        $this->assertIsArray($u->tags);
        $this->assertCount(0, $u->tags);
    }

    public function testLoadRelation(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('tags')->orderBy('id', 'ASC');

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
        ], $selector->fetchData());
    }

    public function testWithNoColumns(): void
    {
        $selector = new Select($this->orm, User::class);
        $data = $selector->with('tags')->buildQuery()->fetchAll();

        $this->assertSame(3, count($data[0]));
    }

    public function testLoadRelationInload(): void
    {
        $selector = new Select($this->orm, User::class);
        $selector->load('tags', [
            'method'    => Select\JoinableLoader::INLOAD,
            'scope' => new Select\QueryScope([], ['id' => 'ASC'])
        ])->orderBy(['id' => 'ASC']);

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
        ], $selector->fetchData());
    }

    public function testSelectArray(): void
    {
        /**
         * @var User $a
         * @var User $b
         */
        [$a, $b] = (new Select($this->orm, User::class))->load('tags')->fetchAll();

        $this->assertCount(2, $a->tags);
        $this->assertCount(1, $b->tags);

        $this->assertIsArray($a->tags);
        $this->assertIsArray($b->tags);
    }

    public function testCreateWithManyToManyCascadeNoContext(): void
    {
        $u = new User();
        $u->email = 'many@email.com';
        $u->balance = 900;

        $t = new Tag();
        $t->name = 'my tag';

        $u->tags->add($t);

        $this->save($u);

        $this->captureWriteQueries();
        $this->save($u);
        $this->assertNumWrites(0);

        $selector = new Select($this->orm->withHeap(new Heap()), User::class);
        $u = $selector->load('tags')->wherePK(3)->fetchOne();

        $this->assertSame('many@email.com', $u->email);
        $this->assertCount(1, $u->tags);
        $this->assertSame('my tag', $u->tags[0]->name);
    }

    public function testCreateWithManyToMany2(): void
    {
        $schemaArray = $this->getSchemaArray();
        $schemaArray[User::class][Schema::RELATIONS]['tags'][Relation::SCHEMA][Relation::NULLABLE] = true;
        $this->orm = $this->orm->withSchema(new Schema($schemaArray));

        $u = new User();
        $u->email = 'many@email.com';
        $u->balance = 900;

        $t1 = new Tag();
        $t1->name = 'my tag 1';
        $t2 = new Tag();
        $t2->name = 'my tag 2';
        $u->tags = [$t1, $t2];

        $this->save($u);

        $this->captureWriteQueries();
        $this->save($u);
        $this->assertNumWrites(0);

        $u = (new Select($this->orm->withHeap(new Heap()), User::class))
            ->load('tags')->wherePK(3)->fetchOne();

        $this->assertSame('many@email.com', $u->email);
        $this->assertCount(2, $u->tags);
        $this->assertSame('my tag 1', $u->tags[0]->name);
        $this->assertSame('my tag 2', $u->tags[1]->name);
    }

    public function testUnlinkManyToManyAndReplaceSome(): void
    {
        /**
         * @var User $a
         * @var User $b
         */
        [$a, $b] = (new Select($this->orm, User::class))->load('tags')->fetchAll();

        unset($a->tags[0]);
        $a->tags[] = (new Select($this->orm, Tag::class))->wherePK(3)->fetchOne();

        $t = new Tag();
        $t->name = 'new tag';

        $b->tags = [$t];

        $this->captureWriteQueries();
        $this->save($a, $b);
        $this->assertNumWrites(5);

        $this->captureWriteQueries();
        $this->save($a, $b);
        $this->assertNumWrites(0);

        /**
         * @var User $a
         * @var User $b
         */
        [$a, $b] = (new Select($this->orm->withHeap(new Heap()), User::class))->load('tags')->fetchAll();

        $this->assertCount(2, $a->tags);
        $this->assertSame('tag b', $a->tags[0]->name);
        $this->assertSame('tag c', $a->tags[1]->name);

        $this->assertCount(1, $b->tags);
        $this->assertSame('new tag', $b->tags[0]->name);
    }

    public function testReassign(): void
    {
        $tagSelect = new Select($this->orm, Tag::class);
        $userSelect = new Select($this->orm, User::class);

        /** @var User $user */
        $user = $userSelect->load('tags')->fetchOne(['id' => 1]);

        $this->captureWriteQueries();
        $this->save($user);
        $this->assertNumWrites(0);

        $wantTags = ['tag a', 'tag c'];

        foreach ($wantTags as $wantTag) {
            $found = false;

            foreach ($user->tags as $tag) {
                if ($tag->name === $wantTag) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $newTag = $tagSelect->fetchOne(['name' => $wantTag]);
                $user->tags[] = $newTag;
            }
        }

        $user->tags =
        array_filter($user->tags, function ($t) use ($wantTags) {
            return in_array($t->name, $wantTags);
        });

        $this->captureWriteQueries();
        $this->save($user);
        $this->assertNumWrites(2);

        $this->orm = $this->orm->withHeap(new Heap());

        $user = (new Select($this->orm, User::class))->fetchOne(['id' => 1]);
        $this->assertCount(2, $user->tags);
        $this->assertSame('tag a', $user->tags[0]->name);
        $this->assertSame('tag c', $user->tags[1]->name);
    }

    private function createOrm(): ORMInterface
    {
        return new ORM(
            (new Factory(
                $this->dbal,
                RelationConfig::getDefault(),
                null,
                new \Cycle\ORM\Collection\ArrayCollectionFactory()
            ))
        );
    }

    private function getSchemaArray(): array
    {
        return [
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
                Schema::RELATIONS   => []
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
        ];
    }
}
