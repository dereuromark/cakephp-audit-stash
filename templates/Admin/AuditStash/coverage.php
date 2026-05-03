<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array{
 *   alias: string,
 *   class: ?string,
 *   plugin: ?string,
 *   has_behavior: bool,
 *   instantiation_error: ?string,
 *   events_30d: int,
 *   status: 'tracked'|'missing'|'empirical'|'internal',
 *   config: ?array<string, mixed>,
 * }> $rows
 * @var string $filter
 * @var bool $includeInternal
 * @var array{tracked: int, missing: int, empirical: int, total: int} $summary
 */

$badge = function (string $status): string {
    return match ($status) {
        'tracked' => '<span class="badge bg-success">Tracked</span>',
        'missing' => '<span class="badge bg-warning text-dark">Missing</span>',
        'empirical' => '<span class="badge bg-info text-dark">Empirical</span>',
        'internal' => '<span class="badge bg-secondary">Internal</span>',
        default => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
};

$chip = function (string $key, string $label, int $count) use ($filter, $includeInternal): string {
    $active = $filter === $key;
    $class = $active ? 'btn-primary' : 'btn-outline-secondary';
    $url = $this->Url->build([
        'controller' => 'AuditStash',
        'action' => 'coverage',
        '?' => array_filter([
            'filter' => $key === 'all' ? null : $key,
            'include_internal' => $includeInternal ? 1 : null,
        ]),
    ]);

    return sprintf(
        '<a href="%s" class="btn btn-sm %s">%s <span class="badge bg-light text-dark">%d</span></a>',
        h($url),
        $class,
        h($label),
        $count,
    );
};
?>
<div class="auditlogs coverage">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0"><?= __('Audit Coverage') ?></h3>
            <p class="text-muted mb-0 small">
                <?= __(
                    '{0} of {1} discoverable tables have AuditLog behavior attached.',
                    '<strong>' . h((string)$summary['tracked']) . '</strong>',
                    '<strong>' . h((string)$summary['total']) . '</strong>',
                ) ?>
            </p>
        </div>
        <div>
            <?= $this->Html->link(__('← Dashboard'), ['controller' => 'AuditStash', 'action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <?php
        $allCount = $summary['tracked'] + $summary['missing'] + $summary['empirical'];
        echo $chip('all', __('All'), $allCount);
        echo $chip('tracked', __('Tracked'), $summary['tracked']);
        echo $chip('missing', __('Missing'), $summary['missing']);
        echo $chip('empirical', __('Empirical'), $summary['empirical']);
        ?>

        <div class="form-check form-switch ms-3">
            <?php
            $toggleUrl = $this->Url->build([
                'controller' => 'AuditStash',
                'action' => 'coverage',
                '?' => array_filter([
                    'filter' => $filter ?: null,
                    'include_internal' => $includeInternal ? null : 1,
                ]),
            ]);
            ?>
            <input class="form-check-input" type="checkbox" role="switch"
                   id="includeInternal" <?= $includeInternal ? 'checked' : '' ?>
                   onclick="window.location='<?= h($toggleUrl) ?>'">
            <label class="form-check-label small" for="includeInternal"><?= __('Include internal tables') ?></label>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?= __('Status') ?></th>
                        <th><?= __('Alias') ?></th>
                        <th><?= __('Plugin') ?></th>
                        <th><?= __('Class') ?></th>
                        <th class="text-end"><?= __('Events (30d)') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows) { ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <?= __('No tables match this filter.') ?>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?= $badge($row['status']) ?></td>
                            <td><code><?= h($row['alias']) ?></code></td>
                            <td><?= $row['plugin'] !== null ? '<code>' . h($row['plugin']) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($row['class']) { ?>
                                    <code class="small text-muted"><?= h($row['class']) ?></code>
                                <?php } else { ?>
                                    <span class="text-muted small"><?= __('No class found') ?></span>
                                <?php } ?>
                                <?php if ($row['instantiation_error']) { ?>
                                    <div class="text-danger small">⚠️ <?= h($row['instantiation_error']) ?></div>
                                <?php } ?>
                            </td>
                            <td class="text-end"><?= h(number_format($row['events_30d'])) ?></td>
                            <td class="text-end">
                                <?php if ($row['events_30d'] > 0) { ?>
                                    <?= $this->Html->link(
                                        __('View activity'),
                                        ['controller' => 'AuditLogs', 'action' => 'index', '?' => ['source' => $row['alias']]],
                                        ['class' => 'btn btn-sm btn-link p-0'],
                                    ) ?>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <strong><?= __('Status legend') ?></strong>:
        <strong><?= __('Tracked') ?></strong> — <?= __('class exists, AuditLog behavior attached') ?>.
        <strong><?= __('Missing') ?></strong> — <?= __('class exists, behavior NOT attached (coverage gap)') ?>.
        <strong><?= __('Empirical') ?></strong> — <?= __('events recorded for a source we couldn\'t map to a class (custom event source, renamed table, plugin uninstalled)') ?>.
        <strong><?= __('Internal') ?></strong> — <?= __('hidden by deny-list (toggle above to include)') ?>.
    </div>
</div>
