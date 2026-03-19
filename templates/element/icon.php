<?php
/**
 * Icon element with fallbacks
 *
 * Supports Templating plugin's IconHelper when available,
 * falls back to Font Awesome HTML.
 *
 * @var \Cake\View\View $this
 * @var string $name Icon name
 * @var string|null $fallback Font Awesome class fallback (e.g., 'fas fa-list')
 * @var array $options Additional options for IconHelper
 * @var array $attributes HTML attributes
 */

$name = $name ?? '';
$fallback = $fallback ?? null;
$options = $options ?? [];
$attributes = $attributes ?? [];

// Try Templating plugin's Icon helper
if ($this->helpers()->has('Icon')) {
    echo $this->Icon->render($name, $options, $attributes);

    return;
}

// Font Awesome fallback mapping
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
