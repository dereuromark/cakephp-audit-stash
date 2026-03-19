<?php
/**
 * Icon element with fallbacks
 *
 * Uses Font Awesome icons (loaded via CDN in the plugin layout).
 * The Templating plugin's Icon helper uses different icon sets (Bootstrap Icons, etc.)
 * which may not have Font Awesome icon names, so we use Font Awesome directly.
 *
 * @var \Cake\View\View $this
 * @var string $name Icon name
 * @var string|null $fallback Font Awesome class fallback (e.g., 'fas fa-list')
 * @var array $attributes HTML attributes
 */

$name = $name ?? '';
$fallback = $fallback ?? null;
$attributes = $attributes ?? [];

// Font Awesome icon mapping
$fontAwesomeMap = [
    'list' => 'fas fa-list',
    'layer-group' => 'fas fa-layer-group',
    'plus-circle' => 'fas fa-plus-circle',
    'edit' => 'fas fa-edit',
    'trash' => 'fas fa-trash',
    'file-csv' => 'fas fa-file-csv',
    'file-code' => 'fas fa-file-code',
    'check-circle' => 'fas fa-check-circle',
    'exclamation-circle' => 'fas fa-exclamation-circle',
    'exclamation-triangle' => 'fas fa-exclamation-triangle',
    'info-circle' => 'fas fa-info-circle',
    'eye' => 'fas fa-eye',
    'history' => 'fas fa-history',
    'undo' => 'fas fa-undo',
    'clock' => 'fas fa-clock',
    'user' => 'fas fa-user',
    'database' => 'fas fa-database',
];

$iconClass = $fallback;
if (!$iconClass && isset($fontAwesomeMap[$name])) {
    $iconClass = $fontAwesomeMap[$name];
}

if ($iconClass) {
    $attrString = '';
    foreach ($attributes as $key => $value) {
        $attrString .= ' ' . h($key) . '="' . h($value) . '"';
    }
    echo '<i class="' . h($iconClass) . ' me-2"' . $attrString . '></i>';
} else {
    // Last resort: text label
    echo '[' . h($name) . ']';
}
