<?php
/**
 * @var \App\View\AppView $this
 * @var string $source
 * @var string $primaryKey
 * @var array<string, array{transaction: string, created: \Cake\I18n\DateTime|null, user_id: string|null, user_display: string|null, logs: array<\AuditStash\Model\Entity\AuditLog>}> $transactions
 */
?>
<div class="auditLogs related-changes content">
    <h3><?= __('Related Changes') ?></h3>

    <div class="alert alert-info">
        <strong><?= __('Record:') ?></strong>
        <code><?= h($source) ?></code> #<?= h($primaryKey) ?>
        <p class="mb-0 mt-2 small">
            <?= __('Showing all audit logs for this record and related records (via foreign keys).') ?>
        </p>
    </div>

    <div class="mb-3">
        <?= $this->Html->link(
            __('Back to Index'),
            ['action' => 'index'],
            ['class' => 'btn btn-secondary']
        ) ?>
        <?= $this->Html->link(
            __('View Timeline'),
            ['action' => 'timeline', $source, $primaryKey],
            ['class' => 'btn btn-outline-primary']
        ) ?>
    </div>

    <?php if (empty($transactions)) { ?>
        <div class="alert alert-warning">
            <?= __('No related changes found.') ?>
        </div>
    <?php } else { ?>
        <?php foreach ($transactions as $txId => $transaction) { ?>
            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= __('Transaction:') ?></strong>
                            <code class="small"><?= h($transaction['transaction']) ?></code>
                        </div>
                        <div class="text-muted small">
                            <?= h($transaction['created']) ?>
                            <?php if ($transaction['user_display'] || $transaction['user_id']) { ?>
                                &bull; <?= $this->Audit->formatUser($transaction['user_id'], $transaction['user_display']) ?>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Event') ?></th>
                                <th><?= __('Source') ?></th>
                                <th><?= __('Record') ?></th>
                                <th><?= __('Changes') ?></th>
                                <th><?= __('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaction['logs'] as $log) { ?>
                                <?php
                                // Highlight the main record
                                $isMainRecord = ($log->source === $source && $log->primary_key == $primaryKey);
                                $rowClass = $isMainRecord ? 'table-primary' : '';
                                ?>
                                <tr class="<?= $rowClass ?>">
                                    <td><?= $this->Audit->eventTypeBadge($log->type) ?></td>
                                    <td>
                                        <code><?= h($log->source) ?></code>
                                        <?php if ($isMainRecord) { ?>
                                            <span class="badge bg-info"><?= __('Main') ?></span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if ($log->primary_key) { ?>
                                            <?= $this->Audit->formatRecord($log->source, $log->primary_key, $log->display_value) ?>
                                        <?php } else { ?>
                                            <span class="badge bg-success"><?= __('New') ?></span>
                                        <?php } ?>
                                    </td>
                                    <td><small><?= $this->Audit->changeSummary($log->changed) ?></small></td>
                                    <td>
                                        <?= $this->Html->link(
                                            __('View'),
                                            ['action' => 'view', $log->id],
                                            ['class' => 'btn btn-sm btn-outline-primary']
                                        ) ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    <?= __n('{0} record in this transaction', '{0} records in this transaction', count($transaction['logs']), count($transaction['logs'])) ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>
