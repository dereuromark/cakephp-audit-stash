# Usage

Enabling the Audit Log in any of your table classes is as simple as adding a behavior in the `initialize()` function:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config = []): void
    {
        parent::initialize($config);
        $this->addBehavior('AuditStash.AuditLog');
    }
}
```

Apply application schema changes through migrations. AuditStash does not require
a separate command whenever an audited table changes.

## Configuring The Behavior

The `AuditLog` behavior can be configured to ignore certain fields of your table, by default it ignores the `created` and `modified` fields:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config = []): void
    {
        parent::initialize($config);
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

`blacklist` and `whitelist` apply to delete entries as well. A delete entry stores the audited columns of the removed row and nothing else: associations that were loaded with `contain()` are not copied into it.

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

### Filtering Insignificant Changes

The behavior can be configured to ignore changes that aren't meaningful for your audit trail. This helps reduce noise and storage:

A value assigned in another scalar type is never logged as a change: setting `'1'` on a field that holds `1` is not an update. The options below only decide whether a field counts as changed. The values that get logged are the real ones, not a trimmed or lower-cased form.

```php
public function initialize(array $config = []): void
{
    $this->addBehavior('AuditStash.AuditLog', [
        // Skip logging if only timestamp fields changed (created, modified, updated_at, etc.)
        'ignoreTimestampOnly' => true,

        // Skip logging if only these specific fields changed
        'ignoreFields' => ['last_login', 'view_count'],

        // Ignore whitespace-only changes (e.g., "hello " vs "hello")
        'ignoreWhitespace' => true,

        // Ignore case-only changes (e.g., "Hello" vs "hello")
        'ignoreCase' => true,
    ]);
}
```

Available options:

| Option | Default | Description |
|--------|---------|-------------|
| `ignoreEmpty` | `true` | Skip logging if no meaningful changes after filtering |
| `ignoreTimestampOnly` | `false` | Skip logging if only timestamp fields changed (`created`, `modified`, `updated`, `updated_at`, `created_at`, `modified_at`) |
| `ignoreFields` | `[]` | Skip logging if only these fields changed |
| `ignoreWhitespace` | `false` | Ignore whitespace-only differences in string values |
| `ignoreCase` | `false` | Ignore case-only differences in string values |

**Example use cases:**

- Enable `ignoreTimestampOnly` when your ORM auto-updates `modified` on every save
- Use `ignoreFields` for counters or cache fields that change frequently but aren't audit-worthy
- Enable `ignoreWhitespace` when form submissions may normalize whitespace differently
- Enable `ignoreCase` when case normalization happens but doesn't represent a real change

### Logging BelongsToMany (Junction Table) Changes

When working with many-to-many relationships (BelongsToMany), changes happen in the junction table (e.g., `articles_tags`).
To track these changes, you need to add the `AuditLogBehavior` to both the target table and the junction table.

```php
// In ArticlesTable
public function initialize(array $config = []): void
{
    $this->belongsToMany('Tags', [
        'joinTable' => 'articles_tags',
    ]);

    // Audit the Articles table
    $this->addBehavior('AuditStash.AuditLog');
}

```

```php
// In TagsTable
public function initialize(array $config = []): void
{
    $this->belongsToMany('Articles', [
        'joinTable' => 'articles_tags',
    ]);

    // Audit the Tags table
    $this->addBehavior('AuditStash.AuditLog');
}

```

```php
// In ArticlesTagsTable (junction table)
public function initialize(array $config = []): void
{
    // Audit the junction table to track tag assignments/removals
    $this->addBehavior('AuditStash.AuditLog');
}
```

Alternatively, you can add the behavior to the junction table dynamically:

```php
// In ArticlesTable::initialize()
$this->Tags->junction()->addBehavior('AuditStash.AuditLog');
```

With this setup, when you add or remove tags from an article:

```php
$article = $this->Articles->get($id, contain: ['Tags']);
$article->tags[] = $this->Tags->get(5); // Add a new tag
$article->setDirty('tags', true);
$this->Articles->save($article);
```

The following audit events will be created:
1. **Tags** - If a new tag was created
2. **ArticlesTags** - For each new tag association (create event)
3. **ArticlesTags** - For each removed tag association (delete event, if using `replace` strategy)

All events in a single save operation share the same `transaction_key` ID, making it easy to see all related changes.

### Logging Cascade-Deleted Records

When deleting a parent record with `dependent => true` associations, the dependent records are also deleted (cascade delete).
By default, these cascade-deleted records are NOT logged unless the dependent tables also have `AuditLogBehavior` attached.

To automatically log cascade-deleted dependent records without adding the behavior to each dependent table, enable the `cascadeDeletes` option:

```php
public function initialize(array $config = []): void
{
    // Set up the association with dependent = true
    $this->hasMany('Comments', [
        'dependent' => true,
    ]);

    // Enable cascade delete logging
    $this->addBehavior('AuditStash.AuditLog', [
        'cascadeDeletes' => true,
    ]);
}
```

When `cascadeDeletes` is enabled:
- Before the parent record is deleted, the behavior queries for all dependent `HasMany` and `HasOne` records
- After deletion, audit events are created for all cascade-deleted records
- All events share the same transaction ID for traceability
- The `parent_source` field indicates which table triggered the cascade delete
- If the dependent table has the behavior itself, its own `blacklist`, `whitelist` and `sensitive` settings decide what is recorded. Otherwise the parent's settings apply

This is particularly useful when:
- You don't want to add `AuditLogBehavior` to every dependent table
- You want to avoid using `setCascadeCallbacks(true)` for performance reasons
- You need a complete audit trail of all deleted records in a single operation

**Note:** The `cascadeDeletes` option is disabled by default for backward compatibility.

### Plugin Tables and the `source` Column

`AuditLogBehavior` writes the value of `$table->getRegistryAlias()` into the `audit_logs.source` column. For app tables that's just the alias (`Comments`). For plugin tables, the recorded source depends on **how the table was loaded into the locator** — and CakePHP allows both forms:

- `$this->fetchTable('Comments.Comments')` → registry alias `Comments.Comments` → audit row `source = 'Comments.Comments'`
- `$this->fetchTable('Comments')` → registry alias `Comments` → audit row `source = 'Comments'`

Associations from app code without an explicit `className` may resolve to the bare alias, so plugin-owned tables often end up writing audit rows under the short form. The Audit-Log-Viewer's coverage report checks both forms, but the behavior records whatever the locator gave it at save time.

This is mostly a non-issue, but **two situations need attention** if your app loads plugin tables:

1. **Mixed load paths produce a fragmented audit trail.** If different code paths in the same app load the same plugin table both with and without the prefix, you'll get rows under both `Comments.Comments` AND `Comments`. The coverage report surfaces the orphaned form as `Empirical` so you can spot it. Pick one form and stick with it — using the dotted form everywhere (`fetchTable('Comments.Comments')`, association `className`, etc.) keeps the audit attribution stable.

2. **Source collisions across an app and plugin with the same name.** If your app has its own `App\Model\Table\CommentsTable` AND you load a plugin's `Comments\Model\Table\CommentsTable`, both writing audit rows under the bare alias `Comments` becomes a real ambiguity — the audit trail can no longer tell the two logical tables apart. The cleanest fix is to alias the plugin table when registering it with the locator, so the `source` column carries a disambiguated name:

   ```php
   // In Application::bootstrap() or wherever you set up table associations:
   $this->getTableLocator()->setConfig('PluginComments', [
       'className' => 'Comments\\Model\\Table\\CommentsTable',
   ]);
   // Then load via $this->fetchTable('PluginComments') so the source column
   // says 'PluginComments' instead of competing with the app's 'Comments'.
   ```

   Alternatively, always load the plugin table via the dotted form (`fetchTable('Comments.Comments')`) and the app table via the bare alias — but that requires every association declaration in the app to set `className` explicitly.

## Storing The Logged In User

It is often useful to store the identifier of the user that is triggering the changes in a certain table. For this purpose, `AuditStash`
provides the `RequestMetadata` listener class, that is capable of storing the current URL, IP and logged in user.

The listener accepts two user-related parameters:
- **userId** (optional): The user ID for linking/filtering (string or integer)
- **userDisplay** (optional): A human-readable display value (username, email, full name, etc.)

You need to add this listener to your application in the `AppController::beforeFilter()` method.

### Using CakePHP Authentication

If you're using the official [CakePHP Authentication plugin](https://book.cakephp.org/authentication/3/en/index.html):

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

class AppController extends Controller
{
    public function beforeFilter(EventInterface $event): void
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
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

class AppController extends Controller
{
    public function beforeFilter(EventInterface $event): void
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

Custom events written with `Audit::log()` have no table. They get the metadata of globally attached listeners only. Keys you pass in `meta` win over what a listener adds.

If you only need to track changes for the controller's default Table class, you can attach it to that specific table's event manager:

```php
public function beforeFilter(EventInterface $event): void
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

public function beforeFilter(EventInterface $event): void
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

## Tracking Request Source (Web, CLI, API)

The `EnvironmentMetadata` listener automatically detects and records where changes originated from. This is useful for distinguishing between changes made via the web interface, CLI commands, API requests, or background jobs.

### Basic Usage

```php
use AuditStash\Meta\EnvironmentMetadata;
use Cake\Event\EventManager;

// Auto-detect source (cli, web, or api)
EventManager::instance()->on(new EnvironmentMetadata());
```

This adds a `request_source` field to the audit log metadata with one of:
- `cli` - Command line (bin/cake commands)
- `web` - Standard web request
- `api` - API request (detected via Accept/Content-Type headers or `/api/` URL prefix)

### Explicit Source Override

For queue workers or other contexts where auto-detection won't work:

```php
// In your queue worker
EventManager::instance()->on(new EnvironmentMetadata('queue'));
```

### With Extra Metadata

```php
EventManager::instance()->on(new EnvironmentMetadata(null, [
    'deployment' => 'production',
    'server' => gethostname(),
    'version' => '1.2.3',
]));
```

### API Detection with Request Object

For more accurate API detection, pass the request object:

```php
// In AppController::beforeFilter()
EventManager::instance()->on(new EnvironmentMetadata(
    source: null, // auto-detect
    extraData: [],
    request: $this->getRequest(),
));
```

API requests are detected by:
- `Accept` header containing `application/json` or `application/xml`
- `Content-Type` header containing `application/json` or `application/xml`
- URL path starting with `/api/`
- `Authorization` header with `Bearer` or `Basic` prefix

### Capturing forensic request fields

`EnvironmentMetadata` can also pull a small set of request-derived fields into `meta`. They are off by default — these fields can carry PII / fingerprintable data and may have GDPR implications, so consumers must opt in explicitly via `capture`:

```php
EventManager::instance()->on(new EnvironmentMetadata(
    request: $this->getRequest(),
    capture: ['user_agent', 'referer', 'session_id'],
));
```

Supported `capture` values:

- `user_agent` — `User-Agent` header
- `referer` — `Referer` header
- `session_id` — PHP session id; only included when a session has actually been started (CLI, queue workers, and anonymous API hits silently skip it)

Empty headers and unknown field names are filtered out so a typo can't smuggle arbitrary values into `meta`. `capture` is a no-op when no `request` is supplied (CLI / queue context).

### Combining with RequestMetadata

You can use both listeners together:

```php
use AuditStash\Meta\EnvironmentMetadata;
use AuditStash\Meta\RequestMetadata;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

public function beforeFilter(EventInterface $event): void
{
    parent::beforeFilter($event);

    $identity = $this->getRequest()->getAttribute('identity');

    // Track user and request info
    EventManager::instance()->on(new RequestMetadata(
        request: $this->getRequest(),
        userId: $identity?->getIdentifier(),
        userDisplay: $identity?->get('username'),
    ));

    // Track request source
    EventManager::instance()->on(new EnvironmentMetadata(
        request: $this->getRequest(),
    ));
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

### `AuditStash.beforeLog` vs `AuditStash.afterLog`

The plugin dispatches two events around persistence:

| Event | Fires | Use it for |
|---|---|---|
| `AuditStash.beforeLog` | just before the persister writes the rows | adding metadata or replacing event objects with transformed payloads |
| `AuditStash.afterLog` | just after the persister returns | side effects that need the rows already-stored (notifications, cache busts, derived projections) |

The payloads differ. `beforeLog` is dispatched on the audited table once per
batch, with `logs`: the `array<\AuditStash\EventInterface>` about to be
written. `afterLog` is dispatched by `TablePersister` once per stored row,
with `auditLog`: the saved `AuditLog` entity.

`afterLog` fires after the row is committed, and with `hashChain` enabled
after the chain lock is released. An exception or error thrown by a listener
is written to the error log and does not undo the audit row or reach the code
that triggered the save.

```php
EventManager::instance()->on('AuditStash.afterLog', function (EventInterface $event): void {
    /** @var \AuditStash\Model\Entity\AuditLog $auditLog */
    $auditLog = $event->getData('auditLog');
    Cache::delete('article_' . $auditLog->primary_key . '_history');
});
```

The [Monitoring & Alerting](/features/monitoring) feature listens on
`afterLog` internally, which is why monitor rules see the rows in their
final, persisted form.

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

public function beforeFilter(EventInterface $event): void
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

### Per-save and background-job reasons

The `AuditStash.beforeLog` event receives `logs`, not the original save options.
There is no `EventManager::getEventData()` API for reading a custom save option.
For a web request, use the request listener above. For a specific batch, attach a
listener before the entity operations and remove it afterwards:

```php
use Cake\Event\EventInterface;

$articles = $this->fetchTable('Articles');
$manager = $articles->getEventManager();
$listener = function (EventInterface $event, array $logs): void {
    foreach ($logs as $log) {
        $log->setMetaInfo($log->getMetaInfo() + ['reason' => 'Scheduled archive']);
    }
};
$manager->on('AuditStash.beforeLog', $listener);
try {
    foreach ($articles->find()->where(['needs_archive' => true]) as $article) {
        $article->set('archived', true);
        $articles->saveOrFail($article);
    }
} finally {
    $manager->off('AuditStash.beforeLog', $listener);
}
```

These are per-entity saves. Using `updateAll()` or `deleteAll()` here would skip
the behavior and the listener.

### Storing Reason in Database (Table Persister)

If using the `TablePersister`, you can extract the reason to a dedicated database column. `user_id` and `user_display` are auto-extracted to their dedicated columns out of the box, so only register here for additional custom fields:

```php
// In your Table::initialize() method
$this->addBehavior('AuditStash.AuditLog');
$this->behaviors()->get('AuditLog')->persister()->setConfig([
    'extractMetaFields' => [
        'reason' => 'reason', // Extract 'reason' from meta to 'reason' column
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

## Tracking File Uploads (Hashes, not Content)

Audited tables that store file content directly (binary blobs, base64 payloads, raw `UploadedFile` objects in a virtual field) cause two problems for the audit trail:

- The audit row balloons to the size of the file — a 10 MB upload becomes a 10 MB `changed` payload, replicated for every save.
- The audit table ends up holding sensitive content in plaintext, defeating the typical reason for separating uploads from primary tables.

The fix is to make the entity field that the auditor sees be a **fingerprint** (hash + size + mime), not the raw content. Two ways to do that, depending on where the file lives in your model.

### Store a fingerprint in a schema column

Add a `file_fingerprint` string column to your application's document table.
Compute it in your upload code after validating and storing the file, before
saving the document entity:

```php
$document->set('file_fingerprint', hash_file('sha256', $storedPath));
$this->Documents->saveOrFail($document);
```

`$storedPath` is the path produced by your upload implementation. Handle file-read
errors before saving. Include the real column in your behavior configuration:

```php
$this->addBehavior('AuditStash.AuditLog', [
    'whitelist' => ['title', 'file_path', 'file_fingerprint'],
]);
```

A computed virtual getter alone does not supply a dirty persisted property or
reliable original fingerprint. A stored column lets the behavior compare the old
and new fingerprint without retaining the file bytes.

### Transforming existing file columns

`BaseEvent` has getters for `changed` and `original`, but no public setters.
A `beforeLog` listener that only edits a local array does not change the saved
payload. Use a custom persister to rebuild events with transformed values, or
store a fingerprint in a separate schema column and blacklist the file content.

For sensitive values whose contents should never be retained, use the behavior's
`sensitive` option:

```php
$this->addBehavior('AuditStash.AuditLog', [
    'sensitive' => ['passport_scan_b64', 'id_photo_b64'],
]);
```

Changed sensitive values are masked in the audit payload. This setting does not
redact arbitrary metadata added by your listeners.

## Custom action events

Use `Audit::log()` for actions that do not map to an entity save or delete:

```php
use AuditStash\Audit;

Audit::log(
    type: 'user.login',
    source: 'Users',
    primaryKey: $userId,
    data: ['method' => 'password'],
    meta: ['user_id' => $userId],
);
```

The `data` argument is stored in `changed`. `primaryKey` may be null when the
action has no single record. Custom types must fit the `type` column, which the
bundled migrations widen to 64 characters.

The facade uses a lazily created `TablePersister` with `AuditStash.persisterConfig`.
It does not resolve a different class from `AuditStash.persister`; set a custom
instance with `Audit::setPersister()` when using another storage backend.
Its `beforeLog` event is global and has no table subject. Reset overrides with
`Audit::setPersister(null)` in tests or between application contexts.

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
                'transaction_key' => $log->getTransactionId(),
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
use ArrayObject;
use Cake\Event\Event;
```

Use the shared options when saving each entity. Flush the audit queue only after
a successful transaction:

```php
$bookmarks = $this->fetchTable('Bookmarks');
$trail = new AuditTrail();
$options = $trail->toSaveOptions();
$first = $bookmarks->newEntity($data1);
$second = $bookmarks->newEntity($data2);
$bookmarks->getConnection()->transactional(function () use ($bookmarks, $first, $second, $options): void {
    $bookmarks->saveOrFail($first, $options);
    $bookmarks->saveOrFail($second, $options);
});

$bookmarks->behaviors()->get('AuditLog')->afterCommit(
    new Event('Model.afterSaveCommit', $bookmarks),
    $first,
    new ArrayObject($options),
);
```

`saveOrFail()` throws if either entity cannot be saved, and the transaction rolls
back. The manual flush is skipped on that path. When using an outer transaction,
flush only after that outer transaction commits. The default `afterCommit` mode
writes the audit logs after the data commit; an audit write failure cannot roll
back that already committed data.

## Operations That Bypass The Audit Listener

The behavior hooks `Model.beforeSave` / `Model.afterSave` / `Model.afterSaveCommit`, all of which fire **per entity** through the ORM. Any path that doesn't go through `Table::save($entity)` / `Table::saveMany()` / `Table::delete($entity)` will **not** be audited:

| Bypass path | Audited? | Notes |
|---|---|---|
| `Table::save($entity)` | ✅ Yes | Standard ORM path. |
| `Table::saveMany($entities)` | ✅ Yes | Each entity fires its own events. |
| `Table::delete($entity)` | ✅ Yes | Including cascade deletes. |
| `Table::updateAll($fields, $conditions)` | ❌ No | Single SQL UPDATE; no model events. |
| `Table::deleteAll($conditions)` | ❌ No | Single SQL DELETE; no model events. |
| `Table::updateQuery()->set(...)->execute()` | ❌ No | Direct query builder; no model events. |
| `Table::deleteQuery()->where(...)->execute()` | ❌ No | Direct query builder; no model events. |
| `Connection::execute('UPDATE ...')` | ❌ No | Raw SQL bypasses the ORM entirely. |
| `Connection::insert()` / `update()` / `delete()` | ❌ No | Low-level driver methods. |

For bulk operations, record a custom summary explicitly or use per-entity saves
when each record's before/after history is needed. For example, from a controller:

```php
use AuditStash\Audit;

$articles = $this->fetchTable('Articles');
$articles->getConnection()->transactional(function () use ($articles): void {
    $affectedIds = $articles->find()
        ->select(['id'])
        ->where(['needs_archive' => true])
        ->all()
        ->extract('id')
        ->toList();
    if (!$affectedIds) {
        return;
    }

    $articles->updateAll(['archived' => true], ['id IN' => $affectedIds]);
    Audit::log(
        type: 'articles.bulk_archive',
        source: 'Articles',
        data: ['affected_ids' => $affectedIds, 'archived' => true],
    );
});
```

The summary has a null primary key and stores IDs in its JSON payload, so it works
with the default integer subject column. It does not provide per-record revert
history. This example uses the default persister on the same database connection;
separate connections or external persisters do not share this transaction.
