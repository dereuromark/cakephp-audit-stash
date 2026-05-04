# Revert & Restore

The plugin can use an audit row to put a record back the way it was — either
restoring every field from a past state, restoring only selected fields, or
recreating a row that has been deleted. All three operations are themselves
recorded as a new audit entry of type `revert`, so the history of an undo is
auditable as well.

## When to use it

| Operation | Source row | Result |
|---|---|---|
| **Full revert** | any UPDATE/CREATE audit row | record patched to that historical state |
| **Partial revert** | any UPDATE/CREATE audit row + field list | only those fields restored, others untouched |
| **Restore deleted** | a record that has a DELETE audit row | record recreated with its pre-deletion data |

The data used for revert/restore comes from the `original` payload on the
audit row, reconstructed via `StateReconstructorService`. Reverts are
transactional — the entity update and the new "revert" audit entry are
written in the same DB transaction, so a save failure leaves nothing behind.

## Admin UI

Two routes are wired by default under the admin prefix:

| Method | Path | Action | Purpose |
|---|---|---|---|
| `GET`  | `/admin/audit-stash/audit-logs/revert-preview/{id}` | `revertPreview` | Show diff between current state and the target audit row |
| `POST` | `/admin/audit-stash/audit-logs/revert/{id}` | `revert` | Execute the revert (full or partial via `fields[]`) |
| `GET` / `POST` | `/admin/audit-stash/audit-logs/restore/{source}/{primaryKey}` | `restore` | Preview then recreate a deleted row |

The viewer template renders Revert / Restore buttons on every relevant audit
row via the bundled `AuditHelper`:

```php
echo $this->Audit->revertButton($auditLog->id);
echo $this->Audit->restoreButton($auditLog->source, $auditLog->primary_key);
```

A successful operation flashes a success message and redirects to the
record's timeline view.

## Programmatic API

For workflows outside the admin UI (cron jobs, custom commands, your own
controllers), use `RevertService` directly:

```php
use AuditStash\Service\RevertService;

$service = new RevertService();

// 1. Full revert — restore every field to the audit row's state
$entity = $service->revertFull('Articles', $articleId, $auditLogId);

// 2. Partial revert — restore only selected fields
$entity = $service->revertPartial(
    source:     'Articles',
    primaryKey: $articleId,
    auditLogId: $auditLogId,
    fields:     ['title', 'body'],
);

// 3. Restore deleted — recreate a row from its DELETE audit
$entity = $service->restoreDeleted('Articles', $articleId);
```

Each call returns the saved `EntityInterface` on success, or `false` if the
target table rejects the save (validation failure, rules failure, missing
primary key, etc.). Failures don't roll back surrounding work because each
call wraps its own DB transaction.

## Audit of the revert itself

Every revert/restore writes a new audit row:

| Column | Value |
|---|---|
| `type` | `revert` (the `AuditLogType::Revert` enum case) |
| `source` | the table being reverted |
| `primary_key` | the record's id |
| `original` | the state the record was in *before* the revert |
| `changed` | the state it was put into by the revert |
| `meta.revert_to_audit_id` | the audit row that was replayed |
| `meta.revert_type` | `full`, `partial`, or `restore` |

The new row participates in the [tamper-evidence chain](./tamper-evidence)
like any other audit row. `AuditHelper::eventTypeBadge()` renders revert
events with a yellow badge so they're visually distinct from regular
create/update/delete activity.

## Restore caveats

`restoreDeleted()`:

- finds the most recent DELETE audit row for the given `source` /
  `primary_key`
- refuses to run if a row with that primary key already exists
- saves with `checkRules = false` to bypass rules that depend on related
  data that may also have been deleted; **no behaviors fire** on the
  restored row

Treat the restored row as a starting point — you may need to re-establish
associations (HABTM joins, has-many children) by hand or via a follow-up
restore pass.

## Configuration

```php
'AuditStash' => [
    'revert' => [
        'enabled'      => true, // master switch
        'auditReverts' => true, // log the revert itself as a `revert` row
    ],
],
```

| Key | Default | Effect |
|---|---|---|
| `revert.enabled` | `true` | When `false`, the three public `RevertService` methods throw `RuntimeException` ("AuditStash revert/restore is disabled…"). The admin UI still loads but any POST to revert/restore raises a 500. Use this when reverts must be forbidden plugin-wide regardless of who reaches the route. |
| `revert.auditReverts` | `true` | When `false`, revert/restore operations still mutate the record, but no `revert` audit row is written. Useful for high-volume systems where the revert audit trail itself doubles the row count and isn't needed — accept the loss of "who undid what" history in exchange. |

To gate by *role* rather than ban entirely, leave `revert.enabled = true`
and use the [`adminAccess` callback](./viewer#required-auditstash-adminaccess)
or your authorization stack to control who can reach the admin route.
