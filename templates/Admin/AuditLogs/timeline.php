<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AuditLog> $auditLogs
 * @var string $source
 * @var string $primaryKey
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs timeline content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3><?= __('Audit Timeline') ?></h3>
            <p class="text-muted mb-0">
                <strong>Table:</strong> <code><?= h($source) ?></code> |
                <strong>Record ID:</strong> <?= h($primaryKey) ?>
            </p>
        </div>
        <div>
            <?= $this->Html->link(__('Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
        </div>
    </div>

    <?php if (empty($auditLogs)) { ?>
        <div class="alert alert-info">
            No audit logs found for this record.
        </div>
    <?php } else { ?>
        <div class="timeline-container">
            <?php foreach ($auditLogs as $index => $auditLog) { ?>
                <div class="timeline-item mb-4">
                    <div class="row">
                        <div class="col-md-2 text-end">
                            <div class="timeline-date">
                                <div class="fw-bold"><?= h($auditLog->created->format('Y-m-d')) ?></div>
                                <div class="text-muted small"><?= h($auditLog->created->format('H:i:s')) ?></div>
                            </div>
                        </div>
                        <div class="col-md-1 text-center">
                            <div class="timeline-marker">
                                <?php if ($auditLog->type === 'create') { ?>
                                    <div class="marker marker-success"></div>
                                <?php } elseif ($auditLog->type === 'update') { ?>
                                    <div class="marker marker-primary"></div>
                                <?php } elseif ($auditLog->type === 'revert') { ?>
                                    <div class="marker marker-warning"></div>
                                <?php } else { ?>
                                    <div class="marker marker-danger"></div>
                                <?php } ?>
                                <?php if ($index < count($auditLogs) - 1) { ?>
                                    <div class="timeline-line"></div>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <?= $this->Audit->eventTypeBadge($auditLog->type) ?>
                                        <span class="ms-2">
                                            <?php if ($auditLog->type === 'create') { ?>
                                                Record created
                                            <?php } elseif ($auditLog->type === 'update') { ?>
                                                <?= $this->Audit->changeSummary($auditLog->changed) ?>
                                            <?php } elseif ($auditLog->type === 'revert') { ?>
                                                <?php
                                                $meta = json_decode($auditLog->meta, true);
                                                $revertType = $meta['revert_type'] ?? 'unknown';
                                                ?>
                                                Record reverted (<?= h($revertType) ?>)
                                            <?php } else { ?>
                                                Record deleted
                                            <?php } ?>
                                        </span>
                                    </div>
                                    <div>
                                        <?= $this->Html->link(
                                            __('View Details'),
                                            ['action' => 'view', $auditLog->id],
                                            ['class' => 'btn btn-sm btn-outline-primary']
                                        ) ?>
                                        <?php if ($auditLog->type === 'delete') { ?>
                                            <?= $this->Audit->restoreButton($auditLog->source, $auditLog->primary_key) ?>
                                        <?php } elseif ($auditLog->type !== 'revert') { ?>
                                            <?= $this->Audit->revertButton($auditLog->id) ?>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <strong>User:</strong> <?= $this->Audit->formatUser($auditLog->user) ?>
                                            </small>
                                        </div>
                                        <div class="col-md-6 text-end">
                                            <small class="text-muted">
                                                <strong>Transaction:</strong>
                                                <?= $this->Audit->transactionId($auditLog->transaction) ?>
                                            </small>
                                        </div>
                                    </div>

                                    <?php if ($auditLog->type === 'create') { ?>
                                        <div class="changes-preview">
                                            <strong>Initial values:</strong>
                                            <?php
                                            $changed = json_decode($auditLog->changed, true);
                                            if ($changed && is_array($changed)) {
                                                echo '<ul class="mb-0">';
                                                $count = 0;
                                                foreach ($changed as $field => $value) {
                                                    if ($count < 5) {
                                                        echo '<li><code>' . h($field) . '</code>: ' . h(is_array($value) ? json_encode($value) : $value) . '</li>';
                                                    }
                                                    $count++;
                                                }
                                                if ($count > 5) {
                                                    echo '<li><em>... and ' . ($count - 5) . ' more fields</em></li>';
                                                }
                                                echo '</ul>';
                                            }
                                            ?>
                                        </div>
                                    <?php } elseif ($auditLog->type === 'update') { ?>
                                        <details>
                                            <summary class="cursor-pointer text-primary">Show changes</summary>
                                            <div class="mt-2">
                                                <?= $this->Audit->diffInline($auditLog->original, $auditLog->changed) ?>
                                            </div>
                                        </details>
                                    <?php } elseif ($auditLog->type === 'delete') { ?>
                                        <div class="alert alert-danger mb-0">
                                            <strong>Record was deleted</strong>
                                        </div>
                                    <?php } elseif ($auditLog->type === 'revert') { ?>
                                        <div class="alert alert-warning mb-2">
                                            <?php
                                            $meta = json_decode($auditLog->meta, true);
                                            $revertType = $meta['revert_type'] ?? 'unknown';
                                            $revertToId = $meta['revert_to_audit_id'] ?? null;
                                            ?>
                                            <strong>Record was reverted (<?= h($revertType) ?>)</strong>
                                            <?php if ($revertToId) { ?>
                                                to state from audit log #<?= h($revertToId) ?>
                                            <?php } ?>
                                        </div>
                                        <details>
                                            <summary class="cursor-pointer text-primary">Show changes</summary>
                                            <div class="mt-2">
                                                <?= $this->Audit->diffInline($auditLog->original, $auditLog->changed) ?>
                                            </div>
                                        </details>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
</div>

<style>
.timeline-container {
    padding: 20px 0;
}

.timeline-date {
    padding-top: 10px;
}

.timeline-marker {
    position: relative;
    height: 100%;
}

.marker {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    margin: 0 auto;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px currentColor;
}

.marker-success {
    background-color: #198754;
    color: #198754;
}

.marker-primary {
    background-color: #0d6efd;
    color: #0d6efd;
}

.marker-danger {
    background-color: #dc3545;
    color: #dc3545;
}

.marker-warning {
    background-color: #ffc107;
    color: #ffc107;
}

.timeline-line {
    position: absolute;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: calc(100% + 40px);
    background-color: #dee2e6;
}

.changes-preview ul {
    list-style-type: none;
    padding-left: 0;
}

.changes-preview li {
    padding: 2px 0;
}

details summary {
    cursor: pointer;
    user-select: none;
}

details summary:hover {
    text-decoration: underline;
}

.audit-diff {
    font-size: 0.85rem;
}

.audit-diff td {
    vertical-align: top;
}

.audit-diff-inline {
    font-size: 0.85rem;
}

.audit-diff-inline td {
    vertical-align: top;
}
</style>
