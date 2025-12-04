<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\AuditStashPlugin;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;

/**
 * Service for reverting and restoring records
 */
class RevertService
{
    use LocatorAwareTrait;

    protected StateReconstructorService $reconstructor;

    public function __construct()
    {
        $this->reconstructor = new StateReconstructorService();
    }

    /**
     * Revert entire record to previous state
     *
     * @param string $source Table name
     * @param string|int $primaryKey Record ID
     * @param int $auditLogId Target audit log entry
     *
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function revertFull(string $source, int|string $primaryKey, int $auditLogId): EntityInterface|false
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId) {
            // Get target state
            $targetState = $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId);

            // Load and update entity
            $table = $this->fetchTable($source);
            $entity = $table->get($primaryKey);

            // Get current state for audit
            $currentState = $entity->extract($entity->getVisible());

            // Patch entity with target state
            $entity = $table->patchEntity($entity, $targetState);

            // Save entity
            if (!$table->save($entity)) {
                return false;
            }

            // Create audit entry for revert
            $this->createRevertAudit($source, $primaryKey, $auditLogId, 'full', $currentState, $targetState);

            return $entity;
        });
    }

    /**
     * Revert specific fields only
     *
     * @param string $source Table name
     * @param string|int $primaryKey Record ID
     * @param int $auditLogId Target audit log entry
     * @param array<string> $fields Fields to revert
     *
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function revertPartial(string $source, int|string $primaryKey, int $auditLogId, array $fields): EntityInterface|false
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId, $fields) {
            // Get target state
            $fullTargetState = $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId);

            // Filter only selected fields
            $targetState = array_intersect_key($fullTargetState, array_flip($fields));

            // Load and update entity
            $table = $this->fetchTable($source);
            $entity = $table->get($primaryKey);

            // Get current state for audit
            $currentState = $entity->extract($fields);

            // Patch entity with target state
            $entity = $table->patchEntity($entity, $targetState);

            // Save entity
            if (!$table->save($entity)) {
                return false;
            }

            // Create audit entry for revert
            $this->createRevertAudit($source, $primaryKey, $auditLogId, 'partial', $currentState, $targetState);

            return $entity;
        });
    }

    /**
     * Restore deleted record
     *
     * @param string $source Table name
     * @param string|int $primaryKey Deleted record ID
     *
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function restoreDeleted(string $source, int|string $primaryKey): EntityInterface|false
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey) {
            // Find DELETE audit entry
            $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
            $deleteLog = $auditLogs->find()
                ->where([
                    'source' => $source,
                    'primary_key' => (string)$primaryKey,
                    'type' => 'delete',
                ])
                ->orderBy(['created' => 'DESC'])
                ->first();

            if (!$deleteLog) {
                return false;
            }

            // Get state before deletion
            $state = json_decode($deleteLog->original, true) ?: [];

            // Create new entity
            $table = $this->fetchTable($source);

            /** @var string $primaryKeyField */
            $primaryKeyField = $table->getPrimaryKey();
            $exists = $table->exists([$primaryKeyField => $primaryKey]);
            if ($exists) {
                return false;
            }

            $entity = $table->newEntity($state, [
                'accessibleFields' => ['*' => true], // Allow setting all fields including timestamps
            ]);

            // Force the primary key
            $entity->set($table->getPrimaryKey(), $primaryKey);
            $entity->setNew(true);

            // If created/modified fields are missing, add them now
            $schema = $table->getSchema();
            if ($schema->hasColumn('created') && !isset($state['created'])) {
                $entity->set('created', new DateTime());
            }
            if ($schema->hasColumn('modified') && !isset($state['modified'])) {
                $entity->set('modified', new DateTime());
            }

            // Save entity without callbacks to avoid triggering behaviors
            if (!$table->save($entity, ['checkRules' => false])) {
                return false;
            }

            // Create audit entry for restore
            $this->createRevertAudit($source, $primaryKey, $deleteLog->id, 'restore', [], $state);

            return $entity;
        });
    }

    /**
     * Create audit entry for revert operation
     *
     * @param string $source Table name
     * @param string|int $primaryKey Record ID
     * @param int $auditLogId Target audit log ID
     * @param string $revertType Type of revert (full, partial, restore)
     * @param array $currentState Current state before revert
     * @param array $targetState Target state after revert
     *
     * @return void
     */
    protected function createRevertAudit(
        string $source,
        int|string $primaryKey,
        int $auditLogId,
        string $revertType,
        array $currentState,
        array $targetState,
    ): void {
        $auditLogs = $this->fetchTable('AuditStash.AuditLogs');

        $auditLog = $auditLogs->newEntity([
            'transaction' => Text::uuid(),
            'type' => 'revert',
            'source' => $source,
            'primary_key' => (string)$primaryKey,
            'original' => json_encode($currentState, AuditStashPlugin::JSON_FLAGS),
            'changed' => json_encode($targetState, AuditStashPlugin::JSON_FLAGS),
            'meta' => json_encode([
                'revert_to_audit_id' => $auditLogId,
                'revert_type' => $revertType,
            ], AuditStashPlugin::JSON_FLAGS),
            'created' => new DateTime(),
        ]);

        $auditLogs->save($auditLog);
    }
}
