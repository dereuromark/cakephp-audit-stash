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

**Performance Note:** The migration uses `binaryuuid` for the transaction_key field, which stores UUIDs as BINARY(16) instead of CHAR(36).
This provides ~56% space savings and better index performance.

### Native JSON Columns

The plugin includes a migration (`20260123120000_UseJsonColumnTypes`) that converts the `original`, `changed`, and `meta`
columns from TEXT to native JSON type. This provides:

- **Automatic JSON validation** on insert/update (database will reject malformed JSON)
- **Better query performance** with JSON functions (e.g., `JSON_EXTRACT()` in MySQL)
- **Ability to create indexes** on JSON paths (MySQL 8.0+)

To enable native JSON columns:

```bash
bin/cake migrations migrate -p AuditStash
```

The `TablePersister` automatically detects JSON columns and skips manual JSON encoding to avoid double-encoding.
This means you don't need to change any configuration - it just works.

**Note:** This requires MySQL 5.7.8+, MariaDB 10.2.7+, or PostgreSQL 9.2+. SQLite does not support native JSON columns.

### Sub-Second Timestamp Precision

Audit events are captured with microsecond precision (ISO 8601 with timezone offset, e.g.
`2026-04-27T12:34:56.123456+00:00`), but the bundled migration creates the `created` column as a plain
`DATETIME`, which MySQL stores at second precision. To actually persist the extra precision, copy the
migration to your app and widen the column:

```bash
cp vendor/dereuromark/cakephp-audit-stash/config/Migrations/20171018185609_CreateAuditLogs.php config/Migrations/
```

Then change the `created` column to use a fractional seconds precision (`3` for milliseconds, `6` for
microseconds):

```php
->addColumn('created', 'datetime', [
    'default' => null,
    'null' => false,
    'precision' => 6,
])
```

Notes:

- PostgreSQL's `TIMESTAMP` already stores microseconds, so no migration change is needed.
- SQLite stores datetimes as text and preserves whatever precision is written.
- Elasticsearch's `date` mapping accepts the extended ISO string but truncates to milliseconds; use
  `date_nanos` if you need full microsecond precision in the index.
- If you want to access the timestamp as a `DateTime` object (e.g. in a custom persister) without
  re-parsing the string, use `BaseEvent::getTimestampObject()`.

### UUID Primary Keys

The default migration creates the `primary_key` column as an integer. If your application uses UUID primary keys,
you need to copy and adjust the migration on the app side:

```bash
# Copy the migration to your app
cp vendor/dereuromark/cakephp-audit-stash/config/Migrations/20171018185609_CreateAuditLogs.php config/Migrations/
```

Then modify the `primary_key` column definition:

```php
->addColumn('primary_key', 'string', [
    'default' => null,
    'limit' => 36,
    'null' => true,
])
```

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

