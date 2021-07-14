<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Morphed;

use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\BaseTest;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\Post;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Spiral\Database\Exception\StatementException;

abstract class MorphedHasManyScopeTest extends BaseTest
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

        $this->makeTable('post', [
            'id'      => 'primary',
            'user_id' => 'integer,nullable',
            'title'   => 'string',
            'content' => 'string'
        ]);

        $this->getDatabase()->table('post')->insertMultiple(
            ['title', 'user_id', 'content'],
            [
                ['post 1', 1, 'post 1 body'],
                ['post 2', 1, 'post 2 body'],
                ['post 3', 2, 'post 3 body'],
                ['post 4', 2, 'post 4 body'],
            ]
        );

        $this->makeTable('comment', [
            'id'          => 'primary',
            'parent_id'   => 'integer',
            'parent_type' => 'string',
            'level'       => 'int',
            'message'     => 'string'
        ]);

        $this->getDatabase()->table('comment')->insertMultiple(
            ['parent_id', 'parent_type', 'level', 'message',],
            [
                [1, 'user', 1, 'msg 1'],
                [1, 'user', 2, 'msg 2'],
                [1, 'user', 3, 'msg 3'],
                [1, 'user', 4, 'msg 4'],
                [2, 'user', 1, 'msg 2.1'],
                [2, 'user', 2, 'msg 2.2'],
                [2, 'user', 3, 'msg 2.3'],
                [1, 'post', 1, 'p.msg 1'],
                [1, 'post', 2, 'p.msg 2'],
                [1, 'post', 3, 'p.msg 3'],
                [1, 'post', 4, 'p.msg 4'],
                [2, 'post', 1, 'p.msg 2.1'],
                [2, 'post', 2, 'p.msg 2.2'],
                [2, 'post', 3, 'p.msg 2.3'],
            ]
        );
    }

    public function testOrdered(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 1', $a->comments[3]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[2]->message);
    }

    public function testOrderedASC(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('msg 4', $a->comments[3]->message);
        $this->assertSame('msg 3', $a->comments[2]->message);
        $this->assertSame('msg 2', $a->comments[1]->message);
        $this->assertSame('msg 1', $a->comments[0]->message);

        $this->assertSame('msg 2.3', $b->comments[2]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testOrderedPosts(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->load('comments')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[0]->message);
        $this->assertSame('p.msg 3', $a->comments[1]->message);
        $this->assertSame('p.msg 2', $a->comments[2]->message);
        $this->assertSame('p.msg 1', $a->comments[3]->message);

        $this->assertSame('p.msg 2.3', $b->comments[0]->message);
        $this->assertSame('p.msg 2.2', $b->comments[1]->message);
        $this->assertSame('p.msg 2.1', $b->comments[2]->message);
    }

    public function testOrderedPostsASC(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->load('comments')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[3]->message);
        $this->assertSame('p.msg 3', $a->comments[2]->message);
        $this->assertSame('p.msg 2', $a->comments[1]->message);
        $this->assertSame('p.msg 1', $a->comments[0]->message);

        $this->assertSame('p.msg 2.3', $b->comments[2]->message);
        $this->assertSame('p.msg 2.2', $b->comments[1]->message);
        $this->assertSame('p.msg 2.1', $b->comments[0]->message);
    }

    public function testOrderedInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 1', $a->comments[3]->message);

        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[2]->message);
    }

    public function testOrderedASCInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('user.id')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('msg 4', $a->comments[3]->message);
        $this->assertSame('msg 3', $a->comments[2]->message);
        $this->assertSame('msg 2', $a->comments[1]->message);
        $this->assertSame('msg 1', $a->comments[0]->message);

        $this->assertSame('msg 2.3', $b->comments[2]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testOrderedPostsInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('post.id', 'ASC')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[0]->message);
        $this->assertSame('p.msg 3', $a->comments[1]->message);
        $this->assertSame('p.msg 2', $a->comments[2]->message);
        $this->assertSame('p.msg 1', $a->comments[3]->message);

        $this->assertSame('p.msg 2.3', $b->comments[0]->message);
        $this->assertSame('p.msg 2.2', $b->comments[1]->message);
        $this->assertSame('p.msg 2.1', $b->comments[2]->message);
    }

    public function testOrderedPostsASCInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('post.id', 'ASC')->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[3]->message);
        $this->assertSame('p.msg 3', $a->comments[2]->message);
        $this->assertSame('p.msg 2', $a->comments[1]->message);
        $this->assertSame('p.msg 1', $a->comments[0]->message);

        $this->assertSame('p.msg 2.3', $b->comments[2]->message);
        $this->assertSame('p.msg 2.2', $b->comments[1]->message);
        $this->assertSame('p.msg 2.1', $b->comments[0]->message);
    }

    public function testOrderedPromisedASC(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('msg 4', $a->comments[3]->message);
        $this->assertSame('msg 3', $a->comments[2]->message);
        $this->assertSame('msg 2', $a->comments[1]->message);
        $this->assertSame('msg 1', $a->comments[0]->message);

        $this->assertSame('msg 2.3', $b->comments[2]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testOrderedAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments')->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[2]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[0]->message);
        $this->assertSame('msg 2.3', $b->comments[1]->message);
        $this->assertSame('msg 2.2', $b->comments[0]->message);
    }

    public function testOrderedAndWherePromised(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[2]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[0]->message);
        $this->assertSame('msg 2.3', $b->comments[1]->message);
        $this->assertSame('msg 2.2', $b->comments[0]->message);
    }

    public function testPostOrderedPromisedASC(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->fetchAll();

        $this->assertCount(4, $a->comments);
        $this->assertCount(3, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[3]->message);
        $this->assertSame('p.msg 3', $a->comments[2]->message);
        $this->assertSame('p.msg 2', $a->comments[1]->message);
        $this->assertSame('p.msg 1', $a->comments[0]->message);

        $this->assertSame('p.msg 2.3', $b->comments[2]->message);
        $this->assertSame('p.msg 2.2', $b->comments[1]->message);
        $this->assertSame('p.msg 2.1', $b->comments[0]->message);
    }

    public function testPostOrderedAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->load('comments')->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('p.msg 4', $a->comments[2]->message);
        $this->assertSame('p.msg 3', $a->comments[1]->message);
        $this->assertSame('p.msg 2', $a->comments[0]->message);
        $this->assertSame('p.msg 2.3', $b->comments[1]->message);
        $this->assertSame('p.msg 2.2', $b->comments[0]->message);
    }

    public function testPostOrderedAndWherePromised(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, Post::class))->fetchAll();

        $this->captureReadQueries();
        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);
        $this->assertNumReads(2);

        $this->assertSame('p.msg 4', $a->comments[2]->message);
        $this->assertSame('p.msg 3', $a->comments[1]->message);
        $this->assertSame('p.msg 2', $a->comments[0]->message);
        $this->assertSame('p.msg 2.3', $b->comments[1]->message);
        $this->assertSame('p.msg 2.2', $b->comments[0]->message);
    }

    public function testOrderedAndWhereReversed(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments')->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
    }

    public function testOrderedAndWhereReversedInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
    }


    public function testOrderedAndWhereReversedPromised(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
    }

    public function testCustomWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        // overwrites default one
        [$a, $b] = (new Select($this->orm, User::class))->orderBy('user.id')->load('comments', [
            'where' => ['@.level' => 1]
        ])->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testCustomWhereInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::WHERE   => ['@.level' => ['>=' => 2]]
        ]);

        // overwrites default one
        [$a, $b] = (new Select($this->orm, User::class))->orderBy('user.id')->load('comments', [
            'where'  => ['@.level' => 1],
            'method' => Select\JoinableLoader::INLOAD
        ])->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testWithWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::WHERE => ['@.level' => 4]
        ]);

        // second user has been filtered out
        $res = (new Select($this->orm, User::class))->with('comments')->fetchAll();

        $this->assertCount(1, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
    }

    public function testWithWhereAltered(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::WHERE => ['@.level' => 4]
        ]);

        // second user has been filtered out
        $res = (new Select($this->orm, User::class))->with('comments', [
            'where' => ['@.level' => 1]
        ])->orderBy('user.id')->fetchAll();

        $this->assertCount(2, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
        $this->assertSame('another@world.com', $res[1]->email);
    }

    public function testLimitParentSelection(): void
    {
        $this->orm = $this->withCommentsSchema([
        ]);

        // second user has been filtered out
        $res = (new Select($this->orm, User::class))
            ->load('comments')
            ->limit(1)->orderBy('user.id')->fetchAll();

        $this->assertCount(1, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
        $this->assertCount(4, $res[0]->comments);
    }

    public function testLimitParentSelectionError(): void
    {
        $this->expectException(LoaderException::class);

        $this->orm = $this->withCommentsSchema([]);

        // do not allow limits with joined and loaded relations
        (new Select($this->orm, User::class))
            ->load('comments', ['method' => Select\JoinableLoader::INLOAD])
            ->limit(1)->orderBy('user.id')->fetchAll();
    }

    public function testInloadWithOrderAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::WHERE   => ['@.level' => ['>=' => 3]],
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        // sort by users and then by comments and only include comments with level > 3
        $res = (new Select($this->orm, User::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD
        ])->orderBy('user.id', 'DESC')->fetchAll();

        $this->assertCount(2, $res);
        $this->assertSame('hello@world.com', $res[1]->email);
        $this->assertSame('another@world.com', $res[0]->email);

        $this->assertCount(2, $res[1]->comments);
        $this->assertCount(1, $res[0]->comments);

        $this->assertSame('msg 4', $res[1]->comments[0]->message);
        $this->assertSame('msg 3', $res[1]->comments[1]->message);
        $this->assertSame('msg 2.3', $res[0]->comments[0]->message);
    }

    public function testInvalidOrderBy(): void
    {
        $this->expectException(StatementException::class);

        $this->orm = $this->withCommentsSchema([
            Relation::WHERE   => ['@.level' => ['>=' => 3]],
            Schema::SCOPE => new Select\QueryScope([], ['@.column' => 'DESC']),
        ]);

        // sort by users and then by comments and only include comments with level > 3
        (new Select($this->orm, User::class))->load('comments', [
            'method' => Select\JoinableLoader::INLOAD,
        ])->orderBy('user.id', 'DESC')->fetchAll();
    }

    protected function withCommentsSchema(array $relationSchema)
    {
        $eSchema = [];
        $rSchema = [];

        if (isset($relationSchema[Schema::SCOPE])) {
            $eSchema[Schema::SCOPE] = $relationSchema[Schema::SCOPE];
        }

        if (isset($relationSchema[Relation::WHERE])) {
            $rSchema[Relation::WHERE] = $relationSchema[Relation::WHERE];
        }

        return $this->orm->withSchema(new Schema([
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
                        Relation::TYPE   => Relation::MORPHED_HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::LOAD   => Relation::LOAD_PROMISE,
                        Relation::SCHEMA => [
                                Relation::CASCADE   => true,
                                Relation::INNER_KEY => 'id',
                                Relation::OUTER_KEY => 'parent_id',
                                Relation::MORPH_KEY => 'parent_type',
                            ] + $rSchema,
                    ]
                ],
                Schema::SCOPE   => SortByIDScope::class
            ],
            Post::class    => [
                Schema::ROLE        => 'post',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'post',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'title', 'content'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::MORPHED_HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::LOAD   => Relation::LOAD_PROMISE,
                        Relation::SCHEMA => [
                                Relation::CASCADE   => true,
                                Relation::INNER_KEY => 'id',
                                Relation::OUTER_KEY => 'parent_id',
                                Relation::MORPH_KEY => 'parent_type',
                            ] + $rSchema,
                    ],
                ]
            ],
            Comment::class => [
                    Schema::ROLE        => 'comment',
                    Schema::MAPPER      => Mapper::class,
                    Schema::DATABASE    => 'default',
                    Schema::TABLE       => 'comment',
                    Schema::PRIMARY_KEY => 'id',
                    Schema::COLUMNS     => ['id', 'parent_id', 'parent_type', 'message', 'level'],
                    Schema::SCHEMA      => [],
                    Schema::RELATIONS   => []
                ] + $eSchema
        ]));
    }
}
