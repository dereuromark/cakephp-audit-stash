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

### How Retention Works

- Tables listed in `tables` use their specific retention period
- Tables **not** listed inherit the `default` retention period
- If no `default` is configured, falls back to 90 days

### Keeping Logs Forever (No Cleanup)

To prevent cleanup for specific tables, run the cleanup command with `--table` to target only specific tables:

```bash
# Only cleanup session logs, leave everything else
bin/cake audit_stash cleanup --table sessions --force
```

Or create a script that runs cleanup for each table you want cleaned:

```bash
#!/bin/bash
# cleanup-audit-logs.sh
bin/cake audit_stash cleanup --table sessions --force
bin/cake audit_stash cleanup --table api_requests --force
# orders and users are intentionally not cleaned
```

**Warning**: Setting a table's retention to `0` will delete **all** logs for that table immediately - this is probably not what you want!

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

