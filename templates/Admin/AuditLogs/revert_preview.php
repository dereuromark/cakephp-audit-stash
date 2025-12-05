<?php
/**
 * @var \App\View\AppView $this
 * @var \AuditStash\Model\Entity\AuditLog $auditLog
 * @var array $currentState
 * @var array $targetState
 * @var array $diff
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs revert content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><?= __('Revert Preview') ?></h3>
        <div>
            <?= $this->Html->link(__('Cancel'), ['action' => 'view', $auditLog->id], ['class' => 'btn btn-secondary']) ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Revert Information</h5>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <th style="width: 30%;">Record</th>
                    <td><code><?= h($auditLog->source) ?></code> #<?= h($auditLog->primary_key) ?></td>
                </tr>
                <tr>
                    <th>Reverting to</th>
                    <td><?= h($auditLog->created) ?></td>
                </tr>
                <?php if ($auditLog->user) { ?>
                <tr>
                    <th>Originally changed by</th>
                    <td><?= $this->Audit->formatUser($auditLog->user) ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <?php if (empty($diff)) { ?>
        <div class="alert alert-info">
            <?= __('No differences found. Record is already in this state.') ?>
        </div>
    <?php } else { ?>
        <?= $this->Form->create(null, ['url' => ['action' => 'revert', $auditLog->id]]) ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= __('Fields to Revert') ?></h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Field</th>
                            <th style="width: 30%;">Current Value</th>
                            <th style="width: 30%;">After Revert</th>
                            <th style="width: 10%;" class="text-center">Revert?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diff as $field => $values) { ?>
                        <tr>
                            <td><strong><?= h($field) ?></strong></td>
                            <td><?= $this->Audit->formatValue($values['current']) ?></td>
                            <td class="table-warning"><?= $this->Audit->formatValue($values['target']) ?></td>
                            <td class="text-center">
                                <?= $this->Form->checkbox('fields[]', [
                                    'value' => $field,
                                    'checked' => true,
                                    'hiddenField' => false,
                                    'class' => 'form-check-input',
                                ]) ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-select-all">
                        <?= __('Select All') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-select-none">
                        <?= __('Select None') ?>
                    </button>
                </div>
                <div>
                    <?= $this->Form->button(__('Revert Selected'), [
                        'class' => 'btn btn-primary',
                        'confirm' => __('Are you sure you want to revert the selected fields?'),
                    ]) ?>
                </div>
            </div>
        </div>

        <?= $this->Form->end() ?>
    <?php } ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[name="fields[]"]');
    const btnSelectAll = document.getElementById('btn-select-all');
    const btnSelectNone = document.getElementById('btn-select-none');

    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = true);
        });
    }

    if (btnSelectNone) {
        btnSelectNone.addEventListener('click', function() {
            checkboxes.forEach(cb => cb.checked = false);
        });
    }
});
</script>
