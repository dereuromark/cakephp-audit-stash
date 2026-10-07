<?php

declare(strict_types=1);

namespace AuditStash\Filter;

/**
 * Filters out insignificant changes from audit logging.
 *
 * This class provides utilities to detect and filter changes that may not
 * be worth logging, such as whitespace-only changes, case changes, or
 * changes only to timestamp fields.
 *
 * Usage in AuditLogBehavior config:
 *
 * ```php
 * $this->addBehavior('AuditStash.AuditLog', [
 *     'ignoreEmpty' => true,
 *     'ignoreTimestampOnly' => true,
 *     'ignoreFields' => ['last_seen'],
 *     'ignoreWhitespace' => true,
 *     'ignoreCase' => false,
 * ]);
 * ```
 */
class ChangeFilter
{
    /**
     * Default timestamp field names.
     *
     * @var array<string>
     */
    public const TIMESTAMP_FIELDS = ['created', 'modified', 'updated_at', 'created_at'];

    /**
     * Filter changes based on the provided rules.
     *
     * Returns the filtered arrays, or null if the change should be ignored entirely.
     *
     * @param array<string, mixed> $changed The changed values
     * @param array<string, mixed> $original The original values
     * @param array<string, mixed> $config Filter configuration
     *
     * @return array{changed: array<string, mixed>, original: array<string, mixed>}|null
     */
    public static function filter(array $changed, array $original, array $config): ?array
    {
        // Compare normalized copies. The values that get logged stay as they are.
        $comparedChanged = $changed;
        $comparedOriginal = $original;
        if (!empty($config['ignoreWhitespace'])) {
            [$comparedChanged, $comparedOriginal] = self::normalizeWhitespace($comparedChanged, $comparedOriginal);
        }
        if (!empty($config['ignoreCase'])) {
            [$comparedChanged, $comparedOriginal] = self::normalizeCase($comparedChanged, $comparedOriginal);
        }

        foreach ($comparedChanged as $field => $value) {
            if (
                array_key_exists($field, $comparedOriginal)
                && self::isSameValue($comparedOriginal[$field], $value)
            ) {
                unset($changed[$field], $original[$field]);
            }
        }

        // Check if only ignored fields changed
        $ignoreFields = $config['ignoreFields'] ?? [];
        if ($ignoreFields) {
            $remainingFields = array_diff(array_keys($changed), $ignoreFields);
            if (!$remainingFields) {
                return null;
            }
        }

        // Check if only timestamp fields changed
        if ($config['ignoreTimestampOnly'] ?? false) {
            $timestampFields = $config['timestampFields'] ?? self::TIMESTAMP_FIELDS;
            $nonTimestampFields = array_diff(array_keys($changed), $timestampFields);
            if (!$nonTimestampFields) {
                return null;
            }
        }

        // Check if no changes remain
        if (($config['ignoreEmpty'] ?? true) && !$changed) {
            return null;
        }

        return ['changed' => $changed, 'original' => $original];
    }

    /**
     * Whether two values are the same for audit purposes. A number and its
     * string form are: an entity field assigned `'1'` while it held `1` did
     * not change. Booleans and null only equal themselves.
     *
     * @param mixed $original Stored value
     * @param mixed $changed Assigned value
     *
     * @return bool
     */
    protected static function isSameValue(mixed $original, mixed $changed): bool
    {
        if ($original === $changed) {
            return true;
        }

        // Only a difference in type is in question here. Two values of the
        // same type that are not identical did change.
        if (gettype($original) === gettype($changed)) {
            return false;
        }

        $isNumber = fn (mixed $value): bool => is_int($value) || is_float($value);
        if ($isNumber($original) && $isNumber($changed)) {
            return $original == $changed;
        }

        [$number, $string] = is_string($changed) ? [$original, $changed] : [$changed, $original];
        if (!$isNumber($number) || !is_string($string) || !is_numeric($string)) {
            return false;
        }

        // Compared as numbers: casting a float to a string would round it.
        return is_int($number) ? (string)$number === $string : (float)$string === $number;
    }

    /**
     * Check if changes should be ignored based on configuration.
     *
     * @param array<string, mixed> $changed The changed values
     * @param array<string, mixed> $original The original values
     * @param array<string, mixed> $config Filter configuration
     *
     * @return bool True if changes should be ignored
     */
    public static function shouldIgnore(array $changed, array $original, array $config): bool
    {
        return self::filter($changed, $original, $config) === null;
    }

    /**
     * Normalize whitespace in string values for comparison.
     *
     * Trims and collapses multiple spaces to single spaces.
     *
     * @param array<string, mixed> $changed Changed values
     * @param array<string, mixed> $original Original values
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected static function normalizeWhitespace(array $changed, array $original): array
    {
        foreach ($changed as $field => $value) {
            if (is_string($value)) {
                $changed[$field] = preg_replace('/\s+/', ' ', trim($value));
            }
        }

        foreach ($original as $field => $value) {
            if (is_string($value)) {
                $original[$field] = preg_replace('/\s+/', ' ', trim($value));
            }
        }

        return [$changed, $original];
    }

    /**
     * Normalize case in string values for comparison.
     *
     * @param array<string, mixed> $changed Changed values
     * @param array<string, mixed> $original Original values
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected static function normalizeCase(array $changed, array $original): array
    {
        foreach ($changed as $field => $value) {
            if (is_string($value)) {
                $changed[$field] = mb_strtolower($value);
            }
        }

        foreach ($original as $field => $value) {
            if (is_string($value)) {
                $original[$field] = mb_strtolower($value);
            }
        }

        return [$changed, $original];
    }

    /**
     * Check if only whitespace changed in a string value.
     *
     * @param string $old Original value
     * @param string $new New value
     *
     * @return bool
     */
    public static function isWhitespaceOnlyChange(string $old, string $new): bool
    {
        $normalizedOld = preg_replace('/\s+/', ' ', trim($old));
        $normalizedNew = preg_replace('/\s+/', ' ', trim($new));

        return $normalizedOld === $normalizedNew;
    }

    /**
     * Check if only case changed in a string value.
     *
     * @param string $old Original value
     * @param string $new New value
     *
     * @return bool
     */
    public static function isCaseOnlyChange(string $old, string $new): bool
    {
        return mb_strtolower($old) === mb_strtolower($new);
    }
}
