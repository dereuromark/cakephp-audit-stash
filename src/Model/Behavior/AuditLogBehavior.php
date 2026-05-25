<?php

declare(strict_types=1);

namespace AuditStash\Model\Behavior;

use ArrayObject;
use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditDeleteEvent;
use AuditStash\Event\AuditUpdateEvent;
use AuditStash\Event\BaseEvent;
use AuditStash\Filter\ChangeFilter;
use AuditStash\Persister\TablePersister;
use AuditStash\PersisterInterface;
use BackedEnum;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Association\HasOne;
use Cake\ORM\Behavior;
use Cake\Utility\Inflector;
use Cake\Utility\Text;
use SplObjectStorage;
use Stringable;
use UnitEnum;
use WeakMap;
use function Cake\Collection\collection;

/**
 * This behavior can be used to log all the creations, modifications and deletions
 * done to a particular table.
 *
 * IMPORTANT: this behavior hooks per-entity ORM model events
 * (`Model.beforeSave` / `afterSave` / `afterSaveCommit`). Bulk-write paths
 * that bypass those events — `Table::updateAll()`, `Table::deleteAll()`,
 * `query()->update()->execute()`, `query()->delete()->execute()`, and raw
 * `Connection::execute()` SQL — are NOT audited. See
 * `docs/guide/usage.md#operations-that-bypass-the-audit-listener` for the
 * full list and the compensating-audit pattern for tables where you really
 * need bulk writes alongside the audit chain.
 */
class AuditLogBehavior extends Behavior
{
    /**
     * Default configuration.
     *
     * - `blacklist`: Fields to exclude from audit logging (default: created, modified, id)
     * - `whitelist`: Fields to include in audit logging (if empty, all fields except blacklisted are included)
     * - `sensitive`: Fields to redact in audit logs (values shown as ****)
     * - `cascadeDeletes`: Whether to log cascade-deleted dependent records (default: false)
     * - `ignoreEmpty`: Skip logging if no changes after filtering (default: true)
     * - `ignoreTimestampOnly`: Skip logging if only timestamp fields changed (default: false)
     * - `ignoreFields`: Skip logging if only these fields changed (default: [])
     * - `ignoreWhitespace`: Ignore whitespace-only changes in strings (default: false)
     * - `ignoreCase`: Ignore case-only changes in strings (default: false)
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'index' => null,
        'type' => null,
        'blacklist' => ['created', 'modified', 'id'],
        'whitelist' => [],
        'sensitive' => [],
        'cascadeDeletes' => false,
        'ignoreEmpty' => true,
        'ignoreTimestampOnly' => false,
        'ignoreFields' => [],
        'ignoreWhitespace' => false,
        'ignoreCase' => false,
    ];

    /**
     * The persister object.
     *
     * @var \AuditStash\PersisterInterface|null
     */
    protected ?PersisterInterface $persister = null;

    /**
     * Audit queues kept alive across a `Table::saveMany()` bulk operation,
     * keyed by the primary entity. CakePHP discards the per-entity options
     * (and its queue) before the deferred `Model.afterSaveCommit` fires, so the
     * queue is stashed here at `beforeSave` time and retrieved in
     * `afterCommit()`. A `WeakMap` is used so entries vanish automatically if a
     * bulk save rolls back (the commit event never fires to clean them up).
     *
     * @var \WeakMap<\Cake\Datasource\EntityInterface, \SplObjectStorage<\Cake\Datasource\EntityInterface, \AuditStash\Event\BaseEvent>>
     */
    protected WeakMap $bulkSaveQueues;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        $this->bulkSaveQueues = new WeakMap();
    }

    /**
     * Returns the list of implemented events.
     *
     * @return array
     */
    public function implementedEvents(): array
    {
        $events = [
            'Model.beforeSave' => 'injectTracking',
            'Model.beforeDelete' => 'injectTracking',
            'Model.afterSave' => 'afterSave',
            'Model.afterDelete' => 'afterDelete',
        ];

        if (Configure::read('AuditStash.saveType') !== 'afterSave') {
            $events['Model.afterSaveCommit'] = 'afterCommit';
            $events['Model.afterDeleteCommit'] = 'afterCommit';
        }

        return $events;
    }

    /**
     * Conditionally adds the `_auditTransaction` and `_auditQueue` keys to $options. They are
     * used to track all changes done inside the same transaction.
     *
     * @param \Cake\Event\Event $event The Model event that is enclosed inside a transaction
     * @param \Cake\Datasource\EntityInterface $entity The entity that is to be saved
     * @param \ArrayObject $options The options to be passed to the save or delete operation
     *
     * @return void
     */
    public function injectTracking(
        Event $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        if (!isset($options['_auditTransaction'])) {
            $options['_auditTransaction'] = Text::uuid();
        }

        if (!isset($options['_auditQueue'])) {
            $options['_auditQueue'] = new SplObjectStorage();
        }

        // Inside a saveMany() the per-entity options (and queue) are discarded
        // before the deferred afterSaveCommit fires, so keep a reference to the
        // queue keyed by the primary entity. Associated saves share this same
        // queue, so afterCommit() can flush parent and children together once
        // the outer transaction has committed. Registered on the primary entity
        // regardless of whether it produces an event, so association-only
        // changes are not lost.
        if (
            $event->getName() === 'Model.beforeSave'
            && ($options['_primary'] ?? false)
            && $this->isBulkSave($options)
        ) {
            /** @var \SplObjectStorage<\Cake\Datasource\EntityInterface, \AuditStash\Event\BaseEvent> $queue */
            $queue = $options['_auditQueue'];
            $this->bulkSaveQueues[$entity] = $queue;
        }

        // Capture cascade-deleted dependent records before they are deleted
        if ($event->getName() === 'Model.beforeDelete' && $this->getConfig('cascadeDeletes')) {
            $this->captureCascadeDeletes($entity, $options);
        }
    }

    /**
     * Captures dependent records that will be cascade-deleted for audit logging.
     *
     * @param \Cake\Datasource\EntityInterface $entity The parent entity being deleted
     * @param \ArrayObject $options The delete options
     *
     * @return void
     */
    protected function captureCascadeDeletes(EntityInterface $entity, ArrayObject $options): void
    {
        if (!isset($options['_cascadeDeleteRecords'])) {
            $options['_cascadeDeleteRecords'] = [];
        }

        $primaryKey = $this->_table->getPrimaryKey();
        $primaryValue = $entity->get(is_array($primaryKey) ? $primaryKey[0] : $primaryKey);

        if ($primaryValue === null) {
            return;
        }

        foreach ($this->_table->associations() as $association) {
            // Only process HasMany and HasOne associations with dependent = true
            if (!($association instanceof HasMany) && !($association instanceof HasOne)) {
                continue;
            }

            if (!$association->getDependent()) {
                continue;
            }

            $target = $association->getTarget();
            $foreignKey = $association->getForeignKey();

            if (!is_string($foreignKey)) {
                continue;
            }

            // Query for dependent records
            $dependentRecords = $target->find()
                ->where([$target->aliasField($foreignKey) => $primaryValue])
                ->all()
                ->toArray();

            foreach ($dependentRecords as $record) {
                $options['_cascadeDeleteRecords'][] = [
                    'entity' => $record,
                    'source' => $target->getRegistryAlias(),
                    'displayField' => $target->getDisplayField(),
                    'primaryKey' => $target->getPrimaryKey(),
                ];
            }
        }
    }

    /**
     * Redacts sensitive fields from the array
     *
     * @param array<string, mixed> $fields Field
     *
     * @return void
     */
    private function redactArray(array &$fields): void
    {
        $sensitive = $this->_config['sensitive'] ?? [];
        if ($sensitive === []) {
            return;
        }

        foreach ($fields as $field => &$value) {
            if (in_array($field, $sensitive, true)) {
                $value = '****';
            }
        }
    }

    /**
     * Creates an audit event for the entity change.
     *
     * @param string $transactionId Transaction Id
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param array<string, mixed> $changed Changed fields
     * @param array<string, mixed> $original Original fields
     *
     * @return \AuditStash\Event\BaseEvent
     */
    protected function createEvent(
        string $transactionId,
        EntityInterface $entity,
        array $changed,
        array $original,
    ): BaseEvent {
        $primary = $entity->extract((array)$this->_table->getPrimaryKey());
        $auditEvent = $entity->isNew() ? AuditCreateEvent::class : AuditUpdateEvent::class;

        // Get display field value for human-friendly identification
        $displayValue = $this->extractDisplayValue($entity, $this->_table->getDisplayField());

        return new $auditEvent(
            $transactionId,
            $primary,
            $this->_table->getRegistryAlias(),
            $changed,
            $original,
            $entity,
            $displayValue,
        );
    }

    /**
     * Calculates the changes done to the entity and stores the audit log event object into the
     * log queue inside the `_auditQueue` key in $options.
     *
     * @param \Cake\Event\Event $event The Model event that is enclosed inside a transaction
     * @param \Cake\Datasource\EntityInterface $entity The entity that is to be saved
     * @param \ArrayObject $options Options array containing the `_auditQueue` key
     *
     * @return void
     */
    public function afterSave(
        Event $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        if (!isset($options['_auditQueue'])) {
            return;
        }

        $config = $this->_config;
        if (empty($config['whitelist'])) {
            $config['whitelist'] = $this->_table->getSchema()->columns();
            $config['whitelist'] = array_merge(
                $config['whitelist'],
                $this->getAssociationProperties(array_keys($options['associated'])),
            );
        }

        $config['whitelist'] = array_diff($config['whitelist'], $config['blacklist']);
        $changed = $entity->extract($config['whitelist'], true);

        if (!$changed) {
            return;
        }

        $original = $entity->extractOriginal(array_keys($changed));

        $properties = $this->getAssociationProperties(array_keys($options['associated']));
        foreach ($properties as $property) {
            unset($changed[$property], $original[$property]);
        }

        if (!$changed || ($original === $changed && !$entity->isNew())) {
            return;
        }

        // Apply change filters for insignificant changes (skip for new entities)
        if (!$entity->isNew()) {
            $filterConfig = [
                'ignoreEmpty' => $config['ignoreEmpty'] ?? true,
                'ignoreTimestampOnly' => $config['ignoreTimestampOnly'] ?? false,
                'ignoreFields' => $config['ignoreFields'] ?? [],
                'ignoreWhitespace' => $config['ignoreWhitespace'] ?? false,
                'ignoreCase' => $config['ignoreCase'] ?? false,
            ];

            $filtered = ChangeFilter::filter($changed, $original, $filterConfig);
            if ($filtered === null) {
                return;
            }

            $changed = $filtered['changed'];
            $original = $filtered['original'];
        }

        $this->redactArray($changed);
        $this->redactArray($original);

        $transaction = $options['_auditTransaction'];
        $auditEvent = $this->createEvent($transaction, $entity, $changed, $original);

        if (!empty($options['_sourceTable'])) {
            $auditEvent->setParentSourceName($options['_sourceTable']->getRegistryAlias());
        }

        $options['_auditQueue']->offsetSet($entity, $auditEvent);

        if (Configure::read('AuditStash.saveType') === 'afterSave') {
            $this->afterCommit(new Event(''), $entity, $options);
        }
    }

    /**
     * Determines whether the current save runs inside a `Table::saveMany()`
     * bulk operation.
     *
     * `Table::saveMany()` is the only core path that sets `_cleanOnSuccess` to
     * `false`, which makes it a reliable marker for the bulk-save case where the
     * per-entity options (and audit queue) are discarded before the deferred
     * commit event fires. See {@see self::injectTracking()} and
     * {@see self::afterCommit()} for how the queue is kept alive across it.
     *
     * @param \ArrayObject $options The save options
     *
     * @return bool
     */
    protected function isBulkSave(ArrayObject $options): bool
    {
        return ($options['_cleanOnSuccess'] ?? true) === false;
    }

    /**
     * Persists all audit log events stored in the `_eventQueue` key inside $options.
     *
     * @param \Cake\Event\Event $event The Model event that is enclosed inside a transaction
     * @param \Cake\Datasource\EntityInterface $entity The entity that is to be saved or deleted
     * @param \ArrayObject $options Options array containing the `_auditQueue` key
     *
     * @return void
     */
    public function afterCommit(
        Event $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        // The queue normally lives on the (shared) options object. For a
        // saveMany() the per-entity options are discarded before this deferred
        // commit event fires, so fall back to the queue stashed by entity in
        // injectTracking().
        /** @var \SplObjectStorage<\Cake\Datasource\EntityInterface, \AuditStash\Event\BaseEvent>|null $queue */
        $queue = $options['_auditQueue'] ?? ($this->bulkSaveQueues[$entity] ?? null);
        if ($queue === null) {
            return;
        }

        $events = collection($queue)
            ->map(fn ($entity, $pos, $it): mixed => $it->getInfo())
            ->toList();

        if ($events) {
            $data = $this->_table->dispatchEvent('AuditStash.beforeLog', ['logs' => $events]);
            $this->persister()->logEvents($data->getData('logs'));
        }

        // Forget the queue after flushing. For a bulk delete the same options
        // (and queue) is shared while `Model.afterDeleteCommit` is dispatched
        // once per entity; without resetting, each dispatch would re-persist the
        // whole queue (N deletes -> N*N records). For a saveMany the stashed
        // per-entity queue is dropped once consumed.
        if (isset($options['_auditQueue'])) {
            $options['_auditQueue'] = new SplObjectStorage();
        }
        if (isset($this->bulkSaveQueues[$entity])) {
            unset($this->bulkSaveQueues[$entity]);
        }
    }

    /**
     * Persists all audit log events stored in the `_eventQueue` key inside $options.
     *
     * @param \Cake\Event\Event $event The Model event that is enclosed inside a transaction
     * @param \Cake\Datasource\EntityInterface $entity The entity that is to be saved or deleted
     * @param \ArrayObject $options Options array containing the `_auditQueue` key
     *
     * @return void
     */
    public function afterDelete(
        Event $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        if (!isset($options['_auditQueue'])) {
            return;
        }
        $transaction = $options['_auditTransaction'];
        $parent = isset($options['_sourceTable']) ? $options['_sourceTable']->getRegistryAlias() : null;
        $primary = $entity->extract((array)$this->_table->getPrimaryKey());

        // Get display field value for human-friendly identification
        $displayValue = $this->extractDisplayValue($entity, $this->_table->getDisplayField());

        // Capture original values before deletion
        $config = $this->_config;
        $original = $entity->getOriginalValues();

        // Filter out blacklisted fields from original
        foreach ($original as $originalKey => $originalValue) {
            if (in_array($originalKey, $config['blacklist'], true)) {
                unset($original[$originalKey]);
            }
        }

        $this->redactArray($original);

        // Log cascade-deleted dependent records first (children are deleted before parent)
        if ($this->getConfig('cascadeDeletes') && !empty($options['_cascadeDeleteRecords'])) {
            $this->logCascadeDeletes($transaction, $options);
        }

        $auditEvent = new AuditDeleteEvent(
            $transaction,
            $primary,
            $this->_table->getRegistryAlias(),
            $parent,
            $original,
            $displayValue,
        );
        $options['_auditQueue']->offsetSet($entity, $auditEvent);

        if (Configure::read('AuditStash.saveType') === 'afterSave') {
            $this->afterCommit(new Event(''), $entity, $options);
        }
    }

    /**
     * Creates audit delete events for cascade-deleted dependent records.
     *
     * @param string $transaction The transaction ID
     * @param \ArrayObject $options The delete options containing captured records
     *
     * @return void
     */
    protected function logCascadeDeletes(string $transaction, ArrayObject $options): void
    {
        $parentSource = $this->_table->getRegistryAlias();
        $config = $this->_config;

        foreach ($options['_cascadeDeleteRecords'] as $record) {
            /** @var \Cake\Datasource\EntityInterface $dependentEntity */
            $dependentEntity = $record['entity'];
            /** @var string $source */
            $source = $record['source'];
            /** @var array<string>|string $displayField */
            $displayField = $record['displayField'];
            /** @var array<string>|string $primaryKey */
            $primaryKey = $record['primaryKey'];

            $primary = $dependentEntity->extract((array)$primaryKey);

            // Get display value
            $displayValue = $this->extractDisplayValue($dependentEntity, $displayField);

            // Get original values
            $original = $dependentEntity->toArray();

            // Filter out blacklisted fields
            foreach ($original as $key => $value) {
                if (in_array($key, $config['blacklist'], true)) {
                    unset($original[$key]);
                }
            }

            $this->redactArray($original);

            $auditEvent = new AuditDeleteEvent(
                $transaction,
                $primary,
                $source,
                $parentSource,
                $original,
                $displayValue,
            );

            $options['_auditQueue']->offsetSet($dependentEntity, $auditEvent);
        }

        // Clear processed records
        $options['_cascadeDeleteRecords'] = [];
    }

    /**
     * Sets the persister object to use for logging all audit events.
     * If called with no arguments, it will return the currently configured persister.
     *
     * @param \AuditStash\PersisterInterface|null $persister The persister object to use
     *
     * @return \AuditStash\PersisterInterface The configured persister
     */
    public function persister(?PersisterInterface $persister = null): PersisterInterface
    {
        if (!($persister instanceof PersisterInterface) && !($this->persister instanceof PersisterInterface)) {
            $class = Configure::read('AuditStash.persister') ?: TablePersister::class;
            $index = $this->getConfig('index') ?: $this->_table->getTable();
            $type = $this->getConfig('type') ?: Inflector::singularize($index);

            /** @var \AuditStash\PersisterInterface $instance */
            $instance = new $class(compact('index', 'type'));

            $persisterConfig = Configure::read('AuditStash.persisterConfig');
            if (is_array($persisterConfig) && $persisterConfig && method_exists($instance, 'setConfig')) {
                $instance->setConfig($persisterConfig);
            }

            $persister = $instance;
        }

        if ($persister !== null) {
            $this->persister = $persister;

            return $persister;
        }

        /** @var \AuditStash\PersisterInterface $existingPersister */
        $existingPersister = $this->persister;

        return $existingPersister;
    }

    /**
     * Resolves a human-friendly display value for an entity, tolerating
     * non-stringable column types (most importantly backed/unit enums and
     * value objects implementing `Stringable`). Composite display fields,
     * unsupported value types, and missing fields all yield `null` so the
     * audit log row is still written.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to read from
     * @param array<string>|string|null $displayField Display field name as
     *   returned by `Table::getDisplayField()`
     *
     * @return string|null
     */
    protected function extractDisplayValue(EntityInterface $entity, array|string|null $displayField): ?string
    {
        if (!is_string($displayField) || !$entity->has($displayField)) {
            return null;
        }

        $value = $entity->get($displayField);

        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string)$value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Helper method used to get the property names of associations for a table.
     *
     * @param array $associated Whitelist of associations to look for
     *
     * @return array List of property names
     */
    protected function getAssociationProperties(array $associated): array
    {
        $associations = $this->_table->associations();
        $result = [];

        foreach ($associated as $name) {
            $association = $associations->get($name);
            if ($association !== null) {
                $result[] = $association->getProperty();
            }
        }

        return $result;
    }
}
