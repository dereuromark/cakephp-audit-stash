<?php
/**
 * Yes/No boolean badge element with fallbacks
 *
 * Supports Templating plugin helpers when available,
 * falls back to Bootstrap 5 badges.
 *
 * @var \Cake\View\View $this
 * @var bool $value Boolean value to display
 * @var array $options Additional options
 */

$value = $value ?? false;
$options = $options ?? [];

$yesLabel = $options['yesLabel'] ?? __('Yes');
$noLabel = $options['noLabel'] ?? __('No');

// Try Templating plugin's helper
if ($this->helpers()->has('Templating')) {
    echo $this->Templating->yesNo($value, $options);

    return;
}

// Try IconSnippet helper
if ($this->helpers()->has('IconSnippet')) {
    echo $this->IconSnippet->yesNo($value, $options);

    return;
}

// Try Format helper from Tools plugin
if ($this->helpers()->has('Format')) {
    echo $this->Format->yesNo($value, $options);

    return;
}

// Bootstrap 5 fallback
if ($value) {
    echo '<span class="badge bg-success">' . h($yesLabel) . '</span>';
} else {
    echo '<span class="badge bg-secondary">' . h($noLabel) . '</span>';
}
