<?php

declare(strict_types=1);

namespace AuditStash\Persister;

use AuditStash\PersisterInterface;
use AuditStash\Service\HashChain;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\EventDispatcherTrait;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use InvalidArgumentException;
use RuntimeException;

/**
 * A persister that uses the ORM API to persist audit logs.
 */
class TablePersister implements PersisterInterface
{
    use EventDispatcherTrait;
    use ExtractionTrait;
    use InstanceConfigTrait;
    use LocatorAwareTrait;
    use LogTrait;

    /**
     * Strategy that will choose between raw and serialized.
     *
     * @var string
     */
    public const STRATEGY_AUTOMATIC = 'automatic';

    /**
     * Strategy that extracts data as separate fields/properties.
     *
     * @var string
     */
    public const STRATEGY_PROPERTIES = 'properties';

    /**
     * Strategy that extracts data as is.
     *
     * @var string
     */
    public const STRATEGY_RAW = 'raw';

    /**
     * Strategy that extracts data serialized in JSON format.
     *
     * @var string
     */
    public const STRATEGY_SERIALIZED = 'serialized';

    /**
     * The default configuration.
     *
     * ## Configuration options
     *
     * - `extractMetaFields` (`bool|array`, defaults to `false`)
     *
     *   Defines whether/which meta data fields to extract as entity properties, thus
     *   making them savable in the target table. When set to `true`, all meta data fields
     *   are being extracted, where the keys are being used as the property/column names.
     *
     *   When passing an array, a `key => value` map is expected, where the key should be
     *   a `Hash::get()` compatible path that is used to extract from the meta data, and
     *   the value will be used as the property/column named:
     *
     *   ```
     *   [
     *       'user.id' => 'user_id'
     *   ]
     *   ```
     *
     *   This would extract `['user']['id']` from the metadata array, and pass it as
     *   a field named `user_id` to the entity to persist.
     *
     *   Alternatively a flat array with single string values can be passed, where the value
     *   will be used as the property/name as well as the path for extracting.
     *
     * - `logErrors` (`bool`, defaults to `true`)
     *
     *   Defines whether to log errors. By default, errors are logged using the log level
     *   `LogLevel::ERROR`.
     *
     * - `primaryKeyExtractionStrategy` (`string`, defaults to `TablePersister::STRATEGY_AUTOMATIC`)
     *
     *   Defines how the primary key values of the changed records should be stored. Valid
     *   values are the persister class' `STORAGE_STRATEGY_*` constants:
     *
     *     * `STRATEGY_AUTOMATIC`: Stores the primary key in a single column, either as is,
     *        or in case of a composite key, in JSON format.
     *
     *     * `STRATEGY_PROPERTIES`: Stores the keys in individual columns if required.
     *       Composite primary keys will be stored prefixed with `primary_key_` followed by the
     *       index of the value in the primary key array.
     *
     *     * `STRATEGY_RAW`: Stores the key as is in a single column.
     *
     *     * `STRATEGY_SERIALIZED`: Stores the primary key in JSON format.
     *
     * - `serializeFields` (`bool`, defaults to `true`)
     *
     *   Defines whether the (non-primary key) fields that expect array data are being
     *   serialized in JSON format.
     *
     * - `table` (`string|\Cake\ORM\Table`, defaults to `AuditLogs`)
     *
     *   Defines the table to use for persisting records. Either a string denoting a table
     *   alias that is going to be resolved using the persisters table locator, or a table
     *   object.
     *
     * - `unsetExtractedMetaFields` (`bool`, defaults to `true`)
     *
     *   Defines whether the fields extracted from the meta data should be unset, ie removed.
     *
     * @var array
     */
    protected array $_defaultConfig = [
        'extractMetaFields' => false,
        'logErrors' => true,
        'primaryKeyExtractionStrategy' => self::STRATEGY_AUTOMATIC,
        'serializeFields' => true,
        'table' => 'AuditStash.AuditLogs',
        'unsetExtractedMetaFields' => true,
        'hashChain' => false,
    ];

    /**
     * The table to use for persisting logs.
     *
     * @var \Cake\ORM\Table|null
     */
    protected ?Table $_table = null;

    /**
     * Returns the table to use for persisting logs.
     *
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        if ($this->_table === null) {
            $this->setTable($this->getConfig('table'));
        }

        assert($this->_table !== null);

        return $this->_table;
    }

    /**
     * Sets the table to use for persisting logs.
     *
     * @param \Cake\ORM\Table|string|null $table Either a string denoting a table alias, or a table object.
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setTable(string|Table|null $table)
    {
        if (is_string($table)) {
            $table = $this->getTableLocator()->get($table);
        }

        if (!($table instanceof Table)) {
            throw new InvalidArgumentException(
                'The `$table` argument must be either a table alias, or an instance of `\Cake\ORM\Table`.',
            );
        }

        $this->_table = $table;

        return $this;
    }

    /**
     * Persists each of the passed EventInterface objects.
     *
     * @param array<\AuditStash\EventInterface> $auditLogs List of EventInterface objects to persist
     *
     * @return void
     */
    public function logEvents(array $auditLogs): void
    {
        $persisterTable = $this->getTable();

        $serializeFields = $this->getConfig('serializeFields');
        $primaryKeyExtractionStrategy = $this->getConfig('primaryKeyExtractionStrategy');
        $extractMetaFields = $this->getConfig('extractMetaFields');
        $unsetExtractedMetaFields = $this->getConfig('unsetExtractedMetaFields');
        $logErrors = $this->getConfig('logErrors');
        $hashChain = (bool)$this->getConfig('hashChain');

        // Auto-detect JSON columns to avoid double-encoding
        // When columns are native JSON type, CakePHP handles encoding automatically
        if ($serializeFields && $this->hasJsonColumns($persisterTable)) {
            $serializeFields = false;
        }

        $hashPayloadColumns = [];
        if ($hashChain) {
            $hashPayloadColumns = $this->assertHashChainReady($persisterTable);
        }

        $persist = function () use (
            $auditLogs,
            $persisterTable,
            $serializeFields,
            $primaryKeyExtractionStrategy,
            $extractMetaFields,
            $unsetExtractedMetaFields,
            $logErrors,
            $hashChain,
            $hashPayloadColumns,
        ): void {
            $prevHash = $hashChain ? $this->loadLastHashForUpdate($persisterTable) : null;

            foreach ($auditLogs as $log) {
                $fields = $this->extractBasicFields($log, $serializeFields);
                $fields += $this->extractPrimaryKeyFields($log, $primaryKeyExtractionStrategy);
                $fields += $this->extractMetaFields(
                    $log,
                    $extractMetaFields,
                    $unsetExtractedMetaFields,
                    $serializeFields,
                );

                if ($hashChain) {
                    // Only hash fields the target schema actually owns, and
                    // stay consistent with ChainVerifier which excludes
                    // prev_hash from the hash input (it contributes via the
                    // chain-link argument instead).
                    $hash = HashChain::hash($prevHash, $this->buildHashPayload($fields, $hashPayloadColumns));
                    $fields['prev_hash'] = $prevHash;
                    $fields['hash'] = $hash;
                }

                $persisterEntity = $persisterTable->newEntity($fields);

                if ($persisterTable->save($persisterEntity)) {
                    if ($hashChain) {
                        $prevHash = $persisterEntity->get('hash');
                    }
                    $this->dispatchEvent('AuditStash.afterLog', ['auditLog' => $persisterEntity]);

                    continue;
                }

                if ($hashChain) {
                    // Break the chain immediately rather than silently drop rows.
                    throw new RuntimeException(
                        'Failed to persist an audit log row while the hash chain was enabled. '
                        . 'Aborting the batch to preserve chain integrity.',
                    );
                }

                if ($logErrors) {
                    $this->log($this->toErrorLog($persisterEntity));
                }
            }
        };

        if ($hashChain) {
            $lockHandle = $this->acquireChainWriteLock($persisterTable);
            try {
                $persisterTable->getConnection()->transactional($persist);
            } finally {
                $this->releaseChainWriteLock($persisterTable, $lockHandle);
            }

            return;
        }

        $persist();
    }

    /**
     * Load the hash of the last (highest-id) row, locking the table against
     * concurrent chain writers for the remainder of the transaction.
     *
     * Only called when `hashChain` is enabled and therefore guaranteed to
     * run inside a transaction. Returns `null` for an empty table.
     *
     * @param \Cake\ORM\Table $table
     *
     * @return string|null
     */
    protected function loadLastHashForUpdate(Table $table): ?string
    {
        $primaryKey = (array)$table->getPrimaryKey();
        $pkField = $primaryKey[0] ?? 'id';

        $query = $table->find()
            ->select(['hash'])
            ->orderByDesc($table->aliasField($pkField))
            ->limit(1);

        // Row-level locking syntax is driver-specific. MySQL and Postgres both
        // honour `SELECT ... FOR UPDATE`. SQLite serializes transactions at
        // the database level so no additional hint is needed, and SQL Server
        // uses a different hint syntax (WITH (UPDLOCK)) that we don't emit
        // here — callers on SQL Server should wrap logEvents() in a
        // SERIALIZABLE transaction if they need the same guarantee.
        $driver = $table->getConnection()->getDriver();
        $driverClass = $driver::class;
        if (str_contains($driverClass, 'Mysql') || str_contains($driverClass, 'Postgres')) {
            $query->epilog('FOR UPDATE');
        }

        /** @var \Cake\Datasource\EntityInterface|null $row */
        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $hash = $row->get('hash');

        return is_string($hash) ? $hash : null;
    }

    /**
     * @param \Cake\ORM\Table $table
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string>
     */
    protected function assertHashChainReady(Table $table): array
    {
        $schema = $table->getSchema();
        $schemaColumns = $schema->columns();
        if (!in_array('prev_hash', $schemaColumns, true) || !in_array('hash', $schemaColumns, true)) {
            throw new InvalidArgumentException(
                'Hash chaining requires `prev_hash` and `hash` columns on the target table. '
                . 'Run the AuditStash migration before enabling `persisterConfig.hashChain`.',
            );
        }

        $primaryKey = (array)$table->getPrimaryKey();
        if (count($primaryKey) !== 1) {
            throw new InvalidArgumentException(
                'Hash chaining requires a single-column numeric primary key on the target table.',
            );
        }

        $primaryKeyType = $schema->getColumnType($primaryKey[0]);
        if (!in_array($primaryKeyType, ['integer', 'biginteger', 'smallinteger', 'tinyinteger'], true)) {
            throw new InvalidArgumentException(
                'Hash chaining requires a single-column numeric primary key on the target table.',
            );
        }

        return array_values(array_diff($schemaColumns, ['id', 'prev_hash', 'hash']));
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string> $hashPayloadColumns
     *
     * @return array<string, mixed>
     */
    protected function buildHashPayload(array $fields, array $hashPayloadColumns): array
    {
        $payload = [];
        foreach ($hashPayloadColumns as $column) {
            $payload[$column] = $fields[$column] ?? null;
        }

        return $payload;
    }

    /**
     * @param \Cake\ORM\Table $table
     *
     * @throws \RuntimeException
     *
     * @return string|null
     */
    protected function acquireChainWriteLock(Table $table): ?string
    {
        $connection = $table->getConnection();
        $driverClass = $connection->getDriver()::class;
        $lockName = 'audit_stash_hash_chain:' . $table->getTable();

        if (str_contains($driverClass, 'Mysql')) {
            $result = $connection
                ->execute('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])
                ->fetch('assoc');
            if ((int)($result['acquired'] ?? 0) !== 1) {
                throw new RuntimeException(sprintf('Failed to acquire hash-chain write lock for `%s`.', $table->getTable()));
            }

            return $lockName;
        }

        if (str_contains($driverClass, 'Postgres')) {
            [$key1, $key2] = $this->advisoryLockKeys($lockName);
            $connection->execute('SELECT pg_advisory_lock(?, ?)', [$key1, $key2]);

            return $lockName;
        }

        return null;
    }

    /**
     * @param \Cake\ORM\Table $table
     * @param string|null $lockHandle
     *
     * @return void
     */
    protected function releaseChainWriteLock(Table $table, ?string $lockHandle): void
    {
        if ($lockHandle === null) {
            return;
        }

        $connection = $table->getConnection();
        $driverClass = $connection->getDriver()::class;

        if (str_contains($driverClass, 'Mysql')) {
            $connection->execute('SELECT RELEASE_LOCK(?)', [$lockHandle]);

            return;
        }

        if (str_contains($driverClass, 'Postgres')) {
            [$key1, $key2] = $this->advisoryLockKeys($lockHandle);
            $connection->execute('SELECT pg_advisory_unlock(?, ?)', [$key1, $key2]);
        }
    }

    /**
     * @param string $lockName
     *
     * @return array{int, int}
     */
    protected function advisoryLockKeys(string $lockName): array
    {
        return [
            $this->toSignedInt32(crc32('audit_stash')),
            $this->toSignedInt32(crc32($lockName)),
        ];
    }

    /**
     * @param int $value
     *
     * @return int
     */
    protected function toSignedInt32(int $value): int
    {
        if ($value <= 0x7fffffff) {
            return $value;
        }

        return $value - 0x100000000;
    }

    /**
     * Checks if the table has JSON column types for the serializable fields.
     *
     * When native JSON columns are used, CakePHP's type system handles
     * encoding/decoding automatically, so we should not serialize manually.
     *
     * @param \Cake\ORM\Table $table The table to check
     *
     * @return bool True if any of the serializable columns are JSON type
     */
    protected function hasJsonColumns(Table $table): bool
    {
        $schema = $table->getSchema();
        $jsonColumns = ['original', 'changed', 'meta'];

        foreach ($jsonColumns as $column) {
            if ($schema->getColumnType($column) === 'json') {
                return true;
            }
        }

        return false;
    }
}
