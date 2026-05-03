<?php

declare(strict_types=1);

namespace TestApp\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\AbstractRule;
use TypeError;

/**
 * Rule whose `matches()` raises a `TypeError`. Used to verify that the
 * monitor catches the full `Throwable` hierarchy (Errors as well as
 * Exceptions), so a typo / null-deref bug in one rule does not abort
 * the rest of the rule chain.
 */
class TypeErrorRule extends AbstractRule
{
    public function matches(AuditLog $auditLog): bool
    {
        throw new TypeError('null dereference');
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
