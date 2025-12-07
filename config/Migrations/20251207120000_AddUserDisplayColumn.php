<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddUserDisplayColumn extends BaseMigration
{
    /**
     * Up
     *
     * Adds user_id column and renames user to user_display.
     * - user_id: stores the user ID for linking/filtering
     * - user_display: stores optional display name (previously 'user' often held usernames)
     */
    public function up(): void
    {
        $this->table('audit_logs')
            ->addColumn('user_id', 'string', [
                'default' => null,
                'limit' => 36,
                'null' => true,
                'after' => 'parent_source',
            ])
            ->renameColumn('user', 'user_display')
            ->addIndex(['user_id'])
            ->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('audit_logs')
            ->renameColumn('user_display', 'user')
            ->removeColumn('user_id')
            ->update();
    }
}
