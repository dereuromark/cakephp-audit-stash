<?php

declare(strict_types=1);

namespace AuditStash\Monitor;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Channel\ChannelInterface;
use AuditStash\Monitor\Rule\AbstractRule;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Monitors audit events and triggers alerts based on configured rules.
 */
class AuditMonitor implements EventListenerInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<\AuditStash\Monitor\Rule\AbstractRule>
     */
    protected array $rules = [];

    /**
     * @var array<string, array<\AuditStash\Monitor\Channel\ChannelInterface>>
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
     * Check all rules against an audit log and send alerts.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log to check
     *
     * @return void
     */
    protected function checkRules(AuditLog $auditLog): void
    {
        foreach ($this->rules as $ruleName => $rule) {
            try {
                if ($rule->matches($auditLog)) {
                    $alert = $rule->createAlert($auditLog);
                    $this->sendAlert($ruleName, $alert);
                }
            } catch (\Exception $e) {
                $this->logger?->error('AuditMonitor: Rule check failed', [
                    'rule' => $ruleName,
                    'error' => $e->getMessage(),
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
     * @return void
     */
    protected function sendAlert(string $ruleName, Alert $alert): void
    {
        $channels = $this->channels[$ruleName] ?? [];

        foreach ($channels as $channel) {
            try {
                $channel->send($alert);
            } catch (\Exception $e) {
                $this->logger?->error('AuditMonitor: Channel send failed', [
                    'rule' => $ruleName,
                    'channel' => get_class($channel),
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
                        $this->channels[$ruleName][] = $channelInstances[$channelName];
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
