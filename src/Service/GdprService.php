<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\AuditStashPlugin;
use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

/**
 * GDPR Compliance Service
 *
 * Provides functionality to anonymize, delete, and export user audit logs
 * for GDPR compliance (Right to Erasure, Right to be Forgotten, Data Portability).
 */
class GdprService
{
    use LocatorAwareTrait;

    /**
     * Default anonymization values for meta fields.
     *
     * @var array<string, mixed>
     */
    protected array $defaultAnonymizeFields = [
        'user' => 'ANONYMIZED',
        'username' => 'ANONYMIZED',
        'email' => 'deleted@anonymized.local',
        'ip' => '0.0.0.0',
        'user_agent' => 'ANONYMIZED',
    ];

    /**
     * Find all audit logs for a specific user.
     *
     * @param string|int $userId User ID to search for
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findByUser(int|string $userId): SelectQuery
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogsTable */
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');

        return $auditLogsTable->find()
            ->where(['user_id' => (string)$userId])
            ->orderBy(['created' => 'DESC']);
    }

    /**
     * Anonymize all audit logs for a user.
     *
     * Replaces identifying information but keeps the audit trail intact
     * for compliance purposes.
     *
     * @param string|int $userId User ID to anonymize
     * @param array<string, mixed> $options Options:
     *   - 'anonymizeFields': Custom fields to anonymize in meta (merges with defaults)
     *   - 'anonymizeUserId': Strategy for user_id field ('hash', 'null', or 'placeholder')
     *   - 'piiFields': Additional PII fields to redact in original/changed data
     *
     * @return int Number of records anonymized
     */
    public function anonymize(int|string $userId, array $options = []): int
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogsTable */
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');

        $anonymizeFields = array_merge(
            $this->defaultAnonymizeFields,
            $this->getConfiguredAnonymizeFields(),
            $options['anonymizeFields'] ?? [],
        );

        $userIdStrategy = $options['anonymizeUserId']
            ?? Configure::read('AuditStash.gdpr.anonymizeUserId', 'hash');

        $piiFields = array_merge(
            $this->getDefaultPiiFields(),
            $options['piiFields'] ?? [],
        );

        $anonymizedUserId = $this->generateAnonymizedUserId($userId, $userIdStrategy);

        $logs = $this->findByUser($userId)->toArray();
        $count = 0;

        foreach ($logs as $log) {
            // Anonymize user_id and user_display
            $log->user_id = $anonymizedUserId;
            $log->user_display = 'ANONYMIZED';

            // Anonymize meta data
            if ($log->meta) {
                $meta = is_string($log->meta) ? json_decode($log->meta, true) : $log->meta;
                if (is_array($meta)) {
                    foreach ($anonymizeFields as $field => $replacement) {
                        if (array_key_exists($field, $meta)) {
                            $meta[$field] = $replacement;
                        }
                    }
                    $log->meta = json_encode($meta, AuditStashPlugin::JSON_FLAGS);
                }
            }

            // Anonymize PII in original data
            if ($log->original) {
                $original = is_string($log->original) ? json_decode($log->original, true) : $log->original;
                if (is_array($original)) {
                    $original = $this->redactPiiFields($original, $piiFields);
                    $log->original = json_encode($original, AuditStashPlugin::JSON_FLAGS);
                }
            }

            // Anonymize PII in changed data
            if ($log->changed) {
                $changed = is_string($log->changed) ? json_decode($log->changed, true) : $log->changed;
                if (is_array($changed)) {
                    $changed = $this->redactPiiFields($changed, $piiFields);
                    $log->changed = json_encode($changed, AuditStashPlugin::JSON_FLAGS);
                }
            }

            if ($auditLogsTable->save($log)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete all audit logs for a user.
     *
     * Use with caution - this destroys the audit trail completely.
     * Consider using anonymize() instead for compliance purposes.
     *
     * @param string|int $userId User ID to delete logs for
     *
     * @return int Number of records deleted
     */
    public function delete(int|string $userId): int
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogsTable */
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');

        return $auditLogsTable->deleteAll(['user_id' => (string)$userId]);
    }

    /**
     * Export all audit logs for a user.
     *
     * Supports GDPR data portability requirements.
     *
     * @param string|int $userId User ID to export logs for
     * @param string $format Export format ('json' or 'array')
     *
     * @return array<mixed>|string
     */
    public function export(int|string $userId, string $format = 'json'): array|string
    {
        $logs = $this->findByUser($userId)->toArray();

        $data = array_map(function ($log) {
            return [
                'id' => $log->id,
                'transaction' => $log->transaction,
                'type' => $log->type->value ?? $log->type,
                'source' => $log->source,
                'primary_key' => $log->primary_key,
                'display_value' => $log->display_value,
                'original' => is_string($log->original) ? json_decode($log->original, true) : $log->original,
                'changed' => is_string($log->changed) ? json_decode($log->changed, true) : $log->changed,
                'meta' => is_string($log->meta) ? json_decode($log->meta, true) : $log->meta,
                'created' => $log->created?->toIso8601String(),
            ];
        }, $logs);

        if ($format === 'json') {
            return (string)json_encode([
                'export_date' => date('c'),
                'user_id' => $userId,
                'record_count' => count($data),
                'audit_logs' => $data,
            ], AuditStashPlugin::JSON_FLAGS | JSON_PRETTY_PRINT);
        }

        return $data;
    }

    /**
     * Get statistics for a user's audit logs.
     *
     * @param string|int $userId User ID
     *
     * @return array<string, mixed>
     */
    public function getStats(int|string $userId): array
    {
        /** @var \AuditStash\Model\Table\AuditLogsTable $auditLogsTable */
        $auditLogsTable = $this->fetchTable('AuditStash.AuditLogs');

        $query = $auditLogsTable->find()
            ->where(['user_id' => (string)$userId]);

        $totalCount = $query->count();

        if ($totalCount === 0) {
            return [
                'total' => 0,
                'by_table' => [],
                'by_type' => [],
            ];
        }

        // Count by table
        $byTable = $auditLogsTable->find()
            ->select([
                'source',
                'count' => $auditLogsTable->find()->func()->count('*'),
            ])
            ->where(['user_id' => (string)$userId])
            ->groupBy(['source'])
            ->orderBy(['count' => 'DESC'])
            ->all()
            ->combine('source', 'count')
            ->toArray();

        // Count by type
        $byType = $auditLogsTable->find()
            ->select([
                'type',
                'count' => $auditLogsTable->find()->func()->count('*'),
            ])
            ->where(['user_id' => (string)$userId])
            ->groupBy(['type'])
            ->all()
            ->combine('type', 'count')
            ->toArray();

        return [
            'total' => $totalCount,
            'by_table' => $byTable,
            'by_type' => $byType,
        ];
    }

    /**
     * Generate anonymized user ID based on strategy.
     *
     * @param string|int $userId Original user ID
     * @param string $strategy Strategy: 'hash', 'null', or 'placeholder'
     *
     * @return string|null
     */
    protected function generateAnonymizedUserId(int|string $userId, string $strategy): ?string
    {
        return match ($strategy) {
            'hash' => 'anon_' . substr(hash('sha256', (string)$userId . Configure::read('Security.salt', '')), 0, 8),
            'null' => null,
            'placeholder' => 'DELETED_USER',
            default => 'anon_' . substr(hash('sha256', (string)$userId), 0, 8),
        };
    }

    /**
     * Get configured anonymize fields from config.
     *
     * @return array<string, mixed>
     */
    protected function getConfiguredAnonymizeFields(): array
    {
        return Configure::read('AuditStash.gdpr.anonymizeFields', []);
    }

    /**
     * Get default PII fields to redact.
     *
     * @return array<string>
     */
    protected function getDefaultPiiFields(): array
    {
        return Configure::read('AuditStash.gdpr.piiFields', [
            'email',
            'name',
            'first_name',
            'last_name',
            'phone',
            'address',
            'ip_address',
            'username',
        ]);
    }

    /**
     * Redact PII fields from data array.
     *
     * @param array<string, mixed> $data Data to redact
     * @param array<string> $piiFields Fields to redact
     *
     * @return array<string, mixed>
     */
    protected function redactPiiFields(array $data, array $piiFields): array
    {
        foreach ($piiFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = '[REDACTED]';
            }
        }

        return $data;
    }
}
