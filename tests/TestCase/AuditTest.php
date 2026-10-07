<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase;

use ArrayObject;
use AuditStash\Audit;
use AuditStash\Event\AuditCustomEvent;
use AuditStash\Meta\RequestMetadata;
use AuditStash\PersisterInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
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

    /**
     * Listeners attached for `AuditStash.beforeLog`, such as RequestMetadata,
     * apply to custom events like they do to entity events.
     *
     * @return void
     */
    public function testLogAppliesGlobalBeforeLogListeners(): void
    {
        $captured = $this->capturePersistedEvents();
        $listener = new RequestMetadata(
            new ServerRequest(['url' => '/login', 'environment' => ['REMOTE_ADDR' => '10.0.0.5']]),
            42,
            'mark',
        );
        EventManager::instance()->on($listener);

        try {
            Audit::log(type: 'user.login', source: 'Users', primaryKey: 42);
        } finally {
            EventManager::instance()->off($listener);
        }

        $this->assertSame(
            ['ip' => '10.0.0.5', 'url' => '/login', 'user_id' => 42, 'user_display' => 'mark'],
            $captured[0]->getMetaInfo(),
        );
    }

    /**
     * @return void
     */
    public function testLogKeepsExplicitMetaOverListenerValues(): void
    {
        $captured = $this->capturePersistedEvents();
        $listener = new RequestMetadata(new ServerRequest(['url' => '/login']), 42, 'mark');
        EventManager::instance()->on($listener);

        try {
            Audit::log(type: 'user.login', source: 'Users', meta: ['user_id' => 7]);
        } finally {
            EventManager::instance()->off($listener);
        }

        $this->assertSame(7, $captured[0]->getMetaInfo()['user_id']);
        $this->assertSame('mark', $captured[0]->getMetaInfo()['user_display']);
    }

    /**
     * A listener may replace the list, for example to drop an event.
     *
     * @return void
     */
    public function testLogPersistsTheLogsTheListenersReturn(): void
    {
        $captured = $this->capturePersistedEvents();
        $listener = function (EventInterface $event): void {
            $event->setData('logs', []);
        };
        EventManager::instance()->on('AuditStash.beforeLog', $listener);

        try {
            Audit::log(type: 'user.login', source: 'Users');
        } finally {
            EventManager::instance()->off('AuditStash.beforeLog', $listener);
        }

        $this->assertSame([], $captured->getArrayCopy());
    }

    /**
     * @return \ArrayObject<int, \AuditStash\EventInterface> Filled as events are persisted
     */
    protected function capturePersistedEvents(): ArrayObject
    {
        /** @var \ArrayObject<int, \AuditStash\EventInterface> $captured */
        $captured = new ArrayObject();
        Audit::setPersister(new class ($captured) implements PersisterInterface {
            /**
             * @param \ArrayObject<int, \AuditStash\EventInterface> $captured
             */
            public function __construct(private ArrayObject $captured)
            {
            }

            /**
             * @inheritDoc
             */
            public function logEvents(array $auditLogs): void
            {
                foreach ($auditLogs as $log) {
                    $this->captured->append($log);
                }
            }
        });

        return $captured;
    }
}
