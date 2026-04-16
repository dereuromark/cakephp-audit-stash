<?php

declare(strict_types=1);

namespace AuditStash\Service;

use DateTimeInterface;

/**
 * Tamper-evident hash chain over audit log rows.
 *
 * Each row's hash is SHA-256 over a canonical JSON encoding of the row's
 * payload plus the previous row's hash. Any later edit to a historic row
 * invalidates that row's hash and, transitively, every hash that follows.
 *
 * The canonical form sorts object keys recursively before encoding, so
 * two semantically equivalent payloads always produce the same hash —
 * regardless of PHP version, array insertion order, or locale.
 *
 * Pure functions only: no I/O, no state. Concurrency and transactional
 * locking belong in the caller (see `TablePersister` when `hashChain` is
 * enabled, or `ChainVerifier` for after-the-fact integrity checks).
 */
class HashChain
{
    /**
     * Fields that do not participate in the hash.
     *
     * `id` is excluded because the primary key is assigned by the database
     * *after* hashing. `hash` itself is obviously excluded. `prev_hash` is
     * included in the hash input separately as the chain link.
     *
     * @var array<string>
     */
    public const EXCLUDED_FIELDS = ['id', 'hash'];

    /**
     * Compute the hash for a new audit row.
     *
     * @param string|null $prevHash Hash of the previous row, or null for the first row.
     * @param array<string, mixed> $payload Row fields. Must NOT include `id` or `hash`.
     *
     * @return string Hex-encoded SHA-256 (64 chars).
     */
    public static function hash(?string $prevHash, array $payload): string
    {
        $canonical = self::canonicalize($payload);
        $input = ($prevHash ?? '') . '|' . self::encode($canonical);

        return hash('sha256', $input);
    }

    /**
     * Verify a sequence of rows.
     *
     * Rows must be passed in chain order (oldest first). Each row is a map
     * with keys `payload`, `prev_hash`, and `hash`. Returns the index of the
     * first row whose stored hash does not match the recomputed one, or
     * `null` if the whole chain is intact.
     *
     * @param array<int, array{payload: array<string, mixed>, prev_hash: string|null, hash: string}> $rows
     *
     * @return int|null Index of the first broken row, or null if intact.
     */
    public static function findFirstBreak(array $rows): ?int
    {
        $expectedPrev = null;
        foreach ($rows as $index => $row) {
            if ($row['prev_hash'] !== $expectedPrev) {
                return $index;
            }

            $recomputed = self::hash($row['prev_hash'], $row['payload']);
            if (!hash_equals($row['hash'], $recomputed)) {
                return $index;
            }

            $expectedPrev = $row['hash'];
        }

        return null;
    }

    /**
     * Verify a sequence of rows in a single boolean.
     *
     * @param array<int, array{payload: array<string, mixed>, prev_hash: string|null, hash: string}> $rows
     *
     * @return bool True if the chain is intact end-to-end.
     */
    public static function verify(array $rows): bool
    {
        return self::findFirstBreak($rows) === null;
    }

    /**
     * Recursively sort array keys so equivalent payloads hash identically
     * regardless of construction order.
     *
     * Numeric-keyed (list) arrays are *not* reordered — list order is
     * semantically meaningful. Associative arrays are sorted by key.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function canonicalize(mixed $value): mixed
    {
        // Normalize DateTime-like values to a second-precision ISO string.
        // This matters because the hash is computed *before* save, but the
        // verifier reads values back *after* save — where many databases
        // have already truncated microseconds. Use a stable representation
        // that survives the round-trip for both plain DATETIME and
        // string-backed SQLite columns.
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }

    /**
     * Deterministic JSON encoding of an already-canonicalized structure.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
