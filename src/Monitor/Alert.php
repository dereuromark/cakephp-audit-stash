<?php

declare(strict_types=1);

namespace AuditStash\Monitor;

use AuditStash\Model\Entity\AuditLog;

/**
 * Alert value object representing a notification to be sent.
 */
class Alert
{
    /**
     * @param string $ruleName Name of the rule that triggered this alert
     * @param string $severity Severity level: 'low', 'medium', 'high', 'critical'
     * @param string $message Human-readable alert message
     * @param \AuditStash\Model\Entity\AuditLog $auditLog The audit log that triggered the alert
     * @param array $context Additional context data
     */
    public function __construct(
        protected string $ruleName,
        protected string $severity,
        protected string $message,
        protected AuditLog $auditLog,
        protected array $context = [],
    ) {
    }

    /**
     * Get the rule name.
     *
     * @return string
     */
    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    /**
     * Get the severity level.
     *
     * @return string
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Get the alert message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the audit log that triggered this alert.
     *
     * @return \AuditStash\Model\Entity\AuditLog
     */
    public function getAuditLog(): AuditLog
    {
        return $this->auditLog;
    }

    /**
     * Get additional context data.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Convert alert to array for serialization.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'rule_name' => $this->ruleName,
            'severity' => $this->severity,
            'message' => $this->message,
            'audit_log' => [
                'id' => $this->auditLog->id,
                'type' => $this->auditLog->type,
                'source' => $this->auditLog->source,
                'primary_key' => $this->auditLog->primary_key,
                'transaction' => $this->auditLog->transaction,
                'created' => $this->auditLog->created?->toIso8601String(),
            ],
            'context' => $this->context,
        ];
    }
}
