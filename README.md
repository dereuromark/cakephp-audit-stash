# AuditStash Plugin For CakePHP

[![Build Status](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml/badge.svg)](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml)
[![Coverage Status](https://img.shields.io/codecov/c/github/dereuromark/cakephp-audit-stash/master.svg?style=flat-square)](https://codecov.io/github/dereuromark/cakephp-audit-stash)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

This plugin implements an "audit trail" for any of your Table classes in your application, that is,
the ability of recording any creation, modification or delete of the entities of any particular table.

By default, this plugin stores audit logs in a database table using the CakePHP ORM. The plugin also includes:
- Built-in UI for browsing and searching audit logs
- Real-time monitoring and alerting system
- Configurable retention policies with automated cleanup
- Optional Elasticsearch support for high-volume applications

## Installation

Install via [composer](https://getcomposer.org):

```bash
composer require dereuromark/cakephp-audit-stash
bin/cake plugin load AuditStash
```

Run the migrations to create the `audit_logs` table:

```bash
bin/cake migrations migrate -p AuditStash
```

## Quick Start

Enable audit logging in any Table class by adding the behavior:

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

Optionally, track the current user and request info in `AppController`:

```php
use AuditStash\Meta\RequestMetadata;
use Cake\Event\EventManager;

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
```

That's it! Your application is now tracking all creates, updates, and deletes.

## Features

### Audit Log Viewer
Browse and search audit logs through a built-in web interface at `/admin/audit-logs`:
- Filter by table, user, event type, date range, transaction ID
- View detailed before/after comparisons with inline or side-by-side diff
- Timeline view showing complete history for specific records
- Export to CSV or JSON

See [Viewer Documentation](docs/viewer.md) for details.

### Monitoring & Alerting
Real-time monitoring system that detects suspicious activities:
- Mass deletion detection
- Off-hours activity monitoring
- Customizable rules and notification channels (email, webhook, logs)
- Extensible architecture for custom rules

See [Monitoring Documentation](docs/monitoring.md) for setup.

### Log Retention & Cleanup
Automated cleanup with configurable retention policies:
- Table-specific retention periods
- Command-line tool for manual or automated cleanup
- Cron-friendly with dry-run support

See [Retention Documentation](docs/retention.md) for configuration.

### Flexible Storage
- **Database (default)**: Simple, fast, works out-of-the-box
- **Elasticsearch**: Optional for high-volume applications
- **Custom**: Implement your own persister

See [Configuration Documentation](docs/configuration.md) for storage options.

## Documentation

- **[Configuration](docs/configuration.md)** - Database and Elasticsearch setup, persister options
- **[Usage](docs/usage.md)** - Behavior configuration, metadata tracking, custom persisters
- **[Viewer](docs/viewer.md)** - Web UI for browsing and searching audit logs
- **[Retention](docs/retention.md)** - Automated log cleanup and retention policies
- **[Monitoring](docs/monitoring.md)** - Real-time alerting for suspicious activities

## Demo

https://sandbox.dereuromark.de/sandbox/audit-stash

## Testing

Run the test suite:

```bash
vendor/bin/phpunit
```

For Elasticsearch tests, set the environment variable:

```bash
elastic_dsn="Cake\ElasticSearch\Datasource\Connection://127.0.0.1:9200?driver=Cake\ElasticSearch\Datasource\Connection" vendor/bin/phpunit
```
