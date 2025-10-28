<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use Cake\I18n\DateTime;

/**
 * Rule to detect activity outside normal business hours.
 *
 * Triggers when operations occur outside configured business hours.
 */
class UnusualTimeActivityRule extends AbstractRule
{
    /**
     * @inheritDoc
     */
    public function matches(AuditLog $auditLog): bool
    {
        $tables = $this->getConfig('tables');
        if ($tables && !in_array($auditLog->source, $tables, true)) {
            return false;
        }

        $businessHours = $this->getConfig('business_hours', [
            'start' => '08:00',
            'end' => '18:00',
        ]);

        $businessDays = $this->getConfig('business_days', [1, 2, 3, 4, 5]);

        $created = $auditLog->created ?? DateTime::now();
        $dayOfWeek = (int)$created->format('N');
        $timeOfDay = $created->format('H:i');

        if (!in_array($dayOfWeek, $businessDays, true)) {
            return true;
        }

        if ($timeOfDay < $businessHours['start'] || $timeOfDay > $businessHours['end']) {
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): string
    {
        return $this->getConfig('severity', 'medium');
    }

    /**
     * @inheritDoc
     */
    public function getMessage(AuditLog $auditLog): string
    {
        $created = $auditLog->created ?? DateTime::now();

        return sprintf(
            'Off-hours activity detected: %s operation on %s at %s',
            ucfirst($auditLog->type),
            $auditLog->source,
            $created->format('Y-m-d H:i:s'),
        );
    }

    /**
     * @inheritDoc
     */
    public function getContext(AuditLog $auditLog): array
    {
        $meta = $auditLog->meta ? json_decode($auditLog->meta, true) : [];
        $created = $auditLog->created ?? DateTime::now();

        return [
            'table' => $auditLog->source,
            'operation' => $auditLog->type,
            'timestamp' => $created->toIso8601String(),
            'day_of_week' => $created->format('l'),
            'time_of_day' => $created->format('H:i:s'),
            'user' => $meta['user'] ?? null,
            'ip' => $meta['ip'] ?? null,
        ];
    }
}
