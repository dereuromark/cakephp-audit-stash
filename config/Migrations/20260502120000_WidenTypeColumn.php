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
        // Intentional no-op: shrinking back to VARCHAR(7) would silently
        // truncate (or hard-reject under strict mode) any custom event
        // types longer than 7 chars — exactly the values this migration
        // was added to support. A wider column is forward-compatible, so
        // leaving it at VARCHAR(64) is a safe rollback.
    }
}
