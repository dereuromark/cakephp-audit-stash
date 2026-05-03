<?php

declare(strict_types=1);

namespace TestApp\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\AbstractRule;

/**
 * Rule that always matches. Used by AuditMonitor lifecycle tests where
 * the rule body is not under test.
 */
class AlwaysMatchRule extends AbstractRule
{
    public function matches(AuditLog $auditLog): bool
    {
        return true;
    }

    public function getSeverity(): string
    {
        return 'high';
    }

    public function getMessage(AuditLog $auditLog): string
    {
        return 'matched';
    }
}
