<?php
/**
 * AuditStash Plugin Configuration
 *
 * This file contains configuration options for the AuditStash plugin.
 */

return [
    'AuditStash' => [
        /**
         * Admin Layout Configuration
         *
         * Controls which layout is used for the admin interface:
         * - null (default): Uses the plugin's isolated Bootstrap 5 layout ('AuditStash.audit_stash')
         *   This is a self-contained layout that doesn't depend on the host application's styling.
         * - false: Disables the plugin layout, uses the app's default layout.
         *   Use this when you want to integrate with your existing admin theme.
         * - string: Uses the specified layout (e.g., 'Admin.default', 'MyTheme.admin')
         */
        'adminLayout' => null,

        /**
         * Standalone Mode
         *
         * When enabled, the admin interface operates independently without
         * inheriting from AppController's authentication/authorization.
         * Useful for quick setup or when using separate admin authentication.
         */
        'standalone' => false,

        /**
         * Dashboard Auto-Refresh
         *
         * Auto-refresh interval in seconds for the index page.
         * Set to 0 to disable auto-refresh.
         */
        'dashboardAutoRefresh' => 0,

        /**
         * User Link Configuration
         *
         * Configure how user IDs are linked in the audit log display.
         * - String pattern: '/admin/users/view/{user}' (placeholders: {user}, {display})
         * - Array URL: ['prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}']
         * - Callable: function($userId, $userDisplay) { return '/admin/users/' . $userId; }
         * - null: No linking (default)
         */
        'linkUser' => null,

        /**
         * Record Link Configuration
         *
         * Configure how record IDs are linked in the audit log display.
         * - String pattern: '/admin/{source}/view/{primary_key}'
         * - Array URL: ['prefix' => 'Admin', 'controller' => '{source}', 'action' => 'view', '{primary_key}']
         * - Callable: function($source, $primaryKey, $displayValue) { return [...]; }
         * - null: No linking (default)
         */
        'linkRecord' => null,

        /**
         * Revert & Restore Configuration
         */
        'revert' => [
            /**
             * Enable or disable revert/restore functionality
             *
             * When set to false, the revert and restore actions will not be available.
             */
            'enabled' => true,

            /**
             * Create audit entries when reverting or restoring
             *
             * When enabled, each revert or restore operation will create a new audit log
             * entry with type 'revert' to track the change history.
             */
            'auditReverts' => true,
        ],
    ],
];
