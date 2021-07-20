<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests;

use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Tests\Fixtures\Nested;
use Cycle\ORM\Tests\Fixtures\Profile;
use Cycle\ORM\Tests\Fixtures\User;

abstract class BelongsToRelationRenamedFieldsTest extends BelongsToRelationTest
{
    public function setUp(): void
    {
        BaseTest::setUp();

        $this->makeTable('user', [
            'user_pk' => 'primary',
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

        $this->makeTable('profile', [
            'profile_pk'    => 'primary',
            'user_id_field' => 'integer',
            'image'         => 'string'
        ]);

        $this->getDatabase()->table('profile')->insertMultiple(
            ['user_id_field', 'image'],
            [
                [1, 'image.png'],
                [2, 'second.png'],
                [2, 'third.png'],
            ]
        );

        $this->makeTable('nested', [
            'nested_pk'        => 'primary',
            'profile_id_field' => 'integer',
            'label'            => 'string'
        ]);

        $this->getDatabase()->table('nested')->insertMultiple(
            ['profile_id_field', 'label'],
            [
                [1, 'nested-label'],
            ]
        );

        $this->makeFK('profile', 'user_id_field', 'user', 'user_pk');
        $this->makeFK('nested', 'profile_id_field', 'profile', 'profile_pk');

        $this->orm = $this->withSchema(new Schema($this->getSchemaArray()));
    }

    private function getSchemaArray(): array
    {
        return [
            User::class    => [
                Schema::ROLE        => 'user',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id' => 'user_pk', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ],
            Profile::class => [
                Schema::ROLE        => 'profile',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'profile',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id' => 'profile_pk', 'user_id' => 'user_id_field', 'image'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'user' => [
                        Relation::TYPE   => Relation::BELONGS_TO,
                        Relation::TARGET => User::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'user_id',
                            Relation::OUTER_KEY => 'id',
                        ],
                    ]
                ]
            ],
            Nested::class  => [
                Schema::ROLE        => 'nested',
                Schema::MAPPER      => Mapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'nested',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id' => 'nested_pk', 'profile_id' => 'profile_id_field', 'label'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'profile' => [
                        Relation::TYPE   => Relation::BELONGS_TO,
                        Relation::TARGET => Profile::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'profile_id',
                            Relation::OUTER_KEY => 'id',
                        ],
                    ]
                ]
            ]
        ];
    }
}
