# Audit View Helper

The plugin ships an `AuditHelper` that the bundled admin viewer uses to
render diffs, badges, and links. It's also useful in your own templates
when you want to show audit data alongside a record — for example, on a
profile page or a "history" tab on an entity view.

## Loading

```php
// In a controller, view, or AppView::initialize()
$this->loadHelper('AuditStash.Audit');
```

The helper auto-loads `HtmlHelper` for link/button generation. Output is
Bootstrap 5 markup (cards, badges, tables) — if your app uses a different
framework you can either wrap the output or copy the methods you need into
a project-local helper.

## Method reference

### Diff rendering

| Method | Returns | Notes |
|---|---|---|
| `diff($original, $changed)` | side-by-side HTML diff | one card per changed key; long text uses word-level diff |
| `diffInline($original, $changed)` | unified inline diff HTML | one table per changed key; `−`/`+` rows |
| `diffStyles()` | `<style>…</style>` block | call once in the layout to ship the diff CSS |
| `diffScript()` | `<script>…</script>` block | call once if you want the inline/side toggle buttons (`#btn-inline-diff` / `#btn-side-diff`) |

If `jfcherng/php-diff` is in your `composer.json`, both diff methods use it
for word-level granularity. Otherwise the helper falls back to a built-in
`DiffLib` (sebastian/diff-based) which is slightly less granular but
dependency-free.

```php
echo $this->Audit->diffStyles();      // once, in the layout
echo $this->Audit->diff($log->original, $log->changed);
```

### Formatting

| Method | Returns |
|---|---|
| `formatValue($value)` | one cell of HTML — handles `null`, bools, arrays (pretty-printed), and long strings |
| `formatUser($userId, $userDisplay = null)` | display name, optionally linked via `AuditStash.linkUser` |
| `formatRecord($source, $primaryKey, $displayValue = null)` | record reference, optionally linked via `AuditStash.linkRecord` |

`formatUser` and `formatRecord` honour the link configuration described in
the [Configuration](./configuration) page — string templates with
`{user}` / `{source}` / `{primary_key}` placeholders, array URLs, or
callables all work.

### Badges and summaries

| Method | Returns |
|---|---|
| `eventTypeBadge($type)` | colored Bootstrap badge — green/blue/red/yellow for create/update/delete/revert, secondary for custom event types |
| `transactionId($txn, $full = false)` | `<code>` short or full transaction id |
| `changeSummary($changed)` | "3 field(s): title, body, status" |

`eventTypeBadge` accepts either an `AuditLogType` enum case or any string,
so it works for the [custom action events](./usage#custom-action-events)
fed through `Audit::log()`.

### Tables

| Method | Returns |
|---|---|
| `metadata($meta)` | two-column key/value table for the `meta` payload |
| `fieldValuesTable($data, $title = null)` | full-width field/value table — handy for CREATE/DELETE rows where there's no diff to render |

### Action buttons

| Method | Returns |
|---|---|
| `revertButton($auditLogId, $options = [])` | `<a class="btn btn-warning">` linking to the revert preview |
| `restoreButton($source, $primaryKey, $options = [])` | `<a class="btn btn-success">` linking to the restore action |

See [Revert & Restore](/features/revert) for the full UI flow.

## Example: history tab on an entity view

```php
<?php
// templates/Articles/history.php
use AuditStash\AuditLogType;

$this->loadHelper('AuditStash.Audit');
echo $this->Audit->diffStyles();
?>

<h2>History — <?= h($article->title) ?></h2>

<?php foreach ($auditLogs as $log): ?>
    <div class="card mb-4">
        <div class="card-header d-flex gap-2 align-items-center">
            <?= $this->Audit->eventTypeBadge($log->type) ?>
            <span class="text-muted small">
                <?= $log->created->nice() ?>
                · <?= $this->Audit->formatUser($log->user_id, $log->user_display) ?>
            </span>
            <span class="ms-auto">
                <?= $this->Audit->transactionId($log->transaction_key) ?>
                <?php if ($log->type === AuditLogType::Update->value): ?>
                    <?= $this->Audit->revertButton($log->id) ?>
                <?php endif ?>
            </span>
        </div>
        <div class="card-body">
            <?= $this->Audit->diff($log->original, $log->changed) ?>
            <?= $this->Audit->metadata($log->meta) ?>
        </div>
    </div>
<?php endforeach ?>
```

## Configuration

The helper itself reads two config keys at construction time:

```php
'differOptions' => [
    'context'          => 2,
    'ignoreCase'       => false,
    'ignoreWhitespace' => false,
],
'rendererOptions' => [
    'detailLevel' => 'word',
    'showHeader'  => false,
    'lineNumbers' => true,
],
```

Override via `$this->loadHelper('AuditStash.Audit', [...])` if you need
different defaults — for example, `'ignoreWhitespace' => true` to suppress
trailing-whitespace-only changes in long text diffs.
