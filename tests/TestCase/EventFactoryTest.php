<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase;

use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditCustomEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Event\AuditUpdateEvent;
use AuditStash\EventFactory;
use Cake\TestSuite\TestCase;

class EventFactoryTest extends TestCase
{
    private EventFactory $factory;

    public function setUp(): void
    {
        parent::setUp();
        $this->factory = new EventFactory();
    }

    public function testCreatesAuditCreateEventForCreateType(): void
    {
        $event = $this->factory->create($this->row('create'));
        $this->assertInstanceOf(AuditCreateEvent::class, $event);
    }

    public function testCreatesAuditUpdateEventForUpdateType(): void
    {
        $event = $this->factory->create($this->row('update'));
        $this->assertInstanceOf(AuditUpdateEvent::class, $event);
    }

    public function testCreatesAuditDeleteEventForDeleteType(): void
    {
        $event = $this->factory->create($this->row('delete'));
        $this->assertInstanceOf(AuditDeleteEvent::class, $event);
    }

    public function testFallsBackToCustomEventForUnknownType(): void
    {
        $event = $this->factory->create($this->row('user.login'));

        $this->assertInstanceOf(AuditCustomEvent::class, $event);
        $this->assertSame('user.login', $event->getEventType());
        $this->assertSame('Users', $event->getSourceName());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $type): array
    {
        return [
            'type' => $type,
            'transaction_key' => '00000000-0000-4000-a000-000000000001',
            'primary_key' => 7,
            'source' => 'Users',
            'parent_source' => null,
            'changed' => ['ip' => '127.0.0.1'],
            'original' => [],
            'meta' => [],
            '@timestamp' => '2026-05-03T10:00:00.000000+00:00',
            'display_value' => 'Alice',
        ];
    }
}
