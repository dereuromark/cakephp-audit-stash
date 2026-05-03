# Testing Audit Logs

The plugin ships a small assertions trait so your own test suite can verify what was actually persisted to `audit_logs` after an action — not just what an in-memory event queue produced.

## Setup

Mix `AuditStash\TestSuite\AuditAssertionsTrait` into any `TestCase` and load the `plugin.AuditStash.AuditLogs` fixture alongside whatever fixtures your test needs:

```php
use AuditStash\TestSuite\AuditAssertionsTrait;
use Cake\TestSuite\TestCase;

class ArticlesControllerTest extends TestCase
{
    use AuditAssertionsTrait;

    protected array $fixtures = [
        'plugin.AuditStash.AuditLogs',
        'app.Articles',
    ];

    public function testArticleCreateIsAudited(): void
    {
        $this->post('/articles/add', ['title' => 'Hello']);

        $this->assertAuditLogged('Articles', 'create');
        $this->assertAuditFieldChanged('Articles', 'title', 'Hello');
        $this->assertAuditCount(1, 'Articles');
    }
}
```

The trait queries the `audit_logs` table directly through `AuditStash.AuditLogs`, so it works the same for controller integration tests, table-level unit tests, and any `Audit::log()` custom-event call.

> [!NOTE]
> The trait is unrelated to the `AuditStash.AuditLog` *behavior* — it doesn't add or wire anything. It only reads. Tests that don't load the `AuditLogs` fixture will fail on the first assertion with a missing-table error.

## Available assertions

### `assertAuditLogged(string $source, ?string $type = null, string $message = '')`

Passes when at least one row exists for `$source` (and, if given, `$type` — `create` / `update` / `delete` / any custom string from `Audit::log()`).

```php
$this->assertAuditLogged('Articles');                  // any event on Articles
$this->assertAuditLogged('Articles', 'create');        // a create on Articles
$this->assertAuditLogged('Users', 'user.login');       // a custom 'user.login' on Users
```

### `assertAuditNotLogged(string $source, ?string $type = null, string $message = '')`

Inverse of `assertAuditLogged()`. Use to confirm an action did *not* trigger a row — useful when testing blacklist / whitelist behavior config:

```php
// `created` / `modified` are blacklisted by default — touching them alone
// must not create an audit row.
$article->modified = new DateTime();
$this->Articles->save($article);

$this->assertAuditNotLogged('Articles');
```

### `assertAuditCount(int $expected, ?string $source = null, string $message = '')`

Exact-count assertion. Without `$source`, counts every row in `audit_logs`:

```php
$this->assertAuditCount(0);                  // no audit rows at all
$this->assertAuditCount(3, 'Articles');      // exactly 3 rows for Articles
$this->assertAuditCount(1, 'Users');         // exactly one Users event
```

Pair with `setUp()` that truncates the table (or the standard fixture rollback) to make the assertion meaningful.

### `assertAuditFieldChanged(string $source, string $field, mixed $expectedValue = null, string $message = '')`

Targets the **most recent** row for `$source` and checks that `$field` appears in the row's `changed` payload. With `$expectedValue` supplied, also asserts the changed-to value with `assertSame()`:

```php
$this->put('/articles/edit/1', ['title' => 'Renamed', 'body' => 'New body']);

$this->assertAuditFieldChanged('Articles', 'title');                 // field present
$this->assertAuditFieldChanged('Articles', 'title', 'Renamed');      // field == value
```

The "most recent" lookup uses `id DESC`, which matches insertion order under `TablePersister`. For a multi-event sequence where you need to assert on a specific earlier row, query `AuditStash.AuditLogs` directly with your own conditions.

> [!NOTE]
> This only inspects `changed`. For `delete` events the relevant payload lives under `original`; query the table directly when you need that.

## Custom assertions

The trait deliberately exposes a small surface so you can compose richer assertions without wrapping a different table query each time. The base query builder is `protected`:

```php
$rowsForUser = $this->buildAuditQuery('Articles', 'update')
    ->where(['user_id' => '42'])
    ->all();

$this->assertCount(2, $rowsForUser);
```

## Tips

- **`assertAuditLogged()` before `assertAuditFieldChanged()`** in the same test makes the failure mode obvious — if no row was logged at all, `assertAuditFieldChanged()` reports a less helpful "field not in changed payload of latest row" message.
- **Truncate per test** if your suite shares the audit table across cases — CakePHP's standard fixture rollback handles this when you list the fixture explicitly.
- **Combine with the `Audit::log()` facade** — custom events show up in the same table, so the same assertions verify them: `assertAuditLogged('Users', 'user.login')` works for events emitted via `Audit::log(type: 'user.login', ...)`.
- **Use `assertAuditNotLogged()` to verify blacklists** — if your `AuditLog` behavior config blacklists `last_seen_at`, write a test that updates only that field and asserts no row was created.
