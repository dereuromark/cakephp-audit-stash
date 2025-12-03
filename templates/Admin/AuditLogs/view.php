<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AuditLog $auditLog
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs view content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><?= __('Audit Log Details') ?></h3>
        <div>
            <?= $this->Html->link(__('Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
            <?= $this->Html->link(
                __('View Timeline'),
                ['action' => 'timeline', $auditLog->source, $auditLog->primary_key],
                ['class' => 'btn btn-primary']
            ) ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= __('Event Information') ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 40%;"><?= __('ID') ?></th>
                            <td><?= $this->Number->format($auditLog->id) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Event Type') ?></th>
                            <td><?= $this->Audit->eventTypeBadge($auditLog->type) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Table/Source') ?></th>
                            <td><code><?= h($auditLog->source) ?></code></td>
                        </tr>
                        <tr>
                            <th><?= __('Record ID') ?></th>
                            <td><?= h($auditLog->primary_key) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Record Name') ?></th>
                            <td><?= h($auditLog->display_value) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Parent Source') ?></th>
                            <td><?= h($auditLog->parent_source) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Transaction ID') ?></th>
                            <td><?= $this->Audit->transactionId($auditLog->transaction, true) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Username') ?></th>
                            <td><?= h($auditLog->username) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Created') ?></th>
                            <td><?= h($auditLog->created) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= __('Metadata') ?></h5>
                </div>
                <div class="card-body">
                    <?= $this->Audit->metadata($auditLog->meta) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <?= __('Changes') ?>
                <?php if ($auditLog->type === 'create') { ?>
                    <span class="badge bg-info"><?= __('New Record') ?></span>
                <?php } elseif ($auditLog->type === 'delete') { ?>
                    <span class="badge bg-danger"><?= __('Deleted Record') ?></span>
                <?php } ?>
            </h5>
            <?php if ($auditLog->type === 'update') { ?>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary active" id="btn-inline-diff"><?= __('Inline') ?></button>
                <button type="button" class="btn btn-outline-secondary" id="btn-side-diff"><?= __('Side-by-side') ?></button>
            </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($auditLog->type === 'create') { ?>
                <?= $this->Audit->fieldValuesTable($auditLog->changed, __('Created with values:')) ?>
            <?php } elseif ($auditLog->type === 'delete') { ?>
                <?= $this->Audit->fieldValuesTable($auditLog->original, __('Deleted record had these values:')) ?>
            <?php } else { ?>
                <div id="inline-diff-view">
                    <?= $this->Audit->diffInline($auditLog->original, $auditLog->changed) ?>
                </div>
                <div id="side-diff-view" style="display: none;">
                    <?= $this->Audit->diff($auditLog->original, $auditLog->changed) ?>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<?= $this->Audit->diffStyles() ?>
<?= $this->Audit->diffScript() ?>
