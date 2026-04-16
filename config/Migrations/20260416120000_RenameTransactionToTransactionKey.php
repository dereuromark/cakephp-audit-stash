<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class RenameTransactionToTransactionKey extends BaseMigration
{
    public function up(): void
    {
        $this->table('audit_logs')
            ->renameColumn('transaction', 'transaction_key')
            ->update();
    }

    public function down(): void
    {
        $this->table('audit_logs')
            ->renameColumn('transaction_key', 'transaction')
            ->update();
    }
}
