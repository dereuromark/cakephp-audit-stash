<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ArticlesTagsFixture
 */
class ArticlesTagsFixture extends TestFixture
{
    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'article_id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null],
        'tag_id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['article_id', 'tag_id']],
        ],
        '_indexes' => [
            'idx_tag_id' => ['type' => 'index', 'columns' => ['tag_id']],
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
                'tag_id' => 1,
            ],
            [
                'article_id' => 1,
                'tag_id' => 2,
            ],
            [
                'article_id' => 2,
                'tag_id' => 1,
            ],
            [
                'article_id' => 2,
                'tag_id' => 3,
            ],
        ];
        parent::init();
    }
}
