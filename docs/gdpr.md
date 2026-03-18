# GDPR Compliance

AuditStash provides built-in tools for GDPR compliance, supporting:

- **Right to Erasure (Article 17)**: Delete or anonymize user audit logs
- **Right to be Forgotten**: Anonymize PII while preserving audit trail integrity
- **Data Portability (Article 20)**: Export user audit data in JSON format

## Command Line Interface

The `audit_stash gdpr` command provides all GDPR operations:

```bash
# View statistics for a user's audit logs
bin/cake audit_stash gdpr stats --user-id=123

# Anonymize user's audit logs (recommended)
bin/cake audit_stash gdpr anonymize --user-id=123

# Preview anonymization without making changes
bin/cake audit_stash gdpr anonymize --user-id=123 --dry-run

# Delete user's audit logs completely
bin/cake audit_stash gdpr delete --user-id=123 --force

# Export user's audit logs
bin/cake audit_stash gdpr export --user-id=123
bin/cake audit_stash gdpr export --user-id=123 --output=/path/to/export.json
```

## Operations

### Stats

View statistics about a user's audit log footprint:

```bash
bin/cake audit_stash gdpr stats --user-id=123
```

Output:
```
GDPR Statistics
---------------
User ID: 123
Total records: 47

By table:
  - Articles: 23
  - Comments: 15
  - Users: 9

By event type:
  - update: 30
  - create: 12
  - delete: 5
```

### Anonymize (Recommended)

Anonymization is the recommended approach for GDPR compliance. It redacts personally identifiable information (PII) while preserving the audit trail for compliance and debugging purposes.

```bash
bin/cake audit_stash gdpr anonymize --user-id=123
```

What gets anonymized:

| Field | Before | After |
|-------|--------|-------|
| `user_id` | `123` | `anon_a1b2c3d4` |
| `user_display` | `John Doe` | `ANONYMIZED` |
| `meta.user` | `john@example.com` | `ANONYMIZED` |
| `meta.ip` | `192.168.1.100` | `0.0.0.0` |
| `meta.email` | `john@example.com` | `deleted@anonymized.local` |
| `original.email` | `old@example.com` | `[REDACTED]` |
| `changed.name` | `John Doe` | `[REDACTED]` |

Use `--dry-run` to preview changes:

```bash
bin/cake audit_stash gdpr anonymize --user-id=123 --dry-run
```

### Delete

Complete deletion removes all audit logs for a user. Use this only when required by specific regulations, as it destroys the audit trail.

```bash
# Requires --force flag
bin/cake audit_stash gdpr delete --user-id=123 --force

# Preview deletion
bin/cake audit_stash gdpr delete --user-id=123 --dry-run
```

### Export

Export all audit logs for a user in JSON format for data portability requests:

```bash
# Output to console
bin/cake audit_stash gdpr export --user-id=123

# Save to file
bin/cake audit_stash gdpr export --user-id=123 --output=/path/to/export.json
```

Export format:
```json
{
  "export_date": "2024-01-15T10:30:00+00:00",
  "user_id": "123",
  "record_count": 47,
  "audit_logs": [
    {
      "id": 1234,
      "transaction": "abc-123-def",
      "type": "update",
      "source": "Articles",
      "primary_key": "42",
      "display_value": "My Article Title",
      "original": {"title": "Old Title"},
      "changed": {"title": "New Title"},
      "meta": {"ip": "192.168.1.1"},
      "created": "2024-01-15T10:30:00+00:00"
    }
  ]
}
```

## Configuration

Configure GDPR behavior in `config/app.php`:

```php
'AuditStash' => [
    'gdpr' => [
        // Strategy for anonymizing user_id: 'hash', 'null', or 'placeholder'
        'anonymizeUserId' => 'hash',

        // Custom anonymization values for meta fields
        'anonymizeFields' => [
            'user' => 'ANONYMIZED',
            'email' => 'deleted@anonymized.local',
            'ip' => '0.0.0.0',
            'username' => 'ANONYMIZED',
            'user_agent' => 'ANONYMIZED',
        ],

        // PII fields to redact in original/changed data
        'piiFields' => [
            'email',
            'name',
            'first_name',
            'last_name',
            'phone',
            'address',
            'ip_address',
            'username',
        ],
    ],
],
```

### User ID Anonymization Strategies

| Strategy | Result | Use Case |
|----------|--------|----------|
| `hash` (default) | `anon_a1b2c3d4` | Allows grouping anonymized records |
| `null` | `null` | Complete removal of user association |
| `placeholder` | `DELETED_USER` | Clear indication of deletion |

## Programmatic Usage

Use `GdprService` directly in your application:

```php
use AuditStash\Service\GdprService;

$service = new GdprService();

// Get statistics
$stats = $service->getStats($userId);

// Anonymize logs
$count = $service->anonymize($userId);

// With custom options
$count = $service->anonymize($userId, [
    'anonymizeUserId' => 'null',
    'piiFields' => ['email', 'ssn', 'credit_card'],
]);

// Delete logs
$count = $service->delete($userId);

// Export logs
$json = $service->export($userId, 'json');
$array = $service->export($userId, 'array');

// Find logs for a user
$query = $service->findByUser($userId);
```

## Best Practices

1. **Prefer anonymization over deletion** - Anonymization preserves audit trail integrity while complying with GDPR.

2. **Always dry-run first** - Use `--dry-run` to preview changes before executing.

3. **Document retention policies** - Configure appropriate retention periods to automatically clean old logs.

4. **Audit GDPR operations** - Consider logging GDPR operations themselves for compliance documentation.

5. **Combine with retention policies** - Use the cleanup command to automatically remove old anonymized logs after a retention period.

## Integration with Retention Policies

Combine GDPR operations with retention policies for a complete data lifecycle:

```php
// config/app.php
'AuditStash' => [
    'retention' => [
        'default' => 90,        // Keep logs for 90 days
        'tables' => [
            'users' => 365,     // Keep user logs for 1 year
            'orders' => 2555,   // Keep order logs for 7 years (compliance)
        ],
    ],
    'gdpr' => [
        'anonymizeUserId' => 'hash',
    ],
],
```

Workflow:
1. User requests data deletion
2. Run `audit_stash gdpr anonymize --user-id=X`
3. Anonymized logs are retained for compliance period
4. Cleanup command removes old anonymized logs based on retention policy
