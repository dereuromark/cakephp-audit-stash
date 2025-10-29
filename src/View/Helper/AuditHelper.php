<?php

declare(strict_types=1);

namespace AuditStash\View\Helper;

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

        $rows = '';
        $hasChanges = false;

        foreach ($allKeys as $key) {
            $oldValue = $original[$key] ?? null;
            $newValue = $changed[$key] ?? null;

            // Skip if values are identical
            if ($oldValue === $newValue) {
                continue;
            }

            $hasChanges = true;
            $rows .= '<tr>';
            $rows .= '<td><strong>' . h($key) . '</strong></td>';
            $rows .= '<td class="bg-danger bg-opacity-10">' . $this->formatValue($oldValue) . '</td>';
            $rows .= '<td class="bg-success bg-opacity-10">' . $this->formatValue($newValue) . '</td>';
            $rows .= '</tr>';
        }

        if (!$hasChanges) {
            return '<p class="text-muted">No changes</p>';
        }

        $html = '<table class="table table-sm table-bordered audit-diff">';
        $html .= '<thead><tr><th style="width: 20%;">Field</th><th style="width: 40%;">Before</th><th style="width: 40%;">After</th></tr></thead>';
        $html .= '<tbody>' . $rows . '</tbody></table>';

        return $html;
    }

    /**
     * Display an inline diff comparison (unified diff style)
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

        $rows = '';
        $hasChanges = false;

        foreach ($allKeys as $key) {
            $oldValue = $original[$key] ?? null;
            $newValue = $changed[$key] ?? null;

            // Skip if values are identical
            if ($oldValue === $newValue) {
                continue;
            }

            $hasChanges = true;
            $rows .= '<tr><td colspan="2" class="bg-light"><strong>' . h($key) . '</strong></td></tr>';

            // Show removed value (including when it's null)
            if (array_key_exists($key, $original)) {
                $rows .= '<tr>';
                $rows .= '<td class="text-end text-danger" style="width: 30px; border-right: 3px solid #dc3545;">−</td>';
                $rows .= '<td class="bg-danger bg-opacity-10">' . $this->formatValue($oldValue) . '</td>';
                $rows .= '</tr>';
            }

            // Show added value (including when it's null)
            if (array_key_exists($key, $changed)) {
                $rows .= '<tr>';
                $rows .= '<td class="text-end text-success" style="width: 30px; border-right: 3px solid #198754;">+</td>';
                $rows .= '<td class="bg-success bg-opacity-10">' . $this->formatValue($newValue) . '</td>';
                $rows .= '</tr>';
            }
        }

        if (!$hasChanges) {
            return '<p class="text-muted">No changes</p>';
        }

        $html = '<div class="audit-diff-inline">';
        $html .= '<table class="table table-sm table-bordered">';
        $html .= '<tbody>' . $rows . '</tbody></table>';
        $html .= '</div>';

        return $html;
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
            return '<div class="text-break">' . h($value) . '</div>';
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
}
