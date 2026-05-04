# Tamper-Evidence / Hash Chain

AuditStash can link every persisted audit row into a **SHA-256 hash chain**,
giving you tamper-evident logs: any later edit of a historic row invalidates
that row's hash and, transitively, every row that follows.

This is useful whenever *"prove nothing was silently edited"* is a requirement:

- **GoBD** (Germany) — § 146, § 147 AO require _Unveränderbarkeit_ of commercial records.
- **SOX** (US) — financial audit trails must be tamper-evident.
- **HIPAA** (US) — access logs to protected health information must detect modification.
- **EASA Part-M / ISO 27001** — change logs with integrity guarantees.
- **Legal e-discovery** — custody chains for litigation-hold records.

> [!NOTE]
> A hash chain proves **tamper-evidence**, not **tamper-prevention**. An
> attacker with direct DB access can still rewrite history — the chain
> just makes the rewrite detectable. Combine the chain with regular
> verification runs and offsite hash anchors (see _Anchoring_ below) for
> full-strength non-repudiation.

## Enabling the chain

The feature is opt-in and off by default; existing installs need do nothing.

### 1. Run the migration

```bash
bin/cake migrations migrate -p AuditStash
```

This adds two nullable columns — `prev_hash` and `hash` — and an index on
`hash`. Rows written before the migration will have `NULL` in both columns.
The verifier skips those legacy rows until it reaches the first row with a
stored `hash` (see _Backfilling_ below).

### 2. Turn on `hashChain` on the persister

In `config/app.php`:

```php
'AuditStash' => [
    'persister' => \AuditStash\Persister\TablePersister::class,
    'persisterConfig' => [
        'hashChain' => true,
    ],
],
```

From the next audit row onwards, every write goes through the chain.

## How the hash is computed

For each row, the persister computes:

```
hash = sha256( prev_hash || "|" || canonical_json(payload) )
```

Where:

- `prev_hash` is the `hash` of the previous row in primary-key order, or
  the empty string for the very first row.
- `payload` is all row fields **except** `id`, `hash`, and `prev_hash`.
  `prev_hash` contributes via the separate chain-link argument rather
  than as a payload field so both writer and verifier agree on the input.
  Schema-owned fields missing from the event payload are normalized to
  `null`, so nullable columns still hash consistently after a DB round-trip.
- `canonical_json` sorts associative-array keys recursively before JSON-
  encoding with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. List
  arrays keep their order because order is semantically meaningful
  there. `DateTimeInterface` values are normalized to second-precision
  strings, and PHP enums are normalized to their scalar value/name, so
  the hash round-trips cleanly across column-type precision and ORM
  type conversion.

Canonicalization makes the hash **deterministic across PHP versions,
machines and insertion orders** — two semantically equivalent rows always
produce the same hash. This matters for cross-environment verifiability
(developer laptop vs. production vs. a third-party auditor's re-run).

## Concurrency

Concurrent writers are a classic trap for naive hash chains: two
requests both read the same "last hash" and one of them produces an
orphaned link. AuditStash handles this by serializing chain writers per
table on MySQL/Postgres and then running the full `logEvents()` batch
inside a single transaction. Within that transaction it still reads the
last chain link with `SELECT ... FOR UPDATE`:

```sql
SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE;
```

On MySQL/Postgres, the advisory lock closes the empty-table bootstrap
race where there is no tail row yet to lock. A second writer waits until
the first writer commits, then reads the freshly-written tail. SQLite
serializes writes at the database level already.

> [!IMPORTANT]
> `hashChain` is currently only supported by `TablePersister`. The
> Elastic Search persister cannot offer the same ordering guarantee and
> will ignore the flag. If you need tamper-evidence on Elastic, route
> your audit events through `TablePersister` (SQL) and replicate to
> Elastic downstream.

## Verifying the chain

### Command line

```bash
bin/cake audit_stash verify_chain
bin/cake audit_stash verify_chain --table=AuditStash.AuditLogs --chunk=1000
```

Exits `0` if the chain is intact, `1` if a break is found. Schedule it:

```bash
# Daily integrity check at 03:15
15 3 * * * cd /var/www/app && bin/cake audit_stash verify_chain
```

or wire it into CI so a broken chain fails the build.

### Programmatically

```php
use AuditStash\Service\ChainVerifier;

$result = (new ChainVerifier())->verify(
    $this->fetchTable('AuditStash.AuditLogs'),
);

if (!$result->intact) {
    $this->logger->critical(sprintf(
        'Audit chain broken at row %d (position %d): %s',
        $result->brokenRowId,
        $result->rowsChecked,
        $result->reason,
    ));
}
```

The verifier streams rows in chunks (default 500) so very long chains
verify in bounded memory. Verification is read-only — it never writes to
the table it inspects.

## Detecting specific tampering patterns

The verifier catches the three common tamper modes:

| Tamper pattern                        | Detected via                                   |
|---------------------------------------|------------------------------------------------|
| Row payload silently edited           | Recomputed hash mismatches stored `hash`       |
| Row deleted from the middle           | Next row's `prev_hash` no longer matches       |
| Row inserted with forged hash         | Recomputed hash mismatches forged `hash`       |
| Rows reordered / swapped              | `prev_hash` pointers no longer form a valid line |

It does *not* catch a **truncation at the tail** — if an attacker
deletes the last N rows and stops there, the remaining chain is still
internally consistent. This is the fundamental limitation of every
append-only hash chain. Mitigations:

- **Anchor the tail periodically.** Write `last_hash` to an external
  system (a second database, S3 with object-lock, a trusted timestamping
  service, a blockchain, a signed email to `compliance@`) so you have an
  offsite pointer to compare against.
- **Count rows.** If you also track row counts offsite, a truncation
  shows up as a row-count mismatch.
- **Use per-day heartbeats.** Emit a daily "heartbeat" audit event;
  missing heartbeats reveal truncated ranges.

## Backfilling an existing table

Rows written before enabling the chain have `NULL` in `prev_hash` and
`hash`. You have three options:

1. **Leave them as-is.** The verifier skips legacy rows with `NULL`
   hashes and starts at the first row with a non-null `hash`. Historic
   rows are not protected, but new rows are.
2. **Seed the chain at the current tail.** Write a single synthetic row
   before enabling `hashChain` with a hand-picked `prev_hash` (typically
   the SHA-256 of a signed statement from the data controller: *"as of
   2026-04-15, the audit table contained 12,347 rows; no prior tamper
   evidence is asserted"*). All subsequent rows chain off that.
3. **Compute hashes for existing rows offline.** Only valid if you can
   assert those rows were *never* edited; otherwise you're laundering
   potentially-tampered data into a "verified" chain. Usually this is
   not what you want.

For most deployments **option 2 is the sensible choice**: it draws a
clean line between the pre-chain past and the chain-protected future,
with an auditable attestation for the transition.

## Impact on performance

Per row, `hashChain` adds:

- One extra `SELECT ... FOR UPDATE` per batch (not per row — the tail is
  read once, then carried forward in memory for the rest of the batch).
- One SHA-256 hash computation per row (~microseconds).
- One transaction wrap per `logEvents()` call (a single transaction
  covering the whole batch).

In practice the overhead is dominated by the row-level lock rather than
the hashing itself, and it's negligible for typical write rates. If your
audit write rate is high enough for lock contention to matter, you
already have a scaling problem that needs a dedicated audit-write queue.

## Example verification output

```text
$ bin/cake audit_stash verify_chain
Verifying hash chain on AuditStash.AuditLogs…
Chain intact — 12483 row(s) verified.
```

```text
$ bin/cake audit_stash verify_chain
Verifying hash chain on AuditStash.AuditLogs…
Chain broken at row id=8421 (position 8421): hash mismatch: stored 7f4a…, recomputed 9c18…
```

The `position` is the ordinal index of the first broken row in the
walk, useful when you want to correlate a break to a specific backup or
deployment window. The `id` is the primary key you'd look up in the
table.

## Further reading

- `src/Service/HashChain.php` — the pure-function hasher and verifier core.
- `src/Service/ChainVerifier.php` — the streaming table verifier.
- `src/Command/VerifyChainCommand.php` — the CLI wrapper.
- `tests/TestCase/Service/HashChainTest.php` — unit tests.
- `tests/TestCase/Service/ChainVerifierTest.php` — integration tests.
