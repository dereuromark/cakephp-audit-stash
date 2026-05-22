<?php

declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class CreateAuditLogs extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Up
     */
    public function up(): void
    {
        // primary_key stores the audited record's primary key (polymorphic), so its
        // type follows the global Polymorphic.type config (default: integer). For
        // integer variants, signedness follows Migrations.unsigned_primary_keys.
        $type = (string)Configure::read('Polymorphic.type', 'integer');
        $signed = !(bool)Configure::read('Migrations.unsigned_primary_keys', false);

        $polymorphicOptions = [
            'default' => null,
            'null' => true,
        ];
        if (in_array($type, ['integer', 'biginteger'], true)) {
            $polymorphicOptions['signed'] = $signed;
        }

        $this->table('audit_logs')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 10,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('transaction', 'binaryuuid', [
                'default' => null,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('type', 'string', [
                'default' => null,
                'limit' => 7,
                'null' => false,
            ])
            ->addColumn('primary_key', $type, $polymorphicOptions)
            ->addColumn('display_value', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('source', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('parent_source', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('username', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('original', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => true,
            ])
            ->addColumn('changed', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => true,
            ])
            ->addColumn('meta', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(['transaction'])
            ->addIndex(['type'])
            ->addIndex(['primary_key'])
            ->addIndex(['display_value'])
            ->addIndex(['source'])
            ->addIndex(['parent_source'])
            ->addIndex(['username'])
            ->addIndex(['created'])
            ->addIndex(['source', 'primary_key'])
            ->addIndex(['source', 'created'])
            ->create();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('audit_logs')->drop();
    }
}
