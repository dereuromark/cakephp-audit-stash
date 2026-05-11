<?php

declare(strict_types=1);

namespace AuditStash\Model\Table;

use AuditStash\Database\JsonQueryHelper;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
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

        // type stays as plain string. Built-in events use AuditLogType enum
        // values (create/update/delete/revert), but custom action events may
        // use any string up to 64 chars.
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
            ->scalar('transaction_key')
            ->maxLength('transaction_key', 36)
            ->requirePresence('transaction_key', 'create')
            ->notEmptyString('transaction_key');

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
     * Apply the full UI filter set to a query in a single call.
     *
     * Single source of truth for the index / export filter logic. The
     * controller previously applied base filters via a private helper
     * and re-applied them after each advanced finder; the export action
     * only ran the base filters, which silently dropped `changed_field`,
     * `field_name+value`, and `bulk_filter` from any export. Routing
     * everything through this finder makes the two actions consistent
     * and the filter rules unit-testable without HTTP setup.
     *
     * Filters compose — supplying multiple advanced filters narrows the
     * result further (e.g. `changed_field=title` + `bulk_filter=yes` →
     * bulk transactions that changed `title`).
     *
     * Recognized keys (all optional):
     *
     * - `source`, `user_id`, `type`, `transaction_key`, `primary_key` (string)
     * - `date_from`, `date_to` (`Y-m-d`)
     * - `changed_field` (identifier-shaped token)
     * - `field_name` + `field_value` (identifier-shaped token + arbitrary scalar)
     * - `bulk_filter` (`yes`|`no`), `min_records` (int, default 5)
     *
     * `user_id` is matched with a wildcard LIKE so callers can pass a
     * substring; the value is escaped for `%` / `_` / `\` so a literal
     * wildcard from user input cannot turn the query into a match-all.
     *
     * The caller is responsible for validating `changed_field` and
     * `field_name` as identifier-shaped tokens before passing them in
     * (see {@see \AuditStash\Database\JsonQueryHelper}, which also
     * rejects path meta characters as defense in depth).
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     * @param array<string, mixed> $params Filter values keyed by request name
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findForFilters(SelectQuery $query, array $params): SelectQuery
    {
        $source = $this->stringFilter($params, 'source');
        if ($source !== null) {
            $query->where(['AuditLogs.source' => $source]);
        }

        $userId = $this->stringFilter($params, 'user_id');
        if ($userId !== null) {
            $needle = addcslashes($userId, '%_\\');
            $query->where(['AuditLogs.user_id LIKE' => '%' . $needle . '%']);
        }

        $type = $this->stringFilter($params, 'type');
        if ($type !== null) {
            $query->where(['AuditLogs.type' => $type]);
        }

        $transactionKey = $this->stringFilter($params, 'transaction_key');
        if ($transactionKey !== null) {
            $query->where(['AuditLogs.transaction_key' => $transactionKey]);
        }

        $primaryKey = $this->stringFilter($params, 'primary_key');
        if ($primaryKey !== null) {
            $query->where(['AuditLogs.primary_key' => $primaryKey]);
        }

        $dateFrom = $this->stringFilter($params, 'date_from');
        if ($dateFrom !== null) {
            $query->where(['AuditLogs.created >=' => $dateFrom . ' 00:00:00']);
        }

        $dateTo = $this->stringFilter($params, 'date_to');
        if ($dateTo !== null) {
            $query->where(['AuditLogs.created <=' => $dateTo . ' 23:59:59']);
        }

        $changedField = $this->stringFilter($params, 'changed_field');
        if ($changedField !== null) {
            $query = $this->findByChangedField($query, $changedField);
        }

        $fieldName = $this->stringFilter($params, 'field_name');
        $fieldValue = $params['field_value'] ?? null;
        if ($fieldName !== null && $fieldValue !== null && $fieldValue !== '') {
            $query = $this->findByChangedFieldValue($query, $fieldName, $fieldValue);
        }

        $bulk = $params['bulk_filter'] ?? null;
        if ($bulk === 'yes' || $bulk === 'no') {
            $rawMin = $params['min_records'] ?? null;
            $minRecords = is_numeric($rawMin) ? max(1, (int)$rawMin) : 5;
            $query = $bulk === 'yes'
                ? $this->findBulkChanges($query, $minRecords)
                : $this->findNonBulkChanges($query, $minRecords);
        }

        return $query;
    }

    /**
     * Pull a non-empty string from the filter params, or return null.
     *
     * @param array<string, mixed> $params
     * @param string $key
     *
     * @return string|null
     */
    protected function stringFilter(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
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
            ->select(['transaction_key'])
            ->groupBy(['transaction_key'])
            ->having(function ($exp, $q) use ($minRecords) {
                return $exp->gte($q->func()->count('*'), $minRecords, 'integer');
            });

        return $query->where([
            'AuditLogs.transaction_key IN' => $subquery,
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
            ->select(['transaction_key'])
            ->groupBy(['transaction_key'])
            ->having(function ($exp, $q) use ($minRecords) {
                return $exp->gte($q->func()->count('*'), $minRecords, 'integer');
            });

        return $query->where([
            'AuditLogs.transaction_key NOT IN' => $bulkSubquery,
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
                'transaction_key' => 'AuditLogs.transaction_key',
                'record_count' => $query->func()->count('*'),
                'sources' => $query->func()->count(
                    $query->expr()->add('DISTINCT AuditLogs.source'),
                ),
                'user_id' => $query->func()->max('AuditLogs.user_id'),
                'user_display' => $query->func()->max('AuditLogs.user_display'),
                'created' => $query->func()->min('AuditLogs.created'),
            ])
            ->groupBy(['AuditLogs.transaction_key'])
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
        /** @var array<\AuditStash\Model\Entity\AuditLog> $logs */
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
     * Build a foreign key name from a table/source name.
     *
     * Uses Cake's Inflector for both snake_case conversion and singularization
     * so irregular plurals (people → person_id), already-singular words
     * (news → news_id), and `-ies` plurals (categories → category_id) all
     * resolve correctly. The previous homegrown `rtrim($x, 's')` produced
     * `peopl_id`, `new_id`, and `categorie_id` respectively.
     *
     * @param string $source The table/source name (e.g., "Articles", "UserProfiles", "Categories", "People")
     *
     * @return string The foreign key name (e.g., "article_id", "user_profile_id", "category_id", "person_id")
     */
    protected function buildForeignKeyName(string $source): string
    {
        return Inflector::singularize(Inflector::underscore($source)) . '_id';
    }
}
