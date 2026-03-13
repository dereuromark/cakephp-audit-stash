<?php

declare(strict_types=1);

namespace AuditStash\Model\Table;

use AuditStash\AuditLogType;
use AuditStash\Database\JsonQueryHelper;
use Cake\Database\Type\EnumType;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * AuditLogs Model
 *
 * @method \AuditStash\Model\Entity\AuditLog newEmptyEntity()
 * @method \AuditStash\Model\Entity\AuditLog newEntity(array $data, array $options = [])
 * @method array<\AuditStash\Model\Entity\AuditLog> newEntities(array $data, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \AuditStash\Model\Entity\AuditLog findOrCreate(\Cake\ORM\Query\SelectQuery|callable|array $search, ?callable $callback = null, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\AuditStash\Model\Entity\AuditLog> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \AuditStash\Model\Entity\AuditLog saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\AuditStash\Model\Entity\AuditLog>|\Cake\Datasource\ResultSetInterface<\AuditStash\Model\Entity\AuditLog> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}>
 */
class AuditLogsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     *
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('audit_logs');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->getSchema()->setColumnType('type', EnumType::from(AuditLogType::class));

        // Set JSON column types for proper array handling
        $this->getSchema()->setColumnType('original', 'json');
        $this->getSchema()->setColumnType('changed', 'json');
        $this->getSchema()->setColumnType('meta', 'json');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     *
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->nonNegativeInteger('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->scalar('transaction')
            ->maxLength('transaction', 36)
            ->requirePresence('transaction', 'create')
            ->notEmptyString('transaction');

        $validator
            ->requirePresence('type', 'create')
            ->notEmptyString('type');

        $validator
            ->allowEmptyString('primary_key');

        $validator
            ->scalar('display_value')
            ->maxLength('display_value', 255)
            ->allowEmptyString('display_value');

        $validator
            ->scalar('source')
            ->maxLength('source', 255)
            ->requirePresence('source', 'create')
            ->notEmptyString('source');

        $validator
            ->scalar('parent_source')
            ->maxLength('parent_source', 255)
            ->allowEmptyString('parent_source');

        $validator
            ->scalar('user_id')
            ->maxLength('user_id', 36)
            ->allowEmptyString('user_id');

        $validator
            ->scalar('user_display')
            ->maxLength('user_display', 255)
            ->allowEmptyString('user_display');

        $validator
            ->allowEmptyString('original');

        $validator
            ->allowEmptyString('changed');

        $validator
            ->allowEmptyString('meta');

        return $validator;
    }

    /**
     * Find logs where a specific field was changed
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param string $field The field name to look for in changed data
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findByChangedField(SelectQuery $query, string $field): SelectQuery
    {
        return $query->where(
            JsonQueryHelper::jsonKeyExists($query, 'AuditLogs.changed', $field),
        );
    }

    /**
     * Find logs where a specific field changed to a specific value
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param string $field The field name to check
     * @param mixed $value The value to match
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findByChangedFieldValue(SelectQuery $query, string $field, mixed $value): SelectQuery
    {
        return $query->where(
            JsonQueryHelper::jsonEquals($query, 'AuditLogs.changed', $field, $value),
        );
    }

    /**
     * Find logs for a record and its related records
     *
     * Searches for logs where:
     * - The source and primary_key match directly
     * - The parent_source matches and the source contains a foreign key to the record
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param string $source The main table/source name
     * @param string|int $primaryKey The primary key value
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findRelatedChanges(SelectQuery $query, string $source, string|int $primaryKey): SelectQuery
    {
        // Build foreign key name from source (e.g., "Articles" -> "article_id")
        $foreignKey = $this->buildForeignKeyName($source);

        return $query->where(function ($exp, $q) use ($source, $primaryKey, $foreignKey) {
            /** @var \Cake\Database\Expression\QueryExpression $exp */
            return $exp->or([
                // Direct match
                $exp->and([
                    'AuditLogs.source' => $source,
                    'AuditLogs.primary_key' => $primaryKey,
                ]),
                // Related via parent_source
                $exp->and([
                    'AuditLogs.parent_source' => $source,
                    JsonQueryHelper::jsonEquals($q, 'AuditLogs.changed', $foreignKey, $primaryKey),
                ]),
                // Related via original (for deletes)
                $exp->and([
                    'AuditLogs.parent_source' => $source,
                    JsonQueryHelper::jsonEquals($q, 'AuditLogs.original', $foreignKey, $primaryKey),
                ]),
            ]);
        });
    }

    /**
     * Find logs from transactions with many records (bulk changes)
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param int $minRecords Minimum number of records in a transaction to be considered bulk
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findBulkChanges(SelectQuery $query, int $minRecords = 5): SelectQuery
    {
        // Subquery to find transactions with many records
        $subquery = $this->find()
            ->select(['transaction'])
            ->groupBy(['transaction'])
            ->having(function ($exp, $q) use ($minRecords) {
                return $exp->gte($q->func()->count('*'), $minRecords, 'integer');
            });

        return $query->where([
            'AuditLogs.transaction IN' => $subquery,
        ]);
    }

    /**
     * Find logs from transactions that are NOT bulk (fewer than minRecords)
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param int $minRecords Transactions with this many or more records are considered bulk
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findNonBulkChanges(SelectQuery $query, int $minRecords = 5): SelectQuery
    {
        // Subquery to find transactions with many records (bulk)
        $bulkSubquery = $this->find()
            ->select(['transaction'])
            ->groupBy(['transaction'])
            ->having(function ($exp, $q) use ($minRecords) {
                return $exp->gte($q->func()->count('*'), $minRecords, 'integer');
            });

        return $query->where([
            'AuditLogs.transaction NOT IN' => $bulkSubquery,
        ]);
    }

    /**
     * Get aggregated statistics for bulk change transactions
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param int $minRecords Minimum number of records in a transaction to be considered bulk
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findBulkChangeStats(SelectQuery $query, int $minRecords = 5): SelectQuery
    {
        return $query
            ->select([
                'transaction' => 'AuditLogs.transaction',
                'record_count' => $query->func()->count('*'),
                'sources' => $query->func()->count(
                    $query->expr()->add('DISTINCT AuditLogs.source'),
                ),
                'user_id' => $query->func()->max('AuditLogs.user_id'),
                'user_display' => $query->func()->max('AuditLogs.user_display'),
                'created' => $query->func()->min('AuditLogs.created'),
            ])
            ->groupBy(['AuditLogs.transaction'])
            ->having(function ($exp, $q) use ($minRecords) {
                return $exp->gte($q->func()->count('*'), $minRecords, 'integer');
            })
            ->orderBy(['created' => 'DESC']);
    }

    /**
     * Get distinct changed fields from audit logs
     *
     * @return array<string>
     */
    public function getDistinctChangedFields(): array
    {
        $logs = $this->find()
            ->select(['changed'])
            ->where(['changed IS NOT' => null])
            ->limit(1000)
            ->toArray();

        $fields = [];
        foreach ($logs as $log) {
            if ($log->changed) {
                $changedData = is_string($log->changed)
                    ? json_decode($log->changed, true)
                    : $log->changed;
                if (is_array($changedData)) {
                    $fields = array_merge($fields, array_keys($changedData));
                }
            }
        }

        return array_unique($fields);
    }

    /**
     * Build a foreign key name from a table/source name
     *
     * @param string $source The table/source name (e.g., "Articles", "UserProfiles")
     *
     * @return string The foreign key name (e.g., "article_id", "user_profile_id")
     */
    protected function buildForeignKeyName(string $source): string
    {
        // Convert CamelCase to snake_case and singularize
        $underscored = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $source) ?? $source);
        // Simple singularize - remove trailing 's' if present
        $singular = rtrim($underscored, 's');

        return $singular . '_id';
    }
}
