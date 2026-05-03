<?php
/**
 * AuditStash Plugin Configuration
 *
 * This file contains configuration options for the AuditStash plugin.
 */

return [
    'AuditStash' => [
        /**
         * Admin route path
         *
         * The path segment mounted under the Admin prefix for the plugin's
         * admin UI. Defaults to '/audit-stash'. Change to '/audit-logs' to
         * retain the pre-dashboard URLs after upgrading.
         */
        'routePath' => '/audit-stash',

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
         * Admin access gate (optional, defense-in-depth).
         *
         * Unset = no-op; the host AppController's auth is the only gate.
         * Set to a Closure that receives the current request and returns
         * literal true to grant access; anything else (non-Closure, returns
         * false, returns a truthy non-bool, or throws) yields a 403.
         *
         * Particularly relevant here because audit logs commonly contain
         * sensitive who-did-what records — PII, IP addresses, before/after
         * field values for every change.
         *
         * Example — restrict to super_admin role on the cakephp/authentication
         * identity:
         */
        // 'accessCheck' => function (\Cake\Http\ServerRequest $request): bool {
        //     $identity = $request->getAttribute('identity');
        //     return $identity !== null && $identity->role === 'super_admin';
        // },

        /**
         * Dashboard Auto-Refresh
         *
         * Auto-refresh interval in seconds for the dashboard entry page.
         * Set to 0 to disable auto-refresh.
         */
        'dashboardAutoRefresh' => 0,

        /**
         * Coverage page configuration
         *
         * The coverage report at /admin/audit-stash/coverage walks every
         * Table class shipped by the app and loaded plugins, classifying
         * each as Tracked / Missing / Empirical. Operational/internal
         * tables are filtered out of the default view via this deny-list:
         *
         * - `hidePlugins`: plugin names whose Tables are always considered
         *   internal. Defaults baked in: AuditStash, Bouncer, Migrations, DebugKit.
         *   Add to extend.
         * - `hideTables`: specific aliases (e.g. 'Sessions') to hide
         *   regardless of plugin.
         *
         * The "Include internal" toggle on the page bypasses both lists.
         */
        'coverage' => [
            'hidePlugins' => [],
            'hideTables' => [],
        ],

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
         * Persister class (FQCN).
         *
         * Defaults to TablePersister (database). For Elasticsearch, set:
         *   'persister' => \AuditStash\Persister\ElasticSearchPersister::class,
         *
         * Note: the `Audit::log()` static facade always uses TablePersister;
         * to direct custom events at a different persister, call
         * `Audit::setPersister()` explicitly during bootstrap.
         */
        // 'persister' => \AuditStash\Persister\TablePersister::class,

        /**
         * Persister-specific options passed to the persister's setConfig().
         *
         * The default persister is TablePersister (database).
         */
        'persisterConfig' => [
            /**
             * Tamper-Evidence Hash Chain
             *
             * When enabled, every audit row is linked into a SHA-256 hash chain,
             * making it possible to detect any later modification or deletion of
             * historic rows. Requires the AddHashChainToAuditLogs migration.
             * Only supported by TablePersister.
             *
             * See docs/tamper-evidence.md for rationale and verification workflow.
             */
            'hashChain' => false,
        ],

        /**
         * Save timing.
         *
         * - null/unset (default): persist after the transaction commits
         *   (`Model.afterSaveCommit`/`afterDeleteCommit`). Audit rows reflect
         *   what actually committed; data lost during a rollback isn't logged.
         * - 'afterSave': persist immediately after `Model.afterSave`/
         *   `afterDelete`, before the transaction commits. Useful when you
         *   need the audit row available to other code in the same request,
         *   at the cost of logging changes that may later roll back.
         */
        // 'saveType' => 'afterSave',

        /**
         * Retention / cleanup policy.
         *
         * Used by `bin/cake audit_stash cleanup`. Without `--days` on the
         * command line, the cleanup walks every entry in `tables` and falls
         * back to `default` for tables without a per-table override. A `null`
         * default means no global retention (per-table only).
         *
         * `tables` keys are source identifiers (Table aliases, e.g. 'Articles').
         * Values are integer days.
         */
        'retention' => [
            'default' => null, // e.g. 365
            'tables' => [
                // 'Sessions' => 30,
                // 'Articles' => 730,
            ],
        ],

        /**
         * Real-time monitoring & alerting.
         *
         * The plugin ships a passive `AuditMonitor` that listens to persisted
         * events and dispatches alerts through configured channels when one or
         * more rules match. Off by default.
         *
         * - `enabled`: master switch.
         * - `rules`: list of rule definitions. Each entry is either an FQCN or
         *   `['class' => ..., 'options' => [...]]` for parameterized rules.
         * - `channels`: list of channel definitions in the same shape. All
         *   matched alerts are dispatched to every configured channel.
         *
         * See docs/monitoring.md for the full rule/channel catalogue.
         */
        'monitor' => [
            'enabled' => false,
            'rules' => [
                // \AuditStash\Monitor\Rule\MassDeleteRule::class,
                // [
                //     'class' => \AuditStash\Monitor\Rule\UnusualTimeActivityRule::class,
                //     'options' => ['startHour' => 22, 'endHour' => 6],
                // ],
            ],
            'channels' => [
                // \AuditStash\Monitor\Channel\LogChannel::class,
                // [
                //     'class' => \AuditStash\Monitor\Channel\EmailChannel::class,
                //     'options' => ['to' => 'security@example.com'],
                // ],
            ],
        ],

        /**
         * GDPR / right-to-be-forgotten configuration.
         *
         * Used by `GdprService` and `bin/cake audit_stash gdpr`.
         *
         * - `anonymizeUserId`: strategy for the `user_id` column when a user
         *   is anonymized. Accepts 'hash' (SHA-256, irreversible but joinable
         *   across rows for the same user), 'null' (clear the column), or
         *   'placeholder' (replace with a fixed sentinel like 'ANONYMIZED').
         * - `anonymizeFields`: meta-field overrides merged on top of the
         *   service's defaults (user, username, email, ip, user_agent).
         *   Keys are meta keys, values are the replacement value.
         * - `piiFields`: list of column names treated as PII when redacting
         *   `original` / `changed` payloads on anonymization.
         */
        'gdpr' => [
            'anonymizeUserId' => 'hash',
            'anonymizeFields' => [
                // 'phone' => 'ANONYMIZED',
            ],
            'piiFields' => [
                'email',
                'name',
                'first_name',
                'last_name',
                'phone',
                'address',
                'ip_address',
                'username',
            ],
        ],

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
