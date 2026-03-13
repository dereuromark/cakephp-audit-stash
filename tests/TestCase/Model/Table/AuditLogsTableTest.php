<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Model\Table;

use AuditStash\Model\Table\AuditLogsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Model\Table\AuditLogsTable Test Case
 *
 * @uses \AuditStash\Model\Table\AuditLogsTable
 */
class AuditLogsTableTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    /**
     * Get the AuditLogs table
     *
     * @return \AuditStash\Model\Table\AuditLogsTable
     */
    protected function getAuditLogsTable(): AuditLogsTable
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $table */
        $table = $this->fetchTable('AuditStash.AuditLogs');

        return $table;
    }

    /**
     * Test findByChangedField method
     *
     * @return void
     */
    public function testFindByChangedField(): void
    {
        // Create test logs with different changed fields
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => json_encode(['title' => 'New Title', 'body' => 'New Body']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => json_encode(['body' => 'Another Body']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 3,
            'changed' => json_encode(['status' => 'published']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        // Find logs where 'title' was changed
        $results = $this->getAuditLogsTable()->find('byChangedField', field: 'title')->toArray();

        $this->assertCount(1, $results);
        $this->assertEquals($log1->id, $results[0]->id);

        // Find logs where 'body' was changed
        $results = $this->getAuditLogsTable()->find('byChangedField', field: 'body')->toArray();

        $this->assertCount(2, $results);
    }

    /**
     * Test findByChangedFieldValue method
     *
     * @return void
     */
    public function testFindByChangedFieldValue(): void
    {
        // Create test logs with different field values
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => json_encode(['status' => 'published']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => json_encode(['status' => 'draft']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 3,
            'changed' => json_encode(['status' => 'published']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        // Find logs where status changed to 'published'
        $results = $this->getAuditLogsTable()->find('byChangedFieldValue', field: 'status', value: 'published')->toArray();

        $this->assertCount(2, $results);
        $ids = array_map(fn ($r) => $r->id, $results);
        $this->assertContains($log1->id, $ids);
        $this->assertContains($log3->id, $ids);

        // Find logs where status changed to 'draft'
        $results = $this->getAuditLogsTable()->find('byChangedFieldValue', field: 'status', value: 'draft')->toArray();

        $this->assertCount(1, $results);
        $this->assertEquals($log2->id, $results[0]->id);
    }

    /**
     * Test findRelatedChanges method
     *
     * @return void
     */
    public function testFindRelatedChanges(): void
    {
        // Create a main record log
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => json_encode(['title' => 'Test Article']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        // Create a related record log (comment on the article)
        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'create',
            'source' => 'Comments',
            'primary_key' => 1,
            'parent_source' => 'Articles',
            'changed' => json_encode(['article_id' => 1, 'body' => 'Test Comment']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        // Create an unrelated log
        $unrelatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-2',
            'type' => 'create',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => json_encode(['name' => 'Test User']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($unrelatedLog);

        // Find related changes for the article
        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'Articles', primaryKey: 1)->toArray();

        $this->assertCount(2, $results);
        $ids = array_map(fn ($r) => $r->id, $results);
        $this->assertContains($mainLog->id, $ids);
        $this->assertContains($relatedLog->id, $ids);
        $this->assertNotContains($unrelatedLog->id, $ids);
    }

    /**
     * Test findBulkChanges method
     *
     * @return void
     */
    public function testFindBulkChanges(): void
    {
        // Create a bulk transaction with many records
        $bulkTxId = 'bulk-tx-123';
        for ($i = 1; $i <= 10; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction' => $bulkTxId,
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'changed' => json_encode(['title' => "Article $i"]),
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Create a small transaction with few records
        $smallTxId = 'small-tx-456';
        for ($i = 1; $i <= 2; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction' => $smallTxId,
                'type' => 'update',
                'source' => 'Users',
                'primary_key' => $i,
                'changed' => json_encode(['name' => "User $i"]),
                'created' => new DateTime(),
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Find bulk changes with default threshold (5)
        $results = $this->getAuditLogsTable()->find('bulkChanges')->toArray();

        $this->assertCount(10, $results);
        foreach ($results as $log) {
            $this->assertEquals($bulkTxId, $log->transaction);
        }

        // Find bulk changes with higher threshold (8)
        $results = $this->getAuditLogsTable()->find('bulkChanges', minRecords: 8)->toArray();

        $this->assertCount(10, $results);

        // Find bulk changes with very high threshold (15) - should be empty
        $results = $this->getAuditLogsTable()->find('bulkChanges', minRecords: 15)->toArray();

        $this->assertCount(0, $results);
    }

    /**
     * Test findBulkChangeStats method
     *
     * @return void
     */
    public function testFindBulkChangeStats(): void
    {
        // Create a bulk transaction with many records across multiple sources
        $bulkTxId = 'bulk-tx-789';
        $now = new DateTime();

        for ($i = 1; $i <= 5; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction' => $bulkTxId,
                'type' => 'create',
                'source' => 'Articles',
                'primary_key' => $i,
                'user_id' => 'user-1',
                'user_display' => 'Test User',
                'changed' => json_encode(['title' => "Article $i"]),
                'created' => $now,
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        for ($i = 1; $i <= 3; $i++) {
            $log = $this->getAuditLogsTable()->newEntity([
                'transaction' => $bulkTxId,
                'type' => 'create',
                'source' => 'Comments',
                'primary_key' => $i,
                'user_id' => 'user-1',
                'user_display' => 'Test User',
                'changed' => json_encode(['body' => "Comment $i"]),
                'created' => $now,
            ]);
            $this->getAuditLogsTable()->save($log);
        }

        // Create a small transaction (should not appear in results)
        $smallTxId = 'small-tx-111';
        $log = $this->getAuditLogsTable()->newEntity([
            'transaction' => $smallTxId,
            'type' => 'update',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => json_encode(['name' => 'User 1']),
            'created' => $now,
        ]);
        $this->getAuditLogsTable()->save($log);

        // Get bulk change stats
        $results = $this->getAuditLogsTable()->find('bulkChangeStats', minRecords: 5)->toArray();

        $this->assertCount(1, $results);
        $stat = $results[0];

        $this->assertEquals($bulkTxId, $stat['transaction']);
        $this->assertEquals(8, $stat['record_count']);
        $this->assertEquals(2, $stat['sources']);
        $this->assertEquals('user-1', $stat['user_id']);
        $this->assertEquals('Test User', $stat['user_display']);
    }

    /**
     * Test getDistinctChangedFields method
     *
     * @return void
     */
    public function testGetDistinctChangedFields(): void
    {
        // Create logs with various fields
        $log1 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 1,
            'changed' => json_encode(['title' => 'New Title', 'body' => 'New Body']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log1);

        $log2 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-2',
            'type' => 'update',
            'source' => 'Users',
            'primary_key' => 1,
            'changed' => json_encode(['name' => 'New Name', 'email' => 'new@email.com']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log2);

        $log3 = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-3',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => 2,
            'changed' => json_encode(['title' => 'Another Title', 'status' => 'published']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($log3);

        $fields = $this->getAuditLogsTable()->getDistinctChangedFields();

        $this->assertContains('title', $fields);
        $this->assertContains('body', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('email', $fields);
        $this->assertContains('status', $fields);
        $this->assertCount(5, $fields);
    }

    /**
     * Test buildForeignKeyName method via findRelatedChanges
     *
     * This indirectly tests the protected buildForeignKeyName method
     * by checking that the related changes finder correctly identifies
     * records with the appropriate foreign key.
     *
     * @return void
     */
    public function testBuildForeignKeyNameViaPascalCase(): void
    {
        // Create a main record with PascalCase source name
        $mainLog = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'create',
            'source' => 'UserProfiles',
            'primary_key' => 1,
            'changed' => json_encode(['bio' => 'Test bio']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($mainLog);

        // Create a related record with snake_case foreign key
        $relatedLog = $this->getAuditLogsTable()->newEntity([
            'transaction' => 'test-tx-1',
            'type' => 'create',
            'source' => 'ProfilePhotos',
            'primary_key' => 1,
            'parent_source' => 'UserProfiles',
            'changed' => json_encode(['user_profile_id' => 1, 'url' => 'photo.jpg']),
            'created' => new DateTime(),
        ]);
        $this->getAuditLogsTable()->save($relatedLog);

        // Find related changes - should find both
        $results = $this->getAuditLogsTable()->find('relatedChanges', source: 'UserProfiles', primaryKey: 1)->toArray();

        $this->assertCount(2, $results);
    }
}
