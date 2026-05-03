<?php

declare(strict_types=1);

namespace TestApp\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\AbstractRule;

/**
 * Rule that never matches. Used by AuditMonitor lifecycle tests to
 * verify that no events fire when no rule matches.
 */
class NeverMatchRule extends AbstractRule
{
    public function matches(AuditLog $auditLog): bool
    {
        return false;
    }

    public function getSeverity(): string
    {
        return 'low';
    }

    public function getMessage(AuditLog $auditLog): string
    {
        return '';
    }
}
