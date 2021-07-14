<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Exception\LoaderException;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Select\JoinableLoader;
use Cycle\ORM\Tests\Fixtures\Comment;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Spiral\Database\Exception\StatementException;

abstract class HasManyScopeTest extends BaseTest
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

        $this->makeFK('comment', 'user_id', 'user', 'id');

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
    }

    public function testScopeOrdered(): void
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

    public function testScopeOrderedAsc(): void
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

    public function testScopeOrderedAscInLoad(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => JoinableLoader::INLOAD
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

    public function testScopeOrderedPromisedAsc(): void
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

    public function testScopeOrderedAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
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

    public function testScopeOrderedAndWherePromised(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::SCHEMA => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
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

    public function testScopeOrderedAndWhereReversed(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
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

    public function testScopeOrderedAndWhereReversedInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => JoinableLoader::INLOAD
        ])->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertSame('msg 4', $a->comments[0]->message);
        $this->assertSame('msg 3', $a->comments[1]->message);
        $this->assertSame('msg 2', $a->comments[2]->message);
        $this->assertSame('msg 2.3', $b->comments[0]->message);
        $this->assertSame('msg 2.2', $b->comments[1]->message);
    }

    public function testScopeOrderedAndWhereReversedPromised(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
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

    public function testScopeOrderedAndCustomWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
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

    public function testScopeOrderedAndCustomWhereInload(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 2]]]
        ]);

        // overwrites default one
        [$a, $b] = (new Select($this->orm, User::class))->orderBy('user.id')->load('comments', [
            'where'  => ['@.level' => 1],
            'method' => JoinableLoader::INLOAD
        ])->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(1, $b->comments);

        $this->assertSame('msg 1', $a->comments[0]->message);
        $this->assertSame('msg 2.1', $b->comments[0]->message);
    }

    public function testOrderByWithScopeOrdered(): void
    {
        $this->orm = $this->withCommentsSchema([
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'ASC']),
            Relation::SCHEMA  => [
                Relation::ORDER_BY => ['@.level' => 'DESC'],
            ],
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

    public function testWithOrderByInLoad(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA  => [
                Relation::ORDER_BY => ['@.level' => 'ASC'],
            ]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'method' => JoinableLoader::INLOAD
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

    public function testWithOrderByAltered(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA  => [
                Relation::ORDER_BY => ['@.level' => 'DESC'],
            ]
        ]);

        [$a, $b] = (new Select($this->orm, User::class))->load('comments', [
            'orderBy' => ['@.level' => 'ASC']
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

    public function testWithOrderByAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA  => [
                Relation::WHERE => ['@.level' => ['>=' => 2]],
                Relation::ORDER_BY => ['@.level' => 'ASC'],
            ]
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

    public function testWithWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA => [Relation::WHERE => ['@.level' => 4]]
        ]);

        // second user has been filtered out
        $res = (new Select($this->orm, User::class))->with('comments')->fetchAll();

        $this->assertCount(1, $res);
        $this->assertSame('hello@world.com', $res[0]->email);
    }

    public function testWithWhereAltered(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA => ['@.level' => 4]
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
            ->load('comments', ['method' => JoinableLoader::INLOAD])
            ->limit(1)->orderBy('user.id')->fetchAll();
    }

    public function testInloadWithScopeOrderedAndWhere(): void
    {
        $this->orm = $this->withCommentsSchema([
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 3]]],
            Schema::SCOPE => new Select\QueryScope([], ['@.level' => 'DESC']),
        ]);

        // sort by users and then by comments and only include comments with level > 3
        $res = (new Select($this->orm, User::class))->load('comments', [
            'method' => JoinableLoader::INLOAD
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
            Relation::SCHEMA  => [Relation::WHERE => ['@.level' => ['>=' => 3]]],
            Schema::SCOPE => new Select\QueryScope([], ['@.column' => 'DESC']),
        ]);

        // sort by users and then by comments and only include comments with level > 3
        (new Select($this->orm, User::class))->load('comments', [
            'method' => JoinableLoader::INLOAD,
        ])->orderBy('user.id', 'DESC')->fetchAll();
    }

    protected function withCommentsSchema(array $relationSchema)
    {
        $eSchema = [];
        if (isset($relationSchema[Schema::SCOPE])) {
            $eSchema[Schema::SCOPE] = $relationSchema[Schema::SCOPE];
        }

        $rSchema = $relationSchema[Relation::SCHEMA] ?? [];

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
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::SCHEMA => [
                                Relation::CASCADE   => true,
                                Relation::INNER_KEY => 'id',
                                Relation::OUTER_KEY => 'user_id',
                            ] + $rSchema,
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
                    Schema::COLUMNS     => ['id', 'user_id', 'level', 'message'],
                    Schema::SCHEMA      => [],
                    Schema::RELATIONS   => []
                ] + $eSchema
        ]));
    }
}
