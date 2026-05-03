<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Rule;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;

/**
 * Rule to detect modifications to sensitive fields.
 *
 * Triggers when an audit log touches any of the configured fields on any of
 * the configured tables. For Create events the fields are looked up in the
 * `changed` payload, for Delete events in `original`, and for Update events
 * in either side.
 *
 * Configuration:
 * - `fields` (array, required): field names that are considered sensitive.
 *   The rule never matches when this is empty — empty means "no sensitive
 *   fields configured", not "match everything".
 * - `tables` (array, optional): restrict matching to these source tables.
 *   When empty (default) the rule matches any source.
 * - `severity` (string, optional): defaults to `high`.
 *
 * ```php
 * 'AuditStash.monitor.rules' => [
 *     [
 *         'class' => SensitiveFieldRule::class,
 *         'config' => [
 *             'tables' => ['Users', 'ApiKeys'],
 *             'fields' => ['password', 'role', 'two_factor_secret', 'api_token'],
 *             'severity' => 'high',
 *         ],
 *     ],
 * ],
 * ```
 */
class SensitiveFieldRule extends AbstractRule
{
    /**
     * @inheritDoc
     */
    public function matches(AuditLog $auditLog): bool
    {
        $fields = (array)$this->getConfig('fields', []);
        if (!$fields) {
            return false;
        }

        $tables = (array)$this->getConfig('tables', []);
        if ($tables && !in_array($auditLog->source, $tables, true)) {
            return false;
        }

        return (bool)$this->matchedFields($auditLog, $fields);
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): string
    {
        return (string)$this->getConfig('severity', 'high');
    }

    /**
     * @inheritDoc
     */
    public function getMessage(AuditLog $auditLog): string
    {
        $fields = (array)$this->getConfig('fields', []);
        $matched = $this->matchedFields($auditLog, $fields);

        return sprintf(
            'Sensitive field(s) %s modified on %s (%s)',
            implode(', ', $matched),
            $auditLog->source,
            $auditLog->type,
        );
    }

    /**
     * @inheritDoc
     */
    public function getContext(AuditLog $auditLog): array
    {
        $fields = (array)$this->getConfig('fields', []);

        return [
            'table' => $auditLog->source,
            'event_type' => $auditLog->type,
            'matched_fields' => $this->matchedFields($auditLog, $fields),
            'configured_fields' => $fields,
        ];
    }

    /**
     * Returns the configured field names that actually appear in the row's
     * `changed` / `original` payload, depending on event type.
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog
     * @param array<string> $fields Configured sensitive field names
     *
     * @return array<string>
     */
    protected function matchedFields(AuditLog $auditLog, array $fields): array
    {
        $payloadKeys = $this->payloadKeys($auditLog);
        if (!$payloadKeys) {
            return [];
        }

        return array_values(array_intersect($fields, $payloadKeys));
    }

    /**
     * Collects the field names present in the relevant side of the row's
     * payload for this event type. Tolerates string-encoded JSON for rows
     * read with non-default hydration (defensive — `AuditLogsTable` casts
     * these columns to `json` so we usually get arrays).
     *
     * @param \AuditStash\Model\Entity\AuditLog $auditLog
     *
     * @return array<string>
     */
    protected function payloadKeys(AuditLog $auditLog): array
    {
        $sides = match ($auditLog->type) {
            AuditLogType::Create->value => ['changed'],
            AuditLogType::Delete->value => ['original'],
            default => ['changed', 'original'],
        };

        $keys = [];
        foreach ($sides as $side) {
            $payload = $auditLog->get($side);
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $payload = is_array($decoded) ? $decoded : null;
            }

            if (is_array($payload)) {
                $keys = array_merge($keys, array_keys($payload));
            }
        }

        return array_values(array_unique($keys));
    }
}
