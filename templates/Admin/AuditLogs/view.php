<?php
/**
 * @var \App\View\AppView $this
 * @var \AuditStash\Model\Entity\AuditLog $auditLog
 */

use AuditStash\AuditLogType;
?>
<div class="auditLogs view content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><?= __d('audit_stash', 'Audit Log Details') ?></h3>
        <div>
            <?= $this->Html->link(__d('audit_stash', 'Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
            <?php if ($auditLog->primary_key) { ?>
                <?= $this->Html->link(
                    __d('audit_stash', 'View Timeline'),
                    ['action' => 'timeline', $auditLog->source, $auditLog->primary_key],
                    ['class' => 'btn btn-primary']
                ) ?>
            <?php } ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= __d('audit_stash', 'Event Information') ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th><?= __d('audit_stash', 'Event Type') ?></th>
                            <td><?= $this->Audit->eventTypeBadge($auditLog->type) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'Source') ?></th>
                            <td><code><?= h($auditLog->source) ?></code></td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'Record') ?></th>
                            <td>
                                <?php if ($auditLog->primary_key) { ?>
                                    <?= $this->Audit->formatRecord($auditLog->source, $auditLog->primary_key, $auditLog->display_value) ?>
                                <?php } else { ?>
                                    <span class="badge bg-success">New</span>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'Parent Source') ?></th>
                            <td><?= h($auditLog->parent_source) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'Transaction ID') ?></th>
                            <td><?= $this->Audit->transactionId($auditLog->transaction_key, true) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'User') ?></th>
                            <td><?= $this->Audit->formatUser($auditLog->user_id, $auditLog->user_display) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('audit_stash', 'Created') ?></th>
                            <td><?= h($auditLog->created) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= __d('audit_stash', 'Metadata') ?></h5>
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
                <?= __d('audit_stash', 'Changes') ?>
                <?php if ($auditLog->type === AuditLogType::Create->value) { ?>
                    <span class="badge bg-info"><?= __d('audit_stash', 'New Record') ?></span>
                <?php } elseif ($auditLog->type === AuditLogType::Delete->value) { ?>
                    <span class="badge bg-danger"><?= __d('audit_stash', 'Deleted Record') ?></span>
                <?php } ?>
            </h5>
            <?php if ($auditLog->type === AuditLogType::Update->value) { ?>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary active" id="btn-inline-diff"><?= __d('audit_stash', 'Inline') ?></button>
                <button type="button" class="btn btn-outline-secondary" id="btn-side-diff"><?= __d('audit_stash', 'Side-by-side') ?></button>
            </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($auditLog->type === AuditLogType::Create->value) { ?>
                <?= $this->Audit->fieldValuesTable($auditLog->changed, __d('audit_stash', 'Created with values:')) ?>
            <?php } elseif ($auditLog->type === AuditLogType::Delete->value) { ?>
                <?= $this->Audit->fieldValuesTable($auditLog->original, __d('audit_stash', 'Deleted record had these values:')) ?>
            <?php } elseif (in_array($auditLog->type, [AuditLogType::Update->value, AuditLogType::Revert->value], true)) { ?>
                <div id="inline-diff-view">
                    <?= $this->Audit->diffInline($auditLog->original, $auditLog->changed) ?>
                </div>
                <div id="side-diff-view" style="display: none;">
                    <?= $this->Audit->diff($auditLog->original, $auditLog->changed) ?>
                </div>
            <?php } else { ?>
                <?= $this->Audit->fieldValuesTable($auditLog->changed, __d('audit_stash', 'Event payload:')) ?>
            <?php } ?>
        </div>
    </div>
</div>

<?= $this->Audit->diffStyles() ?>
<?= $this->Audit->diffScript() ?>
