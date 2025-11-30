# Audit Log Viewer

The plugin provides a built-in web interface to browse and search audit logs when using the `TablePersister`.

## Routes (Admin Prefix by Default)

The audit log viewer routes are **automatically enabled in the Admin prefix** when you load the plugin. No additional configuration needed!

Default routes are available at:
- **Browse logs**: `/admin/audit-logs`
- **View single log**: `/admin/audit-logs/view/{id}`
- **Record timeline**: `/admin/audit-logs/timeline/{table}/{recordId}`
- **Export**: `/admin/audit-logs/export.csv` or `/admin/audit-logs/export.json`

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

## Features

The audit log viewer provides:

- **Browse & Search**: Filter audit logs by table, user, event type, transaction ID, date range, and primary key
- **Detailed View**: View full details of any audit log entry with before/after comparison
- **Timeline View**: See the complete history of changes for a specific record in chronological order
- **Diff Display**: Human-readable before/after comparison with two display modes:
  - **Inline diff** (default): Compact, git-style unified diff with + and - indicators
  - **Side-by-side diff**: Traditional two-column comparison showing before and after values
  - Toggle between views with a single click in the detail view
- **Export**: Export filtered results to CSV or JSON format
- **Metadata Display**: View all metadata associated with audit events (user, IP, URL, etc.)

## Additional Security

The audit log viewer is in the Admin prefix by default, which provides a layer of security. However, you should ensure your Admin prefix is properly secured with authentication/authorization. Here are some additional approaches:

### Option 1: Use Authorization Plugin

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

### Option 2: Role-Based Access Control

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

Create `src/Controller/AuditLogsController.php` in your app to override the plugin controller:

```php
<?php
namespace App\Controller;

use AuditStash\Controller\Admin\AuditLogsController as BaseAuditLogsController;

class AuditLogsController extends BaseAuditLogsController
{
    public function initialize(): void
    {
        parent::initialize();

        $this->viewBuilder()->setPlugin('AuditStash');
        $this->viewBuilder()->setTemplatePath('Admin/AuditLogs');
    }

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

