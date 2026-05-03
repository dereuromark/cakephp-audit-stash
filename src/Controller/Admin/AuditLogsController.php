<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use AuditStash\AuditLogType;
use AuditStash\Service\CoverageService;
use AuditStash\Service\DashboardService;
use AuditStash\Service\RevertService;
use AuditStash\Service\StateReconstructorService;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Log\Log;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * AuditLogs Controller
 *
 * Provides a UI to browse and search audit logs with filtering capabilities.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class AuditLogsController extends AppController
{
    use LoadHelperTrait;

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
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadHelpers();

        // Configure layout
        $adminLayout = Configure::read('AuditStash.adminLayout');
        if ($adminLayout === false) {
            // Disable plugin layout, use app's default
        } elseif ($adminLayout === null) {
            // Use plugin's isolated Bootstrap 5 layout
            $this->viewBuilder()->setLayout('AuditStash.audit_stash');
        } else {
            // Use custom layout
            $this->viewBuilder()->setLayout($adminLayout);
        }
    }

    /**
     * Optional defense-in-depth access gate.
     *
     * Audit logs commonly contain sensitive who-did-what records (PII, IP
     * addresses, what fields were changed). Set `AuditStash.accessCheck` to
     * a Closure that receives the current request and returns literal `true`
     * to grant access; anything else (returns false, returns a truthy
     * non-bool, throws) yields a 403.
     *
     * Unset = no-op (host AppController auth alone applies).
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event
     *
     * @throws \Cake\Http\Exception\ForbiddenException When the configured Closure rejects the request.
     *
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $check = Configure::read('AuditStash.accessCheck');
        if ($check === null) {
            return;
        }
        if (!($check instanceof Closure)) {
            throw new ForbiddenException('AuditStash.accessCheck must be a Closure');
        }

        // Coexist with cakephp/authorization: the gate IS the authorization
        // decision, so silence the policy check.
        if ($this->components()->has('Authorization') && method_exists($this->components()->get('Authorization'), 'skipAuthorization')) {
            $this->components()->get('Authorization')->skipAuthorization();
        }

        try {
            $allowed = $check($this->request) === true;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning(sprintf('AuditStash.accessCheck threw %s: %s', $e::class, $e->getMessage()));

            throw new ForbiddenException('AuditStash admin access denied');
        }

        if (!$allowed) {
            throw new ForbiddenException('AuditStash admin access denied');
        }
    }

    /**
     * Dashboard — KPI cards, daily histogram, top sources/users, recent events.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function dashboard()
    {
        $service = new DashboardService($this->AuditLogs);

        $kpis = $service->kpis();
        $histogram = $service->eventsHistogram(30);
        $topSources = $service->topSources(7, 10);
        $topUsers = $service->topUsers(7, 10);
        $recent = $service->recentEvents(20);

        // Coverage summary count for the "Coverage" KPI card.
        $coverage = (new CoverageService())->summary();

        $this->set(compact('kpis', 'histogram', 'topSources', 'topUsers', 'recent', 'coverage'));
    }

    /**
     * Coverage page — which Tables have AuditLog behavior attached vs which don't.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function coverage()
    {
        $filter = (string)$this->request->getQuery('filter', '');
        $includeInternal = (bool)$this->request->getQuery('include_internal', false);

        $service = new CoverageService();
        $rows = $service->discover($includeInternal);

        if (in_array($filter, ['tracked', 'missing', 'empirical', 'internal'], true)) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === $filter));
        }

        $summary = $service->summary();

        $this->set(compact('rows', 'filter', 'includeInternal', 'summary'));
    }

    /**
     * Index method - Browse and search audit logs
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->AuditLogs->find();
        $this->applyBaseFilters($query);

        // Filter by changed field (field-level tracking).
        // Validated as a strict identifier to avoid JSON path injection.
        $changedField = $this->validateFilterIdentifier(
            $this->request->getQuery('changed_field'),
            'changed_field',
        );
        if ($changedField !== null) {
            $query = $this->AuditLogs->find('byChangedField', field: $changedField);
            $this->applyBaseFilters($query);
        }

        // Filter by field name + value (value-based search).
        // Field name validated as identifier; value is parameter-bound.
        $fieldName = $this->validateFilterIdentifier(
            $this->request->getQuery('field_name'),
            'field_name',
        );
        $fieldValue = $this->request->getQuery('field_value');
        if ($fieldName !== null && $fieldValue !== null && $fieldValue !== '') {
            $query = $this->AuditLogs->find('byChangedFieldValue', field: $fieldName, value: $fieldValue);
            $this->applyBaseFilters($query);
        }

        // Filter by bulk/non-bulk changes
        $bulkFilter = $this->request->getQuery('bulk_filter');
        if ($bulkFilter === 'yes') {
            $minRecords = (int)($this->request->getQuery('min_records') ?: 5);
            $query = $this->AuditLogs->find('bulkChanges', minRecords: $minRecords);
            $this->applyBaseFilters($query);
        } elseif ($bulkFilter === 'no') {
            $minRecords = (int)($this->request->getQuery('min_records') ?: 5);
            $query = $this->AuditLogs->find('nonBulkChanges', minRecords: $minRecords);
            $this->applyBaseFilters($query);
        }

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
     * Apply base filters to a query.
     *
     * Single source of truth for the index/export filter set so the LIKE
     * escape and other guards stay in sync. Used both at the start of index()
     * and after a finder resets the query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify
     *
     * @return void
     */
    protected function applyBaseFilters($query): void
    {
        if ($this->request->getQuery('source')) {
            $query->where(['AuditLogs.source' => $this->request->getQuery('source')]);
        }
        $userId = $this->request->getQuery('user_id');
        if ($userId !== null && $userId !== '') {
            // Escape LIKE wildcards so a literal `%` or `_` from user input
            // does not turn the query into a full-table-scan / match-all.
            $needle = addcslashes((string)$userId, '%_\\');
            $query->where(['AuditLogs.user_id LIKE' => '%' . $needle . '%']);
        }
        if ($this->request->getQuery('type')) {
            $query->where(['AuditLogs.type' => $this->request->getQuery('type')]);
        }
        if ($this->request->getQuery('transaction_key')) {
            $query->where(['AuditLogs.transaction_key' => $this->request->getQuery('transaction_key')]);
        }
        if ($this->request->getQuery('primary_key')) {
            $query->where(['AuditLogs.primary_key' => $this->request->getQuery('primary_key')]);
        }
        if ($this->request->getQuery('date_from')) {
            $query->where(['AuditLogs.created >=' => $this->request->getQuery('date_from') . ' 00:00:00']);
        }
        if ($this->request->getQuery('date_to')) {
            $query->where(['AuditLogs.created <=' => $this->request->getQuery('date_to') . ' 23:59:59']);
        }
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
     * Export method - Export audit logs to CSV or JSON
     *
     * @return \Cake\Http\Response
     */
    public function export(): Response
    {
        $format = $this->request->getParam('_ext') ?: 'csv';
        $query = $this->AuditLogs->find();

        // Reuse the index filter set so LIKE escaping etc. stay aligned.
        $this->applyBaseFilters($query);

        $query->orderBy(['AuditLogs.created' => 'DESC'])->limit(10000);

        $auditLogs = $query->toArray();

        if ($format === 'json') {
            return $this->exportJson($auditLogs);
        }

        return $this->exportCsv($auditLogs);
    }

    /**
     * Export audit logs as CSV
     *
     * @param array $auditLogs Audit logs
     *
     * @throws \RuntimeException
     *
     * @return \Cake\Http\Response
     */
    protected function exportCsv(array $auditLogs): Response
    {
        $filename = 'audit_logs_' . date('Y-m-d_His') . '.csv';

        $response = $this->response
            ->withType('csv')
            ->withDownload($filename);

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new RuntimeException('Failed to open temporary stream');
        }

        // Headers
        fputcsv($output, [
            'ID',
            'Transaction',
            'Type',
            'Source',
            'Primary Key',
            'Display Value',
            'User ID',
            'User Display',
            'Original',
            'Changed',
            'Meta',
            'Created',
        ], escape: '\\');

        // Data
        foreach ($auditLogs as $log) {
            fputcsv($output, [
                $log->id,
                $log->transaction_key,
                $log->type,
                $log->source,
                $log->primary_key,
                $log->display_value,
                $log->user_id,
                $log->user_display,
                is_array($log->original) ? json_encode($log->original) : $log->original,
                is_array($log->changed) ? json_encode($log->changed) : $log->changed,
                is_array($log->meta) ? json_encode($log->meta) : $log->meta,
                $log->created,
            ], escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        if ($csv === false) {
            throw new RuntimeException('Failed to read CSV content');
        }

        return $response->withStringBody($csv);
    }

    /**
     * Export audit logs as JSON
     *
     * @param array $auditLogs Audit logs
     *
     * @throws \RuntimeException
     *
     * @return \Cake\Http\Response
     */
    protected function exportJson(array $auditLogs): Response
    {
        $filename = 'audit_logs_' . date('Y-m-d_His') . '.json';

        $data = [];
        foreach ($auditLogs as $log) {
            $data[] = [
                'id' => $log->id,
                'transaction_key' => $log->transaction_key,
                'type' => $log->type,
                'source' => $log->source,
                'primary_key' => $log->primary_key,
                'display_value' => $log->display_value,
                'user_id' => $log->user_id,
                'user_display' => $log->user_display,
                'original' => is_array($log->original) ? $log->original : ($log->original ? json_decode($log->original, true) : null),
                'changed' => is_array($log->changed) ? $log->changed : ($log->changed ? json_decode($log->changed, true) : null),
                'meta' => is_array($log->meta) ? $log->meta : ($log->meta ? json_decode($log->meta, true) : null),
                'created' => $log->created,
            ];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON');
        }

        return $this->response
            ->withType('json')
            ->withDownload($filename)
            ->withStringBody($json);
    }
}
