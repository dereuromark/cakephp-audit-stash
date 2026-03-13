<?php
/**
 * @var \App\View\AppView $this
 * @var array<array{transaction: string, record_count: int, sources: int, user_id: string|null, user_display: string|null, created: \Cake\I18n\DateTime|null}> $bulkStats
 * @var int $minRecords
 */

$this->loadHelper('AuditStash.Audit');
?>
<div class="auditLogs bulk-changes content">
    <h3><?= __('Bulk Change Transactions') ?></h3>

    <div class="alert alert-info">
        <p class="mb-0">
            <?= __('Showing transactions that affected {0} or more records.', $minRecords) ?>
            <?= __('These may indicate bulk operations, imports, or mass updates.') ?>
        </p>
    </div>

    <div class="mb-3">
        <?= $this->Html->link(
            __('Back to Index'),
            ['action' => 'index'],
            ['class' => 'btn btn-secondary']
        ) ?>
    </div>

    <!-- Minimum records filter -->
    <div class="card mb-4">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => ['query']]) ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <?= $this->Form->control('min_records', [
                        'type' => 'number',
                        'label' => __('Minimum Records'),
                        'default' => $minRecords,
                        'min' => 2,
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <?= $this->Form->button(__('Filter'), ['class' => 'btn btn-primary']) ?>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <?php if (empty($bulkStats)) { ?>
        <div class="alert alert-warning">
            <?= __('No bulk change transactions found with {0} or more records.', $minRecords) ?>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?= __('Transaction ID') ?></th>
                        <th><?= __('Records') ?></th>
                        <th><?= __('Tables Affected') ?></th>
                        <th><?= __('User') ?></th>
                        <th><?= __('Date/Time') ?></th>
                        <th><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkStats as $stat) { ?>
                        <tr>
                            <td>
                                <code class="small"><?= h($stat['transaction']) ?></code>
                            </td>
                            <td>
                                <span class="badge bg-<?= $stat['record_count'] >= 100 ? 'danger' : ($stat['record_count'] >= 20 ? 'warning' : 'info') ?>">
                                    <?= $stat['record_count'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= $stat['sources'] ?></span>
                            </td>
                            <td><?= $this->Audit->formatUser($stat['user_id'], $stat['user_display']) ?></td>
                            <td><small><?= h($stat['created']) ?></small></td>
                            <td>
                                <?= $this->Html->link(
                                    __('View All'),
                                    ['action' => 'index', '?' => ['transaction' => $stat['transaction']]],
                                    ['class' => 'btn btn-sm btn-outline-primary']
                                ) ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-secondary mt-3">
            <strong><?= __('Legend:') ?></strong>
            <span class="badge bg-info ms-2">&lt; 20</span> <?= __('records') ?>
            <span class="badge bg-warning ms-2">20-99</span> <?= __('records') ?>
            <span class="badge bg-danger ms-2">100+</span> <?= __('records') ?>
        </div>
    <?php } ?>
</div>
