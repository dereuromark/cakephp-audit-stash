<?php
/**
 * @var \App\View\AppView $this
 * @var string $source
 * @var string $primaryKey
 * @var \AuditStash\Model\Entity\AuditLog|null $deleteLog
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs restore content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><?= __('Restore Deleted Record') ?></h3>
        <div>
            <?= $this->Html->link(__('Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Record Information</h5>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <th style="width: 30%;">Record</th>
                    <td><code><?= h($source) ?></code> #<?= h($primaryKey) ?></td>
                </tr>
                <?php if ($deleteLog) { ?>
                <tr>
                    <th>Deleted</th>
                    <td><?= h($deleteLog->created) ?></td>
                </tr>
                <?php if ($deleteLog->user) { ?>
                <tr>
                    <th>Deleted by</th>
                    <td><?= $this->Audit->formatUser($deleteLog->user) ?></td>
                </tr>
                <?php } ?>
                <?php } ?>
            </table>
        </div>
    </div>

    <?php if ($deleteLog) { ?>
        <?php
        $data = json_decode($deleteLog->original, true);
        ?>
        <?php if ($data && is_array($data)) { ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Record Data to Restore</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $field => $value) { ?>
                            <tr>
                                <td><strong><?= h($field) ?></strong></td>
                                <td><?= $this->Audit->formatValue($value) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?= $this->Form->create(null, ['url' => ['action' => 'restore', $source, $primaryKey]]) ?>
            <div class="alert alert-warning">
                <strong><?= __('Warning:') ?></strong>
                <?= __('This will restore the record with the data shown above. If a record with this ID already exists, the restore will fail.') ?>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
                <?= $this->Form->button(__('Restore Record'), [
                    'class' => 'btn btn-primary',
                    'confirm' => __('Are you sure you want to restore this record?'),
                ]) ?>
            </div>
            <?= $this->Form->end() ?>
        <?php } else { ?>
            <div class="alert alert-danger">
                <?= __('No record data available to restore.') ?>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="alert alert-danger">
            <?= __('No deletion record found for this record.') ?>
        </div>
    <?php } ?>
</div>
