<?php
/**
 * @var \App\View\AppView $this
 * @var int $rowCount
 * @var int $hardCap
 * @var \Cake\I18n\DateTime|null $defaultFloor
 * @var array<string> $formats
 * @var array<string, mixed> $queryParams
 */

$this->assign('title', __('Export Audit Logs'));

// Filters that are already in effect (carried from the index page query string).
$activeFilters = array_filter([
    'source' => $queryParams['source'] ?? null,
    'user_id' => $queryParams['user_id'] ?? null,
    'type' => $queryParams['type'] ?? null,
    'transaction_key' => $queryParams['transaction_key'] ?? null,
    'primary_key' => $queryParams['primary_key'] ?? null,
    'date_from' => $queryParams['date_from'] ?? null,
    'date_to' => $queryParams['date_to'] ?? null,
], static fn ($v): bool => $v !== null && $v !== '');

$exceedsCap = $rowCount > $hardCap;
?>
<div class="container-fluid">
    <h2><?= __('Export Audit Logs') ?></h2>

    <p class="text-muted mb-4">
        <?= __('Pick a format and (optionally) narrow the date range. The export streams directly to your browser — large result sets are paginated server-side, but the hard cap of {0} rows applies. Narrow your filters if your set exceeds it.', number_format($hardCap)) ?>
    </p>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-4">
                <div class="card-header">
                    <strong><?= __('Result set') ?></strong>
                </div>
                <div class="card-body">
                    <?php if ($exceedsCap): ?>
                        <div class="alert alert-danger mb-3">
                            <?= __('Your filters match {0} rows, which exceeds the hard cap of {1}. Add a narrower date range, source, or user filter and reload this page.', number_format($rowCount), number_format($hardCap)) ?>
                        </div>
                    <?php elseif ($rowCount === 0): ?>
                        <div class="alert alert-warning mb-3">
                            <?= __('No rows match the current filters — nothing to export.') ?>
                        </div>
                    <?php else: ?>
                        <p class="mb-1"><strong><?= number_format($rowCount) ?></strong> <?= __('rows match the current filters.') ?></p>
                    <?php endif; ?>

                    <?php if ($defaultFloor !== null): ?>
                        <p class="text-muted small mb-0">
                            <?= __('No date range supplied — defaulting the lower bound to {0} (configurable via {1}).', '<code>' . h($defaultFloor->format('Y-m-d')) . '</code>', '<code>AuditStash.export.defaultDays</code>') ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <strong><?= __('Active filters') ?></strong>
                </div>
                <div class="card-body">
                    <?php if (!$activeFilters): ?>
                        <p class="text-muted mb-0"><?= __('No filters set. Use the index page filter panel to narrow the export, then click "Export" again.') ?></p>
                    <?php else: ?>
                        <dl class="row mb-0">
                            <?php foreach ($activeFilters as $name => $value): ?>
                                <dt class="col-sm-4 text-truncate"><?= h($name) ?></dt>
                                <dd class="col-sm-8 text-break"><code><?= h($value) ?></code></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>

            <?= $this->Form->create(null, [
                'type' => 'get',
                'url' => ['action' => 'export'] + (count($activeFilters) ? ['?' => $activeFilters] : []),
            ]) ?>
                <?php foreach ($activeFilters as $name => $value): ?>
                    <?= $this->Form->hidden($name, ['value' => $value]) ?>
                <?php endforeach; ?>

                <div class="mb-3">
                    <label class="form-label"><?= __('Format') ?></label>
                    <?php foreach ($formats as $option): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="format" id="format-<?= h($option) ?>" value="<?= h($option) ?>"<?= $option === 'csv' ? ' checked' : '' ?>>
                            <label class="form-check-label" for="format-<?= h($option) ?>"><?= h(strtoupper($option)) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">
                        <?= __('CSV for spreadsheet imports, JSON for one-shot machine reads, NDJSON (one JSON object per line) for streaming pipelines.') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"<?= $exceedsCap || $rowCount === 0 ? ' disabled' : '' ?>>
                    <?= __('Export {0} rows', number_format($rowCount)) ?>
                </button>
                <?= $this->Html->link(__('Back to browse'), ['action' => 'index'] + (count($activeFilters) ? ['?' => $activeFilters] : []), ['class' => 'btn btn-link']) ?>
            <?= $this->Form->end() ?>
        </div>

        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <strong><?= __('About this export') ?></strong>
                </div>
                <div class="card-body small text-muted">
                    <ul class="mb-0">
                        <li><?= __('Output is streamed — your browser starts receiving rows immediately, and the server uses constant memory regardless of result-set size.') ?></li>
                        <li><?= __('The hard cap of {0} rows is enforced server-side; if your filters match more, the request is rejected before any download begins.', number_format($hardCap)) ?></li>
                        <li><?= __('Without an explicit date range, the export defaults to the last {0} days. Set {1} or {2} on the index page to override.', (int)\Cake\Core\Configure::read('AuditStash.export.defaultDays', \AuditStash\Service\ExportService::DEFAULT_DAYS), '<code>date_from</code>', '<code>date_to</code>') ?></li>
                        <li><?= __('JSON-typed columns ({0}) are decoded into nested objects for JSON / NDJSON; CSV cells contain the raw JSON string.', '<code>original</code>, <code>changed</code>, <code>meta</code>') ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
