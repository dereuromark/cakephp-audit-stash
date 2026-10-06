<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\AuditLogType;
use AuditStash\AuditStashPlugin;
use AuditStash\Event\AuditCustomEvent;
use AuditStash\Model\Entity\AuditLog;
use AuditStash\Persister\TablePersister;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use PDOException;
use RuntimeException;

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
    public function revertFull(string $source, string|int $primaryKey, int $auditLogId): EntityInterface|false
    {
        $this->assertEnabled();

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId) {
            // Get target state
            $targetState = $this->withoutSensitive(
                $source,
                $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId),
            );

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
    public function revertPartial(string $source, string|int $primaryKey, int $auditLogId, array $fields): EntityInterface|false
    {
        $this->assertEnabled();

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($source, $primaryKey, $auditLogId, $fields) {
            // Get target state
            $fullTargetState = $this->reconstructor->reconstructState($source, $primaryKey, $auditLogId);

            // Filter only selected fields
            $targetState = $this->withoutSensitive(
                $source,
                array_intersect_key($fullTargetState, array_flip($fields)),
            );

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
    public function restoreDeleted(string $source, string|int $primaryKey): EntityInterface|false
    {
        $this->assertEnabled();

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');

        $restore = function () use ($source, $primaryKey) {
            // Find DELETE audit entry
            $auditLogs = $this->fetchTable('AuditStash.AuditLogs');
            $deleteLog = $auditLogs->find()
                ->where([
                    'source' => $source,
                    'primary_key' => (string)$primaryKey,
                    'type' => AuditLogType::Delete->value,
                ])
                ->orderBy(['created' => 'DESC'])
                ->first();

            if (!$deleteLog instanceof AuditLog) {
                return false;
            }
            // Get state before deletion
            $original = $deleteLog->original;
            $state = is_string($original) ? json_decode($original, true) : $original;
            $state = $this->withoutSensitive($source, $state ?: []);
            if (!$state) {
                return false;
            }

            // Create new entity
            $table = $this->fetchTable($source);

            $primaryKeyField = $table->getPrimaryKey();
            if (is_array($primaryKeyField)) {
                $primaryKeyField = $primaryKeyField[0] ?? '';
            }
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

            // Rules may depend on related rows that were deleted as well
            if (!$table->save($entity, ['checkRules' => false])) {
                return false;
            }

            // Create audit entry for restore
            $this->createRevertAudit($source, $primaryKey, (int)$deleteLog->id, 'restore', [], $state);

            return $entity;
        };

        try {
            return $connection->transactional($restore);
        } catch (PDOException $e) {
            // An incomplete snapshot cannot satisfy the table's column constraints.
            Log::error(sprintf('AuditStash could not restore %s %s: %s', $source, $primaryKey, $e->getMessage()));

            return false;
        }
    }

    /**
     * Removes the fields the source table redacts in its audit rows. Their
     * stored value is the redaction marker, not the data.
     *
     * @param string $source Table name
     * @param array<string, mixed> $state Field values taken from audit rows
     *
     * @return array<string, mixed>
     */
    public function withoutSensitive(string $source, array $state): array
    {
        $table = $this->fetchTable($source);
        if (!$table->hasBehavior('AuditLog')) {
            return $state;
        }

        return array_diff_key($state, array_flip((array)$table->getBehavior('AuditLog')->getConfig('sensitive')));
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
        string|int $primaryKey,
        int $auditLogId,
        string $revertType,
        array $currentState,
        array $targetState,
    ): void {
        if (!Configure::read('AuditStash.revert.auditReverts', true)) {
            return;
        }

        $event = new AuditCustomEvent(
            AuditLogType::Revert->value,
            Text::uuid(),
            $primaryKey,
            $source,
            $this->jsonValues($targetState),
            $this->jsonValues($currentState),
        );
        $event->setMetaInfo([
            'revert_to_audit_id' => $auditLogId,
            'revert_type' => $revertType,
        ]);

        $data = $this->fetchTable($source)->dispatchEvent('AuditStash.beforeLog', ['logs' => [$event]]);
        $this->persister()->logEvents($data->getData('logs'));
    }

    /**
     * Reduces entity values (dates, enums) to what the JSON column gives back
     * on read, so the hash written now matches the one verified later.
     *
     * @param array<string, mixed> $values Field values
     *
     * @return array<string, mixed>
     */
    protected function jsonValues(array $values): array
    {
        return (array)json_decode((string)json_encode($values, AuditStashPlugin::JSON_FLAGS), true);
    }

    /**
     * The persister keeps the payload encoding and the hash chain consistent
     * with the rows the behavior writes.
     *
     * @return \AuditStash\Persister\TablePersister
     */
    protected function persister(): TablePersister
    {
        $class = Configure::read('AuditStash.persister');
        if (!is_string($class) || !is_a($class, TablePersister::class, true)) {
            $class = TablePersister::class;
        }

        $persister = new $class();
        $config = Configure::read('AuditStash.persisterConfig');
        if (is_array($config) && $config) {
            $persister->setConfig($config);
        }

        return $persister;
    }

    /**
     * Refuses to run when `AuditStash.revert.enabled` is `false`.
     *
     * Defaults to enabled (the historic behavior). Hosts that want to
     * disable revert/restore entirely set `AuditStash.revert.enabled => false`
     * and the three public revert methods will throw instead of mutating
     * the row, so an accidental admin-side trigger can't take effect.
     *
     * @throws \RuntimeException When the feature is disabled.
     *
     * @return void
     */
    protected function assertEnabled(): void
    {
        if (!Configure::read('AuditStash.revert.enabled', true)) {
            throw new RuntimeException('AuditStash revert/restore is disabled (AuditStash.revert.enabled = false).');
        }
    }
}
