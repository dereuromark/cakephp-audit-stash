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
        <div class="nav-section-title"><?= __d('audit_stash', 'Navigation') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $isActive('AuditStash', ['index']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditStash', 'action' => 'index']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'gauge', 'fallback' => 'fas fa-gauge']) ?>
                <?= __d('audit_stash', 'Dashboard') ?>
            </a>
            <a class="nav-link <?= $isActive('AuditLogs', ['index']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'list', 'fallback' => 'fas fa-list']) ?>
                <?= __d('audit_stash', 'Audit Logs') ?>
            </a>
            <a class="nav-link <?= $isActive('AuditLogs', ['bulkChanges']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'bulkChanges']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'layer-group', 'fallback' => 'fas fa-layer-group']) ?>
                <?= __d('audit_stash', 'Bulk Changes') ?>
            </a>
            <a class="nav-link <?= $isActive('AuditStash', ['coverage']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditStash', 'action' => 'coverage']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'shield-halved', 'fallback' => 'fas fa-shield-halved']) ?>
                <?= __d('audit_stash', 'Coverage') ?>
            </a>
        </nav>
    </div>

    <!-- Quick Filters -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __d('audit_stash', 'Quick Filters') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'create']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'plus-circle', 'fallback' => 'fas fa-plus-circle']) ?>
                <?= __d('audit_stash', 'Creates') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'update']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'edit', 'fallback' => 'fas fa-edit']) ?>
                <?= __d('audit_stash', 'Updates') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'index', '?' => ['type' => 'delete']]) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'trash', 'fallback' => 'fas fa-trash']) ?>
                <?= __d('audit_stash', 'Deletes') ?>
            </a>
        </nav>
    </div>

    <!-- Export -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __d('audit_stash', 'Export') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditLogs', 'action' => 'export']) ?>">
                <?= $this->element('AuditStash.icon', ['name' => 'file-export', 'fallback' => 'fas fa-file-export']) ?>
                <?= __d('audit_stash', 'Export…') ?>
            </a>
        </nav>
    </div>

    <?php if (\Cake\Core\Configure::read('debug')) { ?>
    <div class="nav-section">
        <div class="nav-section-title text-warning"><?= __d('audit_stash', 'Debug') ?></div>
        <nav class="nav flex-column">
            <?= $this->Form->postLink(
                $this->element('AuditStash.icon', ['name' => 'eraser', 'fallback' => 'fas fa-eraser']) . ' ' . __d('audit_stash', 'Reset (truncate)'),
                ['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'AuditStash', 'action' => 'reset'],
                [
                    'class' => 'nav-link text-danger',
                    'escapeTitle' => false,
                    'confirm' => __d('audit_stash', 'This wipes ALL audit log rows. Continue?'),
                    'block' => true,
                ],
            ) ?>
        </nav>
    </div>
    <?php } ?>
</aside>
