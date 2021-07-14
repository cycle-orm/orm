<?php

/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\StdMapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\Fixtures\SortByIDScope;
use Cycle\ORM\Tests\Traits\TableTrait;

abstract class ManyToManyPromiseEagerLoadTest extends BaseTest
{
    use TableTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->makeTable('distributions', [
            'id'   => 'primary',
            'name' => 'string',
        ]);

        $this->makeTable('version_distribution', [
            'id'         => 'primary',
            'dist_id'    => 'int',
            'version_id' => 'int',
        ]);

        $this->makeTable('versions', [
            'id'        => 'primary',
            'version'   => 'string',
            'module_id' => 'int'
        ]);

        $this->makeTable('modules', [
            'id'   => 'primary',
            'name' => 'string',
        ]);

        $this->makeFK('version_distribution', 'dist_id', 'distributions', 'id');
        $this->makeFK('version_distribution', 'version_id', 'versions', 'id');
        $this->makeFK('versions', 'module_id', 'modules', 'id');


        $this->getDatabase()->table('distributions')->insertMultiple(
            ['name'],
            [
                ['production'], // 1
                ['staging'],    // 2
            ]
        );

        $this->getDatabase()->table('modules')->insertMultiple(
            ['name'],
            [
                ['Module A'], // 1
                ['Module B'], // 2
                ['Module C'], // 3
            ]
        );

        $this->getDatabase()->table('versions')->insertMultiple(
            ['module_id', 'version'],
            [
                [1, 'v1.0'], // 1
                [1, 'v2.0'], // 2
                [2, 'v1.0'], // 3
                [3, 'v1.0'], // 4
                [3, 'v2.0'], // 5
            ]
        );

        $this->getDatabase()->table('version_distribution')->insertMultiple(
            ['dist_id', 'version_id'],
            [
                [1, 1],
                [1, 3],
                [1, 4],
                [2, 2],
                [2, 3],
                [2, 5],
            ]
        );

        $this->orm = $this->withSchema(new Schema([
            'distribution'         => [
                Schema::ROLE        => 'distribution',
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'distributions',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'name'],
                Schema::TYPECAST    => ['id' => 'int'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'versions' => [
                        Relation::TYPE   => Relation::MANY_TO_MANY,
                        Relation::TARGET => 'version',
                        Relation::LOAD   => Relation::LOAD_PROMISE,
                        Relation::SCHEMA => [
                            Relation::CASCADE           => true,
                            Relation::THROUGH_ENTITY    => 'version_distribution',
                            Relation::INNER_KEY         => 'id',
                            Relation::OUTER_KEY         => 'id',
                            Relation::THROUGH_INNER_KEY => 'dist_id',
                            Relation::THROUGH_OUTER_KEY => 'version_id',
                        ],
                    ]
                ],
                Schema::SCOPE   => SortByIDScope::class
            ],
            'version_distribution' => [
                Schema::ROLE        => 'version_context',
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'version_distribution',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'dist_id', 'version_id'],
                Schema::TYPECAST    => ['id' => 'int', 'dist_id' => 'int', 'version_id' => 'int'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ],
            'version'              => [
                Schema::ROLE        => 'tag',
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'versions',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'version', 'module_id'],
                Schema::TYPECAST    => ['id' => 'int', 'module_id' => 'int'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'module' => [
                        Relation::TYPE   => Relation::BELONGS_TO,
                        Relation::TARGET => 'module',
                        Relation::LOAD   => Relation::LOAD_EAGER,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => false,
                            Relation::INNER_KEY => 'module_id',
                            Relation::OUTER_KEY => 'id',
                        ],
                    ]
                ],
                Schema::SCOPE   => SortByIDScope::class
            ],
            'module'               => [
                Schema::ROLE        => 'module',
                Schema::MAPPER      => StdMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'modules',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'name'],
                Schema::TYPECAST    => ['id' => 'int'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [],
                Schema::SCOPE   => SortByIDScope::class
            ]
        ]));
    }

    public function testFetchData(): void
    {
        $selector = new Select($this->orm, 'distribution');
        $selector->load('versions');

        $this->assertSame([
            [
                'id'       => 1,
                'name'     => 'production',
                'versions' => [
                    [
                        'id'         => 1,
                        'dist_id'    => 1,
                        'version_id' => 1,
                        '@'          => [
                            'id'        => 1,
                            'version'   => 'v1.0',
                            'module_id' => 1,
                            'module'    => [
                                'id'   => 1,
                                'name' => 'Module A',
                            ],
                        ],
                    ],
                    [
                        'id'         => 2,
                        'dist_id'    => 1,
                        'version_id' => 3,
                        '@'          => [
                            'id'        => 3,
                            'version'   => 'v1.0',
                            'module_id' => 2,
                            'module'    => [
                                'id'   => 2,
                                'name' => 'Module B',
                            ],
                        ],
                    ],
                    [
                        'id'         => 3,
                        'dist_id'    => 1,
                        'version_id' => 4,
                        '@'          => [
                            'id'        => 4,
                            'version'   => 'v1.0',
                            'module_id' => 3,
                            'module'    => [
                                'id'   => 3,
                                'name' => 'Module C',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 2,
                'name'     => 'staging',
                'versions' => [
                    [
                        'id'         => 4,
                        'dist_id'    => 2,
                        'version_id' => 2,
                        '@'          => [
                            'id'        => 2,
                            'version'   => 'v2.0',
                            'module_id' => 1,
                            'module'    => [
                                'id'   => 1,
                                'name' => 'Module A',
                            ],
                        ],
                    ],
                    [
                        'id'         => 5,
                        'dist_id'    => 2,
                        'version_id' => 3,
                        '@'          => [
                            'id'        => 3,
                            'version'   => 'v1.0',
                            'module_id' => 2,
                            'module'    => [
                                'id'   => 2,
                                'name' => 'Module B',
                            ],
                        ],
                    ],
                    [
                        'id'         => 6,
                        'dist_id'    => 2,
                        'version_id' => 5,
                        '@'          => [
                            'id'        => 5,
                            'version'   => 'v2.0',
                            'module_id' => 3,
                            'module'    =>
                                [
                                    'id'   => 3,
                                    'name' => 'Module C',
                                ],
                        ],
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    public function testFetchDataSingleQuery(): void
    {
        $selector = new Select($this->orm, 'distribution');
        $selector->load('versions', ['method' => Select::SINGLE_QUERY]);

        $this->assertSame([
            [
                'id'       => 1,
                'name'     => 'production',
                'versions' => [
                    [
                        'id'         => 1,
                        'dist_id'    => 1,
                        'version_id' => 1,
                        '@'          => [
                            'id'        => 1,
                            'version'   => 'v1.0',
                            'module_id' => 1,
                            'module'    => [
                                'id'   => 1,
                                'name' => 'Module A',
                            ],
                        ],
                    ],
                    [
                        'id'         => 2,
                        'dist_id'    => 1,
                        'version_id' => 3,
                        '@'          => [
                            'id'        => 3,
                            'version'   => 'v1.0',
                            'module_id' => 2,
                            'module'    => [
                                'id'   => 2,
                                'name' => 'Module B',
                            ],
                        ],
                    ],
                    [
                        'id'         => 3,
                        'dist_id'    => 1,
                        'version_id' => 4,
                        '@'          => [
                            'id'        => 4,
                            'version'   => 'v1.0',
                            'module_id' => 3,
                            'module'    => [
                                'id'   => 3,
                                'name' => 'Module C',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 2,
                'name'     => 'staging',
                'versions' => [
                    [
                        'id'         => 4,
                        'dist_id'    => 2,
                        'version_id' => 2,
                        '@'          => [
                            'id'        => 2,
                            'version'   => 'v2.0',
                            'module_id' => 1,
                            'module'    => [
                                'id'   => 1,
                                'name' => 'Module A',
                            ],
                        ],
                    ],
                    [
                        'id'         => 5,
                        'dist_id'    => 2,
                        'version_id' => 3,
                        '@'          => [
                            'id'        => 3,
                            'version'   => 'v1.0',
                            'module_id' => 2,
                            'module'    => [
                                'id'   => 2,
                                'name' => 'Module B',
                            ],
                        ],
                    ],
                    [
                        'id'         => 6,
                        'dist_id'    => 2,
                        'version_id' => 5,
                        '@'          => [
                            'id'        => 5,
                            'version'   => 'v2.0',
                            'module_id' => 3,
                            'module'    =>
                                [
                                    'id'   => 3,
                                    'name' => 'Module C',
                                ],
                        ],
                    ],
                ],
            ],
        ], $selector->fetchData());
    }

    public function testLoadEagerAfterPreload(): void
    {
        $selector = new Select($this->orm, 'distribution');
        $d = $selector
            ->load('versions')
            ->orderBy('id', 'ASC')
            ->fetchOne();

        foreach ($d->versions as $version) {
            $this->assertNotNull($version->module);
        }
    }


    public function testLoadPromised(): void
    {
        $selector = new Select($this->orm, 'distribution');
        $d = $selector
            ->orderBy('id', 'ASC')
            ->fetchOne();

        foreach ($d->versions as $version) {
            $this->assertNotNull($version->module);
        }
    }
}
