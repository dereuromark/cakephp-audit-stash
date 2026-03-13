<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Convert text columns to native JSON type for better querying and validation.
 *
 * MySQL 5.7.8+ and MariaDB 10.2.7+ support native JSON columns which provide:
 * - Automatic JSON validation on insert/update
 * - Better query performance with JSON functions
 * - Ability to create indexes on JSON paths (MySQL 8.0+)
 *
 * For databases that don't support JSON (older MySQL, SQLite), this migration
 * will be a no-op as the text type will be preserved.
 *
 * @see https://github.com/lorenzo/audit-stash/issues/39
 */
class UseJsonColumnTypes extends BaseMigration
{
    /**
     * Up
     */
    public function up(): void
    {
        $table = $this->table('audit_logs');

        $table->changeColumn('original', 'json', [
            'default' => null,
            'null' => true,
        ]);

        $table->changeColumn('changed', 'json', [
            'default' => null,
            'null' => true,
        ]);

        $table->changeColumn('meta', 'json', [
            'default' => null,
            'null' => true,
        ]);

        $table->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $table = $this->table('audit_logs');

        $table->changeColumn('original', 'text', [
            'default' => null,
            'limit' => 16777215,
            'null' => true,
        ]);

        $table->changeColumn('changed', 'text', [
            'default' => null,
            'limit' => 16777215,
            'null' => true,
        ]);

        $table->changeColumn('meta', 'text', [
            'default' => null,
            'limit' => 16777215,
            'null' => true,
        ]);

        $table->update();
    }
}
