<?php

declare(strict_types=1);

namespace AuditStash\Test\TestCase\Monitor;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;
use AuditStash\Monitor\AuditMonitor;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;
use RuntimeException;
use TestApp\Monitor\Channel\ExplodingChannel;
use TestApp\Monitor\Channel\RecordingChannel;
use TestApp\Monitor\Rule\AlwaysMatchRule;
use TestApp\Monitor\Rule\ExplodingRule;
use TestApp\Monitor\Rule\NeverMatchRule;

class AuditMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('AuditStash.monitor.enabled', true);
        RecordingChannel::reset();
    }

    protected function tearDown(): void
    {
        Configure::delete('AuditStash.monitor');
        EventManager::instance(new EventManager());
        RecordingChannel::reset();
        parent::tearDown();
    }

    public function testHappyPathFiresBothLifecycleEventsAndChannel(): void
    {
        $beforeFired = $afterFired = false;
        $afterResults = null;

        $this->configureMonitor([
            'rules' => [
                'test' => ['class' => AlwaysMatchRule::class, 'channels' => ['ok', 'fail']],
            ],
            'channels' => [
                'ok' => ['class' => RecordingChannel::class, 'returns' => true],
                'fail' => ['class' => RecordingChannel::class, 'returns' => false],
            ],
        ]);

        EventManager::instance()->on(
            'AuditStash.Monitor.beforeAlert',
            function (EventInterface $event) use (&$beforeFired): void {
                $beforeFired = true;
                $this->assertSame('test', $event->getData('rule'));
                $this->assertInstanceOf(Alert::class, $event->getData('alert'));
            },
        );
        EventManager::instance()->on(
            'AuditStash.Monitor.afterAlert',
            function (EventInterface $event) use (&$afterFired, &$afterResults): void {
                $afterFired = true;
                $afterResults = $event->getData('results');
            },
        );

        $this->dispatchAuditLog();

        $this->assertTrue($beforeFired);
        $this->assertTrue($afterFired);
        $this->assertSame(['ok' => true, 'fail' => false], $afterResults);
        $this->assertCount(2, RecordingChannel::$delivered);
    }

    public function testBeforeAlertStopPropagationSuppressesChannelsAndAfterEvent(): void
    {
        $afterFired = false;

        $this->configureMonitor([
            'rules' => [
                'test' => ['class' => AlwaysMatchRule::class, 'channels' => ['ok']],
            ],
            'channels' => [
                'ok' => ['class' => RecordingChannel::class, 'returns' => true],
            ],
        ]);

        EventManager::instance()->on(
            'AuditStash.Monitor.beforeAlert',
            function (EventInterface $event): void {
                $event->stopPropagation();
            },
        );
        EventManager::instance()->on(
            'AuditStash.Monitor.afterAlert',
            function () use (&$afterFired): void {
                $afterFired = true;
            },
        );

        $this->dispatchAuditLog();

        $this->assertSame([], RecordingChannel::$delivered);
        $this->assertFalse($afterFired);
    }

    public function testBeforeAlertCanReplaceTheAlertViaSetData(): void
    {
        $this->configureMonitor([
            'rules' => [
                'test' => ['class' => AlwaysMatchRule::class, 'channels' => ['capture']],
            ],
            'channels' => [
                'capture' => ['class' => RecordingChannel::class, 'returns' => true],
            ],
        ]);

        EventManager::instance()->on(
            'AuditStash.Monitor.beforeAlert',
            function (EventInterface $event): void {
                /** @var \AuditStash\Monitor\Alert $alert */
                $alert = $event->getData('alert');
                $event->setData('alert', new Alert(
                    'MutatedRule',
                    'critical',
                    'redacted',
                    $alert->getAuditLog(),
                    ['mutated' => true],
                ));
            },
        );

        $this->dispatchAuditLog();

        $this->assertCount(1, RecordingChannel::$delivered);
        $delivered = RecordingChannel::$delivered[0];
        $this->assertSame('MutatedRule', $delivered->getRuleName());
        $this->assertSame('critical', $delivered->getSeverity());
        $this->assertSame('redacted', $delivered->getMessage());
        $this->assertSame(['mutated' => true], $delivered->getContext());
    }

    public function testRuleExceptionDispatchesRuleExceptionEventAndDoesNotBreakOtherRules(): void
    {
        $caught = null;

        $this->configureMonitor([
            'rules' => [
                'broken' => ['class' => ExplodingRule::class],
                'good' => ['class' => AlwaysMatchRule::class, 'channels' => ['ok']],
            ],
            'channels' => [
                'ok' => ['class' => RecordingChannel::class, 'returns' => true],
            ],
        ]);

        EventManager::instance()->on(
            'AuditStash.Monitor.ruleException',
            function (EventInterface $event) use (&$caught): void {
                $caught = $event->getData('exception');
            },
        );

        $this->dispatchAuditLog();

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('rule blew up', $caught->getMessage());
        $this->assertCount(1, RecordingChannel::$delivered);
    }

    public function testNonMatchingRuleFiresNoEvents(): void
    {
        $beforeFired = $afterFired = $exceptionFired = false;

        $this->configureMonitor([
            'rules' => ['never' => ['class' => NeverMatchRule::class]],
            'channels' => [],
        ]);

        EventManager::instance()->on('AuditStash.Monitor.beforeAlert', function () use (&$beforeFired): void {
            $beforeFired = true;
        });
        EventManager::instance()->on('AuditStash.Monitor.afterAlert', function () use (&$afterFired): void {
            $afterFired = true;
        });
        EventManager::instance()->on('AuditStash.Monitor.ruleException', function () use (&$exceptionFired): void {
            $exceptionFired = true;
        });

        $this->dispatchAuditLog();

        $this->assertFalse($beforeFired);
        $this->assertFalse($afterFired);
        $this->assertFalse($exceptionFired);
    }

    public function testChannelExceptionMarksItFailedButContinuesOtherChannels(): void
    {
        $afterResults = null;

        $this->configureMonitor([
            'rules' => [
                'test' => ['class' => AlwaysMatchRule::class, 'channels' => ['boom', 'ok']],
            ],
            'channels' => [
                'boom' => ['class' => ExplodingChannel::class],
                'ok' => ['class' => RecordingChannel::class, 'returns' => true],
            ],
        ]);

        EventManager::instance()->on(
            'AuditStash.Monitor.afterAlert',
            function (EventInterface $event) use (&$afterResults): void {
                $afterResults = $event->getData('results');
            },
        );

        $this->dispatchAuditLog();

        $this->assertSame(['boom' => false, 'ok' => true], $afterResults);
        $this->assertCount(1, RecordingChannel::$delivered);
    }

    public function testMonitorIsInertWhenDisabled(): void
    {
        Configure::write('AuditStash.monitor.enabled', false);

        $monitor = new AuditMonitor();

        $this->assertSame([], $monitor->implementedEvents());
    }

    /**
     * @param array{rules?: array<string, mixed>, channels?: array<string, mixed>} $monitorConfig
     */
    private function configureMonitor(array $monitorConfig): AuditMonitor
    {
        Configure::write('AuditStash.monitor.rules', $monitorConfig['rules'] ?? []);
        Configure::write('AuditStash.monitor.channels', $monitorConfig['channels'] ?? []);

        $monitor = new AuditMonitor();
        EventManager::instance()->on($monitor);

        return $monitor;
    }

    private function dispatchAuditLog(): void
    {
        $auditLog = new AuditLog([
            'id' => 1,
            'type' => AuditLogType::Update->value,
            'source' => 'Articles',
            'primary_key' => 7,
        ]);

        EventManager::instance()->dispatch(
            new Event('AuditStash.afterLog', null, ['auditLog' => $auditLog]),
        );
    }
}
