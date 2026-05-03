<?php
/**
 * @var \App\View\AppView $this
 * @var array{
 *   events_today: int,
 *   events_yesterday: int,
 *   active_users_today: int,
 *   active_users_yesterday: int,
 *   sources_7d: int,
 * } $kpis
 * @var array<int, array{date: string, types: array<string, int>, total: int}> $histogram
 * @var array<int, array{source: string, count: int}> $topSources
 * @var array<int, array{user_id: ?string, user_display: ?string, count: int}> $topUsers
 * @var array<int, \AuditStash\Model\Entity\AuditLog> $recent
 * @var array{tracked: int, missing: int, empirical: int, total: int} $coverage
 */

use AuditStash\AuditLogType;

$delta = function (int $now, int $prev): array {
    if ($prev === 0) {
        return ['label' => $now > 0 ? '+∞' : '±0', 'class' => $now > 0 ? 'text-success' : 'text-muted'];
    }
    $pct = (int)round((($now - $prev) / $prev) * 100);
    $sign = $pct > 0 ? '+' : '';

    return [
        'label' => $sign . $pct . '%',
        'class' => $pct > 0 ? 'text-success' : ($pct < 0 ? 'text-danger' : 'text-muted'),
    ];
};

$histMax = 0;
foreach ($histogram as $row) {
    $histMax = max($histMax, $row['total']);
}
$histMax = $histMax > 0 ? $histMax : 1;

$typeColor = [
    AuditLogType::Create->value => '#198754',
    AuditLogType::Update->value => '#0d6efd',
    AuditLogType::Delete->value => '#dc3545',
    AuditLogType::Revert->value => '#ffc107',
];

$topSourceMax = 0;
foreach ($topSources as $row) {
    $topSourceMax = max($topSourceMax, $row['count']);
}
$topSourceMax = $topSourceMax > 0 ? $topSourceMax : 1;

$topUserMax = 0;
foreach ($topUsers as $row) {
    $topUserMax = max($topUserMax, $row['count']);
}
$topUserMax = $topUserMax > 0 ? $topUserMax : 1;

$eventsDelta = $delta($kpis['events_today'], $kpis['events_yesterday']);
$usersDelta = $delta($kpis['active_users_today'], $kpis['active_users_yesterday']);
?>
<div class="auditlogs dashboard">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0"><?= __('Audit Dashboard') ?></h3>
            <p class="text-muted mb-0 small"><?= __('Activity, coverage, and integrity at a glance.') ?></p>
        </div>
        <div>
            <?= $this->Html->link(
                __('Browse all logs') . ' →',
                ['controller' => 'AuditLogs', 'action' => 'index'],
                ['class' => 'btn btn-primary'],
            ) ?>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= __('Events today') ?></div>
                    <div class="d-flex align-items-baseline gap-2">
                        <div class="display-6 fw-bold"><?= h(number_format($kpis['events_today'])) ?></div>
                        <div class="<?= h($eventsDelta['class']) ?> small">
                            <?= h($eventsDelta['label']) ?> <?= __('vs yesterday') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= __('Active users today') ?></div>
                    <div class="d-flex align-items-baseline gap-2">
                        <div class="display-6 fw-bold"><?= h(number_format($kpis['active_users_today'])) ?></div>
                        <div class="<?= h($usersDelta['class']) ?> small">
                            <?= h($usersDelta['label']) ?> <?= __('vs yesterday') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= __('Active sources (7d)') ?></div>
                    <div class="display-6 fw-bold"><?= h(number_format($kpis['sources_7d'])) ?></div>
                    <div class="small text-muted"><?= __('distinct tables/sources with activity') ?></div>
                </div>
            </div>
        </div>
        <div class="col">
            <?= $this->Html->link(
                sprintf(
                    '<div class="card h-100"><div class="card-body">'
                    . '<div class="text-muted small text-uppercase">%s</div>'
                    . '<div class="display-6 fw-bold">%s / %s</div>'
                    . '<div class="small text-muted">%s</div>'
                    . '</div></div>',
                    h(__('Coverage')),
                    h(number_format($coverage['tracked'])),
                    h(number_format($coverage['total'])),
                    h(__('tables with AuditLog behavior')),
                ),
                ['controller' => 'AuditStash', 'action' => 'coverage'],
                ['escapeTitle' => false, 'class' => 'text-decoration-none text-reset'],
            ) ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= __('Events over time (last 30 days)') ?></h5>
            <div class="small text-muted d-flex gap-3">
                <?php foreach ($typeColor as $type => $color) { ?>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="display: inline-block; width: 10px; height: 10px; background: <?= h($color) ?>; border-radius: 2px;"></span>
                        <?= h(ucfirst($type)) ?>
                    </span>
                <?php } ?>
                <span class="d-inline-flex align-items-center gap-1">
                    <span style="display: inline-block; width: 10px; height: 10px; background: #6c757d; border-radius: 2px;"></span>
                    <?= __('Other') ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="audit-chart" style="display: flex; align-items: flex-end; gap: 2px; height: 180px;">
                <?php foreach ($histogram as $row) {
                    $colTitle = $row['date'] . ': ' . $row['total'];
                    ?>
                    <div class="audit-chart-col" title="<?= h($colTitle) ?>" style="flex: 1; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; min-width: 0;">
                        <?php
                        $colHeightPct = ($row['total'] / $histMax) * 100;
                        if ($row['total'] > 0) {
                            ?>
                            <div style="height: <?= h((string)$colHeightPct) ?>%; display: flex; flex-direction: column; justify-content: flex-end;">
                                <?php foreach ($row['types'] as $type => $count) {
                                    $color = $typeColor[$type] ?? '#6c757d';
                                    $segPct = ($count / $row['total']) * 100;
                                    ?>
                                    <div style="height: <?= h((string)$segPct) ?>%; background: <?= h($color) ?>;"></div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
            <div class="d-flex justify-content-between text-muted small mt-2">
                <span><?= h($histogram[0]['date'] ?? '') ?></span>
                <span><?= h(end($histogram)['date'] ?? '') ?></span>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0"><?= __('Top sources (7 days)') ?></h5></div>
                <div class="card-body">
                    <?php if (!$topSources) { ?>
                        <p class="text-muted mb-0"><?= __('No activity yet.') ?></p>
                    <?php } else { ?>
                        <?php foreach ($topSources as $row) {
                            $widthPct = ($row['count'] / $topSourceMax) * 100;
                            ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <?= $this->Html->link(
                                        h($row['source']),
                                        ['controller' => 'AuditLogs', 'action' => 'index', '?' => ['source' => $row['source']]],
                                        ['escapeTitle' => false],
                                    ) ?>
                                    <span class="text-muted"><?= h(number_format($row['count'])) ?></span>
                                </div>
                                <div style="background: #e9ecef; border-radius: 3px; overflow: hidden; height: 8px;">
                                    <div style="background: #0d6efd; height: 100%; width: <?= h((string)$widthPct) ?>%;"></div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0"><?= __('Top users (7 days)') ?></h5></div>
                <div class="card-body">
                    <?php if (!$topUsers) { ?>
                        <p class="text-muted mb-0"><?= __('No user-attributed activity yet.') ?></p>
                    <?php } else { ?>
                        <?php foreach ($topUsers as $row) {
                            $widthPct = ($row['count'] / $topUserMax) * 100;
                            $label = $row['user_display'] ?: ($row['user_id'] ?: __('(anonymous)'));
                            ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <?= $this->Html->link(
                                        h($label),
                                        ['controller' => 'AuditLogs', 'action' => 'index', '?' => ['user_id' => $row['user_id']]],
                                        ['escapeTitle' => false],
                                    ) ?>
                                    <span class="text-muted"><?= h(number_format($row['count'])) ?></span>
                                </div>
                                <div style="background: #e9ecef; border-radius: 3px; overflow: hidden; height: 8px;">
                                    <div style="background: #198754; height: 100%; width: <?= h((string)$widthPct) ?>%;"></div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= __('Recent activity') ?></h5>
            <?= $this->Html->link(
                __('Open full log') . ' →',
                ['controller' => 'AuditLogs', 'action' => 'index'],
                ['class' => 'btn btn-sm btn-outline-secondary'],
            ) ?>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?= __('When') ?></th>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Source') ?></th>
                        <th><?= __('Record') ?></th>
                        <th><?= __('User') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $log) { ?>
                        <tr>
                            <td class="text-muted small"><?= h($log->created?->timeAgoInWords()) ?></td>
                            <td><?= $this->Audit->eventTypeBadge($log->type) ?></td>
                            <td><code class="small"><?= h($log->source) ?></code></td>
                            <td>
                                <?php if ($log->primary_key !== null) { ?>
                                    <?= $this->Audit->formatRecord($log->source, $log->primary_key) ?>
                                <?php } else { ?>
                                    <span class="text-muted">—</span>
                                <?php } ?>
                            </td>
                            <td class="small"><?= $this->Audit->formatUser($log->user_id, $log->user_display) ?></td>
                            <td class="text-end">
                                <?= $this->Html->link(
                                    __('View'),
                                    ['controller' => 'AuditLogs', 'action' => 'view', $log->id],
                                    ['class' => 'btn btn-sm btn-outline-primary'],
                                ) ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
