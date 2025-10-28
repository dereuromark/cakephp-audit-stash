<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AuthorsFixture
 */
class AuthorsFixture extends TestFixture
{
    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null, 'autoIncrement' => true],
        'name' => ['type' => 'string', 'length' => 255, 'null' => false, 'default' => null],
        'created' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        'modified' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ];

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'name' => 'mariano',
                'created' => '2007-03-17 01:16:23',
                'modified' => '2007-03-17 01:18:31',
            ],
            [
                'name' => 'larry',
                'created' => '2007-03-17 01:18:23',
                'modified' => '2007-03-17 01:20:31',
            ],
            [
                'name' => 'garrett',
                'created' => '2007-03-17 01:20:23',
                'modified' => '2007-03-17 01:22:31',
            ],
        ];
        parent::init();
    }
}
