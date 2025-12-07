<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AuditLog> $auditLogs
 * @var array $sources
 * @var array $eventTypes
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs index content">
    <h3><?= __('Audit Logs') ?></h3>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <?= $this->Form->control('source', [
                        'type' => 'select',
                        'options' => ['' => 'All Sources'] + $sources,
                        'label' => 'Source',
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('type', [
                        'type' => 'select',
                        'options' => ['' => 'All Types'] + array_combine($eventTypes, $eventTypes),
                        'label' => 'Event Type',
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('user_id', [
                        'type' => 'text',
                        'label' => 'User ID',
                        'placeholder' => 'Search by user ID',
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('transaction', [
                        'type' => 'text',
                        'label' => 'Transaction ID',
                        'placeholder' => 'Transaction ID',
                        'class' => 'form-control',
                    ]) ?>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-md-3">
                    <?= $this->Form->control('date_from', [
                        'type' => 'date',
                        'label' => 'Date From',
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('date_to', [
                        'type' => 'date',
                        'label' => 'Date To',
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('primary_key', [
                        'type' => 'text',
                        'label' => 'Primary Key',
                        'placeholder' => 'Record ID',
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <?= $this->Form->button(__('Filter'), ['class' => 'btn btn-primary me-2']) ?>
                    <?php if ($this->request->getQueryParams()) { ?>
                        <?= $this->Html->link(__('Clear'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
                    <?php } ?>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <!-- Export buttons -->
    <div class="mb-3">
        <?php
        $queryParams = $this->request->getQueryParams();
        ?>
        <?= $this->Html->link(
            __('Export CSV'),
            ['action' => 'export', '_ext' => 'csv', '?' => $queryParams],
            ['class' => 'btn btn-sm btn-outline-primary']
        ) ?>
        <?= $this->Html->link(
            __('Export JSON'),
            ['action' => 'export', '_ext' => 'json', '?' => $queryParams],
            ['class' => 'btn btn-sm btn-outline-primary']
        ) ?>
    </div>

    <!-- Results table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('created', 'Date/Time') ?></th>
                    <th><?= $this->Paginator->sort('type', 'Event') ?></th>
                    <th><?= $this->Paginator->sort('source', 'Source') ?></th>
                    <th><?= $this->Paginator->sort('primary_key', 'Record') ?></th>
                    <th><?= $this->Paginator->sort('user_id', 'User') ?></th>
                    <th>Changes</th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLogs as $auditLog) { ?>
                <tr>
                    <td><small><?= h($auditLog->created) ?></small></td>
                    <td><?= $this->Audit->eventTypeBadge($auditLog->type) ?></td>
                    <td><code><?= h($auditLog->source) ?></code></td>
                    <td>
                        <?php if ($auditLog->primary_key) { ?>
                            <?= $this->Audit->formatRecord($auditLog->source, $auditLog->primary_key, $auditLog->display_value) ?>
                        <?php } else { ?>
                            <span class="badge bg-success">New</span>
                        <?php } ?>
                    </td>
                    <td><?= $this->Audit->formatUser($auditLog->user_id, $auditLog->user_display) ?></td>
                    <td><small><?= $this->Audit->changeSummary($auditLog->changed) ?></small></td>
                    <td class="actions">
                        <?= $this->Html->link(__('View'), ['action' => 'view', $auditLog->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                        <?= $this->Html->link(
                            __('Timeline'),
                            ['action' => 'timeline', $auditLog->source, $auditLog->primary_key],
                            ['class' => 'btn btn-sm btn-outline-secondary']
                        ) ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>

<style>
.audit-diff {
    font-size: 0.9rem;
}
.audit-diff td {
    vertical-align: top;
}
</style>
