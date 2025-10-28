<?php
declare(strict_types=1);

namespace AuditStash\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Command\CleanupCommand Test Case
 *
 * @uses \AuditStash\Command\CleanupCommand
 */
class CleanupCommandTest extends TestCase
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
        $this->useCommandRunner();

        // Configure TablePersister
        Configure::write('AuditStash.persister', 'AuditStash\Persister\TablePersister');
    }

    /**
     * Test buildOptionParser method
     *
     * @return void
     */
    public function testBuildOptionParser(): void
    {
        $this->exec('audit_stash cleanup --help');
        $this->assertExitSuccess();
        $this->assertOutputContains('Cleanup old audit logs');
        $this->assertOutputContains('--retention');
        $this->assertOutputContains('--table');
        $this->assertOutputContains('--dry-run');
        $this->assertOutputContains('--force');
    }

    /**
     * Test execute with no logs to delete
     *
     * @return void
     */
    public function testExecuteNoLogsToDelete(): void
    {
        Configure::write('AuditStash.retention.default', 1);

        $this->exec('audit_stash cleanup --force');

        $this->assertExitSuccess();
        $this->assertOutputContains('No audit logs to delete');
    }

    /**
     * Test execute with dry run
     *
     * @return void
     */
    public function testExecuteDryRun(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create old audit log
        $oldLog = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-100 days'),
        ]);
        $auditLogsTable->save($oldLog);

        Configure::write('AuditStash.retention.default', 90);

        $this->exec('audit_stash cleanup --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('Dry run mode');
        $this->assertOutputContains('Found 1 audit log(s) to delete');

        // Verify log was not deleted
        $count = $auditLogsTable->find()->count();
        $this->assertSame(1, $count);
    }

    /**
     * Test execute with force flag
     *
     * @return void
     */
    public function testExecuteWithForce(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create old audit log
        $oldLog = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-100 days'),
        ]);
        $auditLogsTable->save($oldLog);

        Configure::write('AuditStash.retention.default', 90);

        $this->exec('audit_stash cleanup --force');

        $this->assertExitSuccess();
        $this->assertOutputContains('Successfully deleted 1 audit log(s)');

        // Verify log was deleted
        $count = $auditLogsTable->find()->count();
        $this->assertSame(0, $count);
    }

    /**
     * Test execute with table filter
     *
     * @return void
     */
    public function testExecuteWithTableFilter(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create old logs for different tables
        $oldLog1 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-100 days'),
        ]);
        $auditLogsTable->save($oldLog1);

        $oldLog2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-100 days'),
        ]);
        $auditLogsTable->save($oldLog2);

        Configure::write('AuditStash.retention.default', 90);

        $this->exec('audit_stash cleanup --force --table articles');

        $this->assertExitSuccess();
        $this->assertOutputContains('Filtering by table: articles');
        $this->assertOutputContains('Successfully deleted 1 audit log(s)');

        // Verify only articles log was deleted
        $count = $auditLogsTable->find()->count();
        $this->assertSame(1, $count);

        $remaining = $auditLogsTable->find()->first();
        $this->assertSame('users', $remaining->source);
    }

    /**
     * Test execute with custom retention period
     *
     * @return void
     */
    public function testExecuteWithCustomRetention(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create logs of different ages
        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-20 days'),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 2,
            'created' => (new DateTime())->modify('-40 days'),
        ]);
        $auditLogsTable->save($log2);

        $this->exec('audit_stash cleanup --force --retention 30');

        $this->assertExitSuccess();
        $this->assertOutputContains('Retention policy: 30 days');
        $this->assertOutputContains('Successfully deleted 1 audit log(s)');

        // Verify only older log was deleted
        $count = $auditLogsTable->find()->count();
        $this->assertSame(1, $count);

        $remaining = $auditLogsTable->find()->first();
        $this->assertSame(1, $remaining->primary_key);
    }

    /**
     * Test execute with table-specific retention from config
     *
     * @return void
     */
    public function testExecuteWithTableSpecificRetention(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create old log
        $oldLog = $auditLogsTable->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'created' => (new DateTime())->modify('-100 days'),
        ]);
        $auditLogsTable->save($oldLog);

        Configure::write('AuditStash.retention', [
            'default' => 30,
            'tables' => [
                'users' => 180,
            ],
        ]);

        $this->exec('audit_stash cleanup --force --table users');

        $this->assertExitSuccess();
        $this->assertOutputContains('Retention policy: 180 days');
        $this->assertOutputContains('No audit logs to delete');
    }

    /**
     * Test that command fails with ElasticSearchPersister
     *
     * @return void
     */
    public function testExecuteFailsWithElasticSearchPersister(): void
    {
        Configure::write('AuditStash.persister', 'AuditStash\Persister\ElasticSearchPersister');

        $this->exec('audit_stash cleanup --force');

        $this->assertExitError();
        $this->assertErrorContains('This command only works with TablePersister');
    }
}
