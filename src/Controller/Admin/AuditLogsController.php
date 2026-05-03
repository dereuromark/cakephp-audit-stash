<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use AuditStash\AuditLogType;
use AuditStash\Service\ExportService;
use AuditStash\Service\RevertService;
use AuditStash\Service\StateReconstructorService;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use InvalidArgumentException;
use Laminas\Diactoros\Stream;
use RuntimeException;

/**
 * AuditLogs Controller
 *
 * Provides a UI to browse and search audit logs with filtering capabilities.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class AuditLogsController extends AppController
{
    use AdminControllerTrait;

    /**
     * Pattern for filter inputs used inside JSON path expressions.
     *
     * Restricts values to identifier-shaped tokens (column names, field names)
     * so users cannot inject JSON path meta characters (`.`, `[`, `]`, `*`,
     * `"`, ...) which would otherwise reshape `JSON_CONTAINS_PATH` /
     * `JSON_EXTRACT` / `jsonb_exists` queries against the audit log JSON
     * payload.
     *
     * @var string
     */
    protected const FILTER_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * The default model class to use.
     *
     * @var string|null
     */
    protected ?string $defaultTable = 'AuditStash.AuditLogs';

    /**
     * Index method - Browse and search audit logs
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $params = $this->resolveFilterParams();
        $query = $this->AuditLogs->find('forFilters', params: $params);
        $query->orderBy(['AuditLogs.created' => 'DESC']);

        $auditLogs = $this->paginate($query);

        // Get distinct sources for filter dropdown
        $sources = $this->AuditLogs->find('list', keyField: 'source', valueField: 'source')
            ->select(['source'])
            ->distinct(['source'])
            ->orderBy(['source' => 'ASC'])
            ->toArray();

        // Built-in CRUD types plus any custom action event types (e.g.
        // "user.login") that have actually been recorded.
        $distinctTypes = $this->AuditLogs->find()
            ->select(['type'])
            ->distinct(['type'])
            ->orderBy(['type' => 'ASC'])
            ->all()
            ->extract('type')
            ->toList();
        $eventTypes = array_values(array_unique(array_merge(
            [
                AuditLogType::Create->value,
                AuditLogType::Update->value,
                AuditLogType::Delete->value,
                AuditLogType::Revert->value,
            ],
            $distinctTypes,
        )));

        // Get distinct changed fields for autocomplete
        $changedFields = $this->AuditLogs->getDistinctChangedFields();
        sort($changedFields);

        $this->set(compact('auditLogs', 'sources', 'eventTypes', 'changedFields'));
    }

    /**
     * Build the filter parameter array consumed by the
     * {@see \AuditStash\Model\Table\AuditLogsTable::findForFilters()} finder.
     *
     * Validates `changed_field` and `field_name` as strict identifiers so
     * the JSON path expression in the table-level finder cannot be
     * reshaped via meta characters. Other values are passed through and
     * parameter-bound by the ORM.
     *
     * @return array<string, mixed>
     */
    protected function resolveFilterParams(): array
    {
        $params = [];
        foreach (
            [
                'source', 'user_id', 'type', 'transaction_key', 'primary_key',
                'date_from', 'date_to', 'field_value', 'bulk_filter', 'min_records',
            ] as $key
        ) {
            $value = $this->request->getQuery($key);
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $changedField = $this->validateFilterIdentifier(
            $this->request->getQuery('changed_field'),
            'changed_field',
        );
        if ($changedField !== null) {
            $params['changed_field'] = $changedField;
        }

        $fieldName = $this->validateFilterIdentifier(
            $this->request->getQuery('field_name'),
            'field_name',
        );
        if ($fieldName !== null) {
            $params['field_name'] = $fieldName;
        }

        return $params;
    }

    /**
     * Whether the supplied filter set carries at least one non-date filter
     * that narrows the export.
     *
     * Used by {@see export()} to decide whether the default 30-day floor
     * still adds value: if the caller already pinned the export to a
     * specific source / row / transaction / user / type / field /
     * bulk class, the floor would silently drop rows the index page
     * shows for the same filters.
     *
     * @param array<string, mixed> $params Filter set from {@see resolveFilterParams()}.
     *
     * @return bool
     */
    protected function hasNarrowingFilters(array $params): bool
    {
        foreach (
            [
                'source', 'user_id', 'type', 'transaction_key', 'primary_key',
                'changed_field', 'field_name', 'bulk_filter',
            ] as $key
        ) {
            $value = $params[$key] ?? null;
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a filter input that will be interpolated into a JSON path
     * expression (`$.<key>`).
     *
     * The audit log's `original`/`changed` columns hold arbitrary JSON. Any
     * character with meaning in the JSON path mini-language (`.`, `[`, `]`,
     * `*`, `"`, escapes) lets a caller reshape the path and read fields the
     * UI did not intend to expose. Reject anything that is not a plain
     * identifier-shaped token.
     *
     * Returns null for empty/missing input (treat as "no filter"). Returns
     * the validated string. Throws on a non-empty malformed value so the
     * request fails loudly instead of silently returning the wrong rows.
     *
     * @param mixed $value The raw query parameter
     * @param string $name The parameter name (for the error message)
     *
     * @throws \Cake\Http\Exception\BadRequestException When the value is set but malformed.
     *
     * @return string|null The validated identifier, or null if not provided.
     */
    protected function validateFilterIdentifier(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match(self::FILTER_IDENTIFIER_PATTERN, $value) !== 1) {
            throw new BadRequestException(sprintf(
                'Invalid %s filter: must match %s',
                $name,
                self::FILTER_IDENTIFIER_PATTERN,
            ));
        }

        return $value;
    }

    /**
     * View method - Display details of a single audit log entry
     *
     * @param string|null $id Audit Log id.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function view(?string $id = null)
    {
        $auditLog = $this->AuditLogs->get($id);

        $this->set(compact('auditLog'));
    }

    /**
     * Timeline method - Show all changes for a specific record
     *
     * @param string|null $source Table/source name
     * @param string|null $primaryKey Primary key value
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function timeline(?string $source = null, ?string $primaryKey = null)
    {
        if ($source === null || $primaryKey === null) {
            $this->Flash->error('Source and primary key are required.');

            return $this->redirect(['action' => 'index']);
        }

        $auditLogs = $this->AuditLogs->find()
            ->where([
                'AuditLogs.source' => $source,
                'AuditLogs.primary_key' => $primaryKey,
            ])
            ->orderBy(['AuditLogs.created' => 'DESC'])
            ->toArray();

        $this->set(compact('auditLogs', 'source', 'primaryKey'));
    }

    /**
     * Preview revert changes
     *
     * @param string|null $id Audit log ID
     *
     * @throws \InvalidArgumentException
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function revertPreview(?string $id = null)
    {
        $auditLog = $this->AuditLogs->get($id);

        if ($auditLog->primary_key === null) {
            throw new InvalidArgumentException('Audit log has no primary key');
        }

        // Load services
        $reconstructor = new StateReconstructorService();

        // Get target state from audit
        $targetState = $reconstructor->reconstructState(
            $auditLog->source,
            $auditLog->primary_key,
            $auditLog->id,
        );

        // Get current state
        $table = $this->fetchTable($auditLog->source);
        $entity = $table->get($auditLog->primary_key);
        $currentState = $entity->toArray();

        // Calculate diff
        $diff = $reconstructor->calculateDiff($currentState, $targetState);

        $this->set(compact('auditLog', 'currentState', 'targetState', 'diff'));
    }

    /**
     * Execute revert
     *
     * @param string|null $id Audit log ID
     *
     * @throws \InvalidArgumentException
     *
     * @return \Cake\Http\Response
     */
    public function revert(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);

        $auditLog = $this->AuditLogs->get($id);

        if ($auditLog->primary_key === null) {
            throw new InvalidArgumentException('Audit log has no primary key');
        }

        $fields = $this->request->getData('fields');

        $revertService = new RevertService();

        if (!$fields) {
            // Full revert
            $entity = $revertService->revertFull(
                $auditLog->source,
                $auditLog->primary_key,
                $auditLog->id,
            );
        } else {
            // Partial revert
            $entity = $revertService->revertPartial(
                $auditLog->source,
                $auditLog->primary_key,
                $auditLog->id,
                $fields,
            );
        }

        if ($entity) {
            $this->Flash->success(__('Record reverted successfully.'));
        } else {
            $this->Flash->error(__('Failed to revert record.'));
        }

        $redirect = $this->redirect(['action' => 'view', $id]);
        assert($redirect instanceof Response);

        return $redirect;
    }

    /**
     * Restore deleted record
     *
     * @param string|null $source Table name
     * @param string|null $primaryKey Primary key value
     *
     * @throws \InvalidArgumentException
     *
     * @return \Cake\Http\Response|null|void Renders view or redirects
     */
    public function restore(?string $source = null, ?string $primaryKey = null)
    {
        if ($this->request->is('post')) {
            if ($source === null || $primaryKey === null) {
                throw new InvalidArgumentException('Source and primary key are required');
            }

            $revertService = new RevertService();
            $entity = $revertService->restoreDeleted($source, $primaryKey);

            if ($entity) {
                $this->Flash->success(__('Record restored successfully.'));

                return $this->redirect(['action' => 'timeline', $source, $primaryKey]);
            }

            $this->Flash->error(__('Failed to restore record.'));
        }

        // Find DELETE audit entry for preview
        $deleteLog = $this->AuditLogs->find()
            ->where([
                'source' => $source,
                'primary_key' => $primaryKey,
                'type' => AuditLogType::Delete->value,
            ])
            ->orderBy(['created' => 'DESC'])
            ->first();

        $this->set(compact('source', 'primaryKey', 'deleteLog'));
    }

    /**
     * Related changes method - Show all changes for a record and its associations
     *
     * @param string|null $source Table/source name
     * @param string|null $primaryKey Primary key value
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function relatedChanges(?string $source = null, ?string $primaryKey = null)
    {
        if ($source === null || $primaryKey === null) {
            $this->Flash->error('Source and primary key are required.');

            return $this->redirect(['action' => 'index']);
        }

        /** @var array<\AuditStash\Model\Entity\AuditLog> $auditLogs */
        $auditLogs = $this->AuditLogs->find('relatedChanges', source: $source, primaryKey: $primaryKey)
            ->orderBy(['AuditLogs.created' => 'DESC'])
            ->toArray();

        // Group by transaction
        $transactions = [];
        foreach ($auditLogs as $log) {
            $transactionId = $log->transaction_key;
            if (!isset($transactions[$transactionId])) {
                $transactions[$transactionId] = [
                    'transaction_key' => $transactionId,
                    'created' => $log->created,
                    'user_id' => $log->user_id,
                    'user_display' => $log->user_display,
                    'logs' => [],
                ];
            }
            $transactions[$transactionId]['logs'][] = $log;
        }

        $this->set(compact('source', 'primaryKey', 'transactions'));
    }

    /**
     * Bulk changes method - Show transactions with many record changes
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function bulkChanges()
    {
        $minRecords = (int)($this->request->getQuery('min_records') ?: 5);

        $bulkStats = $this->AuditLogs->find('bulkChangeStats', minRecords: $minRecords)
            ->toArray();

        $this->set(compact('bulkStats', 'minRecords'));
    }

    /**
     * Export action — renders the dedicated export form when called without
     * a format, otherwise streams the export.
     *
     * URL shapes:
     * - `GET /audit-logs/export` → form page (filter / format / date-range
     *   picker, with row-count estimate)
     * - `GET /audit-logs/export.csv|.json|.ndjson` → streaming download
     *   (legacy URL kept for the index-page quick-export buttons)
     * - `GET /audit-logs/export?format=csv|json|ndjson` → same as above,
     *   used when the form page submits without a `_ext`-style URL
     *
     * The streaming path defaults the date floor to the last
     * `AuditStash.export.defaultDays` days when the caller did not narrow
     * the range, and refuses (`BadRequestException`) when the result set
     * exceeds `AuditStash.export.hardCap` — no silent truncation.
     *
     * @throws \RuntimeException
     *
     * @return \Cake\Http\Response|null|void
     */
    public function export()
    {
        $service = new ExportService();
        $format = $this->resolveExportFormat();
        $params = $this->resolveFilterParams();
        $hasOtherFilters = $this->hasNarrowingFilters($params);
        $dateFrom = isset($params['date_from']) && is_string($params['date_from']) ? $params['date_from'] : null;
        $dateTo = isset($params['date_to']) && is_string($params['date_to']) ? $params['date_to'] : null;

        if ($format === null) {
            $query = $this->AuditLogs->find('forFilters', params: $params);
            $defaultFloor = $service->applyDefaultDateRange($query, $dateFrom, $dateTo, $hasOtherFilters);

            $rowCount = $service->estimate($query);
            $hardCap = $service->hardCap();

            $this->set(compact('rowCount', 'hardCap', 'defaultFloor'));
            $this->set('formats', ExportService::FORMATS);
            $this->set('queryParams', $this->request->getQueryParams());

            return null;
        }

        $query = $this->AuditLogs->find('forFilters', params: $params);
        $service->applyDefaultDateRange($query, $dateFrom, $dateTo, $hasOtherFilters);

        $service->assertWithinHardCap($service->estimate($query));

        $contentType = match ($format) {
            'json' => 'application/json',
            'ndjson' => 'application/x-ndjson',
            default => 'text/csv',
        };

        // php://temp keeps up to 2MB in memory and spills to disk after that,
        // so PHP-process memory stays bounded regardless of result-set size.
        // True byte-by-byte streaming is impractical inside Cake's response
        // model; this gives the same memory profile without the test-mode
        // headaches of php://output.
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Failed to open temp stream');
        }
        $service->stream($query, $format, $resource);
        rewind($resource);

        return $this->response
            ->withType($contentType)
            ->withDownload($service->filename($format))
            ->withBody(new Stream($resource));
    }

    /**
     * Resolve the export format from the request, or return `null` when
     * the user hit the form page without picking one yet.
     *
     * Accepts both `_ext` (route extension, used by the legacy inline
     * buttons) and `?format=` (form submission).
     *
     * @throws \Cake\Http\Exception\BadRequestException When the supplied format is unknown.
     *
     * @return string|null
     */
    protected function resolveExportFormat(): ?string
    {
        $candidate = $this->request->getParam('_ext') ?: $this->request->getQuery('format');
        if ($candidate === null || $candidate === '') {
            return null;
        }
        $candidate = strtolower((string)$candidate);
        if (!in_array($candidate, ExportService::FORMATS, true)) {
            throw new BadRequestException(sprintf(
                'Unsupported export format "%s". Supported: %s.',
                $candidate,
                implode(', ', ExportService::FORMATS),
            ));
        }

        return $candidate;
    }
}
