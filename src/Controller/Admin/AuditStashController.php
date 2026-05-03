<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use AuditStash\Service\CoverageService;
use AuditStash\Service\DashboardService;

/**
 * Dashboard — KPI cards, daily histogram, top sources/users, recent events.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class DashboardController extends AppController
{
    use AdminControllerTrait;

    /**
     * @var string|null
     */
    protected ?string $defaultTable = 'AuditStash.AuditLogs';

    /**
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

        $this->set(compact('kpis', 'histogram', 'topSources', 'topUsers', 'recent', 'coverage'));
    }
}
