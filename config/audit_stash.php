<?php
/**
 * AuditStash Plugin Configuration
 *
 * This file contains configuration options for the AuditStash plugin.
 */

return [
    'AuditStash' => [
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
