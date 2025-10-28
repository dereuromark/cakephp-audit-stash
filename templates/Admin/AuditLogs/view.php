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
                    <h5 class="mb-0">Event Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 40%;">ID</th>
                            <td><?= $this->Number->format($auditLog->id) ?></td>
                        </tr>
                        <tr>
                            <th>Event Type</th>
                            <td><?= $this->Audit->eventTypeBadge($auditLog->type) ?></td>
                        </tr>
                        <tr>
                            <th>Table/Source</th>
                            <td><code><?= h($auditLog->source) ?></code></td>
                        </tr>
                        <tr>
                            <th>Record ID</th>
                            <td><?= h($auditLog->primary_key) ?></td>
                        </tr>
                        <tr>
                            <th>Record Name</th>
                            <td><?= h($auditLog->display_value) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th>Parent Source</th>
                            <td><?= h($auditLog->parent_source) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th>Transaction ID</th>
                            <td><?= $this->Audit->transactionId($auditLog->transaction, true) ?></td>
                        </tr>
                        <tr>
                            <th>Username</th>
                            <td><?= h($auditLog->username) ?: '<em class="text-muted">N/A</em>' ?></td>
                        </tr>
                        <tr>
                            <th>Created</th>
                            <td><?= h($auditLog->created) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Metadata</h5>
                </div>
                <div class="card-body">
                    <?php if ($auditLog->meta) { ?>
                        <?php
                        $meta = json_decode($auditLog->meta, true);
                        if ($meta && is_array($meta)) {
                        ?>
                            <table class="table table-sm">
                                <?php foreach ($meta as $key => $value) { ?>
                                <tr>
                                    <th style="width: 40%;"><?= h($key) ?></th>
                                    <td>
                                        <?php if (is_array($value)) { ?>
                                            <pre class="mb-0"><code><?= h(json_encode($value, JSON_PRETTY_PRINT)) ?></code></pre>
                                        <?php } else { ?>
                                            <?= h($value) ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </table>
                        <?php } else { ?>
                            <p class="text-muted">No metadata available</p>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="text-muted">No metadata available</p>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Changes
                <?php if ($auditLog->type === 'create') { ?>
                    <span class="badge bg-info">New Record</span>
                <?php } elseif ($auditLog->type === 'delete') { ?>
                    <span class="badge bg-danger">Deleted Record</span>
                <?php } ?>
            </h5>
            <?php if ($auditLog->type === 'update') { ?>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary active" id="btn-inline-diff">Inline</button>
                <button type="button" class="btn btn-outline-secondary" id="btn-side-diff">Side-by-side</button>
            </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($auditLog->type === 'create') { ?>
                <h6>Created with values:</h6>
                <?php
                $changed = json_decode($auditLog->changed, true);
                if ($changed && is_array($changed)) {
                ?>
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($changed as $field => $value) { ?>
                            <tr>
                                <td><strong><?= h($field) ?></strong></td>
                                <td><?= $this->Audit->formatValue($value) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="text-muted">No data available</p>
                <?php } ?>
            <?php } elseif ($auditLog->type === 'delete') { ?>
                <h6>Deleted record had these values:</h6>
                <?php
                $original = json_decode($auditLog->original, true);
                if ($original && is_array($original)) {
                ?>
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Field</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($original as $field => $value) { ?>
                            <tr>
                                <td><strong><?= h($field) ?></strong></td>
                                <td><?= $this->Audit->formatValue($value) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="text-muted">No data available</p>
                <?php } ?>
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

<style>
.audit-diff {
    font-size: 0.9rem;
}
.audit-diff td {
    vertical-align: top;
}
.audit-diff-inline {
    font-size: 0.9rem;
}
.audit-diff-inline td {
    vertical-align: top;
}
pre {
    background-color: #f8f9fa;
    padding: 0.5rem;
    border-radius: 0.25rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnInline = document.getElementById('btn-inline-diff');
    const btnSide = document.getElementById('btn-side-diff');
    const inlineView = document.getElementById('inline-diff-view');
    const sideView = document.getElementById('side-diff-view');

    if (btnInline && btnSide) {
        btnInline.addEventListener('click', function() {
            inlineView.style.display = 'block';
            sideView.style.display = 'none';
            btnInline.classList.add('active');
            btnSide.classList.remove('active');
        });

        btnSide.addEventListener('click', function() {
            inlineView.style.display = 'none';
            sideView.style.display = 'block';
            btnSide.classList.add('active');
            btnInline.classList.remove('active');
        });
    }
});
</script>
