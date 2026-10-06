<?php

declare(strict_types=1);

namespace AuditStash\Command;

use AuditStash\Event\AuditSnapshotEvent;
use AuditStash\Persister\ExtractionTrait;
use AuditStash\Persister\TablePersister;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\ORM\Table;

/**
 * Writes a `snapshot` audit row for every record that has no audit history,
 * so a table that got the AuditLog behavior after it already held data has a
 * recorded starting state.
 *
 * Usage:
 *   bin/cake audit_stash snapshot Articles
 *   bin/cake audit_stash snapshot Articles --dry-run
 *   bin/cake audit_stash snapshot Blog.Posts --batch-size 100
 */
class SnapshotCommand extends Command
{
    use ExtractionTrait;

    /**
     * @var int
     */
    public const DEFAULT_BATCH_SIZE = 200;

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'audit_stash snapshot';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Snapshot existing records without audit history';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription(static::getDescription())
            ->addArgument('table', ['required' => true, 'help' => 'Table alias (plugin syntax allowed)'])
            ->addOption('dry-run', ['short' => 'd', 'boolean' => true, 'help' => 'Report counts without writing'])
            ->addOption('batch-size', ['default' => (string)self::DEFAULT_BATCH_SIZE, 'help' => 'Records per batch']);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $batchSize = filter_var($args->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($batchSize === false) {
            $io->error('Batch size must be an integer greater than or equal to 1.');

            return self::CODE_ERROR;
        }
        $class = Configure::read('AuditStash.persister', TablePersister::class);
        if (!is_a($class, TablePersister::class, true)) {
            $io->error('This command only works with TablePersister.');

            return self::CODE_ERROR;
        }
        $table = $this->fetchTable((string)$args->getArgument('table'));
        if (!$table->hasBehavior('AuditLog')) {
            $io->error('The table must have the AuditLog behavior.');

            return self::CODE_ERROR;
        }
        /** @var \AuditStash\Model\Behavior\AuditLogBehavior $behavior */
        $behavior = $table->getBehavior('AuditLog');
        $persister = $behavior->persister();
        if (!$persister instanceof TablePersister) {
            $io->error('This command only works with TablePersister.');

            return self::CODE_ERROR;
        }
        $primaryKey = (array)$table->getPrimaryKey();
        $order = [];
        foreach ($primaryKey as $field) {
            $order[$table->aliasField($field)] = 'ASC';
        }
        $seen = $written = $skipped = $failed = 0;
        $dryRun = (bool)$args->getOption('dry-run');
        $last = null;
        do {
            $query = $table->find()->orderBy($order)->limit($batchSize);
            if ($last !== null) {
                $query->where($this->after($table, $last));
            }
            /** @var array<\Cake\Datasource\EntityInterface> $batch */
            $batch = $query->all()->toList();
            $batchCount = count($batch);
            if ($batchCount === 0) {
                break;
            }
            $last = $batch[$batchCount - 1]->extract($primaryKey);

            $pending = $this->withoutHistory($batch, $table, $persister);
            $seen += $batchCount;
            $skipped += $batchCount - count($pending);
            if ($dryRun) {
                $written += count($pending);

                continue;
            }

            $behavior->snapshot($pending);
            // The persister logs a failed save and carries on, so count what is still missing.
            $missing = count($this->withoutHistory($pending, $table, $persister));
            $failed += $missing;
            $written += count($pending) - $missing;
        } while ($batchCount === $batchSize);

        $io->out(sprintf('Records seen: %d', $seen));
        $io->out(sprintf('Snapshots %s: %d', $dryRun ? 'would be written' : 'written', $written));
        $io->out(sprintf('Skipped (already have history): %d', $skipped));
        if ($failed > 0) {
            $io->error(sprintf('Snapshots that could not be written: %d. See the error log.', $failed));

            return self::CODE_ERROR;
        }

        return self::CODE_SUCCESS;
    }

    /**
     * Condition for the rows that sort after the given primary key. Paging by
     * key instead of offset keeps rows from being skipped when earlier ones
     * are deleted while the command runs.
     *
     * @param \Cake\ORM\Table $table Audited table
     * @param array<string, mixed> $last Primary key of the last processed row
     *
     * @return array<string, mixed>
     */
    protected function after(Table $table, array $last): array
    {
        $alternatives = [];
        $equal = [];
        foreach ($last as $field => $value) {
            $alternatives[] = $equal + [$table->aliasField($field) . ' >' => $value];
            $equal[$table->aliasField($field)] = $value;
        }

        return ['OR' => $alternatives];
    }

    /**
     * Returns the entities that have no audit row of any type yet.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities Entities of one batch
     * @param \Cake\ORM\Table $table Audited table
     * @param \AuditStash\Persister\TablePersister $persister Persister holding the audit rows
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function withoutHistory(array $entities, Table $table, TablePersister $persister): array
    {
        if (!$entities) {
            return [];
        }

        $source = $table->getRegistryAlias();
        $strategy = $persister->getConfig('primaryKeyExtractionStrategy');
        $keys = [];
        foreach ($entities as $index => $entity) {
            // Same serialization the persister uses, so composite keys compare equal.
            $event = new AuditSnapshotEvent('', $entity->extract((array)$table->getPrimaryKey()), $source);
            $keys[$index] = $this->extractPrimaryKeyFields($event, $strategy);
        }

        $rows = $persister->getTable()->find()
            ->select(array_keys(reset($keys)))
            ->distinct()
            ->where(['source' => $source, 'OR' => array_values($keys)])
            ->disableHydration()
            ->all();

        $existing = [];
        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            $existing[$this->keyHash($row)] = true;
        }

        return array_values(array_filter(
            $entities,
            fn (int $index): bool => !isset($existing[$this->keyHash($keys[$index])]),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @param array<string, mixed> $key Primary key column values
     *
     * @return string
     */
    protected function keyHash(array $key): string
    {
        ksort($key);

        return (string)json_encode(array_map(fn (mixed $value): string => (string)$value, $key));
    }
}
