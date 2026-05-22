<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class AuditLogsForeignKeySignedness extends BaseMigration
{
    /**
     * The `primary_key` column stores the primary key of the audited record
     * (a polymorphic reference). It must therefore use the same signedness as
     * the application's primary keys, governed by the
     * `Migrations.unsigned_primary_keys` flag. The original migration hardcoded
     * it as unsigned, which mismatches signed-primary-key apps.
     * Signedness only takes effect on MySQL; SQLite/Postgres ignore it.
     *
     * @return void
     */
    public function up(): void
    {
        $signed = !(bool)Configure::read('Migrations.unsigned_primary_keys');

        $this->table('audit_logs')
            ->changeColumn('primary_key', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => $signed,
            ])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('audit_logs')
            ->changeColumn('primary_key', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => false,
            ])
            ->update();
    }
}
