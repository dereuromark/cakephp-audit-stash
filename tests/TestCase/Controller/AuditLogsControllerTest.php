<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Controller;

use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

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
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'user' => 'testuser',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
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
     * Test index method with date range filter
     *
     * @return void
     */
    public function testIndexWithDateRange(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'display_value' => 'Test Article',
            'user' => 'testuser',
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
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'changed' => json_encode(['title' => 'First Title']),
            'created' => new DateTime('-2 days'),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
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
            'transaction' => 'test-transaction-1',
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
        $this->assertResponseContains('ID');
        $this->assertResponseContains('Transaction');
        $this->assertResponseContains('Type');
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
            'transaction' => 'test-transaction-1',
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
        $this->assertArrayHasKey('transaction', $data[0]);
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
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
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
     * Test export defaults to CSV
     *
     * @return void
     */
    public function testExportDefaultsToCsv(): void
    {
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
        ]);

        $this->assertResponseOk();
        $this->assertContentType('text/csv');
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
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-delete',
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
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-revert',
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
                'transaction' => 'test-transaction-1',
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => '5',
                'changed' => json_encode(['title' => 'Original']),
                'created' => new DateTime('-3 days'),
            ],
            [
                'transaction' => 'test-transaction-2',
                'type' => 'update',
                'source' => 'Articles',
                'primary_key' => '5',
                'original' => json_encode(['title' => 'Original']),
                'changed' => json_encode(['title' => 'Updated']),
                'created' => new DateTime('-2 days'),
            ],
            [
                'transaction' => 'test-transaction-3',
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
                'transaction' => 'test-transaction-4',
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
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-1',
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
            'transaction' => 'test-transaction-delete',
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
