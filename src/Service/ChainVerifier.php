<?php

declare(strict_types=1);

namespace AuditStash\Service;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

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
        $primaryKey = (array)$table->getPrimaryKey();
        $orderField = $table->aliasField($primaryKey[0]);

        $total = 0;
        $expectedPrev = null;
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
                $total++;
                $fields = $row->toArray();
                $id = (int)($fields[$primaryKey[0]] ?? 0);
                $storedHash = (string)($fields['hash'] ?? '');
                $storedPrev = $fields['prev_hash'] ?? null;
                $payload = $fields;
                foreach (self::IGNORED_FIELDS as $ignore) {
                    unset($payload[$ignore]);
                }

                if ($storedPrev !== $expectedPrev) {
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

                $expectedPrev = $storedHash;
                $lastId = $id;
            }

            if ($count === 0 || $count < $chunkSize) {
                break;
            }
        }

        return ChainVerificationResult::intact($total);
    }
}
