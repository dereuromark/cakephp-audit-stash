<?php

declare(strict_types=1);

namespace AuditStash\Event;

use AuditStash\AuditLogType;

/**
 * Represents an audit log event for a newly deleted record.
 */
class AuditDeleteEvent extends BaseEvent
{
    use BaseEventTrait;
    use SerializableEventTrait {
        basicSerialize as public jsonSerialize;
    }

    /**
     * Constructor.
     *
     * @param string $transactionId The global transaction id
     * @param mixed $id The primary key record that got deleted
     * @param string $source The name of the source (table) where the record was deleted
     * @param string|null $parentSource The name of the source (table) that triggered this change
     * @param array $original The original values the entity had before it got deleted
     * @param string|null $displayValue The display field's value
     */
    public function __construct(
        string $transactionId,
        mixed $id,
        string $source,
        ?string $parentSource = null,
        array $original = [],
        ?string $displayValue = null,
    ) {
        parent::__construct($transactionId, $id, $source, [], $original, null, $displayValue);
        $this->parentSource = $parentSource;
    }

    /**
     * Returns the name of this event type.
     *
     * @return string
     */
    public function getEventType(): string
    {
        return AuditLogType::Delete->value;
    }
}
