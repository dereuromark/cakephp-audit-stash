<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Event;

use AuditStash\Event\AuditCustomEvent;
use Cake\TestSuite\TestCase;

class AuditCustomEventTest extends TestCase
{
    public function testGetEventTypeReturnsCustomString(): void
    {
        $event = new AuditCustomEvent(
            'user.login',
            '00000000-0000-4000-a000-000000000001',
            7,
            'Users',
            ['ip' => '127.0.0.1'],
        );

        $this->assertSame('user.login', $event->getEventType());
        $this->assertSame('Users', $event->getSourceName());
        $this->assertSame(7, $event->getId());
        $this->assertSame(['ip' => '127.0.0.1'], $event->getChanged());
        $this->assertNull($event->getOriginal());
    }

    public function testJsonSerializeRoundTripPreservesCustomType(): void
    {
        $event = new AuditCustomEvent(
            'report.exported',
            '00000000-0000-4000-a000-000000000002',
            null,
            'Reports',
            ['format' => 'csv', 'rows' => 1234],
        );

        $serialized = $event->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame('report.exported', $serialized['type']);
        $this->assertSame('Reports', $serialized['source']);
        $this->assertSame(['format' => 'csv', 'rows' => 1234], $serialized['changed']);
    }

    public function testMetaInfoCanBeAttached(): void
    {
        $event = new AuditCustomEvent(
            'user.login',
            '00000000-0000-4000-a000-000000000003',
            7,
            'Users',
        );

        $event->setMetaInfo(['user_id' => 7, 'ip' => '10.0.0.1']);

        $this->assertSame(['user_id' => 7, 'ip' => '10.0.0.1'], $event->getMetaInfo());
    }
}
