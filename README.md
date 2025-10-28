# AuditStash Plugin For CakePHP

[![Build Status](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml/badge.svg)](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml)
[![Coverage Status](https://img.shields.io/codecov/c/github/dereuromark/cakephp-audit-stash/master.svg?style=flat-square)](https://codecov.io/github/dereuromark/cakephp-audit-stash)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

This plugin implements an "audit trail" for any of your Table classes in your application, that is,
the ability of recording any creation, modification or delete of the entities of any particular table.

By default, this plugin stores the audit logs in a regular database table using the CakePHP ORM, which provides
excellent performance and simplicity for most use cases. The plugin also includes a built-in UI for browsing and
searching your audit logs.

For high-volume applications, you can optionally use [Elasticsearch](https://www.elastic.co/products/elasticsearch)
as a storage engine, which provides powerful features for searching large datasets. The plugin is generic enough
that you can implement your own persisting strategies if needed.

## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org) and executing the
following lines in the root of your application.

```
composer require dereuromark/cakephp-audit-stash
bin/cake plugin load AuditStash
```

## Configuration

### Database Table Storage (Default)

The plugin uses a regular database table to store audit logs by default. Run the migrations to create the `audit_logs` table:

```bash
bin/cake migrations migrate -p AuditStash
```

Optionally, bake the corresponding table class if you need to customize it:

```bash
bin/cake bake model AuditLogs
```

**Performance Note:** The migration uses `binaryuuid` for the transaction field, which stores UUIDs as BINARY(16) instead of CHAR(36).
This provides ~56% space savings and better index performance.

The table persister is configured by default, but you can explicitly set it in your `config/app_local.php` or `config/app.php`:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\TablePersister::class,
],
```

The plugin will store logs in a table named `audit_logs`, via a table class with the alias `AuditLogs`, which you can
create/overwrite in your application if needed.

#### Table Persister Configuration

The table persister supports various configuration options, please refer to
[its API documentation](/src/Persister/TablePersister.php) for further information. Generally configuration can be
applied via its `setConfig()` method:

```php
$this->addBehavior('AuditStash.AuditLog');
$this->behaviors()->get('AuditLog')->persister()->setConfig([
    'extractMetaFields' => [
        'user.id' => 'user_id',
    ]
]);
```

### Elasticsearch Storage (Alternative)

For high-volume applications or advanced search capabilities, you can use Elasticsearch instead of the database table.

First, install the official `elastic-search` plugin:

```bash
composer require cakephp/elastic-search
bin/cake plugin load Cake/ElasticSearch
```

Then add the datasource configuration to your `config/app.php` file:

```php
'Datasources' => [
    'auditlog_elastic' => [
        'className' => 'Cake\ElasticSearch\Datasource\Connection',
        'driver' => 'Cake\ElasticSearch\Datasource\Connection',
        'host' => '127.0.0.1', // server where elasticsearch is running
        'port' => 9200
    ],
    ...
]
```

And configure AuditStash to use the Elasticsearch persister:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\ElasticSearchPersister::class,
],
```

**Note:** The Audit Log Viewer UI and cleanup command only work with `TablePersister`.
For Elasticsearch, you should use Kibana for visualization and [Index Lifecycle Management (ILM)](https://www.elastic.co/guide/en/elasticsearch/reference/current/index-lifecycle-management.html) for retention policies.

## Using AuditStash

Enabling the Audit Log in any of your table classes is as simple as adding a behavior in the `initialize()` function:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config = []): void
    {
        ...
        $this->addBehavior('AuditStash.AuditLog');
    }
}
```

Remember to execute the command line each time you change the schema of your table!

### Configuring The Behavior

The `AuditLog` behavior can be configured to ignore certain fields of your table, by default it ignores the `created` and `modified` fields:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config = []): void
    {
        ...
        $this->addBehavior('AuditStash.AuditLog', [
            'blacklist' => ['created', 'modified', 'another_field_name'],
        ]);
    }
}
```

If you prefer, you can use a `whitelist` instead. This means that only the fields listed in that array will be tracked by the behavior:

```php
public function initialize(array $config = []): void
{
    ...
    $this->addBehavior('AuditStash.AuditLog', [
        'whitelist' => ['title', 'description', 'author_id'],
    ]);
}
```

If you have fields that contain sensitive information but still want to track their changes you can use the `sensitive` configuration:

```php
public function initialize(array $config = []): void
{
    ...
    $this->addBehavior('AuditStash.AuditLog', [
        'sensitive' => ['body'],
    ]);
}
```

### Storing The Logged In User

It is often useful to store the identifier of the user that is triggering the changes in a certain table. For this purpose, `AuditStash`
provides the `RequestMetadata` listener class, that is capable of storing the current URL, IP and logged in user.

The `user` parameter accepts a string, integer, or null value. You can pass:
- **User ID** (integer) - Most common for lookups
- **Username** (string) - Useful for human-readable logs
- **Email** (string) - Alternative identifier for audit trails

You need to add this listener to your application in the `AppController::beforeFilter()` method.

#### Using CakePHP Authentication

If you're using the official [CakePHP Authentication plugin](https://book.cakephp.org/authentication/2/en/index.html):

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventManager;

class AppController extends Controller
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        EventManager::instance()->on(
            new RequestMetadata(
                request: $this->getRequest(),
                user: $this->getRequest()->getAttribute('identity')?->getIdentifier(),
            ),
        );
    }
}
```

You can also pass other user fields instead of the identifier:

```php
// Store username instead of ID
user: $this->getRequest()->getAttribute('identity')?->get('username'),

// Store email instead of ID
user: $this->getRequest()->getAttribute('identity')?->get('email'),
```

#### Using TinyAuth

If you're using the [TinyAuth plugin](https://github.com/dereuromark/cakephp-tinyauth):

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventManager;

class AppController extends Controller
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        EventManager::instance()->on(
            new RequestMetadata(
                request: $this->getRequest(),
                user: $this->AuthUser->user('email'),
            ),
        );
    }
}
```

You can pass any field from the user session:

```php
// Store user ID
user: $this->AuthUser->id(),

// Store username
user: $this->AuthUser->user('username'),

// Store email
user: $this->AuthUser->user('email'),
```

#### Attaching Globally vs Per-Table

The above examples use `EventManager::instance()->on()` which attaches the listener **globally**. This is recommended if you plan to use
multiple Table classes for saving or deleting inside the same controller.

If you only need to track changes for the controller's default Table class, you can attach it to that specific table's event manager:

```php
public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    $eventManager = $this->fetchTable()->getEventManager();
    $eventManager->on(
        new RequestMetadata(
            request: $this->getRequest(),
            user: $this->getRequest()->getAttribute('identity')?->getIdentifier(),
        ),
    );
}
```

#### Storing Additional User Information

If you need to store more user information beyond a single identifier (e.g., both user ID and email, or user name), you can create a
custom metadata listener using the `AuditStash.beforeLog` event:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    // First, add the basic RequestMetadata with user ID
    EventManager::instance()->on(
        new RequestMetadata(
            request: $this->getRequest(),
            user: $this->getRequest()->getAttribute('identity')?->getIdentifier(),
        ),
    );

    // Then add additional user info via custom metadata
    $identity = $this->getRequest()->getAttribute('identity');
    EventManager::instance()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs) use ($identity): void {
        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + [
                'user_email' => $identity?->get('email'),
                'user_name' => $identity?->get('name'),
            ]);
        }
    });
}
```

### Storing Extra Information In Logs

`AuditStash` is also capable of storing arbitrary data for each of the logged events. You can use the `ApplicationMetadata` listener or
create your own. If you choose to use `ApplicationMetadata`, your logs will contain the `app_name` key stored and any extra information
your may have provided. You can configure this listener anywhere in your application, such as the `bootstrap.php` file or, again, directly
in your AppController.


```php
use AuditStash\Meta\ApplicationMetadata;
use Cake\Event\EventManager;

EventManager::instance()->on(new ApplicationMetadata('my_blog_app', [
    'server' => $theServerID,
    'extra' => $somExtraInformation,
    'moon_phase' => $currentMoonPhase,
]));

```

Implementing your own metadata listeners is as simple as attaching the listener to the `AuditStash.beforeLog` event. For example:

```php
EventManager::instance()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs): void {
    foreach ($logs as $log) {
        $log->setMetaInfo($log->getMetaInfo() + ['extra' => 'This is extra data to be stored']);
    }
});
```

### Capturing Reasons/Comments For Changes

You can capture user-provided reasons or comments for changes using the metadata system. This is useful for compliance, audit trails, or understanding why changes were made.

#### Approach 1: Per-Request Reason (via Request Attribute)

Store the reason in the request and extract it in a listener:

```php
// In your Controller action (before saving)
public function edit($id)
{
    $article = $this->Articles->get($id);

    if ($this->request->is(['patch', 'post', 'put'])) {
        // Store reason from form input
        $reason = $this->request->getData('audit_reason') ?? 'No reason provided';
        $this->request = $this->request->withAttribute('audit_reason', $reason);

        $article = $this->Articles->patchEntity($article, $this->request->getData());
        if ($this->Articles->save($article)) {
            $this->Flash->success('Article saved.');
            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

Then in your `AppController::beforeFilter()`:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    // Capture reason from request attribute
    EventManager::instance()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs): void {
        $reason = $this->getRequest()->getAttribute('audit_reason');
        if ($reason !== null) {
            foreach ($logs as $log) {
                $log->setMetaInfo($log->getMetaInfo() + [
                    'reason' => $reason,
                ]);
            }
        }
    });
}
```

Add the reason field to your forms:

```php
// In your template (e.g., templates/Articles/edit.php)
<?= $this->Form->control('audit_reason', [
    'label' => 'Reason for change',
    'type' => 'textarea',
    'rows' => 3,
]) ?>
```

#### Approach 2: Per-Save Reason (via Save Options)

Pass the reason directly through save options and extract it in a listener:

```php
// In your Controller
public function edit($id)
{
    $article = $this->Articles->get($id);

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());

        $reason = $this->request->getData('audit_reason') ?? 'No reason provided';

        if ($this->Articles->save($article, ['_auditReason' => $reason])) {
            $this->Flash->success('Article saved.');
            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

Then in your Table's `initialize()` method or `AppController::beforeFilter()`:

```php
use Cake\Event\EventInterface;

// In ArticlesTable::initialize() or globally in AppController
$this->getEventManager()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs): void {
    // Access the reason from the table's event data if available
    $reason = $event->getSubject()->getEventManager()->getEventData('_auditReason') ?? null;

    foreach ($logs as $log) {
        $meta = $log->getMetaInfo();

        // Check if reason was passed in the meta already
        if (!isset($meta['reason']) && $reason !== null) {
            $log->setMetaInfo($meta + ['reason' => $reason]);
        }
    }
});
```

A simpler approach using entity virtual properties:

```php
// In your Entity class (e.g., src/Model/Entity/Article.php)
protected array $_virtual = ['audit_reason'];

// In your Controller
$article->audit_reason = $this->request->getData('audit_reason');
$this->Articles->save($article);

// In your listener (AppController or Table)
EventManager::instance()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs): void {
    foreach ($logs as $log) {
        $entity = $log->getChanged(); // or use reflection to get entity if needed
        // Extract reason from entity if it was set
        if (isset($entity['audit_reason'])) {
            $log->setMetaInfo($log->getMetaInfo() + [
                'reason' => $entity['audit_reason'],
            ]);
        }
    }
});
```

#### Approach 3: CLI/Background Job Reasons

For CLI commands or background jobs where there's no request context:

```php
// In your Shell/Command
use Cake\Event\EventManager;

public function execute()
{
    // Set a reason for this batch operation
    $reason = 'Automated cleanup job - removing old records';

    EventManager::instance()->on('AuditStash.beforeLog', function ($event, array $logs) use ($reason): void {
        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + [
                'reason' => $reason,
                'source' => 'cli',
            ]);
        }
    });

    // Perform your operations
    $this->Articles->deleteAll(['created <' => new DateTime('-1 year')]);
}
```

#### Storing Reason in Database (Table Persister)

If using the `TablePersister`, you can extract the reason to a dedicated database column:

```php
// In your configuration (e.g., config/app_local.php or bootstrap.php)
$this->addBehavior('AuditStash.AuditLog');
$this->behaviors()->get('AuditLog')->persister()->setConfig([
    'extractMetaFields' => [
        'reason' => 'reason', // Extract 'reason' from meta to 'reason' column
        'user' => 'user_id',
    ],
]);
```

Then add a `reason` column to your `audit_logs` table:

```php
// In a migration
$table->addColumn('reason', 'text', [
    'default' => null,
    'null' => true,
]);
```

### Implementing Your Own Persister Strategies

There are valid reasons for wanting to use a different persist engine for your audit logs. Luckily, this plugin allows you to implement
your own storage engines. It is as simple as implementing the `PersisterInterface` interface:

```php
use AuditStash\PersisterInterface;

class MyPersister implements PersisterInterface
{
    /**
     * @param array<\AuditStash\EventInterface> $auditLogs
     */
    public function logEvents(array $auditLogs): void
    {
        foreach ($auditLogs as $log) {
            $eventType = $log->getEventType();
            $data = [
                'timestamp' => $log->getTimestamp(),
                'transaction' => $log->getTransactionId(),
                'type' => $log->getEventType(),
                'primary_key' => $log->getId(),
                'source' => $log->getSourceName(),
                'parent_source' => $log->getParentSourceName(),
                'original' => json_encode($log->getOriginal()),
                'changed' => $eventType === 'delete' ? null : json_encode($log->getChanged()),
                'meta' => json_encode($log->getMetaInfo())
            ];
            $storage = new MyStorage();
            $storage->save($data);
        }
    }
}
```

Finally, you need to configure `AuditStash` to use your new persister. In the `config/app.php` file add the following
lines:

```php
'AuditStash' => [
    'persister' => 'App\Namespace\For\Your\Persister',
]
```

or if you are using as standalone via

```php
\Cake\Core\Configure::write('AuditStash.persister', 'App\Namespace\For\Your\DatabasePersister');
```

The configuration contains the fully namespaced class name of your persister.

### Working With Transactional Queries

Occasionally, you may want to wrap a number of database changes in a transaction, so that it can be rolled back if one
part of the process fails. There are two ways to accomplish this. The easiest is to change your save strategy to use
`afterSave` instead of `afterCommit`. In your applications configuration, such as `config/app.php`:

```php
'AuditStash' => [
    'saveType' => 'afterSave',
]
```

That's it if you use afterSave. You should read up on the difference between the two as there are drawbacks:
https://book.cakephp.org/4/en/orm/table-objects.html#aftersave

If you are using the default afterCommit, in order to create audit logs during a transaction, some additional setup is
required. First create the file `src/Model/Audit/AuditTrail.php` with the following:

```php
<?php
namespace App\Model\Audit;

use Cake\Utility\Text;
use SplObjectStorage;

class AuditTrail
{
    protected SplObjectStorage $_auditQueue;
    protected string $_auditTransaction;

    public function __construct()
    {
        $this->_auditQueue = new SplObjectStorage;
        $this->_auditTransaction = Text::uuid();
    }

    public function toSaveOptions(): array
    {
        return [
            '_auditQueue' => $this->_auditQueue,
            '_auditTransaction' => $this->_auditTransaction,
        ];
    }
}
```

Anywhere you wish to use `Connection::transactional()`, you will need to first include the following at the top of the file:

```php
use App\Model\Audit\AuditTrail;
use ArrayObject
use Cake\Event\Event;
```

Your transaction should then look similar to this example of a BookmarksController:

```php
$trail = new AuditTrail();
$success = $this->Bookmarks->connection()->transactional(function () use ($trail) {
    $bookmark = $this->Bookmarks->newEntity();
    $bookmark1->save($data1, $trail->toSaveOptions());
    $bookmark2 = $this->Bookmarks->newEntity();
    $bookmark2->save($data2, $trail->toSaveOptions());
    ...
    $bookmarkN = $this->Bookmarks->newEntity();
    $bookmarkN->save($dataN, $trail->toSaveOptions());
});

if ($success) {
    $event = new Event('Model.afterCommit', $this->Bookmarks);
    $this->Bookmarks->->behaviors()->get('AuditLog')->afterCommit(
        $event,
        $result,
        new ArrayObject($auditTrail->toSaveOptions()),
    );
}
```

This will save all audit info for your objects, as well as audits for any associated data. Please note, `$result` must
be an instance of an Object. Do not change the text "Model.afterCommit".

## Audit Log Viewer/UI Backend

The plugin provides a built-in web interface to browse and search audit logs when using the `TablePersister`.

### Routes (Admin Prefix by Default)

The audit log viewer routes are **automatically enabled in the Admin prefix** when you load the plugin. No additional configuration needed!

Default routes are available at:
- **Browse logs**: `/admin/audit-logs`
- **View single log**: `/admin/audit-logs/view/{id}`
- **Record timeline**: `/admin/audit-logs/timeline/{table}/{recordId}`
- **Export**: `/admin/audit-logs/export.csv` or `/admin/audit-logs/export.json`

The routes are secured by being in the Admin prefix, which typically requires authentication in your application.

#### Using Without Admin Prefix

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

#### Loading the Helper

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

### Features

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

### Additional Security

The audit log viewer is in the Admin prefix by default, which provides a layer of security. However, you should ensure your Admin prefix is properly secured with authentication/authorization. Here are some additional approaches:

#### Option 1: Use Authorization Plugin

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

#### Option 2: Role-Based Access Control

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

#### Option 3: Create Custom AppController for Plugin

Create `src/Controller/Admin/AuditLogsController.php` in your app to override the plugin controller:

```php
<?php
namespace App\Controller\Admin;

use AuditStash\Controller\AuditLogsController as BaseAuditLogsController;

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

## Log Retention & Cleanup

Automatically delete old audit logs based on configurable retention policies using the cleanup command.

### Configuration

Configure retention policies in your `config/app.php` or `config/app_local.php`:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\TablePersister::class,
    'retention' => [
        'default' => 90, // Keep logs for 90 days by default
        'tables' => [
            'users' => 365,      // Keep user logs for 1 year
            'orders' => 2555,    // Keep order logs for 7 years
            'sessions' => 30,    // Keep session logs for 30 days
        ],
    ],
],
```

### Running Cleanup

The cleanup command provides several options:

```bash
# Clean up logs older than configured retention period
bin/cake audit_stash cleanup

# Dry run to see what would be deleted
bin/cake audit_stash cleanup --dry-run

# Clean up logs for specific table only
bin/cake audit_stash cleanup --table users

# Skip confirmation prompt
bin/cake audit_stash cleanup --force
```

### Automated Cleanup via Cron

Add to your crontab to run cleanup automatically:

```bash
# Run cleanup daily at 2am
0 2 * * * cd /path/to/app && bin/cake audit_stash cleanup --force
```

**Note**: The cleanup command only works with `TablePersister`.
For Elasticsearch, use [Index Lifecycle Management (ILM)](https://www.elastic.co/guide/en/elasticsearch/reference/current/index-lifecycle-management.html) policies instead.

## Demo
https://sandbox.dereuromark.de/sandbox/audit-stash

## Testing

By default, the test suite will not run elastic. If you are an elastic user and wish to test against a local instance
then you will need to set the environment variable:

```console
elastic_dsn="Cake\ElasticSearch\Datasource\Connection://127.0.0.1:9200?driver=Cake\ElasticSearch\Datasource\Connection" vendor/bin/phpunit
```
