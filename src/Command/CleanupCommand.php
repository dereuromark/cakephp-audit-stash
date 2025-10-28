<?php
declare(strict_types=1);

namespace AuditStash\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

/**
 * Command to cleanup old audit logs based on retention policies.
 *
 * This command can be run via cron to automatically delete audit logs
 * that are older than the configured retention period.
 *
 * Configuration example:
 * ```
 * 'AuditStash' => [
 *     'retention' => [
 *         'default' => 90, // days to keep logs by default
 *         'tables' => [
 *             'users' => 180, // keep user logs for 6 months
 *             'orders' => 2555, // keep order logs for 7 years
 *         ],
 *     ],
 * ],
 * ```
 */
class CleanupCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * Default retention period in days.
     *
     * @var int
     */
    protected int $defaultRetention = 90;

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Cleanup old audit logs based on retention policies')
            ->addOption('retention', [
                'short' => 'r',
                'help' => 'Number of days to keep audit logs (overrides configuration)',
                'default' => null,
            ])
            ->addOption('table', [
                'short' => 'T',
                'help' => 'Only cleanup logs for a specific table/source',
                'default' => null,
            ])
            ->addOption('dry-run', [
                'short' => 'd',
                'help' => 'Show what would be deleted without actually deleting',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('force', [
                'short' => 'f',
                'help' => 'Skip confirmation prompt',
                'boolean' => true,
                'default' => false,
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $persisterClass = Configure::read('AuditStash.persister');
        if (!$persisterClass || !str_contains($persisterClass, 'TablePersister')) {
            $io->error('This command only works with TablePersister. ElasticSearch cleanup should be handled via ILM policies.');

            return static::CODE_ERROR;
        }

        /** @var string|null $table */
        $table = $args->getOption('table');
        /** @var bool $dryRun */
        $dryRun = $args->getOption('dry-run');
        /** @var bool $force */
        $force = $args->getOption('force');

        $retention = $this->getRetentionPeriod($args, $table);
        $cutoffDate = (new DateTime())->modify("-{$retention} days");

        $io->info("Retention policy: {$retention} days");
        $io->info('Cutoff date: ' . $cutoffDate->format('Y-m-d H:i:s'));

        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');
        $query = $auditLogsTable->find()
            ->where(['created <' => $cutoffDate]);

        if ($table !== null) {
            $query->where(['source' => $table]);
            $io->info("Filtering by table: {$table}");
        }

        $count = $query->count();

        if ($count === 0) {
            $io->success('No audit logs to delete.');

            return static::CODE_SUCCESS;
        }

        $io->warning("Found {$count} audit log(s) to delete.");

        if ($dryRun) {
            $io->info('Dry run mode - no records will be deleted.');
            $this->displaySummary($io, $query);

            return static::CODE_SUCCESS;
        }

        if (!$force) {
            $continue = $io->askChoice(
                'Do you want to proceed with deletion?',
                ['y', 'n'],
                'n',
            );

            if ($continue !== 'y') {
                $io->info('Cleanup cancelled.');

                return static::CODE_SUCCESS;
            }
        }

        $deleted = $auditLogsTable->deleteAll($query->clause('where'));

        $io->success("Successfully deleted {$deleted} audit log(s).");

        return static::CODE_SUCCESS;
    }

    /**
     * Display summary of records to be deleted.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return void
     */
    protected function displaySummary(ConsoleIo $io, SelectQuery $query): void
    {
        $summary = $query
            ->select([
                'source',
                'count' => $query->func()->count('*'),
            ])
            ->groupBy(['source'])
            ->orderBy(['source'])
            ->toArray();

        if (empty($summary)) {
            return;
        }

        $io->out('');
        $io->out('Summary by table:');
        $io->hr();

        foreach ($summary as $row) {
            $source = $row->source ?? 'N/A';
            $count = $row->count ?? 0;
            $io->out(sprintf('  %-30s %d', $source, $count));
        }

        $io->hr();
    }

    /**
     * Get retention period based on configuration and arguments.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param string|null $table Table name
     * @return int Retention period in days
     */
    protected function getRetentionPeriod(Arguments $args, ?string $table): int
    {
        // Command line override
        /** @var int|null $argRetention */
        $argRetention = $args->getOption('retention');
        if ($argRetention !== null) {
            return (int)$argRetention;
        }

        $config = Configure::read('AuditStash.retention', []);

        // Table-specific retention
        if ($table !== null && isset($config['tables'][$table])) {
            return (int)$config['tables'][$table];
        }

        // Default retention from config
        if (isset($config['default'])) {
            return (int)$config['default'];
        }

        // Fallback to hardcoded default
        return $this->defaultRetention;
    }
}
