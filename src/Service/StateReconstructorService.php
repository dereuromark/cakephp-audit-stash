<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\AuditLogType;
use Cake\Core\Exception\CakeException;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Service for reconstructing record state from audit logs
 */
class StateReconstructorService
{
    use LocatorAwareTrait;

    /**
     * Entry types whose `changed` payload is merged into the replayed state.
     *
     * @var array<string>
     */
    protected const MERGED_TYPES = [
        AuditLogType::Update->value,
        AuditLogType::Revert->value,
    ];

    /**
     * Reconstruct record state at specific audit log entry
     *
     * Replays `changed` payloads up to the target entry. Fields the replay
     * has no value for are then filled from the `original` payload of the
     * first later entry that touched them, so a record whose history does not
     * start with a `create` entry still reconstructs completely.
     *
     * @param string $source Table name
     * @param string|int $primaryKey Record ID
     * @param int $auditLogId Audit log entry to reconstruct to
     *
     * @return array Reconstructed data
     */
    public function reconstructState(string $source, string|int $primaryKey, int $auditLogId): array
    {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        /** @var array<\AuditStash\Model\Entity\AuditLog> $logs */
        $logs = $auditLogs->find()
            ->where([
                'source' => $source,
                'primary_key' => (string)$primaryKey,
            ])
            ->orderBy(['created' => 'ASC', 'id' => 'ASC'])
            ->toArray();

        $state = [];
        $later = [];
        $reached = false;

        foreach ($logs as $log) {
            if ($reached) {
                $later[] = $log;

                continue;
            }

            if ($log->type === AuditLogType::Create->value && $log->changed !== null) {
                $state = $this->decode($log->changed);
            } elseif (in_array($log->type, static::MERGED_TYPES, true)) {
                $state = array_merge($state, $this->decode($log->changed));
            }

            $reached = (int)$log->id === $auditLogId;
        }

        return $state + $this->auditedOnly($this->valuesBefore($later), $source);
    }

    /**
     * Collects, per field, the value it had before the given entries changed it.
     *
     * @param array<\AuditStash\Model\Entity\AuditLog> $logs Entries in chronological order
     *
     * @return array<string, mixed>
     */
    protected function valuesBefore(array $logs): array
    {
        $values = [];

        foreach ($logs as $log) {
            // A later create is a new record under a reused primary key.
            if ($log->type === AuditLogType::Create->value) {
                break;
            }

            $values += $this->decode($log->original);
        }

        return $values;
    }

    /**
     * Drops fields the source table does not audit. A `revert` entry stores
     * the full row in `original`, so those fields would otherwise be restored.
     *
     * @param array<string, mixed> $values Field values
     * @param string $source Table name
     *
     * @return array<string, mixed>
     */
    protected function auditedOnly(array $values, string $source): array
    {
        try {
            $table = $this->fetchTable($source);
        } catch (CakeException) {
            return $values;
        }

        if (!$table->hasBehavior('AuditLog')) {
            return $values;
        }

        $behavior = $table->getBehavior('AuditLog');
        $whitelist = (array)$behavior->getConfig('whitelist');
        if ($whitelist) {
            $values = array_intersect_key($values, array_flip($whitelist));
        }

        return array_diff_key($values, array_flip((array)$behavior->getConfig('blacklist')));
    }

    /**
     * @param mixed $payload JSON string (as written for `revert` entries) or decoded array
     *
     * @return array<string, mixed>
     */
    protected function decode(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
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
