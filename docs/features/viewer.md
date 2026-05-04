# Audit Log Viewer

The plugin provides a built-in web interface to browse and search audit logs when using the `TablePersister`.

## Routes (Admin Prefix by Default)

The audit log viewer routes are **automatically enabled in the Admin prefix** when you load the plugin. No additional configuration needed!

Default routes are available at:
- **Browse logs**: `/admin/audit-logs`
- **View single log**: `/admin/audit-logs/view/{id}`
- **Record timeline**: `/admin/audit-logs/timeline/{table}/{recordId}`
- **Export form**: `/admin/audit-logs/export` — dedicated page with format picker (CSV / JSON / NDJSON), row-count estimate, and active-filter summary
- **Export streaming download**: `/admin/audit-logs/export.csv`, `.json`, or `.ndjson` — bypasses the form (used by the inline "Export CSV" quick button on the index page)

The routes are secured by being in the Admin prefix, which typically requires authentication in your application.

### Using Without Admin Prefix

If you don't use an Admin prefix or want the routes at a different path, disable the default routes and add your own:

```php
// In config/bootstrap.php or Application.php
$this->addPlugin('AuditStash', ['routes' => false]);
```

Then add your custom routes in `config/routes.php`:

```php
use Cake\Routing\RouteBuilder;

// Public routes (make sure to add authentication!)
$routes->plugin('AuditStash', ['path' => '/audit-logs'], function (RouteBuilder $routes) {
    $routes->connect('/', ['controller' => 'AuditLogs', 'action' => 'index']);
    $routes->connect('/view/{id}', ['controller' => 'AuditLogs', 'action' => 'view'])
        ->setPass(['id']);
    $routes->connect('/timeline/{source}/{primaryKey}', ['controller' => 'AuditLogs', 'action' => 'timeline'])
        ->setPass(['source', 'primaryKey']);
    $routes->connect('/export', ['controller' => 'AuditLogs', 'action' => 'export']);
});
```

### Loading the Helper

The AuditHelper is automatically available in your views. If needed, you can explicitly load it:

```php
// In your AppView.php
$this->loadHelper('AuditStash.Audit');

// Or in a controller
public function beforeRender(\Cake\Event\EventInterface $event)
{
    $this->viewBuilder()->addHelper('AuditStash.Audit');
}
```

### Configuring the Helper

The AuditHelper supports configuration options for customizing diff rendering:

```php
// In your AppView.php or controller
$this->loadHelper('AuditStash.Audit', [
    'differOptions' => [
        'context' => 3,              // Number of context lines around changes
        'ignoreCase' => false,       // Case-sensitive comparison
        'ignoreWhitespace' => false, // Whitespace-sensitive comparison
    ],
    'rendererOptions' => [
        'detailLevel' => 'word',     // 'word', 'char', or 'line'
        'showHeader' => false,       // Show diff header
        'lineNumbers' => true,       // Show line numbers
    ],
]);
```

#### Enhanced Diff Rendering

For enhanced word-level diff rendering, install the optional `jfcherng/php-diff` package:

```bash
composer require jfcherng/php-diff
```

When installed, the helper automatically uses this library for better diff output:
- **Word-level highlighting**: Shows which words changed within a line (not just characters)
- **Improved visual styling**: Better CSS for diff display
- **Configurable detail level**: Choose between word, character, or line-level diff

Without `jfcherng/php-diff`, the helper falls back to character-level diff using `sebastian/diff`.

### Linking User to Backend

By default, the user value in audit logs is displayed as plain text. You can configure it to link to your user management backend by setting `AuditStash.linkUser` in your app configuration.

**String Pattern:**

```php
// In config/app.php or config/app_local.php
'AuditStash' => [
    'linkUser' => '/admin/users/view/{user}',
],
```

Available placeholders: `{user}` (the linkable part, populated from `user_id`), `{display}` (the display name, from `user_display`).

**Callable (recommended for conditional linking):**

```php
// In config/app.php
'AuditStash' => [
    'linkUser' => function ($userId, $displayName) {
        // Only link numeric user IDs
        if (is_numeric($userId)) {
            return '/admin/users/view/' . $userId;
        }
        // Return null to display without link
        return null;
    },
],
```

**Array URL (CakePHP routing):**

```php
// In config/app.php
'AuditStash' => [
    'linkUser' => [
        'prefix' => 'Admin',
        'controller' => 'Users',
        'action' => 'view',
        '{user}',
    ],
],
```

#### Storing User ID and Display Name

`RequestMetadata` keeps the linkable ID and the human-readable display name in two separate constructor arguments. They land in the dedicated `user_id` and `user_display` columns and feed the `{user}` and `{display}` placeholders independently:

```php
// In your AppController
$identity = $this->getRequest()->getAttribute('identity');
EventManager::instance()->on(
    new RequestMetadata(
        request: $this->getRequest(),
        userId: $identity?->getIdentifier(),
        userDisplay: $identity?->get('username'),
    ),
);
```

With `linkUser` configured:

```php
'AuditStash' => [
    'linkUser' => '/admin/users/view/{user}', // {user} = userId, e.g. /admin/users/view/456
],
// Renders: <a href="/admin/users/view/456">Jane Smith</a>  (display = userDisplay)
```

`userDisplay` is optional — when omitted, the helper falls back to a `User #<id>` label for numeric / UUID ids or shows the raw `userId` for string identifiers (usernames, emails).

### Linking Records to Backend

By default, the record ID in audit logs is displayed as plain text. You can configure it to link to your record management backend by setting `AuditStash.linkRecord` in your app configuration.

**String Pattern:**

```php
// In config/app.php or config/app_local.php
'AuditStash' => [
    'linkRecord' => '/admin/{source}/view/{primary_key}',
],
```

Available placeholders: `{source}` (table name), `{primary_key}` (record ID), `{display}` (display value if provided).

**Callable (recommended for conditional linking or complex routing):**

```php
// In config/app.php
'AuditStash' => [
    'linkRecord' => function ($source, $primaryKey, $displayValue) {
        // Convert CamelCase table names to controller URLs
        $controller = Inflector::dasherize($source);
        return '/admin/' . $controller . '/view/' . $primaryKey;
    },
],
```

The callable receives three parameters:
- `$source` - The table/source name (e.g., 'Articles', 'Users')
- `$primaryKey` - The primary key value
- `$displayValue` - The display value if provided (falls back to primary key)

Return `null` from the callable to display the value without a link.

**Array URL (CakePHP routing):**

```php
// In config/app.php
'AuditStash' => [
    'linkRecord' => [
        'prefix' => 'Admin',
        'controller' => '{source}',
        'action' => 'view',
        '{primary_key}',
    ],
],
```

**Example: Conditional linking based on table:**

```php
'AuditStash' => [
    'linkRecord' => function ($source, $primaryKey, $displayValue) {
        // Only link certain tables
        $linkableTables = ['Articles', 'Users', 'Comments'];
        if (!in_array($source, $linkableTables)) {
            return null; // No link for other tables
        }

        return '/admin/' . Inflector::dasherize($source) . '/view/' . $primaryKey;
    },
],
```

**Example: Plugin-aware linking via CakePHP convention**

When some of your audited tables come from plugins, the `source` column can hold either `Plugin.Table` (when the table was loaded with the plugin prefix) or `Table` (when loaded via the bare alias — e.g. through associations from app code without an explicit `className`). See _Plugin Tables and the `source` Column_ in [the Usage guide](../guide/usage) for the full rationale.

A small callable resolves both forms via the standard CakePHP `prefix` / `plugin` / `controller` / `action` convention, no per-table mapping required:

```php
'AuditStash' => [
    'linkRecord' => function ($source, $primaryKey, $displayValue) {
        if (str_contains($source, '.')) {
            [$plugin, $table] = explode('.', $source, 2);

            return [
                'prefix' => 'Admin',
                'plugin' => $plugin,
                'controller' => $table,
                'action' => 'view',
                $primaryKey,
            ];
        }

        return [
            'prefix' => 'Admin',
            'plugin' => null,
            'controller' => $source,
            'action' => 'view',
            $primaryKey,
        ];
    },
],
```

This produces:

- `Articles` + `42` → `/admin/articles/view/42`
- `Comments.Comments` + `7` → `/admin/comments/comments/view/7`

It works for the convention case (`{Source}Controller::view($id)` under the Admin prefix). Tables that don't follow the convention — no controller, custom routes, resource-style URLs — need an explicit branch in the callable. Drop `'prefix' => 'Admin'` if your app doesn't use the admin prefix.

**Using with `formatRecord()` in templates:**

The `formatRecord()` helper method is used in the built-in audit log templates. You can also use it in your own templates:

```php
// Basic usage - displays primary key with optional link
<?= $this->Audit->formatRecord('Articles', 123) ?>
// Output (with linkRecord configured): <a href="/admin/articles/view/123">123</a>
// Output (without linkRecord): 123

// With display value - shows the display value but links using primary key
<?= $this->Audit->formatRecord('Articles', 123, 'My Article Title') ?>
// Output (with linkRecord configured): <a href="/admin/articles/view/123">My Article Title</a>
// Output (without linkRecord): My Article Title
```

## Features

The audit log viewer provides:

- **Browse & Search**: Filter audit logs by table, user, event type, transaction key, date range, and primary key
- **Detailed View**: View full details of any audit log entry with before/after comparison
- **Timeline View**: See the complete history of changes for a specific record in chronological order
- **Diff Display**: Human-readable before/after comparison with two display modes:
  - **Inline diff** (default): Compact, git-style unified diff with + and - indicators
  - **Side-by-side diff**: Traditional two-column comparison showing before and after values
  - Toggle between views with a single click in the detail view
- **Export**: Streaming download in CSV / JSON / NDJSON format. Pre-flights with a row-count check against `AuditStash.export.hardCap` (default 100 000) and refuses oversized exports rather than silently truncating. Defaults the date floor to the last `AuditStash.export.defaultDays` days (default 30) when no `date_from` / `date_to` is supplied. The dedicated `/admin/audit-logs/export` page shows the row-count estimate, active-filter summary, and format picker before the user commits to the download.
- **Metadata Display**: View all metadata associated with audit events (user, IP, URL, etc.)

## Coverage report

The coverage page at `/admin/audit-stash/audit-logs/coverage` walks every
Table class shipped by your app and loaded plugins, classifying each as
Tracked / Missing / Empirical (no `AuditLog` behavior, but audit rows exist
for it anyway). Operational/internal tables — sessions, migrations, the
plugin's own `audit_logs` table — would dilute the report, so two deny-list
keys filter them out by default:

```php
'AuditStash' => [
    'coverage' => [
        // Plugin names whose tables are always treated as internal.
        // Defaults baked in: AuditStash, Bouncer, Migrations, DebugKit.
        // Add to extend rather than replace.
        'hidePlugins' => ['Queue', 'Search'],

        // Specific Table aliases to hide regardless of plugin
        'hideTables'  => ['Sessions', 'PhinxLog'],
    ],
],
```

The "Include internal" toggle on the page bypasses both lists for one-off
audits.

## Dashboard auto-refresh

The dashboard page (`/admin/audit-stash`) can refresh itself on an interval
— useful for incident-response or war-room screens. Set
`AuditStash.dashboardAutoRefresh` to a number of seconds:

```php
'AuditStash' => [
    'dashboardAutoRefresh' => 30, // refresh every 30 seconds
],
```

The default `0` disables auto-refresh. Only the dashboard index uses this;
other admin pages don't auto-reload regardless of the value.

## Admin layout

`AuditStash.adminLayout` controls which CakePHP layout the admin pages
render inside:

| Value | Behavior |
|---|---|
| `null` (default) | Use the plugin's self-contained Bootstrap 5 layout (`AuditStash.audit_stash`). No dependency on host-app styling. |
| `false` | Disable the plugin layout entirely — render inside your app's default layout. |
| `string` | Use the named layout, e.g. `'Admin.default'` or `'MyTheme.admin'`, to integrate with an existing admin theme. |

```php
'AuditStash' => [
    'adminLayout' => 'Admin.default',
],
```

## Required: `AuditStash.adminAccess`

The plugin refuses to serve any admin action unless `AuditStash.adminAccess` is explicitly set to a `Closure` — same posture as `cakephp-queue` / `cakephp-databaselog`, because audit logs commonly contain sensitive who-did-what records (PII, IP addresses, before/after field values) and a forgotten host-side guard would expose more than a typical admin page.

The Closure receives the current request and must return literal `true` to grant access. Anything else (returns `false`, returns a truthy non-bool, throws, isn't a Closure, isn't set at all) yields a `403`.

```php
use Cake\Core\Configure;
use Cake\Http\ServerRequest;

Configure::write('AuditStash.adminAccess', function (ServerRequest $request): bool {
    $identity = $request->getAttribute('identity');
    return $identity !== null && $identity->role === 'super_admin';
});
```

To delegate the decision entirely to your host AppController / Authorization stack, pass an explicit `fn() => true` — that is the "I trust the upstream guard" knob:

```php
Configure::write('AuditStash.adminAccess', fn () => true);
```

The gate is checked in `beforeFilter` and calls `Authorization::skipAuthorization()` when the cakephp/authorization component is loaded, so the policy layer doesn't double-reject. Closures that throw `ForbiddenException` are passed through; other throwables are logged and converted to a generic `403`.

## Additional Security

The audit log viewer is in the Admin prefix by default, which provides a layer of security. The required `adminAccess` gate above is the canonical opt-in. Beyond that, you should ensure your Admin prefix is properly secured with authentication/authorization. Here are additional layered approaches:

### Use Authorization Plugin

```php
// In your Admin\Controller\AppController or src/Controller/AppController.php
use Authorization\Controller\Component\AuthorizationComponent;

public function initialize(): void
{
    parent::initialize();
    $this->loadComponent('Authorization.Authorization');
}

public function beforeFilter(\Cake\Event\EventInterface $event)
{
    parent::beforeFilter($event);

    // Require specific permission for audit logs
    if ($this->request->getParam('plugin') === 'AuditStash') {
        $this->Authorization->authorize('viewAuditLogs');
    }
}
```

### Role-Based Access Control

```php
// In your Admin\Controller\AppController
public function beforeFilter(\Cake\Event\EventInterface $event)
{
    parent::beforeFilter($event);

    // Restrict audit logs to super admins only
    if ($this->request->getParam('plugin') === 'AuditStash') {
        $user = $this->Authentication->getIdentity();
        if (!$user || $user->role !== 'super_admin') {
            throw new \Cake\Http\Exception\ForbiddenException('Access denied');
        }
    }
}
```

### Option 3: Create Custom AppController for Plugin

Create `src/Controller/Admin/AuditLogsController.php` in your app to override the plugin controller:

```php
<?php
namespace App\Controller\Admin;

use AuditStash\Controller\Admin\AuditLogsController as BaseAuditLogsController;

class AuditLogsController extends BaseAuditLogsController
{
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Add your custom authorization logic here
        if (!$this->Auth->user('can_view_audit_logs')) {
            throw new \Cake\Http\Exception\ForbiddenException('Insufficient permissions');
        }
    }
}
```

