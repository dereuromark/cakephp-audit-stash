<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Service\CoverageService;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

class CoverageServiceTest extends TestCase
{
    protected array $fixtures = ['plugin.AuditStash.AuditLogs'];

    /**
     * Plugin tables can be registered in CakePHP under either the dotted
     * alias (`Comments.Comments`) or the bare short alias (`Comments`),
     * depending on how the table was first loaded — `fetchTable()` with
     * the prefix vs. associations from app code without it.
     * `AuditLogBehavior` persists the source as `getRegistryAlias()`, so
     * the same logical table can show up in `audit_logs` under either
     * form.
     *
     * The coverage report's "View activity" link must use the form that
     * actually has rows. Before this regression test it always linked to
     * the dotted alias, so when the plugin's audit rows lived under the
     * short alias the link found nothing.
     *
     * @return void
     */
    public function testRowResolvesShortAliasSourceWhenDottedFormHasNoEvents(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $auditLogs->save($auditLogs->newEntity([
            'transaction_key' => 'tx-1',
            'type' => 'create',
            'source' => 'AuditLogs',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));

        $service = new class extends CoverageService {
            protected function discoverClasses(): array
            {
                return [
                    // Pretend AuditLogs is a plugin-prefixed table — so we
                    // exercise the "dotted alias has no events, fall back
                    // to short alias" branch without needing a real
                    // app-level plugin in the test fixtures.
                    'AuditStash.AuditLogs' => [
                        'class' => 'AuditStash\\Model\\Table\\AuditLogsTable',
                        'plugin' => 'AuditStash',
                        'short_alias' => 'AuditLogs',
                    ],
                ];
            }
        };

        $rows = $service->discover(includeInternal: true);
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('AuditStash.AuditLogs', $row['alias']);
        $this->assertSame('AuditLogs', $row['source'], 'Link must point at the form audit_logs actually used.');
        $this->assertSame(1, $row['events_30d']);
    }

    /**
     * When the dotted form has its own rows (e.g. an installation that
     * consistently loads via `fetchTable('Plugin.Table')`), the link
     * stays on the dotted form — that's the more specific identifier,
     * and the row count came from there.
     *
     * @return void
     */
    public function testRowKeepsDottedSourceWhenDottedFormHasEvents(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $auditLogs->save($auditLogs->newEntity([
            'transaction_key' => 'tx-2',
            'type' => 'create',
            'source' => 'AuditStash.AuditLogs',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));

        $service = new class extends CoverageService {
            protected function discoverClasses(): array
            {
                return [
                    'AuditStash.AuditLogs' => [
                        'class' => 'AuditStash\\Model\\Table\\AuditLogsTable',
                        'plugin' => 'AuditStash',
                        'short_alias' => 'AuditLogs',
                    ],
                ];
            }
        };

        $rows = $service->discover(includeInternal: true);
        $this->assertCount(1, $rows);
        $this->assertSame('AuditStash.AuditLogs', $rows[0]['source']);
    }

    /**
     * Plain (non-plugin) tables: alias and source are identical.
     *
     * @return void
     */
    public function testRowSourceMatchesAliasForNonPluginTables(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $auditLogs->save($auditLogs->newEntity([
            'transaction_key' => 'tx-3',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));

        $service = new class extends CoverageService {
            protected function discoverClasses(): array
            {
                return [
                    'Articles' => [
                        'class' => 'App\\Model\\Table\\ArticlesTable',
                        'plugin' => null,
                        'short_alias' => 'Articles',
                    ],
                ];
            }
        };

        $rows = $service->discover(includeInternal: true);
        $this->assertCount(1, $rows);
        $this->assertSame('Articles', $rows[0]['alias']);
        $this->assertSame('Articles', $rows[0]['source']);
    }

    /**
     * When the same logical plugin table has rows under BOTH aliases (a
     * messy state after a refactor), the dotted form is preferred — it's
     * the more specific identifier, and a follow-up cleanup can decide
     * what to do with the orphaned short-alias rows.
     *
     * @return void
     */
    public function testDottedFormWinsWhenBothAliasesHaveEvents(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $auditLogs->save($auditLogs->newEntity([
            'transaction_key' => 'tx-dotted',
            'type' => 'create',
            'source' => 'AuditStash.AuditLogs',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));
        $auditLogs->save($auditLogs->newEntity([
            'transaction_key' => 'tx-short',
            'type' => 'create',
            'source' => 'AuditLogs',
            'primary_key' => 2,
            'created' => new DateTime(),
        ]));

        $service = new class extends CoverageService {
            protected function discoverClasses(): array
            {
                return [
                    'AuditStash.AuditLogs' => [
                        'class' => 'AuditStash\\Model\\Table\\AuditLogsTable',
                        'plugin' => 'AuditStash',
                        'short_alias' => 'AuditLogs',
                    ],
                ];
            }
        };

        $rows = $service->discover(includeInternal: true);
        // The dotted-form row has events on its own, so we don't fall
        // back; the short-alias rows surface separately as 'empirical'
        // (orphaned source — operator decides whether to backfill or
        // delete them).
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[$r['status']][] = $r;
        }

        $this->assertCount(1, $byStatus['internal'] ?? [], 'Dotted-form class row.');
        $this->assertSame('AuditStash.AuditLogs', $byStatus['internal'][0]['source']);
        $this->assertSame(1, $byStatus['internal'][0]['events_30d']);

        $this->assertCount(1, $byStatus['empirical'] ?? [], 'Short-alias rows surface as empirical.');
        $this->assertSame('AuditLogs', $byStatus['empirical'][0]['source']);
        $this->assertSame(1, $byStatus['empirical'][0]['events_30d']);
    }
}
