<?php

declare(strict_types=1);

namespace AuditStash\Event;

use AuditStash\AuditLogType;

/**
 * Represents an audit log event for a record when auditing starts.
 */
class AuditSnapshotEvent extends BaseEvent
{
    /**
     * Constructor.
     *
     * @param string $transactionId The global transaction id
     * @param mixed $id The primary key record being snapshotted
     * @param string $source The name of the source (table) being audited
     * @param array<string, mixed> $original The state when auditing starts
     * @param string|null $displayValue The display field's value
     */
    public function __construct(
        string $transactionId,
        mixed $id,
        string $source,
        array $original = [],
        ?string $displayValue = null,
    ) {
        parent::__construct($transactionId, $id, $source, [], $original, null, $displayValue);
    }

    /**
     * Returns the name of this event type.
     *
     * @return string
     */
    public function getEventType(): string
    {
        return AuditLogType::Snapshot->value;
    }
}
