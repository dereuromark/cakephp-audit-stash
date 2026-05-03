<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use App\Controller\AppController;
use AuditStash\Service\CoverageService;

/**
 * Coverage — which Tables have AuditLog behavior attached vs which don't.
 *
 * @property \AuditStash\Model\Table\AuditLogsTable $AuditLogs
 */
class CoverageController extends AppController
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
}
