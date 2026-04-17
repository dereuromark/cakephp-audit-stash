<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class RenameTransactionToTransactionKey extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('audit_logs');
        if ($table->hasColumn('transaction')) {
            $table->renameColumn('transaction', 'transaction_key')->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('audit_logs');
        if ($table->hasColumn('transaction_key') && !$table->hasColumn('transaction')) {
            $table->renameColumn('transaction_key', 'transaction')->update();
        }
    }
}s
