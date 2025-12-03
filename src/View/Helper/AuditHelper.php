<?php

declare(strict_types=1);

namespace AuditStash\View\Helper;

use AuditStash\Lib\DiffLib;
use Cake\View\Helper;

/**
 * Audit helper for displaying audit log changes in a human-readable format
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class AuditHelper extends Helper
{
 /**
  * Helpers to load
  *
  * @var array
  */
    protected array $helpers = ['Html'];

    /**
     * Display a diff comparison of original vs changed values (side-by-side)
     *
     * Uses character-level side-by-side diff for long text fields.
     *
     * @param string|null $originalJson JSON string of original values
     * @param string|null $changedJson JSON string of changed values
     *
     * @return string HTML output
     */
    public function diff(?string $originalJson, ?string $changedJson): string
    {
        $original = $originalJson ? json_decode($originalJson, true) : [];
        $changed = $changedJson ? json_decode($changedJson, true) : [];

        if (!$original && !$changed) {
            return '<p class="text-muted">No changes</p>';
        }

        $allKeys = array_unique(array_merge(array_keys($original ?: []), array_keys($changed ?: [])));
        sort($allKeys);

        $output = '';
        $hasChanges = false;

        $diffLib = new DiffLib();
        $diffLib->contextLines = 2;

        foreach ($allKeys as $key) {
            $oldValue = $original[$key] ?? null;
            $newValue = $changed[$key] ?? null;

            // Skip if values are identical
            if ($oldValue === $newValue) {
                continue;
            }

            $hasChanges = true;

            $oldStr = $this->valueToString($oldValue);
            $newStr = $this->valueToString($newValue);

            // Use character-level side-by-side diff for long text
            $isLongText = strlen($oldStr) > 100 || strlen($newStr) > 100
                || str_contains($oldStr, "\n") || str_contains($newStr, "\n");

            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($key) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($isLongText) {
                $output .= $diffLib->compareSideBySide($oldStr, $newStr);
            } else {
                $output .= '<div class="row">';
                $output .= '<div class="col-md-6">';
                $output .= '<span class="text-muted">Current:</span>';
                $output .= '<div class="p-2 bg-light border rounded"><del>' . $this->formatValue($oldValue) . '</del></div>';
                $output .= '</div>';
                $output .= '<div class="col-md-6">';
                $output .= '<span class="text-muted">Changed:</span>';
                $output .= '<div class="p-2 bg-warning border rounded"><ins><strong>' . $this->formatValue($newValue) . '</strong></ins></div>';
                $output .= '</div>';
                $output .= '</div>';
            }

            $output .= '</div></div>';
        }

        if (!$hasChanges) {
            return '<p class="text-muted">No changes</p>';
        }

        return '<div class="audit-diff">' . $output . '</div>';
    }

    /**
     * Display an inline diff comparison (unified diff style)
     *
     * Uses character-level diff for long text fields.
     *
     * @param string|null $originalJson JSON string of original values
     * @param string|null $changedJson JSON string of changed values
     *
     * @return string HTML output
     */
    public function diffInline(?string $originalJson, ?string $changedJson): string
    {
        $original = $originalJson ? json_decode($originalJson, true) : [];
        $changed = $changedJson ? json_decode($changedJson, true) : [];

        if (!$original && !$changed) {
            return '<p class="text-muted">No changes</p>';
        }

        $allKeys = array_unique(array_merge(array_keys($original ?: []), array_keys($changed ?: [])));
        sort($allKeys);

        $output = '';
        $hasChanges = false;

        $diffLib = new DiffLib();
        $diffLib->contextLines = 2;

        foreach ($allKeys as $key) {
            $oldValue = $original[$key] ?? null;
            $newValue = $changed[$key] ?? null;

            // Skip if values are identical
            if ($oldValue === $newValue) {
                continue;
            }

            $hasChanges = true;

            $oldStr = $this->valueToString($oldValue);
            $newStr = $this->valueToString($newValue);

            // Use character-level diff for long text
            $isLongText = strlen($oldStr) > 100 || strlen($newStr) > 100
                || str_contains($oldStr, "\n") || str_contains($newStr, "\n");

            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($key) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($isLongText) {
                $output .= $diffLib->compare($oldStr, $newStr);
            } else {
                $output .= '<table class="table table-sm table-bordered mb-0">';
                $output .= '<tbody>';

                // Show removed value
                if (array_key_exists($key, $original)) {
                    $output .= '<tr>';
                    $output .= '<td class="text-end text-danger" style="width: 30px; border-right: 3px solid #dc3545;">−</td>';
                    $output .= '<td class="bg-danger bg-opacity-10">' . $this->formatValue($oldValue) . '</td>';
                    $output .= '</tr>';
                }

                // Show added value
                if (array_key_exists($key, $changed)) {
                    $output .= '<tr>';
                    $output .= '<td class="text-end text-success" style="width: 30px; border-right: 3px solid #198754;">+</td>';
                    $output .= '<td class="bg-success bg-opacity-10">' . $this->formatValue($newValue) . '</td>';
                    $output .= '</tr>';
                }

                $output .= '</tbody></table>';
            }

            $output .= '</div></div>';
        }

        if (!$hasChanges) {
            return '<p class="text-muted">No changes</p>';
        }

        return '<div class="audit-diff-inline">' . $output . '</div>';
    }

    /**
     * Convert a value to string for comparison.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function valueToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT) ?: '';
        }

        return (string)$value;
    }

    /**
     * Format a value for display
     *
     * @param mixed $value Value to format
     *
     * @return string Formatted value
     */
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '<em class="text-muted">null</em>';
        }

        if (is_bool($value)) {
            return $value ? '<span class="badge bg-success">true</span>' : '<span class="badge bg-secondary">false</span>';
        }

        if (is_array($value)) {
            return '<pre class="mb-0"><code>' . h(json_encode($value, JSON_PRETTY_PRINT)) . '</code></pre>';
        }

        if (is_string($value) && strlen($value) > 100) {
            return '<div class="text-break">' . nl2br(h($value)) . '</div>';
        }

        return h((string)$value);
    }

    /**
     * Display event type badge
     *
     * @param string $type Event type (create, update, delete, revert)
     *
     * @return string HTML badge
     */
    public function eventTypeBadge(string $type): string
    {
        $badges = [
            'create' => '<span class="badge bg-success">Create</span>',
            'update' => '<span class="badge bg-primary">Update</span>',
            'delete' => '<span class="badge bg-danger">Delete</span>',
            'revert' => '<span class="badge bg-warning">Revert</span>',
        ];

        return $badges[$type] ?? '<span class="badge bg-secondary">' . h($type) . '</span>';
    }

    /**
     * Format transaction ID as a short code
     *
     * @param string $transaction Transaction ID
     * @param bool $full Whether to show full transaction ID
     *
     * @return string Formatted transaction ID
     */
    public function transactionId(string $transaction, bool $full = false): string
    {
        if ($full) {
            return '<code class="small">' . h($transaction) . '</code>';
        }

        return '<code class="small">' . h(substr($transaction, 0, 8)) . '...</code>';
    }

    /**
     * Display a summary of changes
     *
     * @param string|null $changedJson JSON string of changed values
     *
     * @return string Summary text
     */
    public function changeSummary(?string $changedJson): string
    {
        $changed = $changedJson ? json_decode($changedJson, true) : [];

        if (!$changed) {
            return 'No changes';
        }

        $count = count($changed);
        $fields = array_keys($changed);

        if ($count <= 3) {
            return $count . ' field(s): ' . implode(', ', array_map('h', $fields));
        }

        return $count . ' field(s): ' . implode(', ', array_map('h', array_slice($fields, 0, 3))) . ', ...';
    }

    /**
     * Render revert button
     *
     * @param int $auditLogId Audit log ID
     * @param array $options HTML options
     *
     * @return string HTML button
     */
    public function revertButton(int $auditLogId, array $options = []): string
    {
        $options += ['class' => 'btn btn-sm btn-warning'];

        return $this->Html->link(
            __('Revert'),
            ['action' => 'revertPreview', $auditLogId],
            $options,
        );
    }

    /**
     * Render restore button for deleted records
     *
     * @param string $source Table name
     * @param string|int $primaryKey Primary key
     * @param array $options HTML options
     *
     * @return string HTML button
     */
    public function restoreButton(string $source, int|string $primaryKey, array $options = []): string
    {
        $options += ['class' => 'btn btn-sm btn-success'];

        return $this->Html->link(
            __('Restore'),
            ['action' => 'restore', $source, $primaryKey],
            $options,
        );
    }

    /**
     * Render metadata table from JSON string.
     *
     * @param string|null $metaJson JSON string of metadata
     *
     * @return string HTML output
     */
    public function metadata(?string $metaJson): string
    {
        if (!$metaJson) {
            return '<p class="text-muted">' . __('No metadata available') . '</p>';
        }

        $meta = json_decode($metaJson, true);
        if (!$meta || !is_array($meta)) {
            return '<p class="text-muted">' . __('No metadata available') . '</p>';
        }

        $rows = '';
        foreach ($meta as $key => $value) {
            $rows .= '<tr>';
            $rows .= '<th style="width: 40%;">' . h($key) . '</th>';
            $rows .= '<td>' . $this->formatValue($value) . '</td>';
            $rows .= '</tr>';
        }

        return '<table class="table table-sm">' . $rows . '</table>';
    }

    /**
     * Render a table of field values from JSON string.
     *
     * Used for displaying created or deleted record data.
     *
     * @param string|null $dataJson JSON string of field values
     * @param string|null $title Optional title to display above the table
     *
     * @return string HTML output
     */
    public function fieldValuesTable(?string $dataJson, ?string $title = null): string
    {
        if (!$dataJson) {
            return '<p class="text-muted">' . __('No data available') . '</p>';
        }

        $data = json_decode($dataJson, true);
        if (!$data || !is_array($data)) {
            return '<p class="text-muted">' . __('No data available') . '</p>';
        }

        $output = '';
        if ($title) {
            $output .= '<h6>' . h($title) . '</h6>';
        }

        $output .= '<table class="table table-sm table-bordered">';
        $output .= '<thead><tr><th style="width: 30%;">' . __('Field') . '</th><th>' . __('Value') . '</th></tr></thead>';
        $output .= '<tbody>';

        foreach ($data as $field => $value) {
            $output .= '<tr>';
            $output .= '<td><strong>' . h($field) . '</strong></td>';
            $output .= '<td>' . $this->formatValue($value) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody></table>';

        return $output;
    }

    /**
     * Output CSS styles for diff display.
     *
     * Call this once in your template to include the required styles.
     *
     * @return string CSS style block
     */
    public function diffStyles(): string
    {
        return <<<'CSS'
<style>
.audit-diff, .audit-diff-inline { font-size: 0.9rem; }
.audit-diff td, .audit-diff-inline td { vertical-align: top; }
pre { background-color: #f8f9fa; padding: 0.5rem; border-radius: 0.25rem; }
.diff-wrapper { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 13px; }
.diff-wrapper th, .diff-wrapper td { padding: 4px 8px; border: 1px solid #dee2e6; vertical-align: top; }
.diff-wrapper .line-num { width: 40px; background: #f8f9fa; color: #6c757d; text-align: right; }
.diff-wrapper .sign { width: 20px; text-align: center; font-weight: bold; }
.diff-wrapper tr.unchanged td { background: #fff; }
.diff-wrapper tr.added td { background: #d4edda; }
.diff-wrapper tr.removed td { background: #f8d7da; }
.diff-wrapper tr.separator td { background: #f8f9fa; font-style: italic; }
.diff-wrapper ins { background: #c3e6cb; text-decoration: none; padding: 1px 2px; }
.diff-wrapper del { background: #f5c6cb; text-decoration: line-through; padding: 1px 2px; }
.diff-side-by-side th:nth-child(2), .diff-side-by-side td:nth-child(2) { width: 45%; }
.diff-side-by-side th:nth-child(4), .diff-side-by-side td:nth-child(4) { width: 45%; }
.diff-side-by-side tr.changed td:nth-child(2) { background: #f8d7da; }
.diff-side-by-side tr.changed td:nth-child(4) { background: #d4edda; }
</style>
CSS;
    }

    /**
     * Output JavaScript for diff view toggle.
     *
     * Call this once in your template to include the toggle functionality.
     *
     * @return string JavaScript block
     */
    public function diffScript(): string
    {
        return <<<'JS'
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
JS;
    }
}
