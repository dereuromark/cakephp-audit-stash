<?php

declare(strict_types=1);

namespace AuditStash\Controller\Admin;

use Cake\Core\Plugin;
use Templating\View\Helper\IconSnippetHelper;
use Templating\View\Helper\TemplatingHelper;

/**
 * Trait for loading view helpers with graceful fallbacks.
 *
 * Detects available plugins and loads appropriate helpers:
 * - Tools plugin: Time, Text, Format helpers
 * - Shim plugin: Configure helper
 * - Templating plugin: IconSnippet, Templating helpers (for yes_no badges etc.)
 *
 * Note: The Icon helper is NOT loaded because the plugin layout uses Font Awesome
 * via CDN, and the Templating Icon helper uses different icon sets.
 */
trait LoadHelperTrait
{
    /**
     * Load helpers with fallbacks based on available plugins.
     *
     * @return void
     */
    protected function loadHelpers(): void
    {
        $helpers = [];

        // Time helper: prefer Tools, fallback to core
        if (Plugin::isLoaded('Tools')) {
            $helpers[] = 'Tools.Time';
            $helpers[] = 'Tools.Text';
            $helpers[] = 'Tools.Format';
        } else {
            $helpers[] = 'Time';
            $helpers[] = 'Text';
        }

        // Configure helper: prefer Shim
        if (Plugin::isLoaded('Shim')) {
            $helpers[] = 'Shim.Configure';
        }

        // Templating plugin helpers (for yes_no badges, etc.)
        // Note: Icon helper is not loaded - we use Font Awesome via CDN instead
        if (class_exists(IconSnippetHelper::class)) {
            $helpers[] = 'Templating.IconSnippet';
        }
        if (class_exists(TemplatingHelper::class)) {
            $helpers[] = 'Templating.Templating';
        }

        // Always load AuditStash helpers
        $helpers[] = 'AuditStash.Audit';

        $this->viewBuilder()->addHelpers($helpers);
    }
}
