<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Service\StateReconstructorService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

class StateReconstructorServiceTest extends TestCase
{
    use LocatorAwareTrait;

    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    protected StateReconstructorService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new StateReconstructorService();
    }

    public function tearDown(): void
    {
        unset($this->service);
        parent::tearDown();
    }

    /**
     * Test reconstructState method
     *
     * @return void
     */
    public function testReconstructState(): void
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        // Create initial state
        $log1 = $auditLogs->newEntity([
            'transaction' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => '1',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Title v1', 'body' => 'Body v1', 'status' => 'draft']),
        ]);
        $auditLogs->save($log1);

        // Update 1
        $log2 = $auditLogs->newEntity([
            'transaction' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => '1',
            'original' => json_encode(['title' => 'Title v1']),
            'changed' => json_encode(['title' => 'Title v2']),
        ]);
        $auditLogs->save($log2);

        // Update 2
        $log3 = $auditLogs->newEntity([
            'transaction' => 'test-transaction-3',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => '1',
            'original' => json_encode(['body' => 'Body v1', 'status' => 'draft']),
            'changed' => json_encode(['body' => 'Body v2', 'status' => 'published']),
        ]);
        $auditLogs->save($log3);

        // Reconstruct to initial create
        $state1 = $this->service->reconstructState('articles', 1, $log1->id);
        $this->assertEquals(['title' => 'Title v1', 'body' => 'Body v1', 'status' => 'draft'], $state1);

        // Reconstruct to after first update
        $state2 = $this->service->reconstructState('articles', 1, $log2->id);
        $this->assertEquals(['title' => 'Title v2', 'body' => 'Body v1', 'status' => 'draft'], $state2);

        // Reconstruct to after second update
        $state3 = $this->service->reconstructState('articles', 1, $log3->id);
        $this->assertEquals(['title' => 'Title v2', 'body' => 'Body v2', 'status' => 'published'], $state3);
    }

    /**
     * Test calculateDiff method
     *
     * @return void
     */
    public function testCalculateDiff(): void
    {
        $currentState = [
            'title' => 'Current Title',
            'body' => 'Current Body',
            'status' => 'published',
        ];

        $targetState = [
            'title' => 'Target Title',
            'body' => 'Current Body', // Same as current
            'status' => 'draft',
        ];

        $diff = $this->service->calculateDiff($currentState, $targetState);

        // Should only include changed fields
        $this->assertArrayHasKey('title', $diff);
        $this->assertArrayHasKey('status', $diff);
        $this->assertArrayNotHasKey('body', $diff); // Not changed

        $this->assertEquals('Current Title', $diff['title']['current']);
        $this->assertEquals('Target Title', $diff['title']['target']);

        $this->assertEquals('published', $diff['status']['current']);
        $this->assertEquals('draft', $diff['status']['target']);
    }

    /**
     * Test calculateDiff with new fields
     *
     * @return void
     */
    public function testCalculateDiffWithNewFields(): void
    {
        $currentState = [
            'title' => 'Title',
        ];

        $targetState = [
            'title' => 'Title',
            'body' => 'New Body',
        ];

        $diff = $this->service->calculateDiff($currentState, $targetState);

        $this->assertArrayHasKey('body', $diff);
        $this->assertNull($diff['body']['current']);
        $this->assertEquals('New Body', $diff['body']['target']);
    }

    /**
     * Test calculateDiff with no changes
     *
     * @return void
     */
    public function testCalculateDiffNoChanges(): void
    {
        $currentState = [
            'title' => 'Title',
            'body' => 'Body',
        ];

        $targetState = [
            'title' => 'Title',
            'body' => 'Body',
        ];

        $diff = $this->service->calculateDiff($currentState, $targetState);

        $this->assertEmpty($diff);
    }

    /**
     * Test reconstructState with non-existent record
     *
     * @return void
     */
    public function testReconstructStateNoLogs(): void
    {
        $state = $this->service->reconstructState('nonexistent', 999, 1);

        $this->assertEmpty($state);
    }
}
