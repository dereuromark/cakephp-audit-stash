<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds optional hash chain columns for tamper-evidence.
 *
 * When enabled via `persisterConfig['hashChain'] => true` for
 * `TablePersister`, each row gets a SHA-256 hash linked to the previous
 * row's hash. Any later edit of historic data invalidates the chain from
 * that row onwards. Disabled by default — the columns are nullable and
 * existing installs can ignore them.
 *
 * See docs/tamper-evidence.md for context, concurrency semantics, and the
 * verification workflow.
 */
class AddHashChainToAuditLogs extends BaseMigration
{
    /**
     * Up
     */
    public function up(): void
    {
        $table = $this->table('audit_logs');

        $table
            ->addColumn('prev_hash', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
                'after' => 'meta',
                'comment' => 'SHA-256 of the previous audit log row, null for the first row.',
            ])
            ->addColumn('hash', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
                'after' => 'prev_hash',
                'comment' => 'SHA-256 over canonical JSON of this row + prev_hash.',
            ])
            ->addIndex(['hash'], ['name' => 'idx_hash'])
            ->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $table = $this->table('audit_logs');

        $table
            ->removeIndexByName('idx_hash')
            ->removeColumn('hash')
            ->removeColumn('prev_hash')
            ->update();
    }
}
