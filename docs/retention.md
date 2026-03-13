# Log Retention & Cleanup

Automatically delete old audit logs based on configurable retention policies using the cleanup command.

## Configuration

Configure retention policies in your `config/app.php` or `config/app_local.php`:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\TablePersister::class,
    'retention' => [
        'default' => 90, // Keep logs for 90 days by default
        'tables' => [
            'Users' => 365,           // Keep user logs for 1 year
            'Orders' => 2555,         // Keep order logs for 7 years
            'Sessions' => 30,         // Keep session logs for 30 days
            'ComplianceLogs' => false, // Never delete (keep forever)
        ],
    ],
],
```

### How Retention Works

- Tables listed in `tables` use their specific retention period
- Tables **not** listed inherit the `default` retention period
- If no `default` is configured, falls back to 90 days
- Set a table's retention to `false` to disable cleanup entirely (keep logs forever)

### Table Key Naming

Table keys in the `tables` array must match the `source` column in your audit logs exactly (case-sensitive). By default, CakePHP uses CamelCase table names:

```php
'tables' => [
    'Users' => 365,               // Match CamelCase table name
    'OrderItems' => 730,          // Not 'order_items' or 'orderitems'
    'MyPlugin.Users' => 365,      // Plugin-prefixed tables also supported
],
```

Check your actual `source` values in the `audit_logs` table to ensure the keys match.

### Disabling Retention for Specific Tables

For compliance or legal requirements, you may need to keep certain logs forever. Set the table's retention to `false`:

```php
'AuditStash' => [
    'retention' => [
        'default' => 90,
        'tables' => [
            'FinancialTransactions' => false, // Never delete
            'UserConsentLogs' => false,       // Never delete
        ],
    ],
],
```

When running cleanup for a table with disabled retention:

```bash
bin/cake audit_stash cleanup --table FinancialTransactions --force
# Output: Retention is disabled for table "FinancialTransactions". No logs will be deleted.
```

### Alternative: Selective Cleanup via Script

If you prefer not to use config-based retention, you can selectively cleanup specific tables using the `--table` flag:

```bash
#!/bin/bash
# cleanup-audit-logs.sh
bin/cake audit_stash cleanup --table Sessions --force
bin/cake audit_stash cleanup --table ApiRequests --force
# Orders and Users are intentionally not cleaned
```

**Warning**: Setting a table's retention to `0` will delete **all** logs for that table immediately - this is probably not what you want! Use `false` instead to keep logs forever.

## Running Cleanup

The cleanup command provides several options:

```bash
# Preview what would be deleted (dry run)
bin/cake audit_stash cleanup --dry-run

# Clean up logs older than configured retention period
bin/cake audit_stash cleanup --force

# Clean up logs for specific table only
bin/cake audit_stash cleanup --table Users --force
```

## Automated Cleanup via Cron

Add to your crontab to run cleanup automatically:

```bash
# Run cleanup daily at 2am
0 2 * * * cd /path/to/app && bin/cake audit_stash cleanup --force
```

**Note**: The cleanup command only works with `TablePersister`.
For Elasticsearch, use [Index Lifecycle Management (ILM)](https://www.elastic.co/guide/en/elasticsearch/reference/current/index-lifecycle-management.html) policies instead.

