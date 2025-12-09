<?php

declare(strict_types=1);

namespace AuditStash;

/**
 * Audit Log Type Enum
 *
 * Defines the types of audit log entries.
 */
enum AuditLogType: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Revert = 'revert';
}
