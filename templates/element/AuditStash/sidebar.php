<?php
/**
 * AuditStash Admin Sidebar Navigation
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
<aside class="audit-sidebar d-none d-lg-block">
    <!-- Navigation -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __('Navigation') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $isActive('AuditLogs', ['index']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'list', 'fallback' => 'fas fa-list']) ?>
                <?= __('Audit Logs') ?>
            </a>
            <a class="nav-link <?= $isActive('AuditLogs', ['bulkChanges']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'bulkChanges']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'layer-group', 'fallback' => 'fas fa-layer-group']) ?>
                <?= __('Bulk Changes') ?>
            </a>
        </nav>
    </div>

    <!-- Quick Filters -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __('Quick Filters') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'create']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'plus-circle', 'fallback' => 'fas fa-plus-circle']) ?>
                <?= __('Creates') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'update']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'edit', 'fallback' => 'fas fa-edit']) ?>
                <?= __('Updates') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'delete']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'trash', 'fallback' => 'fas fa-trash']) ?>
                <?= __('Deletes') ?>
            </a>
        </nav>
    </div>

    <!-- Export -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __('Export') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'export', '_ext' => 'csv']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'file-csv', 'fallback' => 'fas fa-file-csv']) ?>
                <?= __('Export CSV') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'export', '_ext' => 'json']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'file-code', 'fallback' => 'fas fa-file-code']) ?>
                <?= __('Export JSON') ?>
            </a>
        </nav>
    </div>
</aside>
