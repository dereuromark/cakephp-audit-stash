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
        'transaction' => ['type' => 'binaryuuid', 'length' => null, 'null' => false, 'default' => null],
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
            'transaction' => ['type' => 'index', 'columns' => ['transaction']],
            'type' => ['type' => 'index', 'columns' => ['type']],
            'primary_key' => ['type' => 'index', 'columns' => ['primary_key']],
            'display_value' => ['type' => 'index', 'columns' => ['display_value']],
            'source' => ['type' => 'index', 'columns' => ['source']],
            'parent_source' => ['type' => 'index', 'columns' => ['parent_source']],
            'username' => ['type' => 'index', 'columns' => ['username']],
            'created' => ['type' => 'index', 'columns' => ['created']],
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
