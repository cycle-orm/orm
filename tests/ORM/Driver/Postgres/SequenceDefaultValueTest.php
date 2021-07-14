<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Driver\Postgres;

use Cycle\ORM\Heap\Heap;
use Cycle\ORM\Schema;
use Cycle\ORM\Select;
use Cycle\ORM\Tests\BaseTest;
use Cycle\ORM\Tests\Fixtures\NotDeletedScope;
use Cycle\ORM\Tests\Fixtures\SequenceDefaultValueMapper;
use Cycle\ORM\Tests\Fixtures\User;
use Cycle\ORM\Tests\Traits\TableTrait;
use Cycle\ORM\Transaction;

class SequenceDefaultValueTest extends BaseTest
{
    use TableTrait;

    public const DRIVER = 'postgres';

    public function setUp(): void
    {
        parent::setUp();

        $this->clearSequence();
        $this->createSequence();

        $this->makeTable(
            'user',
            [
                'id' => 'primary',
                'email' => 'string',
                'balance' => 'float',
                'user_code' => 'int',
                'deleted_at' => 'datetime,null',
            ]
        );

        $this->orm = $this->withSchema(
            new Schema(
                [
                    User::class => [
                        Schema::ROLE => 'user',
                        Schema::MAPPER => SequenceDefaultValueMapper::class,
                        Schema::DATABASE => 'default',
                        Schema::TABLE => 'user',
                        Schema::PRIMARY_KEY => 'id',
                        Schema::COLUMNS => ['id', 'email', 'balance', 'user_code', 'deleted_at'],
                        Schema::TYPECAST => [
                            'id' => 'int',
                            'balance' => 'float',
                            'user_code' => 'int',
                            'deleted_at' => 'datetime'
                        ],
                        Schema::SCHEMA => [],
                        Schema::RELATIONS => [],
                        Schema::SCOPE => NotDeletedScope::class
                    ]
                ]
            )
        );
    }

    public function tearDown(): void
    {
        $this->clearSequence();

        parent::tearDown();
    }

    public function testCreate(): void
    {
        $u = new User();
        $u->email = 'test@email.com';
        $u->balance = 199;

        $this->save($u);

        $data = (new Select($this->orm->withHeap(new Heap()), User::class))->fetchData();

        $this->assertIsInt($data[0]['user_code']);
    }

    private function createSequence(): void
    {
        $this->getDatabase()->query('CREATE SEQUENCE user_code_seq INCREMENT 1 START 1 MINVALUE 1');
    }

    private function clearSequence(): void
    {
        $this->getDatabase()->execute('DROP SEQUENCE IF EXISTS user_code_seq CASCADE');
    }
}
