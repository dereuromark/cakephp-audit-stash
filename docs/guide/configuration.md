# Configuration

## Database Table Storage (Default)

The default persister stores rows through `AuditStash.AuditLogs` in the
`audit_logs` table. Run the migrations described in [Installation](installation.md).
Baking an application `AuditLogsTable` does not replace the plugin alias. To use
a custom table, configure it explicitly:

```php
'AuditStash' => [
    'persisterConfig' => [
        'table' => 'AuditLogs',
    ],
],
```

The custom table must provide the schema and type mappings expected by the
persister and features you enable. The bundled viewer and assertions still query
`AuditStash.AuditLogs`; changing the persister alone does not redirect them.

### JSON columns

The bundled migrations change `original`, `changed`, and `meta` to the migration
adapter's JSON type. Physical storage and JSON validation depend on the database
and adapter. The plugin table maps these fields to CakePHP's `json` type, and the
persister skips manual encoding when the ORM schema reports JSON columns.
Do not infer database indexing or query performance from that type mapping.

### Sub-second timestamps

Events expose an ISO timestamp with microseconds through `getTimestamp()` and a
DateTime object through `getTimestampObject()`. The default database `datetime`
type formats values to seconds, so widening the SQL column alone does not retain
microseconds. A custom table must also map `created` to `datetimefractional`:

```php
// In your custom audit table's initialize() method:
$this->getSchema()->setColumnType('created', 'datetimefractional');
```

Use a new application migration to alter an existing column to the database's
fractional timestamp type. Do not copy and rerun the plugin's table-creation
migration over an existing table. Verify a timestamp round trip on your actual
database, and configure the persister to use that custom table.

### Polymorphic Primary Key Type

The `audit_logs.primary_key` column stores the audited record's primary key (a polymorphic foreign key). Its column
type is controlled by the global `Polymorphic.type` config key (default: `integer`):

```php
// config/app.php (merged into Configure at bootstrap, including the migrations CLI)
'Polymorphic' => [
    'type' => 'uuid', // integer (default) | biginteger | uuid | binaryuuid
],
```

::: warning Fresh installs only
This config key is read at migration time to decide the column type for `audit_logs.primary_key`.
Changing it has no effect on existing installs that have already run the migration — the column already exists with its original type.
To change the column type on an existing install, write an app-side migration that `ALTER`s the column accordingly.
:::

| Value | Column type | Signedness |
|---|---|---|
| `integer` (default) | `INT` | follows `Migrations.unsigned_primary_keys` |
| `biginteger` | `BIGINT` | follows `Migrations.unsigned_primary_keys` |
| `uuid` | `CHAR(36)` | n/a |
| `binaryuuid` | `BINARY(16)` | n/a |

For the `integer` and `biginteger` variants, signedness is inherited from the `Migrations.unsigned_primary_keys`
config key (same as CakePHP core migrations). For `uuid` and `binaryuuid` no `signed` option is set.

::: warning Tables with a composite primary key
A composite key is stored as one JSON string, for example `{"article_id":1,"tag_id":2}`. That does not fit an
`integer`, `biginteger`, `uuid` or `binaryuuid` column, and the value ends up as `NULL`: the audit row is written,
but it can no longer be tied to its record, and the row cannot be reverted or restored.

If you audit such tables, change `audit_logs.primary_key` to a string column wide enough for the JSON form, or
set the persister option `primaryKeyExtractionStrategy` to `TablePersister::STRATEGY_PROPERTIES` and add one
`primary_key_<field>` column per key field.
:::

The table persister is configured by default, but you can explicitly set it in your `config/app_local.php` or `config/app.php`:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\TablePersister::class,
],
```

The plugin will store logs in a table named `audit_logs`, via the plugin table alias `AuditStash.AuditLogs`.

### Table Persister Configuration

The table persister supports various configuration options, please refer to
[its source: `src/Persister/TablePersister.php`](https://github.com/dereuromark/cakephp-audit-stash/blob/master/src/Persister/TablePersister.php) for further information. Generally configuration can be
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
    // Keep other application connections here.
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

