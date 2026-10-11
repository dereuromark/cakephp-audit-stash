# Getting started

Follow [Installation](installation.md) first. This example uses an existing
`Articles` table and the default database persister.

## Enable logging for one table

In `src/Model/Table/ArticlesTable.php`, add the behavior to your existing
`initialize()` method:

```php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('AuditStash.AuditLog');
}
```

Keep the table's existing associations and other initialization. Saves and deletes
through this table now produce audit rows. Bulk SQL operations such as
`updateAll()` and `deleteAll()` bypass entity callbacks and are not logged.
See [operations that bypass the listener](usage.md#operations-that-bypass-the-audit-listener).

## Check a saved change

Save or edit an article through your application's normal form. In local
development, inspect the latest row from application code, for example in an
existing controller action:

```php
$logs = $this->fetchTable('AuditStash.AuditLogs');
$latest = $logs->find()->where(['source' => 'Articles'])->orderByDesc('id')->first();
debug($latest?->toArray());
```

Remove the debug statement after checking the result.

The row contains the event `type`, record `primary_key`, `source`, and changed or
original values. If it is missing, check that you saved through the table with
AuditLog attached, that the transaction committed, and that the changed fields
were not excluded by the behavior's filters.

## Include the current user and request

Logging works without request metadata. To attach it in a web request, register
`RequestMetadata` in your existing `AppController::beforeFilter()`:

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

public function beforeFilter(EventInterface $event): void
{
    parent::beforeFilter($event);

    $identity = $this->getRequest()->getAttribute('identity');
    EventManager::instance()->on(new RequestMetadata(
        request: $this->getRequest(),
        userId: $identity?->getIdentifier(),
    ));
}
```

This assumes your authentication middleware provides an identity with
`getIdentifier()`. Without an identity, `user_id` is null. You can also pass a
`userDisplay` string from your application's user data. Register this listener
once per request; long-running processes must remove or replace request-specific
listeners between requests.

## Next steps

- [Configuration](configuration.md): storage, primary key types, and timestamps.
- [Usage](usage.md): filters, metadata, custom events, and transactions.
- [Testing](testing.md): assert on persisted audit rows.
- [Features](../features/index.md): viewer, monitoring, retention, and hash chains.
