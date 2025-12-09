<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\AuditLogType;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Service for reconstructing record state from audit logs
 */
class StateReconstructorService
{
    use LocatorAwareTrait;

    /**
     * Reconstruct record state at specific audit log entry
     *
     * @param string $source Table name
     * @param string|int $primaryKey Record ID
     * @param int $auditLogId Audit log entry to reconstruct to
     *
     * @return array Reconstructed data
     */
    public function reconstructState(string $source, int|string $primaryKey, int $auditLogId): array
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $logs = $auditLogs->find()
            ->where([
                'source' => $source,
                'primary_key' => (string)$primaryKey,
            ])
            ->orderBy(['created' => 'ASC'])
            ->toArray();

        $state = [];

        // Apply changes sequentially up to target
        foreach ($logs as $log) {
            if ($log->type === AuditLogType::Create) {
                $state = json_decode($log->changed, true) ?: [];
            } elseif ($log->type === AuditLogType::Update) {
                $changed = json_decode($log->changed, true) ?: [];
                $state = array_merge($state, $changed);
            }

            if ($log->id === $auditLogId) {
                break;
            }
        }

        return $state;
    }

    /**
     * Calculate diff between current and target state
     *
     * @param array $currentState Current record data
     * @param array $targetState Target state from audit
     *
     * @return array Fields that will change
     */
    public function calculateDiff(array $currentState, array $targetState): array
    {
        $diff = [];

        foreach ($targetState as $field => $value) {
            if (!isset($currentState[$field]) || $currentState[$field] !== $value) {
                $diff[$field] = [
                    'current' => $currentState[$field] ?? null,
                    'target' => $value,
                ];
            }
        }

        return $diff;
    }
}
