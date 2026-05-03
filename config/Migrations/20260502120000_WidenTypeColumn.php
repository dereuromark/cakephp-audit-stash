<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Widen audit_logs.type from VARCHAR(7) to VARCHAR(64) so that custom
 * action event types (e.g. "user.login", "report.exported") can be stored
 * alongside the built-in create/update/delete/revert values.
 */
class WidenTypeColumn extends BaseMigration
{
    public function up(): void
    {
        $this->table('audit_logs')
            ->changeColumn('type', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('audit_logs')
            ->changeColumn('type', 'string', [
                'limit' => 7,
                'null' => false,
            ])
            ->update();
    }
}
