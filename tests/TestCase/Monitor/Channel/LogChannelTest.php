<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor\Channel;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\LogChannel;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;

/**
 * AuditStash\Monitor\Channel\LogChannel Test Case
 */
class LogChannelTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Log::drop('audit_test');
        Log::setConfig('audit_test', [
            'className' => 'Array',
            'scopes' => ['audit_alerts'],
        ]);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        Log::drop('audit_test');
    }

    /**
     * Test send method
     *
     * @return void
     */
    public function testSend(): void
    {
        $channel = new LogChannel([
            'scope' => 'audit_alerts',
        ]);

        $auditLog = new AuditLog([
            'id' => 1,
            'type' => 'delete',
            'source' => 'users',
            'primary_key' => 123,
            'transaction' => 'abc-123',
            'created' => DateTime::now(),
        ]);

        $alert = new Alert(
            'TestRule',
            'high',
            'Test alert message',
            $auditLog,
            ['test' => 'context'],
        );

        $result = $channel->send($alert);
        $this->assertTrue($result);

        $logs = Log::engine('audit_test')->read();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Test alert message', $logs[0]);
    }

    /**
     * Test severity to log level mapping
     *
     * @return void
     */
    public function testSeverityMapping(): void
    {
        $channel = new LogChannel();

        $testCases = [
            'critical' => 'critical',
            'high' => 'error',
            'medium' => 'warning',
            'low' => 'info',
        ];

        foreach ($testCases as $severity => $expectedLevel) {
            $auditLog = new AuditLog([
                'id' => 1,
                'type' => 'delete',
                'source' => 'test',
                'primary_key' => 1,
                'transaction' => 'test',
            ]);

            $alert = new Alert(
                'TestRule',
                $severity,
                'Test message',
                $auditLog,
            );

            $channel->send($alert);

            $logs = Log::engine('audit_test')->read();
            $lastLog = end($logs);
            $this->assertStringContainsString($expectedLevel, $lastLog);
        }
    }
}
