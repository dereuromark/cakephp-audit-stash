<?php

declare(strict_types=1);

namespace AuditStash\Monitor;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Channel\ChannelInterface;
use AuditStash\Monitor\Rule\AbstractRule;
use Cake\Core\Configure;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Exception;
use Psr\Log\LoggerAwareTrait;

/**
 * Monitors audit events and triggers alerts based on configured rules.
 */
class AuditMonitor implements EventListenerInterface
{
    use EventDispatcherTrait;
    use LoggerAwareTrait;

    /**
     * @var array<string, \AuditStash\Monitor\Rule\AbstractRule>
     */
    protected array $rules = [];

    /**
     * Channel instances grouped by rule name and keyed by channel name.
     *
     * @var array<string, array<string, \AuditStash\Monitor\Channel\ChannelInterface>>
     */
    protected array $channels = [];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * @inheritDoc
     */
    public function implementedEvents(): array
    {
        if (!Configure::read('AuditStash.monitor.enabled', false)) {
            return [];
        }

        return [
            'AuditStash.afterLog' => 'onAfterLog',
        ];
    }

    /**
     * Handle afterLog event.
     *
     * @param \Cake\Event\EventInterface $event The event
     *
     * @return void
     */
    public function onAfterLog(EventInterface $event): void
    {
        $auditLog = $event->getData('auditLog');
        if (!$auditLog instanceof AuditLog) {
            return;
        }

        $this->checkRules($auditLog);
    }

    /**
     * Check all rules against an audit log and dispatch matching alerts
     * through the lifecycle event hooks.
     *
     * Lifecycle for each matching rule:
     *
     * 1. `AuditStash.Monitor.beforeAlert` is dispatched with `rule`,
     *    `auditLog`, and `alert`. Listeners can call `stopPropagation()`
     *    on the event to suppress the alert — channels are skipped and
     *    `afterAlert` is not dispatched. Listeners can also mutate the
     *    `Alert` instance in-place (severity, message, context) before
     *    delivery.
     * 2. Channels run.
     * 3. `AuditStash.Monitor.afterAlert` is dispatched with `rule`,
     *    `auditLog`, `alert`, and `results` (a `[channelName => bool]`
     *    map of per-channel success).
     *
     * Rule exceptions are still caught and logged, but now also
     * dispatched as `AuditStash.Monitor.ruleException` so user code can
     * route them somewhere actionable.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log to check
     *
     * @return void
     */
    protected function checkRules(AuditLog $auditLog): void
    {
        foreach ($this->rules as $ruleName => $rule) {
            try {
                if (!$rule->matches($auditLog)) {
                    continue;
                }

                $alert = $rule->createAlert($auditLog);

                $beforeEvent = $this->dispatchEvent('AuditStash.Monitor.beforeAlert', [
                    'rule' => $ruleName,
                    'auditLog' => $auditLog,
                    'alert' => $alert,
                ]);
                if ($beforeEvent->isStopped()) {
                    continue;
                }
                // Listeners can swap the alert via $event->setData('alert', ...);
                // pick that up so mutated alerts reach the channels.
                $alertFromEvent = $beforeEvent->getData('alert');
                if ($alertFromEvent instanceof Alert) {
                    $alert = $alertFromEvent;
                }

                $results = $this->sendAlert($ruleName, $alert);

                $this->dispatchEvent('AuditStash.Monitor.afterAlert', [
                    'rule' => $ruleName,
                    'auditLog' => $auditLog,
                    'alert' => $alert,
                    'results' => $results,
                ]);
            } catch (Exception $e) {
                $this->logger?->error('AuditMonitor: Rule check failed', [
                    'rule' => $ruleName,
                    'error' => $e->getMessage(),
                ]);
                $this->dispatchEvent('AuditStash.Monitor.ruleException', [
                    'rule' => $ruleName,
                    'auditLog' => $auditLog,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Send alert through configured channels for a rule.
     *
     * @param string $ruleName The rule name
     * @param \AuditStash\Monitor\Alert $alert The alert to send
     *
     * @return array<string, bool> Per-channel success map keyed by channel name
     */
    protected function sendAlert(string $ruleName, Alert $alert): array
    {
        $results = [];

        foreach ($this->channels[$ruleName] ?? [] as $channelName => $channel) {
            try {
                $results[$channelName] = (bool)$channel->send($alert);
            } catch (Exception $e) {
                $results[$channelName] = false;
                $this->logger?->error('AuditMonitor: Channel send failed', [
                    'rule' => $ruleName,
                    'channel' => get_class($channel),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Load configuration and initialize rules and channels.
     *
     * @return void
     */
    protected function loadConfiguration(): void
    {
        $rulesConfig = Configure::read('AuditStash.monitor.rules', []);
        $channelsConfig = Configure::read('AuditStash.monitor.channels', []);

        $channelInstances = [];
        foreach ($channelsConfig as $channelName => $channelConfig) {
            $channelInstances[$channelName] = $this->createChannel($channelConfig);
        }

        foreach ($rulesConfig as $ruleName => $ruleConfig) {
            $rule = $this->createRule($ruleConfig);
            if ($rule) {
                $this->rules[$ruleName] = $rule;

                $channelNames = $ruleConfig['channels'] ?? [];
                $this->channels[$ruleName] = [];
                foreach ($channelNames as $channelName) {
                    if (isset($channelInstances[$channelName])) {
                        $this->channels[$ruleName][$channelName] = $channelInstances[$channelName];
                    }
                }
            }
        }
    }

    /**
     * Create a rule instance from configuration.
     *
     * @param array $config Rule configuration
     *
     * @return \AuditStash\Monitor\Rule\AbstractRule|null
     */
    protected function createRule(array $config): ?AbstractRule
    {
        $class = $config['class'] ?? null;
        if (!$class || !class_exists($class)) {
            $this->logger?->warning('AuditMonitor: Invalid rule class', ['config' => $config]);

            return null;
        }

        $rule = new $class($config);
        if (!$rule instanceof AbstractRule) {
            $this->logger?->warning('AuditMonitor: Class is not an AbstractRule', ['class' => $class]);

            return null;
        }

        return $rule;
    }

    /**
     * Create a channel instance from configuration.
     *
     * @param array $config Channel configuration
     *
     * @return \AuditStash\Monitor\Channel\ChannelInterface|null
     */
    protected function createChannel(array $config): ?ChannelInterface
    {
        $class = $config['class'] ?? null;
        if (!$class || !class_exists($class)) {
            $this->logger?->warning('AuditMonitor: Invalid channel class', ['config' => $config]);

            return null;
        }

        $channel = new $class($config);
        if ($channel instanceof ChannelInterface) {
            return $channel;
        }

        return null;
    }
}
