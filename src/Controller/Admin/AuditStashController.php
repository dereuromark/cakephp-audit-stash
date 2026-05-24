<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use AuditStash\Service\CoverageService;
use AuditStash\Service\DashboardService;
use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;

/**
 * Plugin entry point. Hosts the admin dashboard (`index`) and the coverage
 * report (`coverage`) — both read-only, both backed by their respective
 * services.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class AuditStashController extends AppController
{
    use AdminControllerTrait;

    /**
     * @var string|null
     */
    protected ?string $defaultTable = 'AuditStash.AuditLogs';

    /**
     * Dashboard — KPI cards, daily histogram, top sources/users, recent events.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $service = new DashboardService($this->AuditLogs);

        $kpis = $service->kpis();
        $histogram = $service->eventsHistogram(30);
        $topSources = $service->topSources(7, 10);
        $topUsers = $service->topUsers(7, 10);
        $recent = $service->recentEvents(20);
        $coverage = (new CoverageService())->summary();

        $this->set(['kpis' => $kpis, 'histogram' => $histogram, 'topSources' => $topSources, 'topUsers' => $topUsers, 'recent' => $recent, 'coverage' => $coverage]);
    }

    /**
     * Coverage — which Tables have AuditLog behavior attached vs which don't.
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

        $this->set(['rows' => $rows, 'filter' => $filter, 'includeInternal' => $includeInternal, 'summary' => $summary]);
    }

    /**
     * Truncate audit_logs. Debug-only convenience for development /
     * demo environments — refuses to run when `Configure::read('debug')`
     * is false so a misconfigured route can't wipe production logs.
     *
     * @throws \Cake\Http\Exception\ForbiddenException When debug is off.
     *
     * @return \Cake\Http\Response|null
     */
    public function reset()
    {
        $this->request->allowMethod(['post']);
        if (!Configure::read('debug')) {
            throw new ForbiddenException('AuditStash reset is only available in debug mode.');
        }

        $connection = $this->AuditLogs->getConnection();
        $connection->execute('TRUNCATE TABLE ' . $connection->getDriver()->quoteIdentifier($this->AuditLogs->getTable()));

        $this->Flash->success(__d('audit_stash', 'All audit logs have been deleted.'));

        return $this->redirect(['action' => 'index']);
    }
}
