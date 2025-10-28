<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Channel;

use AuditStash\Monitor\Alert;
use Cake\Log\Log;
use Psr\Log\LogLevel;

/**
 * Log file notification channel.
 */
class LogChannel implements ChannelInterface
{
    /**
     * @param array $config Channel configuration
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function send(Alert $alert): bool
    {
        $scope = $this->config['scope'] ?? 'audit_alerts';
        $level = $this->mapSeverityToLogLevel($alert->getSeverity());

        Log::write($level, $alert->getMessage(), [
            'scope' => [$scope],
            'alert' => $alert->toArray(),
        ]);

        return true;
    }

    /**
     * Map alert severity to PSR log level.
     *
     * @param string $severity Alert severity
     *
     * @return string PSR log level
     */
    protected function mapSeverityToLogLevel(string $severity): string
    {
        return match ($severity) {
            'critical' => LogLevel::CRITICAL,
            'high' => LogLevel::ERROR,
            'medium' => LogLevel::WARNING,
            'low' => LogLevel::INFO,
            default => LogLevel::NOTICE,
        };
    }
}
