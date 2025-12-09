<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\AuditLogType;
use AuditStash\Service\RevertService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

class RevertServiceTest extends TestCase
{
    use LocatorAwareTrait;

    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
        'plugin.AuditStash.Articles',
    ];

    protected RevertService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new RevertService();
    }

    public function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    /**
     * Test revertFull method
     *
     * @return void
     */
    public function testRevertFull(): void
    {
        // Create article with current state
        $articles = $this->fetchTable('Articles');
        $article = $articles->newEntity([
            'id' => 1,
            'title' => 'Updated Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articles->save($article);

        // Create initial audit logs to revert to
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        // Create audit log (original state)
        $createLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '1',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
        ]);
        $auditLogs->save($createLog);

        // Update audit log
        $updateLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'Articles',
            'primary_key' => '1',
            'original' => json_encode(['title' => 'Original Title']),
            'changed' => json_encode(['title' => 'Updated Title']),
        ]);
        $auditLogs->save($updateLog);

        // Revert to create state
        $result = $this->service->revertFull('Articles', 1, $createLog->id);

        $this->assertNotFalse($result);
        $this->assertEquals('Original Title', $result->title);

        // Check that a revert audit log was created
        $revertLog = $auditLogs->find()
            ->where(['type' => AuditLogType::Revert])
            ->first();

        $this->assertNotNull($revertLog);
        $this->assertEquals('Articles', $revertLog->source);
        $this->assertEquals('1', $revertLog->primary_key);

        $meta = json_decode($revertLog->meta, true);
        $this->assertEquals('full', $meta['revert_type']);
        $this->assertEquals($createLog->id, $meta['revert_to_audit_id']);
    }

    /**
     * Test revertPartial method
     *
     * @return void
     */
    public function testRevertPartial(): void
    {
        // Create article with current state
        $articles = $this->fetchTable('Articles');
        $article = $articles->newEntity([
            'id' => 2,
            'title' => 'Updated Title',
            'body' => 'Updated Body',
            'author_id' => 1,
        ]);
        $articles->save($article);

        // Create initial audit logs
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $createLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '2',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
        ]);
        $auditLogs->save($createLog);

        // Revert only title field
        $result = $this->service->revertPartial('Articles', 2, $createLog->id, ['title']);

        $this->assertNotFalse($result);
        $this->assertEquals('Original Title', $result->title);
        $this->assertEquals('Updated Body', $result->body); // Body should remain unchanged

        // Check that a partial revert audit log was created
        $revertLog = $auditLogs->find()
            ->where(['type' => AuditLogType::Revert])
            ->first();

        $this->assertNotNull($revertLog);
        $meta = json_decode($revertLog->meta, true);
        $this->assertEquals('partial', $meta['revert_type']);
    }

    /**
     * Test restoreDeleted method
     *
     * @return void
     */
    public function testRestoreDeleted(): void
    {
        // Create delete audit log
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $deleteLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-delete',
            'type' => 'delete',
            'source' => 'Articles',
            'primary_key' => '99',
            'original' => json_encode([
                'title' => 'Deleted Article',
                'body' => 'This was deleted',
                'author_id' => 1,
            ]),
            'changed' => json_encode([]),
        ]);
        $auditLogs->save($deleteLog);

        // Restore deleted record
        $result = $this->service->restoreDeleted('Articles', 99);

        $this->assertNotFalse($result);
        $this->assertEquals(99, $result->id);
        $this->assertEquals('Deleted Article', $result->title);
        $this->assertEquals('This was deleted', $result->body);

        // Verify record exists in database
        $articles = $this->fetchTable('Articles');
        $article = $articles->get(99);
        $this->assertEquals('Deleted Article', $article->title);

        // Check that a restore audit log was created
        $revertLog = $auditLogs->find()
            ->where(['type' => AuditLogType::Revert])
            ->first();

        $this->assertNotNull($revertLog);
        $meta = json_decode($revertLog->meta, true);
        $this->assertEquals('restore', $meta['revert_type']);
    }

    /**
     * Test restoreDeleted with no delete log
     *
     * @return void
     */
    public function testRestoreDeletedNoLog(): void
    {
        $result = $this->service->restoreDeleted('Articles', 999);

        $this->assertFalse($result);
    }

    /**
     * Test revertFull with non-existent record
     *
     * @return void
     */
    public function testRevertFullNonExistentRecord(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $createLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '999',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Test']),
        ]);
        $auditLogs->save($createLog);

        $this->expectException(RecordNotFoundException::class);

        $this->service->revertFull('Articles', 999, $createLog->id);
    }

    /**
     * Test restoreDeleted when record already exists
     *
     * @return void
     */
    public function testRestoreDeletedRecordExists(): void
    {
        // Create an existing article
        $articles = $this->fetchTable('Articles');
        $article = $articles->newEntity([
            'id' => 50,
            'title' => 'Existing Article',
            'body' => 'Body',
            'author_id' => 1,
        ]);
        $articles->save($article);

        // Create delete audit log for same ID
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $deleteLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-delete',
            'type' => 'delete',
            'source' => 'Articles',
            'primary_key' => '50',
            'original' => json_encode([
                'title' => 'Deleted Article',
                'body' => 'This was deleted',
                'author_id' => 1,
            ]),
            'changed' => json_encode([]),
        ]);
        $auditLogs->save($deleteLog);

        // Try to restore - should fail because record exists
        $result = $this->service->restoreDeleted('Articles', 50);

        $this->assertFalse($result);
    }

    /**
     * Test revertPartial with empty fields array
     *
     * @return void
     */
    public function testRevertPartialEmptyFields(): void
    {
        // Create article
        $articles = $this->fetchTable('Articles');
        $article = $articles->newEntity([
            'id' => 3,
            'title' => 'Current Title',
            'body' => 'Current Body',
            'author_id' => 1,
        ]);
        $articles->save($article);

        // Create audit log
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $createLog = $auditLogs->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'Articles',
            'primary_key' => '3',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
        ]);
        $auditLogs->save($createLog);

        // Revert with empty fields - should succeed but change nothing
        $result = $this->service->revertPartial('Articles', 3, $createLog->id, []);

        $this->assertNotFalse($result);
        $this->assertEquals('Current Title', $result->title);
        $this->assertEquals('Current Body', $result->body);
    }
}
