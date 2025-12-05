<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class RenameUsernameToUser extends BaseMigration
{
    /**
     * Up
     */
    public function up(): void
    {
        $this->table('audit_logs')
            ->renameColumn('username', 'user')
            ->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('audit_logs')
            ->renameColumn('user', 'username')
            ->update();
    }
}
