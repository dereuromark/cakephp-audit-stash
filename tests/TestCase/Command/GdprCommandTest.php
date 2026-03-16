<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Command\GdprCommand Test Case
 *
 * @uses \AuditStash\Command\GdprCommand
 */
class GdprCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('AuditStash.persister', 'AuditStash\Persister\TablePersister');
    }

    /**
     * Test help output
     *
     * @return void
     */
    public function testBuildOptionParser(): void
    {
        $this->exec('audit_stash gdpr --help');
        $this->assertExitSuccess();
        $this->assertOutputContains('GDPR compliance tools');
        $this->assertOutputContains('--user-id');
        $this->assertOutputContains('--dry-run');
        $this->assertOutputContains('--force');
        $this->assertOutputContains('--output');
    }

    /**
     * Test stats action with no logs
     *
     * @return void
     */
    public function testStatsNoLogs(): void
    {
        $this->exec('audit_stash gdpr stats --user-id=999');
        $this->assertExitSuccess();
        $this->assertOutputContains('User ID: 999');
        $this->assertOutputContains('Total records: 0');
        $this->assertOutputContains('No audit logs found for this user');
    }

    /**
     * Test stats action with logs
     *
     * @return void
     */
    public function testStatsWithLogs(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create logs for user
        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'Test User',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'Test User',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        $this->exec('audit_stash gdpr stats --user-id=123');
        $this->assertExitSuccess();
        $this->assertOutputContains('User ID: 123');
        $this->assertOutputContains('Total records: 2');
        $this->assertOutputContains('articles: 2');
    }

    /**
     * Test anonymize dry run
     *
     * @return void
     */
    public function testAnonymizeDryRun(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'John Doe',
            'meta' => json_encode(['user' => 'john@example.com', 'ip' => '192.168.1.1']),
            'original' => json_encode(['email' => 'old@example.com']),
            'changed' => json_encode(['email' => 'new@example.com']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr anonymize --user-id=123 --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('GDPR Anonymization (Dry Run)');
        $this->assertOutputContains('Records found: 1');
        $this->assertOutputContains('Run without --dry-run to execute');

        // Verify data was NOT changed
        $unchanged = $auditLogsTable->get($log->id);
        $this->assertSame('123', $unchanged->user_id);
        $this->assertSame('John Doe', $unchanged->user_display);
    }

    /**
     * Test anonymize action
     *
     * @return void
     */
    public function testAnonymize(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'John Doe',
            'meta' => json_encode(['user' => 'john@example.com', 'ip' => '192.168.1.1']),
            'original' => json_encode(['email' => 'old@example.com', 'name' => 'John']),
            'changed' => json_encode(['email' => 'new@example.com', 'name' => 'Johnny']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr anonymize --user-id=123');

        $this->assertExitSuccess();
        $this->assertOutputContains('Successfully anonymized 1 audit log(s)');

        // Verify data was changed
        $anonymized = $auditLogsTable->get($log->id);
        $this->assertStringStartsWith('anon_', $anonymized->user_id);
        $this->assertSame('ANONYMIZED', $anonymized->user_display);

        // Check meta was anonymized
        $meta = json_decode($anonymized->meta, true);
        $this->assertSame('ANONYMIZED', $meta['user']);
        $this->assertSame('0.0.0.0', $meta['ip']);

        // Check PII was redacted
        $original = json_decode($anonymized->original, true);
        $this->assertSame('[REDACTED]', $original['email']);
        $this->assertSame('[REDACTED]', $original['name']);

        $changed = json_decode($anonymized->changed, true);
        $this->assertSame('[REDACTED]', $changed['email']);
        $this->assertSame('[REDACTED]', $changed['name']);
    }

    /**
     * Test delete requires force flag
     *
     * @return void
     */
    public function testDeleteRequiresForce(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr delete --user-id=123');

        $this->assertExitError();
        $this->assertErrorContains('Deletion requires --force flag');
        $this->assertErrorContains('Consider using "anonymize" instead');

        // Verify data was NOT deleted
        $count = $auditLogsTable->find()->where(['user_id' => '123'])->count();
        $this->assertSame(1, $count);
    }

    /**
     * Test delete dry run
     *
     * @return void
     */
    public function testDeleteDryRun(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr delete --user-id=123 --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('GDPR Deletion (Dry Run)');
        $this->assertOutputContains('Records to delete: 1');
        $this->assertOutputContains('Run without --dry-run to execute');

        // Verify data was NOT deleted
        $count = $auditLogsTable->find()->where(['user_id' => '123'])->count();
        $this->assertSame(1, $count);
    }

    /**
     * Test delete with force
     *
     * @return void
     */
    public function testDeleteWithForce(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr delete --user-id=123 --force');

        $this->assertExitSuccess();
        $this->assertOutputContains('Successfully deleted 1 audit log(s)');

        // Verify data was deleted
        $count = $auditLogsTable->find()->where(['user_id' => '123'])->count();
        $this->assertSame(0, $count);
    }

    /**
     * Test export action
     *
     * @return void
     */
    public function testExport(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'Test User',
            'original' => json_encode(['title' => 'Old Title']),
            'changed' => json_encode(['title' => 'New Title']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->exec('audit_stash gdpr export --user-id=123');

        $this->assertExitSuccess();
        $this->assertOutputContains('"user_id": "123"');
        $this->assertOutputContains('"record_count": 1');
        $this->assertOutputContains('"source": "articles"');
    }

    /**
     * Test export to file
     *
     * @return void
     */
    public function testExportToFile(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $outputFile = TMP . 'gdpr_export_test.json';
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }

        $this->exec('audit_stash gdpr export --user-id=123 --output=' . $outputFile);

        $this->assertExitSuccess();
        $this->assertOutputContains('Export saved to:');
        $this->assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        $data = json_decode($content, true);
        $this->assertSame('123', $data['user_id']);
        $this->assertSame(1, $data['record_count']);

        unlink($outputFile);
    }

    /**
     * Test command fails with non-TablePersister
     *
     * @return void
     */
    public function testFailsWithNonTablePersister(): void
    {
        Configure::write('AuditStash.persister', 'AuditStash\Persister\ElasticSearchPersister');

        $this->exec('audit_stash gdpr stats --user-id=123');

        $this->assertExitError();
        $this->assertErrorContains('This command only works with TablePersister');
    }

    /**
     * Test anonymize with no logs found
     *
     * @return void
     */
    public function testAnonymizeNoLogs(): void
    {
        $this->exec('audit_stash gdpr anonymize --user-id=999');

        $this->assertExitSuccess();
        $this->assertOutputContains('No audit logs found for user ID: 999');
    }

    /**
     * Test delete with no logs found
     *
     * @return void
     */
    public function testDeleteNoLogs(): void
    {
        $this->exec('audit_stash gdpr delete --user-id=999 --force');

        $this->assertExitSuccess();
        $this->assertOutputContains('No audit logs found for user ID: 999');
    }

    /**
     * Test export with no logs found
     *
     * @return void
     */
    public function testExportNoLogs(): void
    {
        $this->exec('audit_stash gdpr export --user-id=999');

        $this->assertExitSuccess();
        $this->assertOutputContains('No audit logs found for user ID: 999');
    }

    /**
     * Test that other user's logs are not affected
     *
     * @return void
     */
    public function testOnlyAffectsTargetUser(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create logs for user 123
        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'User 123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        // Create logs for user 456
        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 2,
            'user_id' => '456',
            'user_display' => 'User 456',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        // Delete user 123's logs
        $this->exec('audit_stash gdpr delete --user-id=123 --force');
        $this->assertExitSuccess();

        // Verify user 456's logs are untouched
        $remaining = $auditLogsTable->find()->where(['user_id' => '456'])->first();
        $this->assertNotNull($remaining);
        $this->assertSame('User 456', $remaining->user_display);

        // Verify user 123's logs are gone
        $deleted = $auditLogsTable->find()->where(['user_id' => '123'])->count();
        $this->assertSame(0, $deleted);
    }
}
