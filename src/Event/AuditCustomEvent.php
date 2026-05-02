<?php

declare(strict_types=1);

namespace AuditStash\Event;

use Cake\Datasource\EntityInterface;

/**
 * Represents an audit log event for a custom action that does not map to
 * entity create/update/delete (e.g. "user.login", "report.exported",
 * "permission.granted").
 *
 * The type string is opaque to the plugin and persisted as-is. It must fit
 * the audit_logs.type column (VARCHAR(64)).
 */
class AuditCustomEvent extends BaseEvent
{
    protected string $eventType;

    /**
     * @param string $eventType The custom event type identifier (e.g. "user.login").
     * @param string $transactionId The global transaction id.
     * @param mixed $id The primary key of the related entity, or any identifier
     *   relevant to the action. May be null.
     * @param string $source The source / scope name (e.g. "Users").
     * @param array|null $data Arbitrary payload describing what happened.
     *   Stored in the `changed` column.
     * @param array|null $original Optional prior state. Stored in `original`.
     * @param \Cake\Datasource\EntityInterface|null $entity Related entity, if any.
     * @param string|null $displayValue Human-friendly label for the row.
     */
    public function __construct(
        string $eventType,
        string $transactionId,
        mixed $id,
        string $source,
        ?array $data = null,
        ?array $original = null,
        ?EntityInterface $entity = null,
        ?string $displayValue = null,
    ) {
        parent::__construct($transactionId, $id, $source, $data, $original, $entity, $displayValue);
        $this->eventType = $eventType;
    }

    /**
     * @inheritDoc
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }
}
