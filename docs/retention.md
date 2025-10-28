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
            'users' => 365,      // Keep user logs for 1 year
            'orders' => 2555,    // Keep order logs for 7 years
            'sessions' => 30,    // Keep session logs for 30 days
        ],
    ],
],
```

## Running Cleanup

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

## Automated Cleanup via Cron

Add to your crontab to run cleanup automatically:

```bash
# Run cleanup daily at 2am
0 2 * * * cd /path/to/app && bin/cake audit_stash cleanup --force
```

**Note**: The cleanup command only works with `TablePersister`.
For Elasticsearch, use [Index Lifecycle Management (ILM)](https://www.elastic.co/guide/en/elasticsearch/reference/current/index-lifecycle-management.html) policies instead.

