<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * TagsFixture
 */
class TagsFixture extends TestFixture
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
                'name' => 'tag1',
                'created' => '2007-03-18 12:22:23',
                'modified' => '2007-03-18 12:24:31',
            ],
            [
                'name' => 'tag2',
                'created' => '2007-03-18 12:24:23',
                'modified' => '2007-03-18 12:26:31',
            ],
            [
                'name' => 'tag3',
                'created' => '2007-03-18 12:26:23',
                'modified' => '2007-03-18 12:28:31',
            ],
        ];
        parent::init();
    }
}
