<?php

declare(strict_types=1);

namespace AuditStash\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * AuditLogsFixture
 */
class AuditLogsFixture extends TestFixture
{
    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'id' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => false, 'default' => null, 'autoIncrement' => true],
        'transaction' => ['type' => 'string', 'length' => 36, 'null' => false, 'default' => null],
        'type' => ['type' => 'string', 'length' => 7, 'null' => false, 'default' => null],
        'primary_key' => ['type' => 'integer', 'length' => 10, 'unsigned' => true, 'null' => true, 'default' => null],
        'display_value' => ['type' => 'string', 'length' => 255, 'null' => true, 'default' => null],
        'source' => ['type' => 'string', 'length' => 255, 'null' => false, 'default' => null],
        'parent_source' => ['type' => 'string', 'length' => 255, 'null' => true, 'default' => null],
        'username' => ['type' => 'string', 'length' => 255, 'null' => true, 'default' => null],
        'original' => ['type' => 'text', 'length' => 16777215, 'null' => true, 'default' => null],
        'changed' => ['type' => 'text', 'length' => 16777215, 'null' => true, 'default' => null],
        'meta' => ['type' => 'text', 'length' => 16777215, 'null' => true, 'default' => null],
        'created' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
        '_indexes' => [
            'idx_transaction' => ['type' => 'index', 'columns' => ['transaction']],
            'idx_type' => ['type' => 'index', 'columns' => ['type']],
            'idx_primary_key' => ['type' => 'index', 'columns' => ['primary_key']],
            'idx_display_value' => ['type' => 'index', 'columns' => ['display_value']],
            'idx_source' => ['type' => 'index', 'columns' => ['source']],
            'idx_parent_source' => ['type' => 'index', 'columns' => ['parent_source']],
            'idx_username' => ['type' => 'index', 'columns' => ['username']],
            'idx_created' => ['type' => 'index', 'columns' => ['created']],
        ],
        '_options' => [
            'quoteIdentifiers' => true,
        ],
    ];

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
