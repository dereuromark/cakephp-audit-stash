<?php
/**
 * @var \App\View\AppView $this
 * @var array<array{transaction_key: string, record_count: int, sources: int, user_id: string|null, user_display: string|null, created: \Cake\I18n\DateTime|null}> $bulkStats
 * @var int $minRecords
 */
?>
<div class="auditLogs bulk-changes content">
    <h3><?= __d('audit_stash', 'Bulk Change Transactions') ?></h3>

    <div class="alert alert-info">
        <p class="mb-0">
            <?= __d('audit_stash', 'Showing transactions that affected {0} or more records.', $minRecords) ?>
            <?= __d('audit_stash', 'These may indicate bulk operations, imports, or mass updates.') ?>
        </p>
    </div>

    <div class="mb-3">
        <?= $this->Html->link(
            __d('audit_stash', 'Back to Index'),
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
                        'label' => __d('audit_stash', 'Minimum Records'),
                        'default' => $minRecords,
                        'min' => 2,
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <?= $this->Form->button(__d('audit_stash', 'Filter'), ['class' => 'btn btn-primary']) ?>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <?php if (empty($bulkStats)) { ?>
        <div class="alert alert-warning">
            <?= __d('audit_stash', 'No bulk change transactions found with {0} or more records.', $minRecords) ?>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?= __d('audit_stash', 'Transaction ID') ?></th>
                        <th><?= __d('audit_stash', 'Records') ?></th>
                        <th><?= __d('audit_stash', 'Tables Affected') ?></th>
                        <th><?= __d('audit_stash', 'User') ?></th>
                        <th><?= __d('audit_stash', 'Date/Time') ?></th>
                        <th><?= __d('audit_stash', 'Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulkStats as $stat) { ?>
                        <tr>
                            <td>
                                <code class="small"><?= h($stat['transaction_key']) ?></code>
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
                                    __d('audit_stash', 'View All'),
                                    ['action' => 'index', '?' => ['transaction_key' => $stat['transaction_key']]],
                                    ['class' => 'btn btn-sm btn-outline-primary']
                                ) ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-secondary mt-3">
            <strong><?= __d('audit_stash', 'Legend:') ?></strong>
            <span class="badge bg-info ms-2">&lt; 20</span> <?= __d('audit_stash', 'records') ?>
            <span class="badge bg-warning ms-2">20-99</span> <?= __d('audit_stash', 'records') ?>
            <span class="badge bg-danger ms-2">100+</span> <?= __d('audit_stash', 'records') ?>
        </div>
    <?php } ?>
</div>
