<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\Post;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\Tag;
use Cycle\ORM\Tests\Fixtures\TagContext;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * This tests provides ability to create deep linked trees using pivoted entity.
 * We did not plan to have such functionality
 * but the side effect of having pivot loader to behave as normal made it possible.
 */
abstract class ManyToManyBelongsToTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'email'   => 'string',
        ]);

        $this->makeTable('post', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'title'   => 'string',
        ]);

        $this->makeTable('tag', [
            'id'   => 'primary',
            'name' => 'string'
        ]);

        $this->makeTable('tag_post_map', [
            'id'      => 'primary',
            'post_id' => 'integer',
            'tag_id'  => 'integer',
            'as'      => 'string,nullable'
        ]);

        $this->makeFK('post', 'user_id', 'user', 'id');
        $this->makeFK('tag_post_map', 'post_id', 'post', 'id');
        $this->makeFK('tag_post_map', 'tag_id', 'tag', 'id');

        $this->getDatabase()->table('user')->insertMultiple(
            ['email'],
            [
                ['hello@world.com'],
                ['another@world.com'],
            ]
        );
        $this->getDatabase()->table('post')->insertMultiple(
            ['user_id', 'title'],
            [
                [1, 'Post title 1-1'],
                [1, 'Post title 1-2'],
                [2, 'Post title 2-1'],
                [2, 'Post title 2-2'],
                [2, 'Post title 2-3'],
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

        $this->getDatabase()->table('tag_post_map')->insertMultiple(
            ['post_id', 'tag_id', 'as'],
            [
                [1, 1, 'primary'],
                [1, 2, 'secondary'],
                [2, 3, 'primary'],
            ]
        );

        $this->orm = $this->withSchema(new Schema($this->getSchemaArray()));
    }

    public function testCreate(): void
    {
        $user = new User();
        $user->email = 'test';

        $post = new Post();
        $post->title = 'test title';
        $post->user = $user;
        $post->comments = new ArrayCollection(
            [
                new Tag(),
                new Tag()
            ]
        );
        $post->comments[0]->name = 'name 1';
        $post->comments[1]->name = 'name 2';

        $collection = $post->comments;
        $this->save($post);
        // $this->assertSame($this->orm->getHeap()->get($post)->getRelation('comments'), $post->comments);
        $this->assertInstanceOf(ArrayCollection::class, $post->comments);
        $this->assertSame($collection, $post->comments);

        $postId = $post->id;

        $this->orm->getHeap()->clean();

        $p = (new Select($this->orm, Post::class))
            ->wherePK($postId)->fetchOne();

        $this->assertSame('name 1', $p->comments[0]->name);
        $this->assertSame('name 2', $p->comments[1]->name);
    }

    // todo check bench to debug this case
    public function testUpdate(): void
    {
        $user = new User();
        $user->email = 'test';

        $post = new Post();
        $post->title = 'test title';
        $post->user = $user;
        $post->comments = new ArrayCollection(
            [
                new Tag(),
                new Tag()
            ]
        );
        $post->comments[0]->name = 'name 1';
        $post->comments[1]->name = 'name 2';

        $this->save($post);

        $postId = $post->id;

        // /** @var Post $p */
        // $p = (new Select($this->orm, Post::class))
        //     ->load('user', ['method' => Select\JoinableLoader::INLOAD])
        //     ->wherePK(1)->fetchOne();

        $post->user->email = 'new-email';
        $post->title = 'new title';
        $post->comments[0]->name = 'new name';

        $this->save($post);
        $this->assertInstanceOf(Collection::class, $post->comments);

        $this->orm->getHeap()->clean();
        /** @var Post $p */
        $p = (new Select($this->orm, Post::class))
            ->load('user', ['method' => Select\JoinableLoader::INLOAD])
            ->wherePK($postId)->fetchOne();

        $this->assertSame('new-email', $p->user->email);
        $this->assertSame('new title', $p->title);
        $this->assertSame('new name', $p->comments[0]->name);
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
                Schema::COLUMNS     => ['id', 'email'],
                Schema::TYPECAST    => [
                    'id' => 'int'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ],
            Post::class       => [
                Schema::ROLE        => 'post',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'post',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'title'],
                Schema::TYPECAST    => [
                    'id' => 'int',
                    'user_id' => 'int' # todo: in the benches it crashes if comment same line in BelongsTo relation
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'user' => [
                        Relation::TYPE   => Relation::BELONGS_TO,
                        Relation::TARGET => User::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::NULLABLE => true,
                            Relation::INNER_KEY => 'user_id',
                            Relation::OUTER_KEY => 'id',
                        ],
                    ],
                    'comments' => [
                        Relation::TYPE   => Relation::MANY_TO_MANY,
                        Relation::TARGET => Tag::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE           => true,
                            Relation::NULLABLE          => true,
                            Relation::INNER_KEY         => 'id',
                            Relation::OUTER_KEY         => 'id',
                            Relation::THROUGH_ENTITY    => 'tag_context',
                            Relation::THROUGH_INNER_KEY => 'post_id',
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
                Schema::RELATIONS   => [],
                Schema::SCOPE       => SortByIDScope::class
            ],
            TagContext::class => [
                Schema::ROLE        => 'tag_context',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'tag_post_map',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'post_id', 'tag_id'],
                Schema::TYPECAST    => [
                    'id' => 'int',
                    'post_id' => 'int',
                    'tag_id' => 'int'
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ];
    }
}
