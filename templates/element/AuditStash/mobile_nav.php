<?php
/**
 * AuditStash Admin Mobile Navigation
 *
 * Offcanvas navigation for mobile devices.
 *
 * @var \Cake\View\View $this
 */

$controller = $this->getRequest()->getParam('controller');
$action = $this->getRequest()->getParam('action');
$plugin = $this->getRequest()->getParam('plugin');
$prefix = $this->getRequest()->getParam('prefix');

$isActive = function (string $c, ?array $actions = null) use ($controller, $action): string {
    if ($controller !== $c) {
        return '';
    }
    if ($actions === null) {
        return 'active';
    }

    return in_array($action, $actions, true) ? 'active' : '';
};
?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="auditMobileNav" aria-labelledby="auditMobileNavLabel"
     style="background: linear-gradient(135deg, #4a2c6a 0%, #2d1a42 100%);">
    <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title text-white" id="auditMobileNavLabel">
            <i class="fas fa-clipboard-list me-2"></i>
            AuditStash
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <!-- Navigation -->
        <div class="mb-4">
            <div class="text-white-50 small text-uppercase mb-2"><?= __('Navigation') ?></div>
            <nav class="nav flex-column">
                <a class="nav-link text-white-50 <?= $isActive('AuditLogs', ['index']) ? 'text-white fw-bold' : '' ?>"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index']) ?>">
                    <i class="fas fa-list me-2"></i>
                    <?= __('Audit Logs') ?>
                </a>
                <a class="nav-link text-white-50 <?= $isActive('AuditLogs', ['bulkChanges']) ? 'text-white fw-bold' : '' ?>"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'bulkChanges']) ?>">
                    <i class="fas fa-layer-group me-2"></i>
                    <?= __('Bulk Changes') ?>
                </a>
            </nav>
        </div>

        <!-- Quick Filters -->
        <div class="mb-4">
            <div class="text-white-50 small text-uppercase mb-2"><?= __('Quick Filters') ?></div>
            <nav class="nav flex-column">
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'create']]) ?>">
                    <i class="fas fa-plus-circle me-2"></i>
                    <?= __('Creates') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'update']]) ?>">
                    <i class="fas fa-edit me-2"></i>
                    <?= __('Updates') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'delete']]) ?>">
                    <i class="fas fa-trash me-2"></i>
                    <?= __('Deletes') ?>
                </a>
            </nav>
        </div>

        <!-- Export -->
        <div class="mb-4">
            <div class="text-white-50 small text-uppercase mb-2"><?= __('Export') ?></div>
            <nav class="nav flex-column">
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'export', '_ext' => 'csv']) ?>">
                    <i class="fas fa-file-csv me-2"></i>
                    <?= __('Export CSV') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'export', '_ext' => 'json']) ?>">
                    <i class="fas fa-file-code me-2"></i>
                    <?= __('Export JSON') ?>
                </a>
            </nav>
        </div>
    </div>
</div>
