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
        'plugin.AuditStash.Articles',
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
            'transaction_key' => 'test-transaction-1',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => '1',
            'original' => json_encode([]),
            'changed' => json_encode(['title' => 'Title v1', 'body' => 'Body v1', 'status' => 'draft']),
        ]);
        $auditLogs->save($log1);

        // Update 1
        $log2 = $auditLogs->newEntity([
            'transaction_key' => 'test-transaction-2',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => '1',
            'original' => json_encode(['title' => 'Title v1']),
            'changed' => json_encode(['title' => 'Title v2']),
        ]);
        $auditLogs->save($log2);

        // Update 2
        $log3 = $auditLogs->newEntity([
            'transaction_key' => 'test-transaction-3',
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

    /**
     * A record that existed before auditing was enabled has no `create` entry.
     *
     * @return void
     */
    public function testReconstructStateWithoutCreateEntry(): void
    {
        $first = $this->log('update', ['title' => 'Title v1'], ['title' => 'Title v2']);
        $this->log('update', ['body' => 'Body v1'], ['body' => 'Body v2']);

        $state = $this->service->reconstructState('articles', 1, $first);

        $this->assertSame(['title' => 'Title v2', 'body' => 'Body v1'], $state);
    }

    /**
     * @return void
     */
    public function testReconstructStateAppliesRevertEntries(): void
    {
        $this->log('update', ['title' => 'A'], ['title' => 'B']);
        $this->log('update', ['title' => 'B'], ['title' => 'C']);
        $this->log('revert', ['title' => 'C', 'body' => 'Body'], ['title' => 'B']);
        $last = $this->log('update', ['body' => 'Body'], ['body' => 'X']);

        $state = $this->service->reconstructState('articles', 1, $last);

        $this->assertSame(['title' => 'B', 'body' => 'X'], $state);
    }

    /**
     * Values of a record created later under the same primary key must not
     * leak into the state of the earlier record.
     *
     * @return void
     */
    public function testReconstructStateStopsAtRecreate(): void
    {
        $first = $this->log('update', ['title' => 'Title v1'], ['title' => 'Title v2']);
        $this->log('delete', ['title' => 'Title v2', 'body' => 'Body v1'], null);
        $recreated = ['title' => 'New', 'body' => 'New body', 'summary' => 'S1'];
        $this->log('create', $recreated, $recreated);
        $this->log('update', ['summary' => 'S1'], ['summary' => 'S2']);

        $state = $this->service->reconstructState('articles', 1, $first);

        $this->assertSame(['title' => 'Title v2', 'body' => 'Body v1'], $state);
    }

    /**
     * A `revert` entry stores the full row in `original`, including fields
     * the behavior never audits.
     *
     * @return void
     */
    public function testReconstructStateSkipsBlacklistedFields(): void
    {
        $this->fetchTable('Articles')->addBehavior('AuditStash.AuditLog');

        $first = $this->log('update', ['title' => 'A'], ['title' => 'B'], 'Articles');
        $this->log(
            'revert',
            ['id' => 1, 'title' => 'B', 'body' => 'Body', 'created' => '2007-03-18T10:39:23+00:00'],
            ['title' => 'A'],
            'Articles',
        );

        $state = $this->service->reconstructState('Articles', 1, $first);

        $this->assertSame(['title' => 'B', 'body' => 'Body'], $state);
    }

    /**
     * @return void
     */
    public function testReconstructStateHonorsWhitelist(): void
    {
        $this->fetchTable('Articles')->addBehavior('AuditStash.AuditLog', ['whitelist' => ['title']]);

        $first = $this->log('update', ['title' => 'A'], ['title' => 'B'], 'Articles');
        $this->log('revert', ['title' => 'B', 'body' => 'Body', 'published' => 'Y'], ['title' => 'A'], 'Articles');

        $state = $this->service->reconstructState('Articles', 1, $first);

        $this->assertSame(['title' => 'B'], $state);
    }

    /**
     * A restore writes the delete entry's payload into `changed`, and that
     * payload is not narrowed by the whitelist.
     *
     * @return void
     */
    public function testReconstructStateHonorsWhitelistForReplayedRevert(): void
    {
        $this->fetchTable('Articles')->addBehavior('AuditStash.AuditLog', ['whitelist' => ['title']]);

        $this->log('revert', [], ['title' => 'A', 'body' => 'Body'], 'Articles');
        $last = $this->log('update', ['title' => 'A'], ['title' => 'B'], 'Articles');

        $state = $this->service->reconstructState('Articles', 1, $last);

        $this->assertSame(['title' => 'B'], $state);
    }

    /**
     * @param string $type Entry type
     * @param array<string, mixed> $original Original values
     * @param array<string, mixed>|null $changed Changed values
     * @param string $source Source name
     *
     * @return int The entry id
     */
    protected function log(string $type, array $original, ?array $changed, string $source = 'articles'): int
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
        $log = $auditLogs->newEntity([
            'transaction_key' => 'test-transaction',
            'type' => $type,
            'source' => $source,
            'primary_key' => '1',
            'original' => json_encode($original),
            'changed' => $changed === null ? null : json_encode($changed),
        ]);
        $auditLogs->saveOrFail($log);

        return (int)$log->id;
    }
}
