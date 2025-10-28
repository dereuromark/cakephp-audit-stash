# Configuration

## Database Table Storage (Default)

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

### Table Persister Configuration

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

## Elasticsearch Storage (Alternative)

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

