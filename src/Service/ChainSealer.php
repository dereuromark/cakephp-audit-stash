<?php

declare(strict_types=1);

namespace AuditStash\Service;

use AuditStash\Event\AuditCustomEvent;
use AuditStash\Persister\TablePersister;
use Cake\Core\Configure;
use Cake\Database\Expression\IdentifierExpression;
use Cake\ORM\Table;
use Cake\Utility\Text;
use Closure;

/**
 * Keeps the hash chain verifiable across authorized maintenance.
 *
 * Retention cleanup and GDPR erasure remove or rewrite rows that are already
 * part of the chain, which breaks its links for good. After such an operation
 * a `chain_seal` row is appended. It stores a digest over every row that
 * exists at that moment. The verifier checks rows up to the seal against that
 * digest and walks the chain links only for the rows after it.
 */
class ChainSealer
{
    /**
     * @var string
     */
    public const TYPE = 'chain_seal';

    /**
     * @var string
     */
    public const SOURCE = 'AuditStash';

    /**
     * @var int
     */
    protected const CHUNK_SIZE = 500;

    /**
     * Result of the verification the last maintain() call ran before its work.
     *
     * @var \AuditStash\Service\ChainVerificationResult|null
     */
    protected ?ChainVerificationResult $before = null;

    /**
     * Whether the last maintain() call wrote a seal.
     *
     * @var bool
     */
    protected bool $sealed = false;

    /**
     * Runs an operation that removes or rewrites chained rows and seals the
     * chain afterwards. With the hash chain off, it only runs the operation.
     *
     * @param string $operation Name recorded in the seal, for example `cleanup`
     * @param \Closure(): array{0: int, 1?: array<string, mixed>} $work Does the
     *   change and returns the number of affected rows plus facts for the seal
     *
     * @return int Number of affected rows
     */
    public function maintain(string $operation, Closure $work): int
    {
        $this->before = null;
        $this->sealed = false;
        $persister = $this->persister();
        if (!$persister->getConfig('hashChain')) {
            return $work()[0];
        }

        return $persister->chainMaintenance(function (?ChainVerificationResult $before) use ($operation, $work): int {
            $this->before = $before;
            $result = $work();
            if ($result[0] > 0) {
                $this->sealed = $this->seal($operation, $result[1] ?? [], $this->before);
            }

            return $result[0];
        }, $this->check(...));
    }

    /**
     * @return bool Whether the last maintain() call wrote a seal
     */
    public function hasSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * Whether the last maintain() call found the chain already broken.
     *
     * @return \AuditStash\Service\ChainVerificationResult|null The failed verification, or null
     */
    public function brokenBefore(): ?ChainVerificationResult
    {
        return $this->before !== null && !$this->before->intact ? $this->before : null;
    }

    /**
     * Verifies the chain before maintenance touches it. Pass the result to
     * seal(): a seal must not turn an already broken chain into a clean one.
     *
     * @return \AuditStash\Service\ChainVerificationResult|null Null while the hash chain is off
     */
    public function check(): ?ChainVerificationResult
    {
        $persister = $this->persister();
        if (!$persister->getConfig('hashChain')) {
            return null;
        }

        return (new ChainVerifier())->verify($persister->getTable());
    }

    /**
     * Appends a seal row if the hash chain is enabled.
     *
     * @param string $operation What changed the chain, for example `cleanup`
     * @param array<string, mixed> $details Extra facts to record with the seal
     * @param \AuditStash\Service\ChainVerificationResult|null $before Result of check() from before the operation
     *
     * @return bool Whether a seal was written
     */
    public function seal(string $operation, array $details = [], ?ChainVerificationResult $before = null): bool
    {
        $persister = $this->persister();
        if (!$persister->getConfig('hashChain')) {
            return false;
        }
        if ($before !== null && !$before->intact) {
            $details['broken_before'] = sprintf('row %d: %s', (int)$before->brokenRowId, (string)$before->reason);
        }

        // An emptied table is sealed too, so a recorded break survives it.
        $table = $persister->getTable();
        $throughId = $this->maxId($table);
        $segment = $this->digest($table, $throughId);
        $event = new AuditCustomEvent(static::TYPE, Text::uuid(), null, static::SOURCE, [
            'operation' => $operation,
            'sealed_through_id' => $throughId,
            'sealed_rows' => $segment['rows'],
            'sealed_digest' => $segment['digest'],
        ] + $details);
        $persister->logEvents([$event]);

        return true;
    }

    /**
     * Digest over the stored hash and the content of every row up to the
     * given id. Any later change to one of those rows, and any row added to
     * or removed from that range, gives a different digest.
     *
     * @param \Cake\ORM\Table $table Audit log table
     * @param int $throughId Highest id to include
     * @param int $chunkSize Rows to read per query
     *
     * @return array{digest: string, rows: int}
     */
    public function digest(Table $table, int $throughId, int $chunkSize = self::CHUNK_SIZE): array
    {
        /** @var string $primaryKey */
        $primaryKey = $table->getPrimaryKey();
        $orderField = $table->aliasField($primaryKey);
        $payloadColumns = array_diff($table->getSchema()->columns(), ChainVerifier::IGNORED_FIELDS);

        $context = hash_init('sha256');
        $rows = 0;
        $lastId = 0;
        do {
            $chunk = $table->find()
                ->where([$orderField . ' >' => $lastId, $orderField . ' <=' => $throughId])
                ->orderByAsc($orderField)
                ->limit($chunkSize)
                ->all();

            $count = 0;
            /** @var \Cake\Datasource\EntityInterface $row */
            foreach ($chunk as $row) {
                $count++;
                $fields = $row->toArray();
                $lastId = (int)$fields[$primaryKey];
                $prevHash = $fields['prev_hash'] ?? null;
                $payload = [];
                foreach ($payloadColumns as $column) {
                    $payload[$column] = $fields[$column] ?? null;
                }
                $contentHash = HashChain::hash(is_string($prevHash) ? $prevHash : null, $payload);
                hash_update($context, $lastId . ':' . ($fields['hash'] ?? '') . ':' . $contentHash . "\n");
            }
            $rows += $count;
        } while ($count === $chunkSize);

        return ['digest' => hash_final($context), 'rows' => $rows];
    }

    /**
     * Returns the newest seal at or below the given id, with its payload decoded.
     *
     * @param \Cake\ORM\Table $table Audit log table
     * @param int $maxId Highest id to consider
     *
     * @return array{id: int, through_id: int, digest: string, broken_before: string|null}|null
     */
    public function latestSeal(Table $table, int $maxId): ?array
    {
        /** @var string $primaryKey */
        $primaryKey = $table->getPrimaryKey();
        /** @var \Cake\Datasource\EntityInterface|null $row */
        $row = $table->find()
            ->where(['type' => static::TYPE, $table->aliasField($primaryKey) . ' <=' => $maxId])
            ->orderByDesc($table->aliasField($primaryKey))
            ->first();
        if ($row === null) {
            return null;
        }

        $data = $row->get('changed');
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data) || !isset($data['sealed_through_id'], $data['sealed_digest'])) {
            return null;
        }

        return [
            'id' => (int)$row->get($primaryKey),
            'through_id' => (int)$data['sealed_through_id'],
            'digest' => (string)$data['sealed_digest'],
            'broken_before' => isset($data['broken_before']) ? (string)$data['broken_before'] : null,
        ];
    }

    /**
     * @param \Cake\ORM\Table $table Audit log table
     *
     * @return int
     */
    protected function maxId(Table $table): int
    {
        /** @var string $primaryKey */
        $primaryKey = $table->getPrimaryKey();
        $row = $table->find()
            ->select(['max' => $table->find()->func()->max(new IdentifierExpression($table->aliasField($primaryKey)))])
            ->disableHydration()
            ->first();

        return (int)($row['max'] ?? 0);
    }

    /**
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
}
