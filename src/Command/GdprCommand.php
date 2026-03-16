<?php

declare(strict_types=1);

namespace AuditStash\Command;

use AuditStash\Service\GdprService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;

/**
 * GDPR compliance command for audit logs.
 *
 * Provides tools for anonymizing, deleting, and exporting user audit logs
 * to comply with GDPR requirements (Right to Erasure, Data Portability).
 *
 * Usage:
 *   bin/cake audit_stash gdpr anonymize --user-id=123
 *   bin/cake audit_stash gdpr delete --user-id=123 --force
 *   bin/cake audit_stash gdpr export --user-id=123 --output=/path/to/file.json
 *   bin/cake audit_stash gdpr stats --user-id=123
 */
class GdprCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'audit_stash gdpr';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'GDPR compliance tools for audit logs (anonymize, delete, export)';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        parent::buildOptionParser($parser);

        $parser
            ->setDescription('GDPR compliance tools for user audit logs.')
            ->addArgument('action', [
                'help' => 'Action to perform: anonymize, delete, export, stats',
                'required' => true,
                'choices' => ['anonymize', 'delete', 'export', 'stats'],
            ])
            ->addOption('user-id', [
                'short' => 'u',
                'help' => 'User ID to process (required)',
                'required' => true,
            ])
            ->addOption('dry-run', [
                'short' => 'd',
                'boolean' => true,
                'help' => 'Show what would be done without making changes',
                'default' => false,
            ])
            ->addOption('force', [
                'short' => 'f',
                'boolean' => true,
                'help' => 'Skip confirmation prompt (required for delete)',
                'default' => false,
            ])
            ->addOption('output', [
                'short' => 'o',
                'help' => 'Output file path for export action',
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
        if (!is_a($persister, 'AuditStash\Persister\TablePersister', true)) {
            $io->error('This command only works with TablePersister.');

            return self::CODE_ERROR;
        }

        $action = $args->getArgument('action');
        $userIdOption = $args->getOption('user-id');

        if (!$userIdOption || !is_string($userIdOption)) {
            $io->error('User ID is required (--user-id)');

            return self::CODE_ERROR;
        }

        $userId = $userIdOption;
        $service = new GdprService();

        return match ($action) {
            'anonymize' => $this->executeAnonymize($service, $userId, $args, $io),
            'delete' => $this->executeDelete($service, $userId, $args, $io),
            'export' => $this->executeExport($service, $userId, $args, $io),
            'stats' => $this->executeStats($service, $userId, $io),
            default => self::CODE_ERROR,
        };
    }

    /**
     * Execute anonymize action.
     *
     * @param \AuditStash\Service\GdprService $service GDPR service
     * @param string $userId User ID
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     *
     * @return int Exit code
     */
    protected function executeAnonymize(GdprService $service, string $userId, Arguments $args, ConsoleIo $io): int
    {
        $dryRun = (bool)$args->getOption('dry-run');
        $stats = $service->getStats($userId);

        if ($stats['total'] === 0) {
            $io->success('No audit logs found for user ID: ' . $userId);

            return self::CODE_SUCCESS;
        }

        $io->out('');
        $io->out(sprintf('<info>GDPR Anonymization%s</info>', $dryRun ? ' (Dry Run)' : ''));
        $io->hr();
        $io->out(sprintf('User ID: %s', $userId));
        $io->out(sprintf('Records found: %d', $stats['total']));
        $io->out('');

        $io->out('By table:');
        foreach ($stats['by_table'] as $table => $count) {
            $io->out(sprintf('  - %s: %d record(s)', $table, $count));
        }
        $io->out('');

        $io->out('Fields to anonymize:');
        $io->out('  - user_id, user_display');
        $io->out('  - meta: user, username, email, ip, user_agent');
        $io->out('  - PII fields in original/changed data');
        $io->out('');

        if ($dryRun) {
            $io->warning('Dry run mode - no changes made.');
            $io->out('Run without --dry-run to execute.');

            return self::CODE_SUCCESS;
        }

        $count = $service->anonymize($userId);

        $io->success(sprintf('Successfully anonymized %d audit log(s).', $count));

        return self::CODE_SUCCESS;
    }

    /**
     * Execute delete action.
     *
     * @param \AuditStash\Service\GdprService $service GDPR service
     * @param string $userId User ID
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     *
     * @return int Exit code
     */
    protected function executeDelete(GdprService $service, string $userId, Arguments $args, ConsoleIo $io): int
    {
        $dryRun = (bool)$args->getOption('dry-run');
        $force = (bool)$args->getOption('force');
        $stats = $service->getStats($userId);

        if ($stats['total'] === 0) {
            $io->success('No audit logs found for user ID: ' . $userId);

            return self::CODE_SUCCESS;
        }

        $io->out('');
        $io->out(sprintf('<warning>GDPR Deletion%s</warning>', $dryRun ? ' (Dry Run)' : ''));
        $io->hr();
        $io->out(sprintf('User ID: %s', $userId));
        $io->out(sprintf('Records to delete: %d', $stats['total']));
        $io->out('');

        $io->out('By table:');
        foreach ($stats['by_table'] as $table => $count) {
            $io->out(sprintf('  - %s: %d record(s)', $table, $count));
        }
        $io->out('');

        if ($dryRun) {
            $io->warning('Dry run mode - no changes made.');
            $io->out('Run without --dry-run to execute.');

            return self::CODE_SUCCESS;
        }

        if (!$force) {
            $io->error('Deletion requires --force flag. This action is irreversible!');
            $io->warning('Consider using "anonymize" instead to preserve audit trail integrity.');

            return self::CODE_ERROR;
        }

        $count = $service->delete($userId);

        $io->success(sprintf('Successfully deleted %d audit log(s).', $count));

        return self::CODE_SUCCESS;
    }

    /**
     * Execute export action.
     *
     * @param \AuditStash\Service\GdprService $service GDPR service
     * @param string $userId User ID
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     *
     * @return int Exit code
     */
    protected function executeExport(GdprService $service, string $userId, Arguments $args, ConsoleIo $io): int
    {
        $stats = $service->getStats($userId);

        if ($stats['total'] === 0) {
            $io->success('No audit logs found for user ID: ' . $userId);

            return self::CODE_SUCCESS;
        }

        $io->out('');
        $io->out('<info>GDPR Export</info>');
        $io->hr();
        $io->out(sprintf('User ID: %s', $userId));
        $io->out(sprintf('Records to export: %d', $stats['total']));
        $io->out('');

        $json = $service->export($userId, 'json');

        $outputPath = $args->getOption('output');
        if ($outputPath && is_string($outputPath)) {
            if (file_put_contents($outputPath, $json) !== false) {
                $io->success(sprintf('Export saved to: %s', $outputPath));
            } else {
                $io->error(sprintf('Failed to write to: %s', $outputPath));

                return self::CODE_ERROR;
            }
        } else {
            $io->out($json);
        }

        return self::CODE_SUCCESS;
    }

    /**
     * Execute stats action.
     *
     * @param \AuditStash\Service\GdprService $service GDPR service
     * @param string $userId User ID
     * @param \Cake\Console\ConsoleIo $io Console IO
     *
     * @return int Exit code
     */
    protected function executeStats(GdprService $service, string $userId, ConsoleIo $io): int
    {
        $stats = $service->getStats($userId);

        $io->out('');
        $io->out('<info>GDPR Statistics</info>');
        $io->hr();
        $io->out(sprintf('User ID: %s', $userId));
        $io->out(sprintf('Total records: %d', $stats['total']));
        $io->out('');

        if ($stats['total'] === 0) {
            $io->success('No audit logs found for this user.');

            return self::CODE_SUCCESS;
        }

        $io->out('By table:');
        foreach ($stats['by_table'] as $table => $count) {
            $io->out(sprintf('  - %s: %d', $table, $count));
        }
        $io->out('');

        $io->out('By event type:');
        foreach ($stats['by_type'] as $type => $count) {
            $io->out(sprintf('  - %s: %d', $type, $count));
        }

        return self::CODE_SUCCESS;
    }
}
