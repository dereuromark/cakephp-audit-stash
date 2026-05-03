<?php

declare(strict_types=1);

namespace TestApp\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\AbstractRule;
use RuntimeException;

/**
 * Rule whose `matches()` throws. Used by AuditMonitor lifecycle tests
 * to verify the `AuditStash.Monitor.ruleException` event fires and that
 * the exception in one rule does not break later rules in the chain.
 */
class ExplodingRule extends AbstractRule
{
    public function matches(AuditLog $auditLog): bool
    {
        throw new RuntimeException('rule blew up');
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
