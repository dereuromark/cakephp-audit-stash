<?php

declare(strict_types=1);

namespace AuditStash\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\I18n\DateTime;

/**
 * Cleanup old audit logs command.
 *
 * Removes audit log entries older than a specified retention period.
 * Useful for maintaining database size and complying with retention policies.
 *
 * Usage:
 *   bin/cake audit_stash cleanup --force
 *   bin/cake audit_stash cleanup --retention 365 --dry-run
 *   bin/cake audit_stash cleanup --retention 30 --table Users --force
 *
 * Configuration (in config/app.php):
 *   'AuditStash' => [
 *       'retention' => [
 *           'default' => 90,
 *           'tables' => [
 *               'users' => 365,
 *               'orders' => 730,
 *               'compliance_logs' => false, // Never delete
 *           ],
 *       ],
 *   ]
 */
class CleanupCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'audit_stash cleanup';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Cleanup old audit logs based on retention policy';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Cleanup old audit logs based on configured retention periods.')
            ->addOption('retention', [
                'short' => 'r',
                'help' => 'Override retention period in days (default: from config or 90)',
            ])
            ->addOption('table', [
                'short' => 't',
                'help' => 'Only cleanup logs for a specific table/source (optional)',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Show what would be deleted without actually deleting',
                'default' => false,
            ])
            ->addOption('force', [
                'short' => 'f',
                'boolean' => true,
                'help' => 'Actually delete records (required unless --dry-run)',
                'default' => false,
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // Check persister type
        $persister = Configure::read('AuditStash.persister', 'AuditStash\Persister\TablePersister');
        if (str_contains($persister, 'ElasticSearch')) {
            $io->error('This command only works with TablePersister');

            return self::CODE_ERROR;
        }

        $tableOption = $args->getOption('table');
        $table = is_string($tableOption) ? $tableOption : null;
        $dryRun = (bool)$args->getOption('dry-run');
        $force = (bool)$args->getOption('force');

        // Get retention period
        $retention = $this->getRetentionDays($args, $table);

        // Check if retention is disabled for this table
        if ($retention === null) {
            $io->success(sprintf('Retention is disabled for table "%s". No logs will be deleted.', $table));

            return self::CODE_SUCCESS;
        }

        if (!$dryRun && !$force) {
            $io->error('You must specify --force to actually delete records, or use --dry-run to preview.');

            return self::CODE_ERROR;
        }

        $io->out(sprintf('Retention policy: %d days', $retention));

        if ($table) {
            $io->out(sprintf('Filtering by table: %s', $table));
        }

        if ($dryRun) {
            $io->out('<warning>Dry run mode</warning>');
        }

        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogsTable */
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');

        $cutoffDate = DateTime::now()->subDays($retention);

        // Build query conditions
        $conditions = ['created <' => $cutoffDate];
        if ($table) {
            $conditions['source'] = $table;
        }

        // Count records to delete
        $count = $auditLogsTable->find()
            ->where($conditions)
            ->count();

        if ($count === 0) {
            $io->success('No audit logs to delete.');

            return self::CODE_SUCCESS;
        }

        $io->out(sprintf('Found %d audit log(s) older than %s', $count, $cutoffDate->toDateString()));

        if ($dryRun) {
            // Show summary by table
            $summary = $auditLogsTable->find()
                ->select([
                    'source',
                    'count' => $auditLogsTable->find()->func()->count('*'),
                ])
                ->where($conditions)
                ->groupBy(['source'])
                ->orderBy(['count' => 'DESC'])
                ->all();

            $io->out('');
            $io->out('Summary by table:');
            foreach ($summary as $row) {
                $io->out(sprintf('  %s: %d record(s)', $row['source'], $row['count']));
            }

            return self::CODE_SUCCESS;
        }

        // Delete records
        $deleted = $auditLogsTable->deleteAll($conditions);

        $io->success(sprintf('Successfully deleted %d audit log(s).', $deleted));

        return self::CODE_SUCCESS;
    }

    /**
     * Get retention period in days from config or command option
     *
     * Returns null if retention is disabled for the table (configured as false).
     *
     * @param \Cake\Console\Arguments $args Command arguments
     * @param string|null $table Table name for table-specific retention
     *
     * @return int|null Retention period in days, or null if disabled
     */
    protected function getRetentionDays(Arguments $args, ?string $table): ?int
    {
        // Command line option takes precedence
        $retention = $args->getOption('retention');
        if ($retention !== null && $retention !== false) {
            return (int)$retention;
        }

        // Check for table-specific retention in config
        if ($table) {
            $tableRetention = Configure::read('AuditStash.retention.tables.' . $table);
            // false means retention is disabled for this table
            if ($tableRetention === false) {
                return null;
            }
            if ($tableRetention !== null) {
                return (int)$tableRetention;
            }
        }

        // Fall back to default retention from config
        $defaultRetention = Configure::read('AuditStash.retention.default');
        if ($defaultRetention !== null) {
            return (int)$defaultRetention;
        }

        // Ultimate fallback
        return 90;
    }
}
