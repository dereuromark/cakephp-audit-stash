<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RuntimeException;

/**
 * AuditStash\Controller\Admin\AuditLogsController Test Case
 *
 * @uses \AuditStash\Controller\Admin\AuditLogsController
 */
class AuditLogsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
        'plugin.AuditStash.Articles',
    ];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Configure::delete('AuditStash.accessCheck');
        parent::tearDown();
    }

    public function testAccessCheckUnsetIsNoOp(): void
    {
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);

        $this->assertResponseOk();
    }

    public function testAccessCheckNonClosureRejects(): void
    {
        $this->disableErrorHandlerMiddleware();
        Configure::write('AuditStash.accessCheck', 'not a closure');

        $this->expectException(ForbiddenException::class);
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);
    }

    public function testAccessCheckClosureFalseRejects(): void
    {
        $this->disableErrorHandlerMiddleware();
        Configure::write('AuditStash.accessCheck', fn () => false);

        $this->expectException(ForbiddenException::class);
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);
    }

    public function testAccessCheckRequiresStrictTrue(): void
    {
        $this->disableErrorHandlerMiddleware();
        Configure::write('AuditStash.accessCheck', fn () => 1);

        $this->expectException(ForbiddenException::class);
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);
    }

    public function testAccessCheckClosureTrueAllows(): void
    {
        Configure::write('AuditStash.accessCheck', fn () => true);

        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);

        $this->assertResponseOk();
    }

    public function testAccessCheckThrowingYields403(): void
    {
        $this->disableErrorHandlerMiddleware();
        Configure::write('AuditStash.accessCheck', function (): bool {
            throw new RuntimeException('oops');
        });

        $this->expectException(ForbiddenException::class);
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);
    }

    public function testAccessCheckExplicitForbiddenIsRespected(): void
    {
        $this->disableErrorHandlerMiddleware();
        Configure::write('AuditStash.accessCheck', function (): bool {
            throw new ForbiddenException('custom denial reason');
        });

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('custom denial reason');
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);
    }

    public function testAccessCheckReceivesRequest(): void
    {
        $received = null;
        Configure::write('AuditStash.accessCheck', function ($request) use (&$received): bool {
            $received = $request;

            return true;
        });

        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);

        $this->assertResponseOk();
        $this->assertNotNull($received);
        $this->assertStringContainsString('audit', $received->getPath());
    }

    /**
     * Test index method
     *
     * @return void
     */
    public function testIndex(): void
    {
        $this->get(['prefix' => 'Admin', 'plugin' => 'AuditStash', 'controller' => 'AuditLogs', 'action' => 'index']);

        $this->assertResponseOk();
        $this->assertResponseContains('Audit Logs');
    }

    /**
     * Test index method with filters
     *
     * @return void
     */
    public function testIndexWithFilters(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create test logs
        $log1 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'user' => 'testuser',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 2,
            'user' => 'admin',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        // Test filter by source
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['source' => 'articles'],
        ]);

        $this->assertResponseOk();

        // Test filter by type
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['type' => 'update'],
        ]);

        $this->assertResponseOk();

        // Test filter by user
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['user' => 'admin'],
        ]);

        $this->assertResponseOk();
    }

    /**
     * LIKE wildcards in `user_id` must be escaped, not treated as wildcards.
     *
     * Without escaping, a literal `_` matches every row and `%a%` ignores
     * positional intent (Issue #1). The fix `addcslashes()` escapes them so
     * the search behaves as a substring match on the literal needle.
     *
     * @return void
     */
    public function testIndexUserIdLikeWildcardsAreEscaped(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'tx-real',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => 'real-user',
            'created' => new DateTime(),
        ]));

        // A bare `_` would match everything if not escaped. With escaping it
        // matches nothing because no `user_id` contains a literal underscore
        // matching the needle "_".
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['user_id' => '_'],
        ]);

        $this->assertResponseOk();
        $auditLogs = $this->viewVariable('auditLogs');
        $this->assertNotNull($auditLogs);
        $count = 0;
        foreach ($auditLogs as $_log) {
            $count++;
        }
        $this->assertSame(0, $count, 'Underscore must not act as a SQL LIKE wildcard.');
    }

    /**
     * JSON-path filter inputs must reject non-identifier values to prevent
     * path injection into MySQL `JSON_CONTAINS_PATH` etc. (Issue #2).
     *
     * @return void
     */
    public function testIndexChangedFieldRejectsPathMetaCharacters(): void
    {
        $this->disableErrorHandlerMiddleware();
        $this->expectException(BadRequestException::class);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['changed_field' => 'foo.bar'],
        ]);
    }

    /**
     * `field_name` is also interpolated into the JSON path, so it gets the
     * same identifier-shape gate (Issue #2).
     *
     * @return void
     */
    public function testIndexFieldNameRejectsPathMetaCharacters(): void
    {
        $this->disableErrorHandlerMiddleware();
        $this->expectException(BadRequestException::class);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['field_name' => '*', 'field_value' => 'x'],
        ]);
    }

    /**
     * Empty/missing JSON-path filters should be ignored, not rejected.
     *
     * @return void
     */
    public function testIndexAcceptsEmptyChangedFieldFilter(): void
    {
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['changed_field' => ''],
        ]);

        $this->assertResponseOk();
    }

    /**
     * Test index method with date range filter
     *
     * @return void
     */
    public function testIndexWithDateRange(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime('2024-01-15'),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => [
                'date_from' => '2024-01-01',
                'date_to' => '2024-01-31',
            ],
        ]);

        $this->assertResponseOk();
    }

    /**
     * Test view method
     *
     * @return void
     */
    public function testView(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'display_value' => 'Test Article',
            'user_id' => 'user-123',
            'user_display' => 'testuser',
            'original' => json_encode(['title' => 'Old Title']),
            'changed' => json_encode(['title' => 'New Title']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'view',
            $log->id,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Audit Log Details');
        $this->assertResponseContains('Test Article');
        $this->assertResponseContains('testuser');
    }

    /**
     * Test timeline method
     *
     * @return void
     */
    public function testTimeline(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create multiple logs for same record
        $log1 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'changed' => json_encode(['title' => 'First Title']),
            'created' => new DateTime('-2 days'),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'original' => json_encode(['title' => 'First Title']),
            'changed' => json_encode(['title' => 'Updated Title']),
            'created' => new DateTime('-1 day'),
        ]);
        $auditLogsTable->save($log2);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'timeline',
            'articles',
            '1',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Audit Timeline');
        $this->assertResponseContains('articles');
    }

    /**
     * Test timeline method without parameters
     *
     * @return void
     */
    public function testTimelineWithoutParams(): void
    {
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'timeline',
        ]);

        $this->assertResponseCode(302);
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test export method with CSV format
     *
     * @return void
     */
    public function testExportCsv(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user' => 'testuser',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'csv',
        ]);

        $this->assertResponseOk();
        $this->assertContentType('text/csv');
        $this->assertHeaderContains('Content-Disposition', 'attachment');
        // Header row uses snake_case column names so the CSV is unambiguous
        // for programmatic consumers (was 'ID', 'Transaction', etc. pre-2.0).
        $this->assertResponseContains('id');
        $this->assertResponseContains('transaction_key');
        $this->assertResponseContains('type');
    }

    /**
     * Test export method with JSON format
     *
     * @return void
     */
    public function testExportJson(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user' => 'testuser',
            'original' => json_encode(['title' => 'Old']),
            'changed' => json_encode(['title' => 'New']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'json',
        ]);

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertHeaderContains('Content-Disposition', 'attachment');

        $data = json_decode($this->_response->getBody()->__toString(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('transaction_key', $data[0]);
        $this->assertArrayHasKey('type', $data[0]);
    }

    /**
     * Test export method with filters
     *
     * @return void
     */
    public function testExportWithFilters(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log1 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-2',
            'type' => 'create',
            'source' => 'users',
            'primary_key' => 2,
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'json',
            '?' => [
                'source' => 'articles',
            ],
        ]);

        $this->assertResponseOk();
        $this->assertContentType('application/json');

        $data = json_decode($this->_response->getBody()->__toString(), true);
        $this->assertCount(1, $data);
        $this->assertSame('articles', $data[0]['source']);
    }

    /**
     * Without an `_ext` or `?format=`, the export action renders the
     * dedicated form page (introduced in 2.0) instead of streaming a
     * default-format download.
     *
     * @return void
     */
    public function testExportRendersFormPageWithoutFormat(): void
    {
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
        ]);

        $this->assertResponseOk();
        $this->assertContentType('text/html');
        $this->assertResponseContains('Export Audit Logs');
        $this->assertResponseContains('Format');
    }

    /**
     * The form-page submit path passes the chosen format via a query
     * parameter (the form is plain `GET`), and that path streams the
     * download just like the legacy `_ext`-based URL.
     *
     * @return void
     */
    public function testExportFormatQueryParamStreamsDownload(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'tx-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '?' => ['format' => 'ndjson'],
        ]);

        $this->assertResponseOk();
        $this->assertContentType('application/x-ndjson');
        $this->assertHeaderContains('Content-Disposition', 'attachment');
    }

    /**
     * NDJSON: one JSON object per line. Use to verify the streaming
     * format actually emits valid line-delimited JSON.
     *
     * @return void
     */
    public function testExportNdjson(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        for ($i = 1; $i <= 3; $i++) {
            $auditLogsTable->save($auditLogsTable->newEntity([
                'transaction_key' => 'tx-' . $i,
                'type' => 'update',
                'source' => 'articles',
                'primary_key' => $i,
                'created' => new DateTime(),
            ]));
        }

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'ndjson',
        ]);

        $this->assertResponseOk();
        $body = trim((string)$this->_response->getBody());
        $lines = explode("\n", $body);
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('transaction_key', $row);
        }
    }

    /**
     * The hard cap is enforced server-side; oversized exports are rejected
     * before any download starts so the user gets a clear error rather
     * than a silently truncated file (the pre-2.0 behaviour was a hidden
     * `LIMIT 10000`).
     *
     * @return void
     */
    public function testExportRefusesWhenHardCapExceeded(): void
    {
        Configure::write('AuditStash.export.hardCap', 2);

        try {
            $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
            for ($i = 1; $i <= 5; $i++) {
                $auditLogsTable->save($auditLogsTable->newEntity([
                    'transaction_key' => 'tx-' . $i,
                    'type' => 'create',
                    'source' => 'articles',
                    'primary_key' => $i,
                    'created' => new DateTime(),
                ]));
            }

            $this->disableErrorHandlerMiddleware();
            $thrown = null;
            try {
                $this->get([
                    'prefix' => 'Admin',
                    'plugin' => 'AuditStash',
                    'controller' => 'AuditLogs',
                    'action' => 'export',
                    '_ext' => 'csv',
                ]);
            } catch (BadRequestException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'Expected the hard cap to throw BadRequestException.');
            $this->assertStringContainsString('exceeding the hard cap of 2', $thrown->getMessage());
        } finally {
            Configure::delete('AuditStash.export.hardCap');
        }
    }

    /**
     * Without an explicit date range, the export defaults to the last N
     * days (configurable via `AuditStash.export.defaultDays`). This stops
     * an unbounded export from getting slower with every passing month.
     *
     * @return void
     */
    public function testExportDefaultDateRangeNarrowsResultSet(): void
    {
        Configure::write('AuditStash.export.defaultDays', 7);

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $recent = $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'recent',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));
        $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'ancient',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 2,
            'created' => new DateTime('-90 days'),
        ]));

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'json',
        ]);

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data, 'Default date range must filter out the 90-day-old row.');
        $this->assertSame('recent', $data[0]['transaction_key']);

        Configure::delete('AuditStash.export.defaultDays');
    }

    /**
     * An explicit `date_from` overrides the default narrowing, so a
     * one-shot "give me everything since launch" export is still possible
     * by setting an early date.
     *
     * @return void
     */
    public function testExplicitDateRangeOverridesDefault(): void
    {
        Configure::write('AuditStash.export.defaultDays', 7);

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'recent',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]));
        $auditLogsTable->save($auditLogsTable->newEntity([
            'transaction_key' => 'ancient',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 2,
            'created' => new DateTime('-90 days'),
        ]));

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '_ext' => 'json',
            '?' => ['date_from' => date('Y-m-d', strtotime('-180 days'))],
        ]);

        $this->assertResponseOk();
        $data = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(2, $data, 'Explicit date_from must include the 90-day-old row.');

        Configure::delete('AuditStash.export.defaultDays');
    }

    /**
     * Unknown format strings via `?format=` are rejected with 400 rather
     * than falling back silently to CSV.
     *
     * @return void
     */
    public function testExportRejectsUnknownFormat(): void
    {
        $this->disableErrorHandlerMiddleware();
        $this->expectException(BadRequestException::class);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '?' => ['format' => 'xml'],
        ]);
    }

    /**
     * Test revertPreview method
     *
     * @return void
     */
    public function testRevertPreview(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $articlesTable = $this->getTableLocator()->get('Articles');

        // Create article
        $article = $articlesTable->newEntity([
            'id' => 1,
            'title' => 'Current Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articlesTable->save($article);

        // Create audit log
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => '1',
            'original' => json_encode(['title' => 'Old Title']),
            'changed' => json_encode(['title' => 'Old Title']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'revertPreview',
            $log->id,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Revert Preview');
    }

    /**
     * Test restore method GET request
     *
     * @return void
     */
    public function testRestoreGet(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create delete audit log
        $deleteLog = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-delete',
            'type' => 'delete',
            'source' => 'Articles',
            'primary_key' => '99',
            'original' => json_encode([
                'id' => 99,
                'title' => 'Deleted Article',
                'body' => 'This was deleted',
            ]),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($deleteLog);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'restore',
            'Articles',
            '99',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Restore Deleted Record');
        $this->assertResponseContains('Deleted Article');
    }

    /**
     * Test revert POST request (full revert)
     *
     * @return void
     */
    public function testRevertPostFull(): void
    {
        $this->enableRetainFlashMessages();

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $articlesTable = $this->getTableLocator()->get('Articles');

        // Create article
        $article = $articlesTable->newEntity([
            'id' => 10,
            'title' => 'Current Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articlesTable->save($article);

        // Create audit log
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '10',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->post([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'revert',
            $log->id,
        ]);

        $this->assertResponseCode(302);
        $this->assertRedirect(['action' => 'view', $log->id]);
        $this->assertFlashMessage('Record reverted successfully.');

        // Verify article was reverted
        $article = $articlesTable->get(10);
        $this->assertEquals('Original Title', $article->title);
    }

    /**
     * Test revert POST request (partial revert)
     *
     * @return void
     */
    public function testRevertPostPartial(): void
    {
        $this->enableRetainFlashMessages();

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $articlesTable = $this->getTableLocator()->get('Articles');

        // Create article
        $article = $articlesTable->newEntity([
            'id' => 11,
            'title' => 'Current Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articlesTable->save($article);

        // Create audit log
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '11',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->post(
            [
                'prefix' => 'Admin',
                'plugin' => 'AuditStash',
                'controller' => 'AuditLogs',
                'action' => 'revert',
                $log->id,
            ],
            ['fields' => ['title']],
        );

        $this->assertResponseCode(302);
        $this->assertFlashMessage('Record reverted successfully.');

        // Verify only title was reverted
        $article = $articlesTable->get(11);
        $this->assertEquals('Original Title', $article->title);
        $this->assertEquals('Current Body', $article->body);
    }

    /**
     * Test view method displays revert event correctly (smoke test)
     *
     * @return void
     */
    public function testViewRevertEvent(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create a revert audit log
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-revert',
            'type' => 'revert',
            'source' => 'Articles',
            'primary_key' => '1',
            'original' => json_encode(['title' => 'Current Title']),
            'changed' => json_encode(['title' => 'Reverted Title']),
            'meta' => json_encode([
                'revert_to_audit_id' => 123,
                'revert_type' => 'partial',
            ]),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'view',
            $log->id,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Audit Log Details');
    }

    /**
     * Test timeline with revert events renders correctly (smoke test)
     *
     * @return void
     */
    public function testTimelineWithRevertEvents(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create various log types for the same record
        $logs = [
            [
                'transaction_key' => 'test-transaction-1',
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => '5',
                'changed' => json_encode(['title' => 'Original']),
                'created' => new DateTime('-3 days'),
            ],
            [
                'transaction_key' => 'test-transaction-2',
                'type' => 'update',
                'source' => 'Articles',
                'primary_key' => '5',
                'original' => json_encode(['title' => 'Original']),
                'changed' => json_encode(['title' => 'Updated']),
                'created' => new DateTime('-2 days'),
            ],
            [
                'transaction_key' => 'test-transaction-3',
                'type' => 'revert',
                'source' => 'Articles',
                'primary_key' => '5',
                'original' => json_encode(['title' => 'Updated']),
                'changed' => json_encode(['title' => 'Original']),
                'meta' => json_encode([
                    'revert_to_audit_id' => 1,
                    'revert_type' => 'full',
                ]),
                'created' => new DateTime('-1 day'),
            ],
            [
                'transaction_key' => 'test-transaction-4',
                'type' => 'delete',
                'source' => 'Articles',
                'primary_key' => '5',
                'original' => json_encode(['title' => 'Original']),
                'changed' => json_encode([]),
                'created' => new DateTime(),
            ],
        ];

        foreach ($logs as $logData) {
            $log = $auditLogsTable->newEntity($logData);
            $auditLogsTable->save($log);
        }

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'timeline',
            'Articles',
            '5',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Audit Timeline');
        $this->assertResponseContains('marker-success'); // create
        $this->assertResponseContains('marker-primary'); // update
        $this->assertResponseContains('marker-warning'); // revert
        $this->assertResponseContains('marker-danger'); // delete
        $this->assertResponseContains('Record reverted'); // revert description
    }

    /**
     * Test revert preview with no changes renders correctly (smoke test)
     *
     * @return void
     */
    public function testRevertPreviewNoChanges(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $articlesTable = $this->getTableLocator()->get('Articles');

        // Create article matching audit state
        $article = $articlesTable->newEntity([
            'id' => 20,
            'title' => 'Same Title',
            'body' => 'Same Body',
            'author_id' => 1,
        ]);
        $articlesTable->save($article);

        // Create audit log with same state
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '20',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Same Title', 'body' => 'Same Body']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'revertPreview',
            $log->id,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Revert Preview');
        $this->assertResponseContains('No differences found');
    }

    /**
     * Test revert preview with changes renders correctly (smoke test)
     *
     * @return void
     */
    public function testRevertPreviewWithChanges(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');
        $articlesTable = $this->getTableLocator()->get('Articles');

        // Create article with different state
        $article = $articlesTable->newEntity([
            'id' => 21,
            'title' => 'Current Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articlesTable->save($article);

        // Create audit log with different state
        $log = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '21',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Old Title', 'body' => 'Old Body']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'revertPreview',
            $log->id,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Revert Preview');
        $this->assertResponseContains('Fields to Revert');
        $this->assertResponseContains('title');
        $this->assertResponseContains('body');
        $this->assertResponseContains('Current Title');
        $this->assertResponseContains('Old Title');
    }

    /**
     * Test restore with no delete log renders correctly (smoke test)
     *
     * @return void
     */
    public function testRestoreNoDeleteLog(): void
    {
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'restore',
            'Articles',
            '999',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Restore Deleted Record');
        $this->assertResponseContains('No deletion record found');
    }

    /**
     * Test restore POST request
     *
     * @return void
     */
    public function testRestorePost(): void
    {
        $this->enableRetainFlashMessages();

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create delete audit log
        $deleteLog = $auditLogsTable->newEntity([
            'transaction_key' => 'test-transaction-delete',
            'type' => 'delete',
            'source' => 'Articles',
            'primary_key' => '98',
            'original' => json_encode([
                'title' => 'Restored Article',
                'body' => 'This was restored',
                'author_id' => 1,
            ]),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($deleteLog);

        $this->post([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'restore',
            'Articles',
            '98',
        ]);

        $this->assertResponseCode(302);
        $this->assertRedirect(['action' => 'timeline', 'Articles', '98']);
        $this->assertFlashMessage('Record restored successfully.');

        // Verify article was restored
        $articlesTable = $this->getTableLocator()->get('Articles');
        $article = $articlesTable->get(98);
        $this->assertEquals('Restored Article', $article->title);
    }
}
