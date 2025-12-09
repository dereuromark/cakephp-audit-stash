<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Rule;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\MassDeleteRule;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Monitor\Rule\MassDeleteRule Test Case
 */
class MassDeleteRuleTest extends TestCase
{
    /**
     * Fixtures
     *
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
    ];

    /**
     * Test matches method with threshold exceeded
     *
     * @return void
     */
    public function testMatchesThresholdExceeded(): void
    {
        $rule = new MassDeleteRule([
            'threshold' => 2,
            'timeframe' => 300,
        ]);

        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        // Create multiple delete events
        for ($i = 0; $i < 3; $i++) {
            $auditLogsTable->save($auditLogsTable->newEntity([
                'type' => AuditLogType::Delete,
                'transaction' => 'test-' . $i,
                'source' => 'users',
                'primary_key' => $i + 1,
            ]));
        }

        $testLog = $auditLogsTable->newEntity([
            'type' => AuditLogType::Delete,
            'transaction' => 'test',
            'source' => 'users',
            'primary_key' => 999,
        ]);

        $this->assertTrue($rule->matches($testLog));
    }

    /**
     * Test matches method with threshold not exceeded
     *
     * @return void
     */
    public function testMatchesThresholdNotExceeded(): void
    {
        $rule = new MassDeleteRule([
            'threshold' => 10,
            'timeframe' => 300,
        ]);

        $testLog = new AuditLog([
            'type' => AuditLogType::Delete,
            'source' => 'users',
            'primary_key' => 1,
        ]);

        $this->assertFalse($rule->matches($testLog));
    }

    /**
     * Test matches method with non-delete event
     *
     * @return void
     */
    public function testMatchesNonDeleteEvent(): void
    {
        $rule = new MassDeleteRule([
            'threshold' => 1,
        ]);

        $testLog = new AuditLog([
            'type' => AuditLogType::Update,
            'source' => 'users',
            'primary_key' => 1,
        ]);

        $this->assertFalse($rule->matches($testLog));
    }

    /**
     * Test matches method with table filter
     *
     * @return void
     */
    public function testMatchesWithTableFilter(): void
    {
        $rule = new MassDeleteRule([
            'threshold' => 1,
            'tables' => ['sensitive_data'],
        ]);

        $testLog = new AuditLog([
            'type' => AuditLogType::Delete,
            'source' => 'users',
            'primary_key' => 1,
        ]);

        $this->assertFalse($rule->matches($testLog));
    }

    /**
     * Test getSeverity method
     *
     * @return void
     */
    public function testGetSeverity(): void
    {
        $rule = new MassDeleteRule(['severity' => 'critical']);
        $this->assertSame('critical', $rule->getSeverity());

        $rule = new MassDeleteRule();
        $this->assertSame('critical', $rule->getSeverity());
    }

    /**
     * Test getMessage method
     *
     * @return void
     */
    public function testGetMessage(): void
    {
        $auditLogsTable = $this->getTableLocator()->get('AuditStash.AuditLogs');

        for ($i = 0; $i < 5; $i++) {
            $auditLogsTable->save($auditLogsTable->newEntity([
                'type' => AuditLogType::Delete,
                'transaction' => 'test-' . $i,
                'source' => 'posts',
                'primary_key' => $i + 1,
            ]));
        }

        $rule = new MassDeleteRule([
            'threshold' => 1,
            'timeframe' => 300,
        ]);

        $testLog = new AuditLog([
            'type' => AuditLogType::Delete,
            'source' => 'posts',
            'primary_key' => 999,
        ]);

        $message = $rule->getMessage($testLog);

        $this->assertStringContainsString('Mass deletion detected', $message);
        $this->assertStringContainsString('posts', $message);
    }

    /**
     * Test getContext method
     *
     * @return void
     */
    public function testGetContext(): void
    {
        $rule = new MassDeleteRule([
            'threshold' => 5,
            'timeframe' => 600,
        ]);

        $testLog = new AuditLog([
            'type' => AuditLogType::Delete,
            'source' => 'users',
            'primary_key' => 1,
        ]);

        $context = $rule->getContext($testLog);

        $this->assertArrayHasKey('threshold', $context);
        $this->assertArrayHasKey('timeframe_seconds', $context);
        $this->assertArrayHasKey('delete_count', $context);
        $this->assertArrayHasKey('table', $context);
        $this->assertSame(5, $context['threshold']);
        $this->assertSame(600, $context['timeframe_seconds']);
        $this->assertSame('users', $context['table']);
    }
}
