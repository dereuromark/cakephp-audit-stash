<?php

declare(strict_types=1);

namespace AuditStash\Service;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use InvalidArgumentException;

/**
 * Walks an audit-log table row by row and reports the first chain break.
 *
 * Rows are streamed in primary-key order with a small buffer so very long
 * chains verify in bounded memory. Stored hashes are compared with the
 * timing-safe `hash_equals`.
 *
 * This service is read-only: it never writes to the table it verifies.
 */
class ChainVerifier
{
    /**
     * Fields that do not participate in the hash (see HashChain::EXCLUDED_FIELDS
     * plus `prev_hash`, which contributes to the hash input separately).
     *
     * @var array<string>
     */
    protected const IGNORED_FIELDS = ['id', 'hash', 'prev_hash'];

    /**
     * Verify the whole chain in a table.
     *
     * @param \Cake\ORM\Table $table Audit log table to verify.
     * @param int $chunkSize How many rows to stream per query.
     *
     * @return \AuditStash\Service\ChainVerificationResult
     */
    public function verify(Table $table, int $chunkSize = 500): ChainVerificationResult
    {
        $primaryKey = $this->validatePrimaryKey($table);
        $orderField = $table->aliasField($primaryKey);
        $payloadColumns = array_values(array_diff($table->getSchema()->columns(), self::IGNORED_FIELDS));

        $total = 0;
        $expectedPrev = null;
        $started = false;
        $lastId = 0;

        while (true) {
            $rows = $table->find()
                ->where([$orderField . ' >' => $lastId])
                ->orderByAsc($orderField)
                ->limit($chunkSize)
                ->all();

            $count = 0;
            foreach ($rows as $row) {
                if (!$row instanceof EntityInterface) {
                    continue;
                }
                $count++;
                $fields = $row->toArray();
                $id = (int)($fields[$primaryKey] ?? 0);
                $storedHash = $fields['hash'] ?? null;
                $storedPrev = $fields['prev_hash'] ?? null;

                if (!$started && $storedHash === null) {
                    if ($storedPrev !== null) {
                        return ChainVerificationResult::broken(
                            $id,
                            $total + 1,
                            'Encountered prev_hash before the chain started.',
                        );
                    }

                    $lastId = $id;

                    continue;
                }

                if (!is_string($storedHash) || $storedHash === '') {
                    return ChainVerificationResult::broken(
                        $id,
                        $total + 1,
                        'Encountered a row with an empty hash after the chain started.',
                    );
                }

                $total++;
                $payload = $this->buildPayload($fields, $payloadColumns);

                if ($started && $storedPrev !== $expectedPrev) {
                    return ChainVerificationResult::broken(
                        $id,
                        $total,
                        sprintf(
                            'prev_hash mismatch: row stores %s, expected %s',
                            $storedPrev === null ? 'NULL' : substr($storedPrev, 0, 16) . '…',
                            $expectedPrev === null ? 'NULL' : substr($expectedPrev, 0, 16) . '…',
                        ),
                    );
                }

                $recomputed = HashChain::hash(
                    is_string($storedPrev) ? $storedPrev : null,
                    $payload,
                );

                if (!hash_equals($storedHash, $recomputed)) {
                    return ChainVerificationResult::broken(
                        $id,
                        $total,
                        sprintf(
                            'hash mismatch: stored %s, recomputed %s',
                            substr($storedHash, 0, 16) . '…',
                            substr($recomputed, 0, 16) . '…',
                        ),
                    );
                }

                $started = true;
                $expectedPrev = $storedHash;
                $lastId = $id;
            }

            if ($count === 0 || $count < $chunkSize) {
                break;
            }
        }

        return ChainVerificationResult::intact($total);
    }

    /**
     * @param \Cake\ORM\Table $table Audit log table to verify.
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    protected function validatePrimaryKey(Table $table): string
    {
        $primaryKey = (array)$table->getPrimaryKey();
        if (count($primaryKey) !== 1) {
            throw new InvalidArgumentException(
                'Chain verification requires a single-column numeric primary key.',
            );
        }

        $primaryKeyField = $primaryKey[0];
        $primaryKeyType = $table->getSchema()->getColumnType($primaryKeyField);
        if (!in_array($primaryKeyType, ['integer', 'biginteger', 'smallinteger', 'tinyinteger'], true)) {
            throw new InvalidArgumentException(
                'Chain verification requires a single-column numeric primary key.',
            );
        }

        return $primaryKeyField;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string> $payloadColumns
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(array $fields, array $payloadColumns): array
    {
        $payload = [];
        foreach ($payloadColumns as $column) {
            $payload[$column] = $fields[$column] ?? null;
        }

        return $payload;
    }
}
