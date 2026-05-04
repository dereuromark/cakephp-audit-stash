# Features Overview

The plugin ships several optional building blocks on top of the core audit
trail. Each is documented on its own page; this overview is the map.

## Admin Viewer

A self-contained UI under `/admin/audit-stash` (route prefix configurable via
`AuditStash.routePath`). Includes a dashboard, table-coverage report, search,
side-by-side and inline diffs, per-record timeline, and CSV/JSON export.

→ [Admin Viewer](./viewer)

## Revert & Restore

Use any past audit row to put a record back the way it was — full revert,
partial revert (selected fields only), or restore of a deleted record.
Each operation writes its own `revert` audit entry, so undo history is
itself auditable. Available from the admin UI and via `RevertService` for
programmatic use.

→ [Revert & Restore](./revert)

## Monitoring & Alerting

Real-time rule engine that spots mass deletions, off-hours activity, and
anything else you can express as a query. Notifies via email, webhook, or the
Cake log channel.

→ [Monitoring & Alerting](./monitoring)

## Retention & Cleanup

Per-table retention policies plus a CLI cleanup command with dry-run support
— suitable for cron.

→ [Retention & Cleanup](./retention)

## Tamper-Evidence

Optional SHA-256 hash chain over rows for GoBD / SOX / HIPAA-grade integrity.
Each row links to its predecessor; one CLI command verifies the entire chain.
Off by default, opt-in per persister.

→ [Tamper-Evidence](./tamper-evidence)

## GDPR

Helpers and recipes for redacting personal data, exporting subject-access
requests, and reconciling audit retention with GDPR erasure obligations.

→ [GDPR](./gdpr)
