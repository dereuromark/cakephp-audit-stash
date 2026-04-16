<?php

declare(strict_types=1);

namespace AuditStash\Command;

use AuditStash\Service\ChainVerifier;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Verifies the SHA-256 hash chain on an audit log table.
 *
 * Usage:
 *
 *     bin/cake audit_stash verify_chain
 *     bin/cake audit_stash verify_chain --table=CustomAuditLogs --chunk=1000
 *
 * Exits with code 0 if the chain is intact, 1 if a break is found. Wire
 * this up as a cron job for continuous tamper-evidence monitoring.
 */
class VerifyChainCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'audit_stash verify_chain';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Verify the SHA-256 hash chain on an audit-log table.')
            ->addOption('table', [
                'help' => 'Table alias to verify. Defaults to AuditStash.AuditLogs.',
                'default' => 'AuditStash.AuditLogs',
            ])
            ->addOption('chunk', [
                'help' => 'How many rows to read per query. Larger = fewer round trips, more memory.',
                'default' => '500',
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $tableAlias = (string)$args->getOption('table');
        $chunk = max(1, (int)$args->getOption('chunk'));

        $table = $this->fetchTable($tableAlias);

        $io->out(sprintf('<info>Verifying hash chain on <comment>%s</comment>…</info>', $tableAlias));

        $result = (new ChainVerifier())->verify($table, $chunk);

        if ($result->intact) {
            $io->success(sprintf(
                'Chain intact — %d row(s) verified.',
                $result->rowsChecked,
            ));

            return self::CODE_SUCCESS;
        }

        $io->error(sprintf(
            'Chain broken at row id=%d (position %d): %s',
            (int)$result->brokenRowId,
            $result->rowsChecked,
            (string)$result->reason,
        ));

        return self::CODE_ERROR;
    }
}
