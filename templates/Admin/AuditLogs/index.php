<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\AuditStash\Model\Entity\AuditLog> $auditLogs
 * @var array<string, string> $sources
 * @var array<string> $eventTypes
 * @var array<string> $changedFields
 */
?>
<div class="auditLogs index content">
    <h3><?= __('Audit Logs') ?></h3>

    <?php
    $hasAdvancedFilters = $this->request->getQuery('changed_field')
        || $this->request->getQuery('field_name')
        || $this->request->getQuery('field_value')
        || $this->request->getQuery('bulk_filter');
    ?>
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
            <!-- Basic Filters -->
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
                    <?= $this->Form->control('primary_key', [
                        'type' => 'text',
                        'label' => 'Primary Key',
                        'placeholder' => 'Record ID',
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
                    <?= $this->Form->control('transaction', [
                        'type' => 'text',
                        'label' => 'Transaction ID',
                        'placeholder' => 'Transaction ID',
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

            <!-- Advanced Filters (collapsible) -->
            <div class="mt-3">
                <a class="text-decoration-none" data-toggle="collapse" data-bs-toggle="collapse" href="#advancedFilters" role="button" aria-expanded="<?= $hasAdvancedFilters ? 'true' : 'false' ?>" aria-controls="advancedFilters">
                    <small><?= __('Advanced Filters') ?> <span class="collapse-icon"><?= $hasAdvancedFilters ? '&#9660;' : '&#9654;' ?></span></small>
                </a>
                <div class="collapse<?= $hasAdvancedFilters ? ' show' : '' ?>" id="advancedFilters">
                    <div class="row g-3 mt-2">
                        <div class="col-md-3">
                            <label class="form-label"><?= __('Changed Field') ?></label>
                            <small class="text-muted d-block mb-1"><?= __('Find records where this field was modified') ?></small>
                            <?= $this->Form->control('changed_field', [
                                'type' => 'text',
                                'label' => false,
                                'placeholder' => 'Field name',
                                'class' => 'form-control',
                                'list' => 'changed-fields-list',
                            ]) ?>
                            <datalist id="changed-fields-list">
                                <?php foreach ($changedFields as $field) { ?>
                                    <option value="<?= h($field) ?>">
                                <?php } ?>
                            </datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label"><?= __('Value Search') ?></label>
                            <small class="text-muted d-block mb-1"><?= __('Find where field changed to specific value (both required)') ?></small>
                            <div class="row g-2">
                                <div class="col-6">
                                    <?= $this->Form->control('field_name', [
                                        'type' => 'text',
                                        'label' => false,
                                        'placeholder' => 'Field name',
                                        'class' => 'form-control',
                                        'list' => 'changed-fields-list',
                                    ]) ?>
                                </div>
                                <div class="col-6">
                                    <?= $this->Form->control('field_value', [
                                        'type' => 'text',
                                        'label' => false,
                                        'placeholder' => 'equals value',
                                        'class' => 'form-control',
                                    ]) ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= __('Bulk Filter') ?></label>
                            <small class="text-muted d-block mb-1"><?= __('Filter by transaction size (5+ records = bulk)') ?></small>
                            <?= $this->Form->control('bulk_filter', [
                                'type' => 'select',
                                'label' => false,
                                'options' => [
                                    '' => __('– All –'),
                                    'yes' => __('Bulk only'),
                                    'no' => __('Non-bulk only'),
                                ],
                                'class' => 'form-select',
                            ]) ?>
                        </div>
                    </div>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <!-- Export buttons and quick links -->
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
        <?= $this->Html->link(
            __('View Bulk Changes'),
            ['action' => 'bulkChanges'],
            ['class' => 'btn btn-sm btn-outline-secondary']
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
                        <?= $this->Html->link(
                            __('Related'),
                            ['action' => 'relatedChanges', $auditLog->source, $auditLog->primary_key],
                            ['class' => 'btn btn-sm btn-outline-info']
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
