<?php

declare(strict_types=1);

namespace AuditStash\View\Helper;

use AuditStash\Lib\DiffLib;
use Cake\Core\Configure;
use Cake\View\Helper;
use Jfcherng\Diff\DiffHelper;

/**
 * Audit helper for displaying audit log changes in a human-readable format
 *
 * Uses jfcherng/php-diff for word-level diff rendering if available,
 * falls back to DiffLib (sebastian/diff based) otherwise.
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
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'differOptions' => [
            'context' => 2,
            'ignoreCase' => false,
            'ignoreWhitespace' => false,
        ],
        'rendererOptions' => [
            'detailLevel' => 'word',
            'showHeader' => false,
            'lineNumbers' => true,
        ],
    ];

    /**
     * Whether jfcherng/php-diff is available.
     *
     * @var bool|null
     */
    protected ?bool $_hasJfcherng = null;

    /**
     * Check if jfcherng/php-diff library is available.
     *
     * @return bool
     */
    protected function hasJfcherngDiff(): bool
    {
        if ($this->_hasJfcherng === null) {
            $this->_hasJfcherng = class_exists(DiffHelper::class);
        }

        return $this->_hasJfcherng;
    }

    /**
     * Render diff using jfcherng/php-diff.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderJfcherngDiff(string $old, string $new, string $renderer): string
    {
        /** @var array<string, mixed> $differOptions */
        $differOptions = $this->getConfig('differOptions');
        /** @var array<string, mixed> $rendererOptions */
        $rendererOptions = $this->getConfig('rendererOptions');

        /** @phpstan-ignore class.notFound */
        return DiffHelper::calculate($old, $new, $renderer, $differOptions, $rendererOptions);
    }

    /**
     * Render diff using fallback DiffLib.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderFallbackDiff(string $old, string $new, string $renderer): string
    {
        $diffLib = new DiffLib();
        /** @var array<string, mixed> $differOptions */
        $differOptions = $this->getConfig('differOptions');
        $diffLib->contextLines = $differOptions['context'] ?? 3;

        if ($renderer === 'SideBySide') {
            return $diffLib->compareSideBySide($old, $new);
        }

        return $diffLib->compare($old, $new);
    }

    /**
     * Render a diff between two strings.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderDiff(string $old, string $new, string $renderer): string
    {
        // Check for whitespace-only changes first - use DiffLib for special rendering
        $diffLib = new DiffLib();
        if ($diffLib->isWhitespaceOnlyChange($old, $new)) {
            $result = $diffLib->renderWhitespaceChange($old, $new);
            if ($result !== null) {
                return $result;
            }
            // Fall through to regular diff if text too long
        }

        if ($this->hasJfcherngDiff()) {
            return $this->renderJfcherngDiff($old, $new, $renderer);
        }

        return $this->renderFallbackDiff($old, $new, $renderer);
    }

    /**
     * Display a diff comparison of original vs changed values (side-by-side)
     *
     * Uses word-level side-by-side diff for long text fields.
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

            // Use word-level side-by-side diff for long text
            $isLongText = strlen($oldStr) > 100 || strlen($newStr) > 100
                || str_contains($oldStr, "\n") || str_contains($newStr, "\n");

            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($key) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($isLongText) {
                $output .= '<div class="diff-wrapper">';
                $output .= $this->renderDiff($oldStr, $newStr, 'SideBySide');
                $output .= '</div>';
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
     * Uses word-level diff for long text fields.
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

            // Use word-level diff for long text
            $isLongText = strlen($oldStr) > 100 || strlen($newStr) > 100
                || str_contains($oldStr, "\n") || str_contains($newStr, "\n");

            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($key) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($isLongText) {
                $output .= '<div class="diff-wrapper">';
                $output .= $this->renderDiff($oldStr, $newStr, 'Inline');
                $output .= '</div>';
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
     * Format user for display, optionally linking to user record.
     *
     * Supports compound format "id:displayName" for storing both linkable ID and display name.
     * Configure separator via `AuditStash.userSeparator` (default: ':').
     *
     * Configure linking via `AuditStash.linkUser`:
     * - String pattern: '/admin/users/view/{user}' - placeholders: {user}, {display}, {raw}
     * - Callable: function($user, $displayName, $raw) { return '/admin/users/view/' . $user; }
     * - Array URL: ['prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}']
     *
     * @param string|int|null $user User identifier (ID, "id:name", username, email, etc.)
     *
     * @return string HTML output
     */
    public function formatUser(string|int|null $user): string
    {
        if ($user === null || $user === '') {
            return '<em class="text-muted">N/A</em>';
        }

        $separator = Configure::read('AuditStash.userSeparator') ?? ':';
        $parsed = $this->parseUserValue((string)$user, $separator);

        $linkConfig = Configure::read('AuditStash.linkUser');
        if ($linkConfig) {
            $url = $this->buildUserUrl($linkConfig, $parsed['id'], $parsed['display'], (string)$user);
            if ($url) {
                return $this->Html->link(h($parsed['display']), $url);
            }
        }

        return h($parsed['display']);
    }

    /**
     * Parse user value into ID and display name.
     *
     * Supports compound format "id:displayName" where the first part before separator
     * is used for linking (ID) and the second part for display.
     *
     * For bare IDs (no separator), displays as "User #id" for UUIDs or numeric IDs,
     * or the raw value if it looks like a username/email.
     *
     * @param string $user Raw user value
     * @param string $separator Separator character (default ':')
     *
     * @return array{id: string, display: string} Parsed values
     */
    protected function parseUserValue(string $user, string $separator): array
    {
        if ($separator !== '' && str_contains($user, $separator)) {
            $parts = explode($separator, $user, 2);

            return [
                'id' => $parts[0],
                'display' => $parts[1] ?? $parts[0],
            ];
        }

        // For bare values, check if it looks like an ID (UUID or numeric)
        $isUuid = (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user);
        $isNumeric = ctype_digit($user);

        if ($isUuid || $isNumeric) {
            return [
                'id' => $user,
                'display' => __('User #{0}', $user),
            ];
        }

        // Looks like a username/email, use as-is
        return [
            'id' => $user,
            'display' => $user,
        ];
    }

    /**
     * Build URL for user link.
     *
     * @param callable|array|string $linkConfig Link configuration
     * @param string $id User ID (for linking)
     * @param string $displayName Display name
     * @param string $raw Raw user value
     *
     * @return array|string|null URL or null if no link
     */
    protected function buildUserUrl(
        callable|string|array $linkConfig,
        string $id,
        string $displayName,
        string $raw,
    ): string|array|null {
        if (is_callable($linkConfig)) {
            return $linkConfig($id, $displayName, $raw);
        }

        $replacements = [
            '{user}' => $id,
            '{display}' => $displayName,
            '{raw}' => $raw,
        ];

        if (is_string($linkConfig)) {
            return str_replace(array_keys($replacements), array_values($replacements), $linkConfig);
        }

        // Replace placeholders in array values
        return array_map(function ($value) use ($replacements) {
            if (is_string($value) && isset($replacements[$value])) {
                return $replacements[$value];
            }

            return $value;
        }, $linkConfig);
    }

    /**
     * Format record for display, optionally linking to the record.
     *
     * Configure linking via `AuditStash.linkRecord`:
     * - String pattern: '/admin/{source}/view/{primary_key}' - placeholders: {source}, {primary_key}, {display}
     * - Callable: function($source, $primaryKey, $displayValue) { return ['controller' => $source, 'action' => 'view', $primaryKey]; }
     * - Array URL: ['prefix' => 'Admin', 'controller' => '{source}', 'action' => 'view', '{primary_key}']
     *
     * @param string $source Table/source name (e.g., 'Articles', 'Users')
     * @param string|int $primaryKey Primary key value
     * @param string|null $displayValue Optional display value (shown instead of primary key)
     *
     * @return string HTML output
     */
    public function formatRecord(string $source, string|int $primaryKey, ?string $displayValue = null): string
    {
        $display = $displayValue ?? (string)$primaryKey;

        $linkConfig = Configure::read('AuditStash.linkRecord');
        if ($linkConfig) {
            $url = $this->buildRecordUrl($linkConfig, $source, (string)$primaryKey, $display);
            if ($url) {
                return $this->Html->link(h($display), $url);
            }
        }

        return h($display);
    }

    /**
     * Build URL for record link.
     *
     * @param callable|array|string $linkConfig Link configuration
     * @param string $source Table/source name
     * @param string $primaryKey Primary key value
     * @param string $displayValue Display value
     *
     * @return array|string|null URL or null if no link
     */
    protected function buildRecordUrl(
        callable|string|array $linkConfig,
        string $source,
        string $primaryKey,
        string $displayValue,
    ): string|array|null {
        if (is_callable($linkConfig)) {
            return $linkConfig($source, $primaryKey, $displayValue);
        }

        $replacements = [
            '{source}' => $source,
            '{primary_key}' => $primaryKey,
            '{display}' => $displayValue,
        ];

        if (is_string($linkConfig)) {
            return str_replace(array_keys($replacements), array_values($replacements), $linkConfig);
        }

        // Replace placeholders in array values
        return array_map(function ($value) use ($replacements) {
            if (is_string($value) && isset($replacements[$value])) {
                return $replacements[$value];
            }

            return $value;
        }, $linkConfig);
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
     * Includes jfcherng/php-diff built-in styles (if available) plus custom overrides.
     *
     * @return string CSS style block
     */
    public function diffStyles(): string
    {
        $libraryStyles = '';
        if ($this->hasJfcherngDiff()) {
            /** @phpstan-ignore class.notFound */
            $libraryStyles = DiffHelper::getStyleSheet();
        }

        $customStyles = <<<'CSS'
.audit-diff, .audit-diff-inline { font-size: 0.9rem; }
.audit-diff td, .audit-diff-inline td { vertical-align: top; }
pre { background-color: #f8f9fa; padding: 0.5rem; border-radius: 0.25rem; }
.diff-wrapper { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 13px; }
.diff-wrapper th, .diff-wrapper td { padding: 4px 8px; border: 1px solid #dee2e6; vertical-align: top; }
.diff-wrapper .line-num { width: 40px; background: #f8f9fa; color: #6c757d; text-align: right; user-select: none; }
.diff-wrapper .sign { width: 20px; text-align: center; font-weight: bold; }
.diff-wrapper tr.unchanged td { background: #f8f9fa; }
.diff-wrapper tr.added td { background: #e6ffec; }
.diff-wrapper tr.removed td { background: #ffebe9; }
.diff-wrapper tr.changed td { background: #fef6d9; }
.diff-wrapper tr.separator td { background: #f0f0f0; font-style: italic; }
.diff-wrapper ins { background: #94f094; text-decoration: none; padding: 1px 2px; font-weight: bold; }
.diff-wrapper del { background: #f09494; text-decoration: none; padding: 1px 2px; font-weight: bold; }
.diff-wrapper .old { background-color: #ffebe9; }
.diff-wrapper .new { background-color: #e6ffec; }
/* Side-by-side specific */
.diff-side-by-side th:nth-child(2), .diff-side-by-side td:nth-child(2) { width: 45%; }
.diff-side-by-side th:nth-child(4), .diff-side-by-side td:nth-child(4) { width: 45%; }
/* Whitespace-only changes */
.diff-wrapper .empty-line { font-weight: bold; }
.diff-whitespace-change ins.empty-line { background-color: #acf2bd; color: #155724; text-decoration: none; padding: 1px 3px; border-radius: 2px; }
.diff-whitespace-change del.empty-line { background-color: #fdb8c0; color: #721c24; text-decoration: line-through; padding: 1px 3px; border-radius: 2px; }
CSS;

        return '<style>' . $libraryStyles . "\n" . $customStyles . '</style>';
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
