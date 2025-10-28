<?php
declare(strict_types=1);

namespace AuditStash\Test\TestCase\Controller;

use Cake\Datasource\Exception\RecordNotFoundException;
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
            'username' => 'testuser',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 2,
            'username' => 'admin',
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

        // Test filter by username
        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'index',
            '?' => ['username' => 'admin'],
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
            'username' => 'testuser',
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
     * Test view method with invalid id
     *
     * @return void
     */
    public function testViewInvalidId(): void
    {
        $this->expectException(RecordNotFoundException::class);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'view',
            999999,
        ]);
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
            'username' => 'testuser',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->get([
            'prefix' => 'Admin',
            'plugin' => 'AuditStash',
            'controller' => 'AuditLogs',
            'action' => 'export',
            '?' => ['format' => 'csv'],
        ]);

        $this->assertResponseOk();
        $this->assertContentType('text/csv');
        $this->assertHeader('Content-Disposition', 'attachment');
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
            'username' => 'testuser',
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
            '?' => ['format' => 'json'],
        ]);

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertHeader('Content-Disposition', 'attachment');

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
            '?' => [
                'format' => 'json',
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
}
