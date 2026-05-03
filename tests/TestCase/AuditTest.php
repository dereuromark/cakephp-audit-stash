<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase;

use AuditStash\Audit;
use AuditStash\Event\AuditCustomEvent;
use AuditStash\PersisterInterface;
use Cake\TestSuite\TestCase;

class AuditTest extends TestCase
{
    public function tearDown(): void
    {
        Audit::setPersister(null);
        parent::tearDown();
    }

    public function testLogDispatchesCustomEventToPersister(): void
    {
        $captured = [];
        Audit::setPersister(new class ($captured) implements PersisterInterface {
            /** @param array<\AuditStash\EventInterface> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function logEvents(array $auditLogs): void
            {
                foreach ($auditLogs as $log) {
                    $this->captured[] = $log;
                }
            }
        });

        Audit::log(
            type: 'user.login',
            source: 'Users',
            primaryKey: 7,
            data: ['ip' => '10.0.0.1'],
            meta: ['user_id' => 7, 'user_display' => 'Alice'],
            displayValue: 'Alice',
        );

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertInstanceOf(AuditCustomEvent::class, $event);
        $this->assertSame('user.login', $event->getEventType());
        $this->assertSame('Users', $event->getSourceName());
        $this->assertSame(7, $event->getId());
        $this->assertSame(['ip' => '10.0.0.1'], $event->getChanged());
        $this->assertSame(['user_id' => 7, 'user_display' => 'Alice'], $event->getMetaInfo());
        $this->assertSame('Alice', $event->getDisplayValue());
        $this->assertNotEmpty($event->getTransactionId());
    }

    public function testLogGeneratesUniqueTransactionIdPerCall(): void
    {
        $captured = [];
        Audit::setPersister(new class ($captured) implements PersisterInterface {
            /** @param array<\AuditStash\EventInterface> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function logEvents(array $auditLogs): void
            {
                foreach ($auditLogs as $log) {
                    $this->captured[] = $log;
                }
            }
        });

        Audit::log('user.login', 'Users', 1);
        Audit::log('user.login', 'Users', 2);

        $this->assertCount(2, $captured);
        $this->assertNotSame($captured[0]->getTransactionId(), $captured[1]->getTransactionId());
    }
}
