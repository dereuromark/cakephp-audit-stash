<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Alert;

/**
 * Abstract base class for monitoring rules.
 */
abstract class AbstractRule
{
    /**
     * @param array $config Rule configuration
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Check if this rule matches the given audit log.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log to check
     *
     * @return bool True if the rule matches
     */
    abstract public function matches(AuditLog $auditLog): bool;

    /**
     * Get the severity level for this rule.
     *
     * @return string One of: 'low', 'medium', 'high', 'critical'
     */
    abstract public function getSeverity(): string;

    /**
     * Get a human-readable message for the alert.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log that triggered the alert
     *
     * @return string The alert message
     */
    abstract public function getMessage(AuditLog $auditLog): string;

    /**
     * Get additional context data for the alert.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log
     *
     * @return array Context data
     */
    public function getContext(AuditLog $auditLog): array
    {
        return [];
    }

    /**
     * Create an alert for this rule.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log that triggered the alert
     *
     * @return \AuditStash\Monitor\Alert
     */
    public function createAlert(AuditLog $auditLog): Alert
    {
        return new Alert(
            $this->getRuleName(),
            $this->getSeverity(),
            $this->getMessage($auditLog),
            $auditLog,
            $this->getContext($auditLog),
        );
    }

    /**
     * Get the rule name.
     *
     * @return string
     */
    public function getRuleName(): string
    {
        $className = static::class;
        $parts = explode('\\', $className);
        $shortName = end($parts);

        return str_replace('Rule', '', $shortName);
    }

    /**
     * Get configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not set
     *
     * @return mixed
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
