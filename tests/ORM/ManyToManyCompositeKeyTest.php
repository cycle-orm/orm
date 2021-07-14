<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Collection\Pivoted\PivotedCollectionInterface;
use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\CompositePK;
use Cycle\ORM\Tests\Fixtures\CompositePKChild;
use Cycle\ORM\Tests\Fixtures\CompositePKPivot;
use Cycle\ORM\Tests\Traits\TableTrait;
use Cycle\ORM\Transaction;
use Doctrine\Common\Collections\Collection;

abstract class ManyToManyCompositeKeyTest extends BaseTest
{
    use TableTrait;

    protected const
        CHILDREN_CONTAINER = 'pivoted',
        PARENTS_CONTAINER = 'pivoted',

        PARENT_1 = ['key1' => 1, 'key2' => 1, 'key3' => 101],
        PARENT_2 = ['key1' => 1, 'key2' => 2, 'key3' => 102],
        PARENT_3 = ['key1' => 2, 'key2' => 1, 'key3' => 201],
        PARENT_4 = ['key1' => 2, 'key2' => 2, 'key3' => 202],

        CHILD_1 = ['key1' => 1, 'key2' => 1, 'key3' => null],
        CHILD_2 = ['key1' => 1, 'key2' => 2, 'key3' => 'foo1'],
        CHILD_3 = ['key1' => 2, 'key2' => 1, 'key3' => 'bar1'],
        CHILD_4 = ['key1' => 3, 'key2' => 3, 'key3' => 'fiz'],

        PIVOT_1_1 = [
            'parent_key1' => self::PARENT_1['key1'], 'parent_key2' => self::PARENT_1['key2'],
            'child_key1' => self::CHILD_1['key1'], 'child_key2' => self::CHILD_1['key2'],
            'as' => 'foo1'
        ],
        PIVOT_1_2 = [
            'parent_key1' => self::PARENT_1['key1'], 'parent_key2' => self::PARENT_1['key2'],
            'child_key1' => self::CHILD_2['key1'], 'child_key2' => self::CHILD_2['key2'],
            'as' => 'foo2'
        ],
        PIVOT_1_3 = [
            'parent_key1' => self::PARENT_1['key1'], 'parent_key2' => self::PARENT_1['key2'],
            'child_key1' => self::CHILD_3['key1'], 'child_key2' => self::CHILD_3['key2'],
            'as' => 'foo3'
        ],
        PIVOT_2_2 = [
            'parent_key1' => self::PARENT_2['key1'], 'parent_key2' => self::PARENT_2['key2'],
            'child_key1' => self::CHILD_2['key1'], 'child_key2' => self::CHILD_2['key2'],
            'as' => 'bar2'
        ],
        PIVOT_2_3 = [
            'parent_key1' => self::PARENT_2['key1'], 'parent_key2' => self::PARENT_2['key2'],
            'child_key1' => self::CHILD_3['key1'], 'child_key2' => self::CHILD_3['key2'],
            'as' => 'bar2'
        ],
        PIVOT_3_3 = [
            'parent_key1' => self::PARENT_3['key1'], 'parent_key2' => self::PARENT_3['key2'],
            'child_key1' => self::CHILD_3['key1'], 'child_key2' => self::CHILD_3['key2'],
            'as' => 'baz3'
        ],

        PARENT_1_FULL = self::PARENT_1 + [
            self::CHILDREN_CONTAINER => [
                ['key1' => 1] + self::PIVOT_1_1 + ['@' => self::CHILD_1],
                ['key1' => 2] + self::PIVOT_1_2 + ['@' => self::CHILD_2],
                ['key1' => 3] + self::PIVOT_1_3 + ['@' => self::CHILD_3],
            ]
        ],
        PARENT_2_FULL = self::PARENT_2 + [
            self::CHILDREN_CONTAINER => [
                ['key1' => 4] + self::PIVOT_2_2 + ['@' => self::CHILD_2],
                ['key1' => 5] + self::PIVOT_2_3 + ['@' => self::CHILD_3],
            ]
        ],
        PARENT_3_FULL = self::PARENT_3 + [
            self::CHILDREN_CONTAINER => [
                ['key1' => 6] + self::PIVOT_3_3 + ['@' => self::CHILD_3],
            ]
        ],
        PARENT_4_FULL = self::PARENT_4 + [self::CHILDREN_CONTAINER => []],

        PARENTS_FULL = [
            self::PARENT_1_FULL,
            self::PARENT_2_FULL,
            self::PARENT_3_FULL,
            self::PARENT_4_FULL,
        ];

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable(
            'parent_entity',
            [
                'pField1' => 'bigInteger,primary',
                'pField2' => 'bigInteger,primary',
                'pField3' => 'integer,nullable',
            ]
        );
        $this->makeTable(
            'child_entity',
            [
                'field1' => 'bigInteger,primary',
                'field2' => 'bigInteger,primary',
                'field3' => 'string,nullable',
                'parent_field1' => 'bigInteger,null',
                'parent_field2' => 'bigInteger,null',
            ]
        );
        $this->makeTable('pivot_entity', [
            'pivot_id' => 'primary',
            'parent_field_1' => 'bigInteger',
            'parent_field_2' => 'bigInteger',
            'child_field_1' => 'bigInteger',
            'child_field_2' => 'bigInteger',
            'as' => 'string,nullable'
        ]);

        $this->makeCompositeFK(
            'pivot_entity',
            ['parent_field_1', 'parent_field_2'],
            'parent_entity',
            ['pField1', 'pField2']
        );
        $this->makeCompositeFK(
            'pivot_entity',
            ['child_field_1', 'child_field_2'],
            'child_entity',
            ['field1', 'field2']
        );
        $this->makeIndex('pivot_entity', ['parent_field_1', 'parent_field_2', 'child_field_1', 'child_field_2'], true);

        $this->getDatabase()->table('parent_entity')->insertMultiple(
            ['pField1', 'pField2', 'pField3'],
            [
                self::PARENT_1,
                self::PARENT_2,
                self::PARENT_3,
                self::PARENT_4,
            ]
        );
        $this->getDatabase()->table('child_entity')->insertMultiple(
            ['field1', 'field2', 'field3'],
            [
                self::CHILD_1,
                self::CHILD_2,
                self::CHILD_3,
                self::CHILD_4,
            ]
        );
        $this->getDatabase()->table('pivot_entity')->insertMultiple(
            ['parent_field_1', 'parent_field_2', 'child_field_1', 'child_field_2', 'as'],
            [
                self::PIVOT_1_1,
                self::PIVOT_1_2,
                self::PIVOT_1_3,
                self::PIVOT_2_2,
                self::PIVOT_2_3,
                self::PIVOT_3_3,
            ]
        );

        $this->orm = $this->withSchema(new Schema($this->getSchemaArray()));
    }

    public function testInitRelation(): void
    {
        $u = $this->orm->make(CompositePK::class);
        $this->assertInstanceOf(PivotedCollectionInterface::class, $u->pivoted);
    }

    public function testLoadParentRelation(): void
    {
        $selector = (new Select($this->orm, CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->orderBy('key3', 'ASC')
        ;

        $this->assertSame(self::PARENTS_FULL, $selector->fetchData());
    }

    public function testWithNoColumns(): void
    {
        $selector = new Select($this->orm, CompositePK::class);
        $data = $selector->with(self::CHILDREN_CONTAINER)->buildQuery()->fetchAll();

        $this->assertSame(3, count($data[0]));
    }

    public function testLoadRelationInload(): void
    {
        $selector = new Select($this->orm, CompositePK::class);
        $selector->load(self::CHILDREN_CONTAINER, [
            'method'    => Select\JoinableLoader::INLOAD,
            'scope' => new Select\QueryScope([], ['key1' => 'ASC', 'key2' => 'ASC'])
        ])->orderBy(['key3' => 'ASC']);

        $this->assertSame(self::PARENTS_FULL, $selector->fetchData());
    }

    public function testRelationContextAccess(): void
    {
        /**
         * @var CompositePK $a
         * @var CompositePK $b
         * @var CompositePK $c
         * @var CompositePK $d
         */
        [$a, $b, $c, $d] = (new Select($this->orm, CompositePK::class))->load(self::CHILDREN_CONTAINER)->fetchAll();

        $this->assertCount(3, $a->pivoted);
        $this->assertCount(2, $b->pivoted);
        $this->assertCount(1, $c->pivoted);
        $this->assertCount(0, $d->pivoted);

        $this->assertInstanceOf(PivotedCollectionInterface::class, $a->pivoted);
        $this->assertInstanceOf(PivotedCollectionInterface::class, $b->pivoted);
        $this->assertInstanceOf(PivotedCollectionInterface::class, $c->pivoted);
        $this->assertInstanceOf(PivotedCollectionInterface::class, $d->pivoted);

        $this->assertTrue($a->pivoted->hasPivot($a->pivoted[0]));
        $this->assertTrue($a->pivoted->hasPivot($a->pivoted[1]));
        $this->assertTrue($b->pivoted->hasPivot($b->pivoted[0]));

        $this->assertFalse($b->pivoted->hasPivot($a->pivoted[0]));
        $this->assertFalse($c->pivoted->hasPivot($a->pivoted[0]));
        $this->assertFalse($c->pivoted->hasPivot($b->pivoted[0]));

        $this->assertInstanceOf(CompositePKPivot::class, $a->pivoted->getPivot($a->pivoted[0]));
        $this->assertInstanceOf(CompositePKPivot::class, $a->pivoted->getPivot($a->pivoted[1]));
        $this->assertInstanceOf(CompositePKPivot::class, $b->pivoted->getPivot($b->pivoted[0]));

        $this->assertEquals('foo1', $a->pivoted->getPivot($a->pivoted[0])->as);
        $this->assertEquals('foo2', $a->pivoted->getPivot($a->pivoted[1])->as);
        $this->assertEquals('bar2', $b->pivoted->getPivot($b->pivoted[0])->as);
        $this->assertEquals('baz3', $c->pivoted->getPivot($c->pivoted[0])->as);
    }

    public function testCreateWithManyToManyCascadeNoContext(): void
    {
        $u = new CompositePK();
        $u->key1 = 901;
        $u->key2 = 902;
        $u->key3 = 900;

        $t = new CompositePKChild();
        $t->key1 = 700;
        $t->key2 = 700;
        $t->key3 = 'my child';

        $u->pivoted->add($t);

        $this->save($u);

        $u = (new Select($this->orm->withHeap(new Heap()), CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->wherePK([901, 902])
            ->fetchOne();

        $this->assertSame(900, $u->key3);
        $this->assertCount(1, $u->pivoted);
        $this->assertSame('my child', $u->pivoted[0]->key3);

        $this->assertInstanceOf(CompositePKPivot::class, $u->pivoted->getPivot($u->pivoted[0]));
    }

    public function testCreateWithManyToManyPivotContextArray(): void
    {
        $u = new CompositePK();
        $u->key1 = 901;
        $u->key2 = 902;
        $u->key3 = 900;

        $t = new CompositePKChild();
        $t->key1 = 700;
        $t->key2 = 700;
        $t->key3 = 'my child';

        $u->pivoted->add($t);
        $u->pivoted->setPivot($t, ['as' => 'super']);

        (new Transaction($this->orm))->persist($u)->run();

        $u = (new Select($this->orm->withHeap(new Heap()), CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->wherePK([901, 902])
            ->fetchOne();

        $this->assertSame(900, $u->key3);
        $this->assertCount(1, $u->pivoted);
        $this->assertSame('my child', $u->pivoted[0]->key3);

        $this->assertInstanceOf(CompositePKPivot::class, $u->pivoted->getPivot($u->pivoted[0]));
        $this->assertSame('super', $u->pivoted->getPivot($u->pivoted[0])->as);
    }

    public function testCreateWithManyToManyNoWrites(): void
    {
        $u = new CompositePK();
        $u->key1 = 901;
        $u->key2 = 902;
        $u->key3 = 900;

        $t = new CompositePKChild();
        $t->key1 = 700;
        $t->key2 = 700;
        $t->key3 = 'my child';

        $u->pivoted->add($t);
        $u->pivoted->setPivot($t, ['as' => 'super']);

        $this->save($u);

        $this->orm = $this->orm->withHeap(new Heap());
        $u = (new Select($this->orm, CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->wherePK([901, 902])
            ->fetchOne();

        $this->captureWriteQueries();
        $this->save($u);
        $this->assertNumWrites(0);
    }

    public function testCreateWithManyToManyPivotContext(): void
    {
        $u = new CompositePK();
        $u->key1 = 901;
        $u->key2 = 902;
        $u->key3 = 900;

        $t = new CompositePKChild();
        $t->key1 = 700;
        $t->key2 = 700;
        $t->key3 = 'my child';

        $pc = new CompositePKPivot();
        $pc->as = 'super';

        $u->pivoted->add($t);
        $u->pivoted->setPivot($t, $pc);

        (new Transaction($this->orm))->persist($u)->run();

        $selector = (new Select($this->orm->withHeap(new Heap()), CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->wherePK([901, 902]);

        $selector->fetchOne();
        $u = $selector->load(self::CHILDREN_CONTAINER)
            ->wherePK([901, 902])->fetchOne();

        $this->assertSame(900, $u->key3);
        $this->assertCount(1, $u->pivoted);
        $this->assertSame('my child', $u->pivoted[0]->key3);

        $this->assertInstanceOf(CompositePKPivot::class, $u->pivoted->getPivot($u->pivoted[0]));
        $this->assertSame('super', $u->pivoted->getPivot($u->pivoted[0])->as);
    }

    public function testUnlinkManyToManyAndReplaceSome(): void
    {
        $tagSelector = new Select($this->orm, CompositePKChild::class);

        $selector = new Select($this->orm, CompositePK::class);
        /**
         * @var CompositePK $a
         * @var CompositePK $b
         * @var CompositePK $c
         * @var CompositePK $d
         */
        [$a, $b, $c, $d] = $selector->load(self::CHILDREN_CONTAINER)->fetchAll();

        $a->pivoted->remove(0);
        $a->pivoted->add($tagSelector->wherePK([
            self::CHILD_4['key1'], self::CHILD_4['key2']
        ])->fetchOne());
        $a->pivoted->getPivot($a->pivoted[1])->as = 'new';

        // remove all
        $b->pivoted->clear();

        $t = new CompositePKChild();
        $t->key1 = 9;
        $t->key2 = 9;
        $t->key3 = 'new child';

        $pc = new CompositePKPivot();
        $pc->as = 'super';

        $b->pivoted->add($t);
        $b->pivoted->setPivot($t, $pc);

        $this->captureWriteQueries();
        $this->save($a, $b);
        // 3 Inserts (2 pivots, 1 child)
        // 3 Deletes (1 from $a, 2 from $b)
        // 1 Update
        $this->assertNumWrites(7);

        $this->captureWriteQueries();
        $this->save($a, $b);
        $this->assertNumWrites(0);

        /**
         * @var CompositePK $a
         * @var CompositePK $b
         * @var CompositePK $c
         * @var CompositePK $d
         */
        [$a, $b, $c, $d] = (new Select($this->orm->withHeap(new Heap()), CompositePK::class))
            ->load(self::CHILDREN_CONTAINER, ['orderBy' => ['@.key1' => 'asc']])
            ->fetchAll();

        $this->assertSame(self::CHILD_2['key3'], $a->pivoted[0]->key3);
        $this->assertSame('new', $a->pivoted->getPivot($a->pivoted[0])->as);

        $this->assertSame('new child', $b->pivoted[0]->key3);
        $this->assertSame('super', $b->pivoted->getPivot($b->pivoted[0])->as);
    }

    public function testReassign(): void
    {
        $tagSelect = new Select($this->orm, CompositePKChild::class);
        $userSelect = new Select($this->orm, CompositePK::class);

        /** @var CompositePK $u */
        $u = $userSelect->load(self::CHILDREN_CONTAINER)
            ->fetchOne(['key1' => 1, 'key2' => 1]);

        $this->assertInstanceOf(Collection::class, $u->pivoted);

        $this->captureWriteQueries();
        (new Transaction($this->orm))->persist($u)->run();
        $this->assertNumWrites(0);

        $wantTags = [self::CHILD_1['key3'], self::CHILD_4['key3']];

        foreach ($wantTags as $wantTag) {
            $found = false;

            foreach ($u->pivoted as $tag) {
                if ($tag->key3 === $wantTag) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $newTag = $tagSelect->fetchOne(['key3' => $wantTag]);
                $u->pivoted->add($newTag);
            }
        }

        $u->pivoted = $u->pivoted->filter(function ($t) use ($wantTags) {
            return in_array($t->key3, $wantTags);
        });

        $this->captureWriteQueries();
        (new Transaction($this->orm))->persist($u)->run();
        // Insert 1 pivot
        // Delete 2 pivot (unlink child2 and child3)
        $this->assertNumWrites(3);

        $this->orm = $this->orm->withHeap(new Heap());

        $u = (new Select($this->orm, CompositePK::class))
            ->load(self::CHILDREN_CONTAINER)
            ->fetchOne(['key1' => 1, 'key2' => 1]);
        $this->assertCount(2, $u->pivoted);
        $this->assertSame(self::CHILD_1['key3'], $u->pivoted[0]->key3);
        $this->assertSame(self::CHILD_4['key3'], $u->pivoted[1]->key3);
    }

    private function getSchemaArray(): array
    {
        return [
            CompositePK::class    => [
                Schema::ROLE        => 'parent_entity',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'parent_entity',
                Schema::PRIMARY_KEY => ['key1', 'key2'],
                Schema::COLUMNS     => [
                    'key1' => 'pField1',
                    'key2' => 'pField2',
                    'key3' => 'pField3',
                ],
                Schema::TYPECAST    => [
                    'key1' => 'int',
                    'key2' => 'int',
                    'key3' => 'int',
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    self::CHILDREN_CONTAINER => [
                        Relation::TYPE   => Relation::MANY_TO_MANY,
                        Relation::TARGET => CompositePKChild::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE           => true,
                            Relation::THROUGH_ENTITY    => CompositePKPivot::class,
                            Relation::INNER_KEY => ['key1', 'key2'],
                            Relation::OUTER_KEY => ['key1', 'key2'],
                            Relation::THROUGH_INNER_KEY => ['parent_key1', 'parent_key2'],
                            Relation::THROUGH_OUTER_KEY => ['child_key1', 'child_key2'],
                        ],
                    ]
                ]
            ],
            CompositePKChild::class => [
                Schema::ROLE        => 'child_entity',
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'child_entity',
                Schema::MAPPER      => Mapper::class,
                Schema::PRIMARY_KEY => ['key1', 'key2'],
                Schema::COLUMNS     => [
                    'key1'        => 'field1',
                    'key2'        => 'field2',
                    'key3'        => 'field3',
                ],
                Schema::TYPECAST    => [
                    'key1' => 'int',
                    'key2' => 'int',
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
            ],
            CompositePKPivot::class => [
                Schema::ROLE        => 'pivot_entity',
                Schema::TABLE       => 'pivot_entity',
                Schema::DATABASE    => 'default',
                Schema::MAPPER      => Mapper::class,
                Schema::PRIMARY_KEY => 'key1',
                Schema::COLUMNS     => [
                    'key1' => 'pivot_id',
                    'parent_key1' => 'parent_field_1',
                    'parent_key2' => 'parent_field_2',
                    'child_key1' => 'child_field_1',
                    'child_key2' => 'child_field_2',
                    'as' => 'as',
                ],
                Schema::TYPECAST    => [
                    'key1' => 'int',
                    'parent_key1' => 'int',
                    'parent_key2' => 'int',
                    'child_key1' => 'int',
                    'child_key2' => 'int',
                ],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ];
    }
}
