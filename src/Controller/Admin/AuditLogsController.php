<?php
declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use Cake\Http\Response;

/**
 * AuditLogs Controller
 *
 * Provides a UI to browse and search audit logs with filtering capabilities.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class AuditLogsController extends AppController
{
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
    }

    /**
     * Index method - Browse and search audit logs
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->AuditLogs->find();

        // Filter by table/source
        if ($this->request->getQuery('source')) {
            $query->where(['AuditLogs.source' => $this->request->getQuery('source')]);
        }

        // Filter by username
        if ($this->request->getQuery('username')) {
            $query->where(['AuditLogs.username LIKE' => '%' . $this->request->getQuery('username') . '%']);
        }

        // Filter by event type
        if ($this->request->getQuery('type')) {
            $query->where(['AuditLogs.type' => $this->request->getQuery('type')]);
        }

        // Filter by transaction ID
        if ($this->request->getQuery('transaction')) {
            $query->where(['AuditLogs.transaction' => $this->request->getQuery('transaction')]);
        }

        // Filter by primary key
        if ($this->request->getQuery('primary_key')) {
            $query->where(['AuditLogs.primary_key' => $this->request->getQuery('primary_key')]);
        }

        // Filter by date range
        if ($this->request->getQuery('date_from')) {
            $query->where(['AuditLogs.created >=' => $this->request->getQuery('date_from')]);
        }
        if ($this->request->getQuery('date_to')) {
            $query->where(['AuditLogs.created <=' => $this->request->getQuery('date_to')]);
        }

        $query->orderBy(['AuditLogs.created' => 'DESC']);

        $auditLogs = $this->paginate($query);

        // Get distinct sources for filter dropdown
        $sources = $this->AuditLogs->find('list', [
            'keyField' => 'source',
            'valueField' => 'source',
        ])
            ->select(['source'])
            ->distinct(['source'])
            ->orderBy(['source' => 'ASC'])
            ->toArray();

        $eventTypes = ['create', 'update', 'delete'];

        $this->set(compact('auditLogs', 'sources', 'eventTypes'));
    }

    /**
     * View method - Display details of a single audit log entry
     *
     * @param string|null $id Audit Log id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
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
     * Export method - Export audit logs to CSV or JSON
     *
     * @return \Cake\Http\Response
     */
    public function export(): Response
    {
        $format = $this->request->getParam('_ext') ?: 'csv';
        $query = $this->AuditLogs->find();

        // Apply same filters as index
        if ($this->request->getQuery('source')) {
            $query->where(['AuditLogs.source' => $this->request->getQuery('source')]);
        }
        if ($this->request->getQuery('username')) {
            $query->where(['AuditLogs.username LIKE' => '%' . $this->request->getQuery('username') . '%']);
        }
        if ($this->request->getQuery('type')) {
            $query->where(['AuditLogs.type' => $this->request->getQuery('type')]);
        }
        if ($this->request->getQuery('date_from')) {
            $query->where(['AuditLogs.created >=' => $this->request->getQuery('date_from')]);
        }
        if ($this->request->getQuery('date_to')) {
            $query->where(['AuditLogs.created <=' => $this->request->getQuery('date_to')]);
        }

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
            throw new \RuntimeException('Failed to open temporary stream');
        }

        // Headers
        fputcsv($output, [
            'ID',
            'Transaction',
            'Type',
            'Source',
            'Primary Key',
            'Display Value',
            'Username',
            'Original',
            'Changed',
            'Meta',
            'Created',
        ]);

        // Data
        foreach ($auditLogs as $log) {
            fputcsv($output, [
                $log->id,
                $log->transaction,
                $log->type,
                $log->source,
                $log->primary_key,
                $log->display_value,
                $log->username,
                $log->original,
                $log->changed,
                $log->meta,
                $log->created,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        if ($csv === false) {
            throw new \RuntimeException('Failed to read CSV content');
        }

        return $response->withStringBody($csv);
    }

    /**
     * Export audit logs as JSON
     *
     * @param array $auditLogs Audit logs
     * @return \Cake\Http\Response
     */
    protected function exportJson(array $auditLogs): Response
    {
        $filename = 'audit_logs_' . date('Y-m-d_His') . '.json';

        $data = [];
        foreach ($auditLogs as $log) {
            $data[] = [
                'id' => $log->id,
                'transaction' => $log->transaction,
                'type' => $log->type,
                'source' => $log->source,
                'primary_key' => $log->primary_key,
                'display_value' => $log->display_value,
                'username' => $log->username,
                'original' => $log->original ? json_decode($log->original, true) : null,
                'changed' => $log->changed ? json_decode($log->changed, true) : null,
                'meta' => $log->meta ? json_decode($log->meta, true) : null,
                'created' => $log->created,
            ];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON');
        }

        return $this->response
            ->withType('json')
            ->withDownload($filename)
            ->withStringBody($json);
    }
}
