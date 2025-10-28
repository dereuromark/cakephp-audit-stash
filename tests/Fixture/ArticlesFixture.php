<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ArticlesFixture
 */
class ArticlesFixture extends TestFixture
{
    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null, 'autoIncrement' => true],
        'author_id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => true, 'default' => null],
        'title' => ['type' => 'string', 'length' => 255, 'null' => false, 'default' => null],
        'body' => ['type' => 'text', 'length' => 16777215, 'null' => true, 'default' => null],
        'published' => ['type' => 'string', 'length' => 1, 'null' => true, 'default' => 'N'],
        'created' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        'modified' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
        '_indexes' => [
            'idx_author_id' => ['type' => 'index', 'columns' => ['author_id']],
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
                'author_id' => 1,
                'title' => 'First Article',
                'body' => 'First Article Body',
                'published' => 'Y',
                'created' => '2007-03-18 10:39:23',
                'modified' => '2007-03-18 10:41:31',
            ],
            [
                'author_id' => 3,
                'title' => 'Second Article',
                'body' => 'Second Article Body',
                'published' => 'Y',
                'created' => '2007-03-18 10:41:23',
                'modified' => '2007-03-18 10:43:31',
            ],
            [
                'author_id' => 1,
                'title' => 'Third Article',
                'body' => 'Third Article Body',
                'published' => 'Y',
                'created' => '2007-03-18 10:43:23',
                'modified' => '2007-03-18 10:45:31',
            ],
        ];
        parent::init();
    }
}
