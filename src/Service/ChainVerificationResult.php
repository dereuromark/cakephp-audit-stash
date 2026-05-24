<?php

declare(strict_types=1);

namespace AuditStash\Service;

/**
 * Immutable result of {@see ChainVerifier::verify()}.
 */
final readonly class ChainVerificationResult
{
    /**
     * @param bool $intact True if every row's stored hash matches the recomputed one.
     * @param int $rowsChecked Number of rows walked (including the broken one if any).
     * @param int|null $brokenRowId Primary key of the first broken row, or null if intact.
     * @param string|null $reason Human-readable description of the break, or null if intact.
     */
    public function __construct(
        public bool $intact,
        public int $rowsChecked,
        public ?int $brokenRowId,
        public ?string $reason,
    ) {
    }

    public static function intact(int $rowsChecked): self
    {
        return new self(true, $rowsChecked, null, null);
    }

    public static function broken(int $rowId, int $rowsChecked, string $reason): self
    {
        return new self(false, $rowsChecked, $rowId, $reason);
    }
}
