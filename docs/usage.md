# Usage

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

## Configuring The Behavior

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

## Storing The Logged In User

It is often useful to store the identifier of the user that is triggering the changes in a certain table. For this purpose, `AuditStash`
provides the `RequestMetadata` listener class, that is capable of storing the current URL, IP and logged in user.

The listener accepts two user-related parameters:
- **userId** (optional): The user ID for linking/filtering (string or integer)
- **userDisplay** (optional): A human-readable display value (username, email, full name, etc.)

You need to add this listener to your application in the `AppController::beforeFilter()` method.

### Using CakePHP Authentication

If you're using the official [CakePHP Authentication plugin](https://book.cakephp.org/authentication/2/en/index.html):

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventManager;

class AppController extends Controller
{
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $identity = $this->getRequest()->getAttribute('identity');

        EventManager::instance()->on(
            new RequestMetadata(
                request: $this->getRequest(),
                userId: $identity?->getIdentifier(),
                userDisplay: $identity?->get('username'),
            ),
        );
    }
}
```

This stores:
- `user_id`: The user ID (used for linking to user records and filtering)
- `user_display`: The display value (shown in the audit viewer as "John Doe" instead of a UUID)

If `userDisplay` is not provided, IDs will display as "User #id" for UUIDs/numeric values, or the raw value if it looks like a username/email.

### Using TinyAuth

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
                userId: $this->AuthUser->id(),
                userDisplay: $this->AuthUser->user('username'),
            ),
        );
    }
}
```

### Attaching Globally vs Per-Table

The above examples use `EventManager::instance()->on()` which attaches the listener **globally**. This is recommended if you plan to use
multiple Table classes for saving or deleting inside the same controller.

If you only need to track changes for the controller's default Table class, you can attach it to that specific table's event manager:

```php
public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    $identity = $this->getRequest()->getAttribute('identity');
    $eventManager = $this->fetchTable()->getEventManager();
    $eventManager->on(
        new RequestMetadata(
            request: $this->getRequest(),
            userId: $identity?->getIdentifier(),
            userDisplay: $identity?->get('username'),
        ),
    );
}
```

### Storing Additional User Information

If you need to store more user information beyond `userId` and `userDisplay` (e.g., user email, role, etc.), you can add custom metadata using the `AuditStash.beforeLog` event:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    $identity = $this->getRequest()->getAttribute('identity');

    // Add the basic RequestMetadata with user ID and display name
    EventManager::instance()->on(
        new RequestMetadata(
            request: $this->getRequest(),
            userId: $identity?->getIdentifier(),
            userDisplay: $identity?->get('username'),
        ),
    );

    // Optionally add additional user info via custom metadata
    EventManager::instance()->on('AuditStash.beforeLog', function (EventInterface $event, array $logs) use ($identity): void {
        foreach ($logs as $log) {
            $log->setMetaInfo($log->getMetaInfo() + [
                'user_email' => $identity?->get('email'),
                'user_role' => $identity?->get('role'),
            ]);
        }
    });
}
```

## Storing Extra Information In Logs

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

## Capturing Reasons/Comments For Changes

You can capture user-provided reasons or comments for changes using the metadata system. This is useful for compliance, audit trails, or understanding why changes were made.

### Approach 1: Per-Request Reason (via Request Attribute)

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

### Approach 2: Per-Save Reason (via Save Options)

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

### Approach 3: CLI/Background Job Reasons

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

### Storing Reason in Database (Table Persister)

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

## Implementing Your Own Persister Strategies

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

## Working With Transactional Queries

Occasionally, you may want to wrap a number of database changes in a transaction, so that it can be rolled back if one
part of the process fails. There are two ways to accomplish this. The easiest is to change your save strategy to use
`afterSave` instead of `afterCommit`. In your applications configuration, such as `config/app.php`:

```php
'AuditStash' => [
    'saveType' => 'afterSave',
]
```

That's it if you use afterSave. You should read up on the difference between the two as there are drawbacks:
https://book.cakephp.org/5/en/orm/table-objects.html#aftersave

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

