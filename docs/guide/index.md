# Getting Started

`cakephp-audit-stash` records every create, update, and delete that happens
through your CakePHP Table classes — together with who made the change, from
which request, and (optionally) a verifiable hash chain to prove the log
hasn't been tampered with.

## Requirements

- PHP 8.2+
- CakePHP 5.3+

See the [version map](https://github.com/dereuromark/cakephp-audit-stash/wiki#cakephp-version-map)
for older Cake/PHP combinations.

## Installation

```bash
composer require dereuromark/cakephp-audit-stash
bin/cake plugin load AuditStash
bin/cake migrations migrate -p AuditStash
```

The migration creates the `audit_logs` table used by the default database
persister.

## Enable Logging

Add the behavior to any Table you want tracked:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('AuditStash.AuditLog');
    }
}
```

That alone gets you full create / update / delete logging. The next step is
usually wiring up the current user and request in your `AppController` so
each row remembers *who* and *from where*:

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventManager;

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
```

## Where Next

- [Configuration](./configuration) — persisters, table list, route prefix
- [Usage](./usage) — behavior options, custom events, metadata, custom persisters
- [View Helper](./view-helper) — render diffs, badges, and revert buttons in your own templates
- [Testing](./testing) — `AuditAssertionsTrait` for asserting in your own suite
- [Features](/features/) — viewer, revert, monitoring, retention, tamper-evidence, GDPR
