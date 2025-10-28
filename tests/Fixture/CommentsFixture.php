<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * CommentsFixture
 */
class CommentsFixture extends TestFixture
{
    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null, 'autoIncrement' => true],
        'article_id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null],
        'user_id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => true, 'default' => null],
        'comment' => ['type' => 'text', 'length' => 16777215, 'null' => true, 'default' => null],
        'published' => ['type' => 'string', 'length' => 1, 'null' => true, 'default' => 'N'],
        'created' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        'modified' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
        '_indexes' => [
            'idx_article_id' => ['type' => 'index', 'columns' => ['article_id']],
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
                'article_id' => 1,
                'user_id' => 2,
                'comment' => 'First Comment for First Article',
                'published' => 'Y',
                'created' => '2007-03-18 10:45:23',
                'modified' => '2007-03-18 10:47:31',
            ],
            [
                'article_id' => 1,
                'user_id' => 4,
                'comment' => 'Second Comment for First Article',
                'published' => 'Y',
                'created' => '2007-03-18 10:47:23',
                'modified' => '2007-03-18 10:49:31',
            ],
            [
                'article_id' => 2,
                'user_id' => 1,
                'comment' => 'First Comment for Second Article',
                'published' => 'Y',
                'created' => '2007-03-18 10:49:23',
                'modified' => '2007-03-18 10:51:31',
            ],
        ];
        parent::init();
    }
}
