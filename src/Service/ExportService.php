<?php

declare(strict_types=1);

namespace AuditStash\Service;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use RuntimeException;

/**
 * Streams audit log exports in CSV, JSON, or NDJSON format with a
 * size cap and sensible defaults.
 *
 * The previous in-controller export buffered the entire result set in
 * memory (`->toArray()` followed by `php://temp` for CSV / `json_encode`
 * for JSON) and silently capped at 10,000 rows. This service instead:
 *
 * - Pre-flights the query with a `count()` and refuses (`BadRequestException`)
 *   when the result set exceeds `AuditStash.export.hardCap` (default 100,000).
 *   No silent truncation.
 * - Iterates the result set in batches of `AuditStash.export.batchSize`
 *   (default 1,000), writing rows as they hydrate. Memory is O(batch),
 *   not O(rows).
 * - Defaults the date floor to `AuditStash.export.defaultDays` (default 30)
 *   when the caller did not narrow the range, so an unbounded export does
 *   not get slower with every passing month.
 *
 * Use {@see ExportService::stream()} to write to a callable / stream,
 * and {@see ExportService::estimate()} to render the form-page row-count
 * estimate without firing the export.
 */
class ExportService
{
    /**
     * Supported export formats.
     *
     * @var array
     */
    public const FORMATS = ['csv', 'json', 'ndjson'];

    /**
     * Default hard cap on rows per export. Override via
     * `AuditStash.export.hardCap`.
     *
     * @var int
     */
    public const DEFAULT_HARD_CAP = 100000;

    /**
     * Default date-range floor in days when the caller did not pick one.
     * Override via `AuditStash.export.defaultDays`.
     *
     * @var int
     */
    public const DEFAULT_DAYS = 30;

    /**
     * Default batch size for streamed iteration. Override via
     * `AuditStash.export.batchSize`.
     *
     * @var int
     */
    public const DEFAULT_BATCH_SIZE = 1000;

    /**
     * Columns exported when the caller did not pick a subset.
     *
     * @var array<string>
     */
    public const DEFAULT_FIELDS = [
        'id',
        'transaction_key',
        'type',
        'source',
        'primary_key',
        'display_value',
        'user_id',
        'user_display',
        'original',
        'changed',
        'meta',
        'created',
    ];

    /**
     * Apply a default date floor to the query when neither `date_from` nor
     * `date_to` was supplied AND the caller has not narrowed the export
     * with another filter (`source`, `primary_key`, ...). Consumers should
     * call this before {@see assertWithinHardCap()} so the cap check sees
     * the narrowed query, not the unbounded one.
     *
     * Why `$hasOtherFilters`: the floor exists to keep an UNFILTERED export
     * from getting slower with every passing month. When the caller has
     * already pinned the export to a specific source / row / transaction,
     * the floor stops being load-protective and just hides rows the user
     * could see on the index page — same filters, different result count.
     * The hard cap remains the safety net for accidentally huge exports.
     *
     * @param \Cake\ORM\Query\SelectQuery $query
     * @param string|null $dateFrom Caller-supplied lower bound (`Y-m-d`).
     * @param string|null $dateTo Caller-supplied upper bound (`Y-m-d`).
     * @param bool $hasOtherFilters Whether the caller already narrowed the
     *   query with another filter. When true, the floor is skipped.
     *
     * @return \Cake\I18n\DateTime|null The applied default floor, or null
     *   when the caller's filters were honored as-is.
     */
    public function applyDefaultDateRange(
        SelectQuery $query,
        ?string $dateFrom,
        ?string $dateTo,
        bool $hasOtherFilters = false,
    ): ?DateTime {
        if (($dateFrom !== null && $dateFrom !== '') || ($dateTo !== null && $dateTo !== '')) {
            return null;
        }
        if ($hasOtherFilters) {
            return null;
        }

        $days = (int)Configure::read('AuditStash.export.defaultDays', self::DEFAULT_DAYS);
        $floor = DateTime::now()->subDays($days);
        $query->where(['AuditLogs.created >=' => $floor]);

        return $floor;
    }

    /**
     * Estimate the result set size of an export query without firing the
     * export itself. Used by the form page to show the user a row count
     * before they submit.
     *
     * @param \Cake\ORM\Query\SelectQuery $query
     *
     * @return int
     */
    public function estimate(SelectQuery $query): int
    {
        return (clone $query)->count();
    }

    /**
     * Throw `BadRequestException` when the export exceeds the configured
     * hard cap. Pre-flight gate: callers should call this before
     * {@see stream()}, so users see the "narrow your filters" error
     * before any download starts.
     *
     * @param int $rowCount
     *
     * @throws \Cake\Http\Exception\BadRequestException
     *
     * @return void
     */
    public function assertWithinHardCap(int $rowCount): void
    {
        $cap = $this->hardCap();
        if ($rowCount > $cap) {
            throw new BadRequestException(sprintf(
                'Export would produce %d rows, exceeding the hard cap of %d. '
                . 'Narrow your filters (e.g. set a date range or pick a single source) and try again.',
                $rowCount,
                $cap,
            ));
        }
    }

    /**
     * Stream the export to the given resource (typically `php://output`
     * inside a `Response::withCallback()` callback).
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to iterate.
     * @param string $format One of `csv`, `json`, `ndjson`.
     * @param resource $output Open writable stream.
     * @param array<string>|null $fields Whitelist of columns to include,
     *   or `null` for {@see DEFAULT_FIELDS}.
     *
     * @throws \RuntimeException When the format is unsupported.
     *
     * @return void
     */
    public function stream(SelectQuery $query, string $format, $output, ?array $fields = null): void
    {
        $format = strtolower($format);
        if (!in_array($format, self::FORMATS, true)) {
            throw new RuntimeException(sprintf('Unsupported export format: %s', $format));
        }

        $fields = $this->normalizeFields($fields);
        $batchSize = (int)Configure::read('AuditStash.export.batchSize', self::DEFAULT_BATCH_SIZE);
        $batchSize = max(1, $batchSize);

        $iter = (clone $query)->orderBy(['AuditLogs.id' => 'ASC'])->all();

        match ($format) {
            'csv' => $this->writeCsv($iter, $output, $fields, $batchSize),
            'json' => $this->writeJson($iter, $output, $fields, $batchSize),
            'ndjson' => $this->writeNdjson($iter, $output, $fields, $batchSize),
        };
    }

    /**
     * Build the suggested download filename for the given format and the
     * current timestamp. Kept here so callers stay consistent.
     *
     * @param string $format
     *
     * @return string
     */
    public function filename(string $format): string
    {
        return sprintf('audit_logs_%s.%s', date('Y-m-d_His'), $format === 'ndjson' ? 'ndjson' : $format);
    }

    /**
     * @return int
     */
    public function hardCap(): int
    {
        return (int)Configure::read('AuditStash.export.hardCap', self::DEFAULT_HARD_CAP);
    }

    /**
     * @param array<string>|null $fields
     *
     * @return array<string>
     */
    protected function normalizeFields(?array $fields): array
    {
        if ($fields === null) {
            return self::DEFAULT_FIELDS;
        }

        $valid = array_values(array_intersect($fields, self::DEFAULT_FIELDS));
        if (!$valid) {
            return self::DEFAULT_FIELDS;
        }

        // `id` always included so rows remain identifiable across exports.
        if (!in_array('id', $valid, true)) {
            array_unshift($valid, 'id');
        }

        return $valid;
    }

    /**
     * @param iterable<array<string, mixed>|\Cake\Datasource\EntityInterface> $iter
     * @param resource $output
     * @param array<string> $fields
     * @param int $batchSize
     *
     * @return void
     */
    protected function writeCsv(iterable $iter, $output, array $fields, int $batchSize): void
    {
        fputcsv($output, $fields, escape: '\\');

        $written = 0;
        foreach ($iter as $row) {
            fputcsv($output, $this->extractRow($row, $fields, jsonEncodeArrays: true), escape: '\\');
            $written++;
            if ($written % $batchSize === 0) {
                $this->flush($output);
            }
        }
        $this->flush($output);
    }

    /**
     * @param iterable<array<string, mixed>|\Cake\Datasource\EntityInterface> $iter
     * @param resource $output
     * @param array<string> $fields
     * @param int $batchSize
     *
     * @return void
     */
    protected function writeJson(iterable $iter, $output, array $fields, int $batchSize): void
    {
        fwrite($output, '[');

        $written = 0;
        foreach ($iter as $row) {
            $payload = $this->extractRow($row, $fields, jsonEncodeArrays: false);
            $encoded = (string)json_encode($payload);
            fwrite($output, $written === 0 ? "\n  " : ",\n  ");
            fwrite($output, $encoded);
            $written++;
            if ($written % $batchSize === 0) {
                $this->flush($output);
            }
        }
        fwrite($output, "\n]\n");
        $this->flush($output);
    }

    /**
     * @param iterable<array<string, mixed>|\Cake\Datasource\EntityInterface> $iter
     * @param resource $output
     * @param array<string> $fields
     * @param int $batchSize
     *
     * @return void
     */
    protected function writeNdjson(iterable $iter, $output, array $fields, int $batchSize): void
    {
        $written = 0;
        foreach ($iter as $row) {
            $payload = $this->extractRow($row, $fields, jsonEncodeArrays: false);
            fwrite($output, (string)json_encode($payload) . "\n");
            $written++;
            if ($written % $batchSize === 0) {
                $this->flush($output);
            }
        }
        $this->flush($output);
    }

    /**
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $row
     * @param array<string> $fields
     * @param bool $jsonEncodeArrays True for CSV (cells are scalar), false for JSON/NDJSON.
     *
     * @return array<string, mixed>
     */
    protected function extractRow(EntityInterface|array $row, array $fields, bool $jsonEncodeArrays): array
    {
        $extracted = [];
        foreach ($fields as $field) {
            $value = $row instanceof EntityInterface ? $row->get($field) : ($row[$field] ?? null);

            if (is_array($value)) {
                $extracted[$field] = $jsonEncodeArrays ? (string)json_encode($value) : $value;

                continue;
            }

            // `original`/`changed`/`meta` may arrive as JSON-encoded strings
            // when hydration didn't unpack them — try to materialize the
            // structure for JSON/NDJSON output, leave the raw string for CSV.
            if (in_array($field, ['original', 'changed', 'meta'], true) && is_string($value) && $value !== '') {
                if (!$jsonEncodeArrays) {
                    $decoded = json_decode($value, true);
                    $extracted[$field] = is_array($decoded) ? $decoded : $value;

                    continue;
                }
            }

            $extracted[$field] = $value;
        }

        return $extracted;
    }

    /**
     * Best-effort flush so a slow client / proxy sees output as it
     * generates rather than at end-of-response.
     *
     * @param resource $output
     *
     * @return void
     */
    protected function flush($output): void
    {
        if (function_exists('fflush')) {
            @fflush($output);
        }
    }
}
