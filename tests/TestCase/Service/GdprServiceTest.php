<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Service;

use AuditStash\Service\GdprService;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Service\GdprService Test Case
 *
 * @uses \AuditStash\Service\GdprService
 */
class GdprServiceTest extends TestCase
{
    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    protected GdprService $service;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GdprService();
    }

    /**
     * Test findByUser method
     *
     * @return void
     */
    public function testFindByUser(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create logs for different users
        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-2',
            'type' => 'create',
            'source' => 'comments',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        $log3 = $auditLogsTable->newEntity([
            'transaction' => 'test-3',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 2,
            'user_id' => '456',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log3);

        $result = $this->service->findByUser(123)->toArray();
        $this->assertCount(2, $result);
        $this->assertSame('123', $result[0]->user_id);
        $this->assertSame('123', $result[1]->user_id);
    }

    /**
     * Test getStats method
     *
     * @return void
     */
    public function testGetStats(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-2',
            'type' => 'create',
            'source' => 'articles',
            'primary_key' => 2,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        $log3 = $auditLogsTable->newEntity([
            'transaction' => 'test-3',
            'type' => 'delete',
            'source' => 'comments',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log3);

        $stats = $this->service->getStats(123);

        $this->assertSame(3, $stats['total']);
        $this->assertArrayHasKey('articles', $stats['by_table']);
        $this->assertArrayHasKey('comments', $stats['by_table']);
        $this->assertSame(2, $stats['by_table']['articles']);
        $this->assertSame(1, $stats['by_table']['comments']);
    }

    /**
     * Test getStats with no logs
     *
     * @return void
     */
    public function testGetStatsNoLogs(): void
    {
        $stats = $this->service->getStats(999);

        $this->assertSame(0, $stats['total']);
        $this->assertEmpty($stats['by_table']);
        $this->assertEmpty($stats['by_type']);
    }

    /**
     * Test anonymize method
     *
     * @return void
     */
    public function testAnonymize(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'John Doe',
            'meta' => json_encode([
                'user' => 'john@example.com',
                'ip' => '192.168.1.100',
                'email' => 'john@example.com',
            ]),
            'original' => json_encode(['email' => 'old@example.com', 'name' => 'John']),
            'changed' => json_encode(['email' => 'new@example.com', 'name' => 'Johnny']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $count = $this->service->anonymize(123);
        $this->assertSame(1, $count);

        $anonymized = $auditLogsTable->get($log->id);

        // Check user_id is hashed
        $this->assertStringStartsWith('anon_', $anonymized->user_id);
        $this->assertSame('ANONYMIZED', $anonymized->user_display);

        // Check meta is anonymized
        $meta = json_decode($anonymized->meta, true);
        $this->assertSame('ANONYMIZED', $meta['user']);
        $this->assertSame('0.0.0.0', $meta['ip']);
        $this->assertSame('deleted@anonymized.local', $meta['email']);

        // Check PII is redacted
        $original = json_decode($anonymized->original, true);
        $this->assertSame('[REDACTED]', $original['email']);
        $this->assertSame('[REDACTED]', $original['name']);

        $changed = json_decode($anonymized->changed, true);
        $this->assertSame('[REDACTED]', $changed['email']);
        $this->assertSame('[REDACTED]', $changed['name']);
    }

    /**
     * Test anonymize with null user ID strategy
     *
     * @return void
     */
    public function testAnonymizeWithNullStrategy(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'John Doe',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $count = $this->service->anonymize(123, ['anonymizeUserId' => 'null']);
        $this->assertSame(1, $count);

        $anonymized = $auditLogsTable->get($log->id);
        $this->assertNull($anonymized->user_id);
    }

    /**
     * Test anonymize with placeholder strategy
     *
     * @return void
     */
    public function testAnonymizeWithPlaceholderStrategy(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'user_display' => 'John Doe',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $count = $this->service->anonymize(123, ['anonymizeUserId' => 'placeholder']);
        $this->assertSame(1, $count);

        $anonymized = $auditLogsTable->get($log->id);
        $this->assertSame('DELETED_USER', $anonymized->user_id);
    }

    /**
     * Test anonymize with custom PII fields
     *
     * @return void
     */
    public function testAnonymizeWithCustomPiiFields(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'original' => json_encode(['ssn' => '123-45-6789', 'title' => 'Manager']),
            'changed' => json_encode(['ssn' => '987-65-4321', 'title' => 'Director']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $count = $this->service->anonymize(123, ['piiFields' => ['ssn']]);
        $this->assertSame(1, $count);

        $anonymized = $auditLogsTable->get($log->id);

        $original = json_decode($anonymized->original, true);
        $this->assertSame('[REDACTED]', $original['ssn']);
        $this->assertSame('Manager', $original['title']); // Not PII

        $changed = json_decode($anonymized->changed, true);
        $this->assertSame('[REDACTED]', $changed['ssn']);
        $this->assertSame('Director', $changed['title']); // Not PII
    }

    /**
     * Test delete method
     *
     * @return void
     */
    public function testDelete(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log1 = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log1);

        $log2 = $auditLogsTable->newEntity([
            'transaction' => 'test-2',
            'type' => 'create',
            'source' => 'comments',
            'primary_key' => 1,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log2);

        $log3 = $auditLogsTable->newEntity([
            'transaction' => 'test-3',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 2,
            'user_id' => '456',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log3);

        $count = $this->service->delete(123);
        $this->assertSame(2, $count);

        // Verify user 123's logs are deleted
        $remaining123 = $auditLogsTable->find()->where(['user_id' => '123'])->count();
        $this->assertSame(0, $remaining123);

        // Verify user 456's logs are intact
        $remaining456 = $auditLogsTable->find()->where(['user_id' => '456'])->count();
        $this->assertSame(1, $remaining456);
    }

    /**
     * Test export method as JSON
     *
     * @return void
     */
    public function testExportJson(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 42,
            'display_value' => 'Test Article',
            'user_id' => '123',
            'original' => json_encode(['title' => 'Old']),
            'changed' => json_encode(['title' => 'New']),
            'meta' => json_encode(['ip' => '127.0.0.1']),
            'created' => new DateTime('2024-01-15 10:30:00'),
        ]);
        $auditLogsTable->save($log);

        $json = $this->service->export(123, 'json');
        $this->assertIsString($json);

        $data = json_decode($json, true);
        $this->assertSame(123, $data['user_id']);
        $this->assertSame(1, $data['record_count']);
        $this->assertCount(1, $data['audit_logs']);
        $this->assertSame('articles', $data['audit_logs'][0]['source']);
        $this->assertSame(42, $data['audit_logs'][0]['primary_key']);
    }

    /**
     * Test export method as array
     *
     * @return void
     */
    public function testExportArray(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'articles',
            'primary_key' => 42,
            'user_id' => '123',
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $array = $this->service->export(123, 'array');
        $this->assertIsArray($array);
        $this->assertCount(1, $array);
        $this->assertSame('articles', $array[0]['source']);
    }

    /**
     * Test configured anonymize fields are used
     *
     * @return void
     */
    public function testConfiguredAnonymizeFields(): void
    {
        Configure::write('AuditStash.gdpr.anonymizeFields', [
            'custom_field' => 'CUSTOM_ANONYMIZED',
        ]);

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'meta' => json_encode(['custom_field' => 'sensitive_data']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->service->anonymize(123);

        $anonymized = $auditLogsTable->get($log->id);
        $meta = json_decode($anonymized->meta, true);
        $this->assertSame('CUSTOM_ANONYMIZED', $meta['custom_field']);

        Configure::delete('AuditStash.gdpr.anonymizeFields');
    }

    /**
     * Test configured PII fields are used
     *
     * @return void
     */
    public function testConfiguredPiiFields(): void
    {
        Configure::write('AuditStash.gdpr.piiFields', ['custom_pii']);

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        $log = $auditLogsTable->newEntity([
            'transaction' => 'test-1',
            'type' => 'update',
            'source' => 'users',
            'primary_key' => 1,
            'user_id' => '123',
            'original' => json_encode(['custom_pii' => 'secret', 'other' => 'visible']),
            'created' => new DateTime(),
        ]);
        $auditLogsTable->save($log);

        $this->service->anonymize(123);

        $anonymized = $auditLogsTable->get($log->id);
        $original = json_decode($anonymized->original, true);
        $this->assertSame('[REDACTED]', $original['custom_pii']);
        $this->assertSame('visible', $original['other']);

        Configure::delete('AuditStash.gdpr.piiFields');
    }
}
