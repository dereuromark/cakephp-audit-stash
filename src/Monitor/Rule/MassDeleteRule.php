<?php

declare(strict_types=1);

namespace AuditStash\Monitor\Rule;

use AuditStash\AuditLogType;
use AuditStash\Model\Entity\AuditLog;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Rule to detect mass deletion events.
 *
 * Triggers when multiple delete operations occur within a short timeframe.
 */
class MassDeleteRule extends AbstractRule
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    public function matches(AuditLog $auditLog): bool
    {
        if ($auditLog->type !== AuditLogType::Delete) {
            return false;
        }

        $tables = $this->getConfig('tables');
        if ($tables && !in_array($auditLog->source, $tables, true)) {
            return false;
        }

        $threshold = $this->getConfig('threshold', 10);
        $timeframe = $this->getConfig('timeframe', 300);

        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');
        $since = DateTime::now()->subSeconds($timeframe);

        $count = $auditLogsTable->find()
            ->where([
                'type' => AuditLogType::Delete->value,
                'source' => $auditLog->source,
                'created >=' => $since,
            ])
            ->count();

        return $count >= $threshold;
    }

    /**
     * @inheritDoc
     */
    public function getSeverity(): string
    {
        return $this->getConfig('severity', 'critical');
    }

    /**
     * @inheritDoc
     */
    public function getMessage(AuditLog $auditLog): string
    {
        $threshold = $this->getConfig('threshold', 10);
        $timeframe = $this->getConfig('timeframe', 300);
        $minutes = round($timeframe / 60);

        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');
        $since = DateTime::now()->subSeconds($timeframe);

        $count = $auditLogsTable->find()
            ->where([
                'type' => AuditLogType::Delete->value,
                'source' => $auditLog->source,
                'created >=' => $since,
            ])
            ->count();

        return sprintf(
            'Mass deletion detected: %d records deleted from %s in the last %d minute(s)',
            $count,
            $auditLog->source,
            $minutes,
        );
    }

    /**
     * @inheritDoc
     */
    public function getContext(AuditLog $auditLog): array
    {
        $threshold = $this->getConfig('threshold', 10);
        $timeframe = $this->getConfig('timeframe', 300);
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');
        $since = DateTime::now()->subSeconds($timeframe);

        $count = $auditLogsTable->find()
            ->where([
                'type' => AuditLogType::Delete->value,
                'source' => $auditLog->source,
                'created >=' => $since,
            ])
            ->count();

        return [
            'threshold' => $threshold,
            'timeframe_seconds' => $timeframe,
            'delete_count' => $count,
            'table' => $auditLog->source,
        ];
    }
}
