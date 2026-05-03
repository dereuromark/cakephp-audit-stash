# Monitoring & Alerting

The plugin includes a real-time monitoring system that can detect suspicious activities and send notifications through various channels (email, webhooks, logs, etc.).

## How It Works

The monitoring system:
1. Listens to audit log events in real-time
2. Checks each log against configured rules
3. Triggers alerts when rules match
4. Sends notifications through configured channels

## Configuration

Enable monitoring and configure rules in your `config/app.php` or `config/app_local.php`:

```php
'AuditStash' => [
    'monitor' => [
        'enabled' => true,

        // Define monitoring rules
        'rules' => [
            // Detect mass deletions
            'mass_delete' => [
                'class' => \AuditStash\Monitor\Rule\MassDeleteRule::class,
                'threshold' => 10,              // Trigger if 10+ deletes
                'timeframe' => 300,             // Within 5 minutes (seconds)
                'tables' => ['users', 'orders'], // Only for these tables (optional)
                'severity' => 'critical',
                'channels' => ['email', 'log'],  // Send to these channels
            ],

            // Detect activity outside business hours
            'off_hours' => [
                'class' => \AuditStash\Monitor\Rule\UnusualTimeActivityRule::class,
                'business_hours' => ['start' => '08:00', 'end' => '18:00'],
                'business_days' => [1, 2, 3, 4, 5], // Monday-Friday (1=Monday, 7=Sunday)
                'tables' => ['financial_records', 'payroll'],
                'severity' => 'medium',
                'channels' => ['log', 'email'],
            ],

            // Alert when sensitive fields are touched
            'sensitive_fields' => [
                'class' => \AuditStash\Monitor\Rule\SensitiveFieldRule::class,
                'tables' => ['Users', 'ApiKeys'],         // Optional; empty = all sources
                'fields' => ['password', 'role', 'two_factor_secret', 'api_token'],
                'severity' => 'high',
                'channels' => ['log', 'email'],
            ],
        ],

        // Configure notification channels
        'channels' => [
            'email' => [
                'class' => \AuditStash\Monitor\Channel\EmailChannel::class,
                'to' => ['security@example.com', 'admin@example.com'],
                'from' => 'audit@example.com',
                'template' => 'AuditStash.audit_alert', // Email template (default)
            ],

            'webhook' => [
                'class' => \AuditStash\Monitor\Channel\WebhookChannel::class,
                'url' => 'https://hooks.example.com/audit-alerts',
                'headers' => [
                    'Authorization' => 'Bearer your-token-here',
                ],
                'retry' => 3, // Number of retry attempts
            ],

            'log' => [
                'class' => \AuditStash\Monitor\Channel\LogChannel::class,
                'scope' => 'audit_alerts', // Log scope
            ],
        ],
    ],
],
```

## Built-in Rules

### MassDeleteRule

Detects when multiple delete operations occur within a short timeframe.

**Configuration options:**
- `threshold` (int): Number of deletes to trigger alert (default: 10)
- `timeframe` (int): Time window in seconds (default: 300)
- `tables` (array): Specific tables to monitor (optional, monitors all if not set)
- `severity` (string): Alert severity level (default: 'critical')

### UnusualTimeActivityRule

Detects activity outside normal business hours.

**Configuration options:**
- `business_hours` (array): Start and end times (default: 08:00-18:00)
  ```php
  ['start' => '08:00', 'end' => '18:00']
  ```
- `business_days` (array): Days of week to consider business days (default: [1,2,3,4,5])
  - 1 = Monday, 7 = Sunday
- `tables` (array): Specific tables to monitor (optional)
- `severity` (string): Alert severity level (default: 'medium')

### SensitiveFieldRule

Detects modifications to fields that you've flagged as sensitive (passwords, tokens, role assignments, etc.). Looks at the `changed` payload for create / update events and at `original` for deletes, so password rotations and API-key revocations both fire.

**Configuration options:**
- `fields` (array, required): Field names to flag. Empty means "no sensitive fields configured" — the rule never matches.
- `tables` (array): Restrict to these source tables. Empty (default) matches any source.
- `severity` (string): Alert severity level (default: 'high')

**Example:**
```php
'sensitive_fields' => [
    'class' => \AuditStash\Monitor\Rule\SensitiveFieldRule::class,
    'tables' => ['Users'],
    'fields' => ['password', 'role', 'email'],
    'severity' => 'high',
    'channels' => ['email', 'webhook'],
],
```

The alert message lists the matched fields and the alert context includes both `matched_fields` and `configured_fields`, so downstream channels can render which sensitive field actually fired.

## Available Channels

### EmailChannel

Sends alerts via email using CakePHP's Mailer.

**Configuration:**
- `to` (string|array): Recipient email address(es)
- `from` (string): Sender email address
- `template` (string): Email template name (default: 'AuditStash.audit_alert')

**Email Templates:**

The plugin includes default HTML and text templates at:
- `templates/email/html/audit_alert.php`
- `templates/email/text/audit_alert.php`

You can override these by creating your own templates in your app's `templates/email/` directory.

### WebhookChannel

Posts alert data to an external URL as JSON.

**Configuration:**
- `url` (string, required): Webhook URL
- `headers` (array): Additional HTTP headers
- `retry` (int): Number of retry attempts on failure (default: 1)

**Payload format:**
```json
{
    "rule_name": "MassDelete",
    "severity": "critical",
    "message": "Mass deletion detected: 15 records deleted from users in the last 5 minute(s)",
    "audit_log": {
        "id": 123,
        "type": "delete",
        "source": "users",
        "primary_key": 456,
        "transaction_key": "550e8400-e29b-41d4-a716-446655440000",
        "created": "2024-03-15T14:30:00+00:00"
    },
    "context": {
        "threshold": 10,
        "timeframe_seconds": 300,
        "delete_count": 15,
        "table": "users"
    }
}
```

### LogChannel

Writes alerts to CakePHP log files.

**Configuration:**
- `scope` (string): Log scope name (default: 'audit_alerts')

Alerts are written to your configured log with appropriate severity levels:
- `critical` → CRITICAL
- `high` → ERROR
- `medium` → WARNING
- `low` → INFO

## Creating Custom Rules

Extend `AbstractRule` to create custom monitoring rules:

```php
namespace App\Monitor\Rule;

use AuditStash\Model\Entity\AuditLog;
use AuditStash\Monitor\Rule\AbstractRule;

class CustomRule extends AbstractRule
{
    public function matches(AuditLog $auditLog): bool
    {
        // Return true if this log should trigger an alert
        return $auditLog->type === 'delete' && $auditLog->source === 'critical_data';
    }

    public function getSeverity(): string
    {
        return $this->getConfig('severity', 'high');
    }

    public function getMessage(AuditLog $auditLog): string
    {
        return sprintf(
            'Critical data deleted: %s',
            $auditLog->primary_key
        );
    }

    public function getContext(AuditLog $auditLog): array
    {
        return [
            'table' => $auditLog->source,
            'id' => $auditLog->primary_key,
        ];
    }
}
```

Then configure it:

```php
'AuditStash' => [
    'monitor' => [
        'rules' => [
            'custom' => [
                'class' => \App\Monitor\Rule\CustomRule::class,
                'severity' => 'high',
                'channels' => ['email'],
            ],
        ],
    ],
],
```

## Creating Custom Channels

Implement `ChannelInterface` to create custom notification channels:

```php
namespace App\Monitor\Channel;

use AuditStash\Monitor\Alert;
use AuditStash\Monitor\Channel\ChannelInterface;

class SlackChannel implements ChannelInterface
{
    public function __construct(protected array $config = [])
    {
    }

    public function send(Alert $alert): bool
    {
        $webhookUrl = $this->config['webhook_url'];
        $payload = [
            'text' => sprintf('[%s] %s', strtoupper($alert->getSeverity()), $alert->getMessage()),
            'attachments' => [
                [
                    'fields' => [
                        ['title' => 'Table', 'value' => $alert->getAuditLog()->source],
                        ['title' => 'Type', 'value' => $alert->getAuditLog()->type],
                    ],
                ],
            ],
        ];

        // Post to Slack webhook...
        return true;
    }
}
```

## Hooking Into the Monitor Lifecycle

Channels are the happy-path delivery mechanism. For everything else — per-context suppression, alert mutation, custom incident store, paging integration, error routing — the monitor dispatches three CakePHP events around every alert. Listen with `EventManager::instance()->on(...)` exactly like any other CakePHP event.

| Event | When | What you can do |
|---|---|---|
| `AuditStash.Monitor.beforeAlert` | After a rule matches and the `Alert` has been built, before any channel runs. Carries `rule`, `auditLog`, `alert`. | Call `$event->stopPropagation()` to suppress the alert (channels are skipped, `afterAlert` is not dispatched). Or replace the alert via `$event->setData('alert', $newAlert)` — the channels and `afterAlert` will see the replaced instance. |
| `AuditStash.Monitor.afterAlert` | After every channel for the rule has been called. Carries `rule`, `auditLog`, `alert`, and `results` (a `[channelName => bool]` map of per-channel success). | React on success / failure — write to your own incident store, page on partial failures, emit telemetry. |
| `AuditStash.Monitor.ruleException` | When a rule's `matches()` (or `createAlert()`) throws. Carries `rule`, `auditLog`, `exception`. | Forward the exception to Sentry / your error pipeline; today it is otherwise only logged. |

### Suppress an alert under specific conditions

```php
EventManager::instance()->on(
    'AuditStash.Monitor.beforeAlert',
    function (EventInterface $event): void {
        $alert = $event->getData('alert');
        // Don't page during the migration window for tenant 42.
        if ($alert->getAuditLog()->meta['tenant_id'] ?? null === 42) {
            $event->stopPropagation();
        }
    },
);
```

### Redact PII before delivery

```php
EventManager::instance()->on(
    'AuditStash.Monitor.beforeAlert',
    function (EventInterface $event): void {
        $alert = $event->getData('alert');
        $event->setData('alert', new Alert(
            $alert->getRuleName(),
            $alert->getSeverity(),
            $alert->getMessage(),
            $alert->getAuditLog(),
            // Strip user_email from the alert context before any channel sees it.
            array_diff_key($alert->getContext(), ['user_email' => 1]),
        ));
    },
);
```

### Route to a non-channel target after delivery

```php
EventManager::instance()->on(
    'AuditStash.Monitor.afterAlert',
    function (EventInterface $event) use ($incidentStore): void {
        if (in_array(false, $event->getData('results'), true)) {
            // At least one channel failed. Persist the alert to a fallback table
            // so on-call has something to triage even if Slack/email were down.
            $incidentStore->writeFallback($event->getData('alert'));
        }
    },
);
```

### Forward rule exceptions to your error reporter

```php
EventManager::instance()->on(
    'AuditStash.Monitor.ruleException',
    function (EventInterface $event) use ($sentry): void {
        $sentry->captureException($event->getData('exception'), [
            'extra' => [
                'rule' => $event->getData('rule'),
                'auditLog' => $event->getData('auditLog')->toArray(),
            ],
        ]);
    },
);
```

The events fire on the global `EventManager` (the same one CakePHP uses for everything else), so you can wire them up from `Application::bootstrap()`, a dedicated listener class, or anywhere else that runs at request boot time.

## Disabling Monitoring

To disable monitoring entirely:

```php
'AuditStash' => [
    'monitor' => [
        'enabled' => false,
    ],
],
```

Or don't configure the `monitor` section at all.

## Performance Considerations

- Rules are checked in real-time as audit logs are created
- Use specific `tables` filters in rules to avoid checking every audit event
- Webhook channels retry on failure, which may slow down requests
- Consider using `LogChannel` for high-volume environments and processing logs asynchronously
- The monitoring system only activates when `TablePersister` is used (not with ElasticSearch)

