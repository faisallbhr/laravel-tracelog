# TraceLog — Transaction Awareness

**Status:** Implementation specification / proposed design  
**Target:** `faisallbhr/laravel-tracelog`, branch `dev`  
**Compatibility target:** Laravel 11, 12, 13; PHP versions supported by the package  
**Goal:** Preserve every observed Eloquent event while accurately explaining whether its database transaction committed, rolled back, or remains unresolved.

## 1. Problem

TraceLog currently writes Eloquent changes to JSONL as soon as their events fire. A later `DB::rollBack()` reverses database writes but **cannot undo JSONL**. The viewer may therefore show `updated` as though it were durable. This is especially confusing when a reported exception follows several changes.

Do **not** delete or rewrite historical Eloquent events. They are useful evidence of what code attempted. Add transaction context and append-only lifecycle records, then derive a conservative persistence outcome in the reader/viewer.

## 2. Scope and non-goals

### In scope

- Automatic instrumentation for **both** manual `DB::beginTransaction()` / `DB::commit()` / `DB::rollBack()` and callback `DB::transaction(fn () => ...)`.
- Associate *watched* Eloquent events with the **actual model database connection**, transaction identity and nesting level at the instant of the event.
- Distinguish root commit, root rollback, nested rollback to savepoint, and nested release/decrement (which **is not** durable commit).
- Multi-connection isolation; transactions outside the watched scope should not produce unnecessary trace noise.
- Append-only JSONL and backwards-compatible TraceReader + Flow Debugger interpretation.
- Correct cleanup in request/worker scopes and queue jobs; no cross-job leakage.
- Meaningful automated tests covering edge cases below.

### Out of scope

- SQL query tracing; general-purpose audit log; intercepting raw PDO transactions bypassing Laravel; distributed transactions or two-phase commit.
- Guaranteeing transaction outcome after abrupt process termination, power failure, database disconnect, or external DB-side rollback without Laravel events.
- Claiming that a successful SQL statement outside an explicit transaction is durable in every possible database configuration.
- Treating exception reporting as proof of rollback.

## 3. Key semantic rules

1. **An Eloquent event is an observation, not proof of commit.** Never hide or erase it after rollback.
2. `transaction_status` is determined **per model event**, not globally per trace. One trace may contain committed, rolled-back, non-transactional, and unknown events.
3. The relevant connection is `$model->getConnection()` (including the resolved default connection), **not** simply `DB::connection()` without a name.
4. Root commit (transaction level returns to zero) can mark surviving changes on that connection as committed. Inner `commit()` alone cannot.
5. Nested rollback invalidates only changes from the rolled-back nesting scope **if the database/driver actually supports and executed the appropriate savepoint behavior**. If not provable, use `unknown`, not a fabricated status.
6. A rollback at an outer level invalidates all descendant changes still dependent on that outer transaction, even if an inner scope previously committed.
7. An exception event is independent: `reported exception != rolled back transaction`.
8. Only explicit watch enables transaction lifecycle logging, consistent with checkpoint/exception behavior. A model with `Traceable` alone may still emit Eloquent records; its transaction metadata must not force noisy global transaction lifecycle logging. Define and test how such events are shown (prefer `unknown` when uncorrelatable).
9. No additional public `TraceLog::transaction()` API.
10. Preserve existing trace ID, tags, source and log partition behavior.

## 4. Proposed architecture

- `Internal/TransactionTracker` — scoped, in-memory state indexed by **connection identity**, tracking root transaction and nested frames/savepoints. Must never store unbounded full model payloads.
- `Listeners/DatabaseTransactionListener` — consumes Laravel's `TransactionBeginning`, `TransactionCommitted`, `TransactionRolledBack` events and delegates to tracker. Register listeners through the service provider.
- `Listeners/EloquentEventListener` — asks tracker for metadata for the model's **resolved connection**, immediately before writing the event.
- `Internal/TraceWriter` — remains append-only; transaction lifecycle records go through the same writer.
- `Internal/TraceReader` — joins lifecycle records to model events and derives effective outcomes; supports legacy records without transaction metadata.
- Viewer — shows a small, non-misleading transaction badge and optional transaction lifecycle entries.

**Important:** Do not assume Laravel's transaction event payload includes a reliable savepoint identifier or final level. Verify actual event dispatch order, `$connection->transactionLevel()` at listener time, and driver behavior against Laravel 11/12/13 source/tests. Build an adapter/normalizer that captures **before/after levels** reliably rather than guessing from the event name. Document any driver/version limitations. Never assume every nested `TransactionCommitted` event means a physical SQL `COMMIT`.

### Connection identity

Use a stable per-scope identity for the actual connection object (e.g. `spl_object_id` internally) plus connection name for display; if separate connections can share names, distinguish them internally. Persist a non-sensitive, trace-local `connection_key` and optionally `connection_name`, never credentials/DSN. Verify behavior with read/write connections and reconnection.

### Transaction identity

Assign a trace-local `transaction_id` (unique within a trace and connection) for each root transaction; identify nested frames with `transaction_level` and, if needed, `frame_id` / parent frame ID. Do not reuse IDs after rollback and restart. Avoid coupling transaction IDs to model IDs.

### When watch starts mid-transaction

A transaction may already be open before `TraceLog::watch()`. The tracker must detect the model connection's current `transactionLevel()` at event time. If beginning history is unavailable, mark the transaction as **partially observed**. You may associate later lifecycle events if provable; do not claim committed/rolled back without reliable correlation. Add explicit tests.

### When watch stops mid-transaction

If `TraceLog::stop()` is called before commit/rollback, keep previously written model events but do not invent an outcome. If lifecycle tracking is deliberately retained until the transaction ends, prove that retention cannot leak across scopes and document it; otherwise report `unknown`.

## 5. Suggested JSONL records

Existing Eloquent records keep their current fields and may add:

```json
{
  "timestamp": "2026-11-17T09:21:50.000000+00:00",
  "trace_id": "363d3349",
  "tags": ["sales-order"],
  "event": "updated",
  "model": "App\\Models\\SalesOrder",
  "id": 2773540,
  "source": "app/Services/OrderService.php:42",
  "transaction": {
    "id": "tx-1",
    "connection_key": "conn-1",
    "connection_name": "mysql",
    "level": 1,
    "frame_id": "frame-1"
  }
}
```

Append transaction lifecycle records (illustrative schema; choose one consistent canonical shape):

```json
{
  "timestamp": "2026-11-17T09:21:50.100000+00:00",
  "trace_id": "363d3349",
  "tags": ["sales-order"],
  "event": "transaction",
  "source": null,
  "transaction": {
    "id": "tx-1",
    "connection_key": "conn-1",
    "connection_name": "mysql",
    "action": "rollback",
    "from_level": 1,
    "to_level": 0,
    "frame_id": "frame-1"
  }
}
```

- `action`: `begin`, `commit`, `rollback` (optionally distinguish `savepoint_rollback` / `nested_commit` if this makes reconstruction safer).
- `source` remains present to satisfy current reader validation; nullable for framework-generated lifecycle records.
- No `model`/`id` required on transaction records. Update `TraceReader::isValidEvent()` accordingly; **do not** misclassify them as model events.
- No eager `status: committed` on Eloquent records: their outcome is determined **later** from lifecycle events.
- Legacy JSONL without `transaction` remains readable and displays `Unknown` or `Not tracked`, not `Committed`.
- Keep append-only ordering deterministic; if timestamps tie, use original file/line order. File rotation and date partitions may separate event and outcome; design a bounded lookup strategy or show `Unknown` rather than silently asserting a final state.

## 6. Outcome resolution

Recommended UI statuses:

| Status | Meaning |
|---|---|
| `Committed` | Reliable root commit observed for this model event's surviving transaction scope |
| `Rolled back` | Reliable rollback observed that invalidated this model event's scope |
| `Pending` | Transaction is known open within the observed live stream; not yet finalized |
| `Unknown` | Missing/partial lifecycle, unsupported driver/savepoint semantics, incomplete logs, or interrupted process |
| `Outside transaction` | No active Laravel-managed transaction on the model connection when the event fired; **not** equivalent to a proven commit |

Nested example:

```text
BEGIN outer (level 1)
  update A              => pending
  BEGIN inner (level 2)
    update B            => pending
  ROLLBACK inner to L1  => B rolled back, A still pending
  update C              => pending
COMMIT outer            => A and C committed, B remains rolled back
```

Another example:

```text
BEGIN outer
  update A
  BEGIN inner
    update B
  COMMIT inner           => A and B still pending
ROLLBACK outer          => A and B rolled back
```

If a DB driver or Laravel version does not execute actual savepoint rollback, tests must establish actual DB state and the tracker must use conservative `Unknown` where the event stream cannot establish what was undone.

## 7. Integration and lifecycle

### Service provider

Register the tracker as scoped. Attach listeners to Laravel transaction events. Make sure event listeners do not allocate a trace ID, write JSONL, or keep heavy state when there is no relevant active watch/trace.

### Eloquent event listener

Read transaction level from the **same connection object as the model**. Include transaction metadata only when trustworthy. Avoid introducing a new query; `transactionLevel()` is local connection state. Existing Eloquent diff, sensitive attribute filtering, and `Traceable` behavior must remain unchanged.

### Exception correlation (verified against current `dev` implementation)

The current implementation includes `src/Internal/ExceptionRecorder.php`, registered as a scoped service in `TraceLogServiceProvider`. It writes `event: exception` using `TraceContext::metadata()` and `TraceWriter`, with class, message (redacted by default), and throw-site `source`. Laravel's `ExceptionHandler::reportable()` callback invokes it; `JobExceptionOccurred` also invokes it. A `WeakMap<Throwable, true>` deduplicates the same Throwable object **within the same recorder instance/scope**. Do not assume deduplication across worker processes, attempts, or recreated scopes. `TRACELOG_EXCEPTION_MESSAGES` opts into raw exception messages; transaction records must never copy exception messages.

**Hard rule:** exception reporting is **not** transaction lifecycle instrumentation. `report($e)` can occur before or after `DB::rollBack()`, and a reported exception can be caught followed by a successful commit. A rollback may occur without any exception being reported. Only database lifecycle evidence may resolve transaction outcomes. Preserve actual observed timeline order, not a synthetic "exception -> rollback" ordering.

For the same trace, Eloquent records, checkpoints, exception records and transaction lifecycle records must coexist in the append-only JSONL. Exception records are not model changes, are not assigned a rollback status, and do not change transaction frames. The viewer may display a trace-level exception indicator, but must never infer all model changes rolled back from that indicator.

**Queue ordering:** the service provider currently registers `JobExceptionOccurred` recording before `registerQueueHooks()` registers its reset listener. Preserve that order (or explicitly consolidate listeners) so recording happens while the propagated watch/context is still active. Tracker cleanup must occur after recording, but should not alter the original exception or Laravel's queue handling. Do not assume every job failure implies DB rollback; observe the actual DB transaction lifecycle.

**Watch scope:** `ExceptionRecorder` only records with `WatchRegistry::hasActiveWatch()`. A `Traceable` model alone does not enable exception correlation. Transaction tracking must not silently change this behavior or start writing unrelated transaction records. Test both the active and inactive paths.

**Failure isolation:** `ExceptionRecorder::record()` currently catches writer failures to avoid masking the original exception. Transaction instrumentation must likewise be non-disruptive; test failures and document any visibility gaps rather than asserting a definitive outcome when lifecycle records are missing.

### Queue workers

The queue payload already propagates watch/context; it does **not** propagate a live DB transaction across processes. Transaction IDs and open frames must **not** be copied as live transaction state to jobs. On `JobProcessed` / `JobExceptionOccurred`, clean up tracker state in the correct order relative to exception logging and `TraceLog::stop()`. Avoid cross-job state leakage; handle sync queue separately. A dispatched job that runs later uses its **own** database transaction identity while sharing the trace ID.

### Failure behavior

Observability should not change application transaction outcomes. Avoid callbacks that throw into business logic because logging failed. Decide and document fail-open handling consistent with existing TraceLog writer policy. Handle transaction event gaps conservatively.

## 8. Test strategy (mandatory)

Use **integration tests with real database connections**, not only mocked event dispatch. SQLite in-memory may be useful for quick cases, but use MySQL/MariaDB integration tests for production-relevant savepoint behavior. Test Laravel 11, 12, 13 in CI where supported. Assert **both** actual persisted database values and TraceLog's reconstructed status.

### A. Basic manual and callback transactions

- [ ] A1. Manual `beginTransaction -> update -> commit`: database persists; update = `Committed`.
- [ ] A2. Manual `beginTransaction -> update -> rollBack`: database unchanged; update = `Rolled back`.
- [ ] A3. `DB::transaction(fn () => update)`: persists; update = `Committed`.
- [ ] A4. Callback throws: transaction rolled back; exception is rethrown by Laravel; update = `Rolled back`; reported exception appears **only if actually reported**.
- [ ] A5. Manual `try/catch` rollback without `report($e)`: rollback recognized, no fabricated exception event.
- [ ] A6. Exception reported but caught and then transaction committed: update = `Committed`; exception remains separate.
- [ ] A7. Transaction begins and commits without watched Eloquent events: no unrelated noisy trace records; no new trace ID.
- [ ] A8. Watched event outside transaction: `Outside transaction`, not `Committed`.

### B. Nested transactions and savepoints

- [ ] B1. Outer update A, inner update B, inner rollback, outer commit: A = `Committed`, B = `Rolled back`; verify DB values.
- [ ] B2. Outer update A, inner update B, inner commit, outer rollback: **both** `Rolled back`.
- [ ] B3. Outer update A, inner update B, inner commit, outer commit: both `Committed` only after root commit.
- [ ] B4. Three nested levels; rollback deepest, commit middle, commit outer: only deepest changes rolled back.
- [ ] B5. Three nested levels; rollback middle: middle and deeper changes rolled back, outer survives if later committed.
- [ ] B6. Rollback inner, start a **new** inner at same numeric level, update C, commit outer: old inner B rolled back; new inner C committed (must not reuse frame identity).
- [ ] B7. Nested callback transaction throws, exception caught by outer code, outer continues and commits: inner rolled back; outer changes committed.
- [ ] B8. Nested callback returns normally, then outer throws: all dependent updates rolled back.
- [ ] B9. Verify exact Laravel event ordering and `transactionLevel()` observed by listeners for each supported version/driver; add regression tests rather than relying on assumptions.
- [ ] B10. Driver with no savepoint support or simulated missing lifecycle event: no false `Committed`/`Rolled back` claims.

### C. Connections and isolation

- [ ] C1. Two named connections: A rollback, B commit in same trace; A changes rolled back, B committed.
- [ ] C2. Default model connection vs explicitly named connection: metadata follows the **model**, not the facade's default.
- [ ] C3. Same model class on different connections; no accidental mixing of transaction IDs.
- [ ] C4. Root transaction IDs not reused across sequential transactions on same connection.
- [ ] C5. Read/write splitting or reconnect behavior: no stale identity attribution; if ambiguous, `Unknown`.
- [ ] C6. Unwatched transaction on another connection does not create trace noise.

### D. Scope, timing, and resilience

- [ ] D1. Watch begins before transaction; expected full correlation.
- [ ] D2. Watch begins **inside** an already-open transaction; no invented beginning; final outcome only if provable.
- [ ] D3. Watch stops while transaction open; no leaked context; earlier events remain, status conservative.
- [ ] D4. Transaction opened before any watched model event; trace ID generated lazily and correlated correctly.
- [ ] D5. `Traceable` model without explicit watch: previous behavior preserved, no global lifecycle spam.
- [ ] D6. Repeated `watch()` calls and tags updates: no duplicate lifecycle records or broken correlation.
- [ ] D7. A rollback happens with no exception; rollback still recognized.
- [ ] D8. Exception occurs but is caught and transaction commits; no false rollback.
- [ ] D9. Abruptly missing final commit/rollback event or truncated JSONL: status `Unknown`/`Pending` only when justified, never `Committed`.
- [ ] D10. Log rotation, pagination, and same-date multi-part traces: correct resolution across files.
- [ ] D11. Transaction crosses midnight/date partitions: no false final status; explicitly test reader behavior and document limitations.
- [ ] D12. Concurrent requests/processes using same log directory: no transaction ID collision or cross-trace attribution.
- [ ] D13. Large transaction with thousands of model events: tracker memory bounded; reader stays within documented limits.

### E. Queue lifecycle

- [ ] E1. Parent request trace ID propagated to async job; job's **new** transaction ID is distinct.
- [ ] E2. Successful queued job transaction: committed changes, same trace ID.
- [ ] E3. Failed queued job with rollback: rolled-back changes, exception correlation retained before cleanup.
- [ ] E4. Subsequent job on same worker: no stale watch, transaction frames or trace IDs.
- [ ] E5. Sync queue behavior: does not prematurely clear parent's transaction tracker.
- [ ] E6. Retry of a job: distinguish each attempt's transaction identity; never mark prior rolled-back attempt committed.

### F. Reader, viewer and compatibility

- [ ] F1. Legacy Eloquent JSONL without `transaction` still parses and renders.
- [ ] F2. New `transaction` records pass validation without model/id.
- [ ] F3. Checkpoint and exception records still parse and display; not treated as model changes.
- [ ] F4. Bad/malformed lifecycle payload is ignored or shown as `Unknown`, never causes false commit.
- [ ] F5. Mixed outcomes within one trace display **per event**; no blanket trace-wide rollback badge.
- [ ] F6. Nested commit does not display `Committed` before outer commit.
- [ ] F7. Rolled-back model changes remain visible and expandable (including diff).
- [ ] F8. Transaction metadata does not leak credentials, SQL, bindings or sensitive values.
- [ ] F9. Stable ordering when multiple events have identical timestamps.
- [ ] F10. UI distinguishes `Outside transaction`, `Pending`, `Unknown`, `Committed`, `Rolled back` accessibly without relying on color alone.

### G. Exception correlation integration (additional mandatory regression cases)

All tests in this section must exercise the **real current exception reporting integration**, not just construct JSONL fixtures. For cases with database writes, assert the actual final DB values **and** per-event transaction outcomes. Preserve the existing `ExceptionRecorderTest` coverage.

- [ ] G1. Manual transaction: update -> rollback -> `report($e)`. Update is `Rolled back`; exception follows rollback in timeline and shares trace ID/tags.
- [ ] G2. Manual transaction: update -> `report($e)` -> rollback. Exception precedes rollback; update is ultimately `Rolled back` after lifecycle resolution.
- [ ] G3. Manual transaction: update -> `report($e)` -> commit. Update is `Committed`; exception is shown but does **not** imply rollback.
- [ ] G4. Manual transaction: update -> rollback, no exception or `report()`. Update is `Rolled back`; no fabricated exception.
- [ ] G5. Callback `DB::transaction()`: update -> throw, exception caught outside -> `report($e)`. DB rollback is real, exception recorded once, correct event order.
- [ ] G6. Callback transaction: update -> throw, exception caught outside but **not reported**. DB rollback recognized; no exception record is invented.
- [ ] G7. Callback transaction: inner exception caught **inside callback**, `report($e)` then return normally. Outer transaction commits; changes remain `Committed`.
- [ ] G8. Same Throwable reported twice within one active scope, then rollback: only one exception record; rollback outcome unchanged.
- [ ] G9. Two distinct Throwable instances with same message/class: both reported once; neither influences transaction status.
- [ ] G10. Nested: outer update A -> inner update B -> inner rollback -> `report($e)` -> outer commit. A `Committed`, B `Rolled back`, exception shown once.
- [ ] G11. Nested: inner commit -> `report($e)` -> outer rollback. Both outer and inner changes `Rolled back`; inner commit never durable.
- [ ] G12. Three levels: deepest rollback, then new transaction at same numeric nesting level, then outer commit. Only changes in rolled-back frame reverted; exception correlation cannot conflate reused levels.
- [ ] G13. Two DB connections in one trace: connection A rolls back after reported exception; B commits. Correct independent outcomes, same trace metadata.
- [ ] G14. Exception reported without an explicit watch (even when a `Traceable` model exists): no exception or unrelated transaction lifecycle spam, no eager trace ID allocation.
- [ ] G15. Exception message redaction default and opt-in raw message: transaction metadata and viewer never accidentally copy raw message or SQL bindings.
- [ ] G16. Async job with propagated watch: update -> rollback -> `JobExceptionOccurred`; exception recorded before context reset, original trace ID retained, correct transaction outcome.
- [ ] G17. Async job failure where update already committed: job exception appears but committed update remains `Committed`.
- [ ] G18. Queue retry on same worker: prior attempt's rollback and exception do not leak frames or dedup state into next attempt; distinct attempt transaction identity.
- [ ] G19. Sync job failure inside parent's transaction: no premature parent context/tracker reset; subsequent parent commit/rollback resolves correctly.
- [ ] G20. Logging fails while recording exception or transaction event: original exception and database transaction semantics remain unchanged; missing evidence produces `Unknown`, not a guessed status.
- [ ] G21. JSONL rotation between update, rollback, and exception: reader resolves status across parts and preserves ordering (including identical timestamps).
- [ ] G22. Legacy exception/checkpoint/model events mixed with new transaction records: reader and viewer remain backwards compatible; exception record never requires `model`/`id`.
- [ ] G23. Reported exception in a transaction that started before `watch()`: if beginning or linkage cannot be established, show `Unknown`, never claim a definitive outcome without proof.
- [ ] G24. Cross-midnight transaction with exception reported on a different date: no false commit/rollback status when the reader cannot retrieve complete lifecycle evidence.

**Implementation note:** Existing `JobExceptionOccurred` listener order is a property of the current code, not a substitute for a regression test. Verify order on Laravel 11, 12 and 13 and ensure the tracker is cleaned even when queue propagation is disabled. Test nested/savepoint behavior on a real savepoint-capable database (MySQL/MariaDB) rather than relying solely on SQLite or synthetic Laravel events.

## 9. Acceptance criteria

Implementation is complete only when:

1. Manual and closure transaction examples work without any new public TraceLog API.
2. Nested/savepoint tests verify **database state** and trace status, including reuse of the same nesting level.
3. Connection identity is accurate for model events, including two simultaneous connections.
4. Rollback never deletes earlier Eloquent events; append-only JSONL remains valid.
5. Inner commit is never mislabeled as durable commit.
6. Exception correlation remains independent of rollback; all G-series integration tests pass, including report-before/after-rollback, caught exceptions followed by commit, nested transactions, and queue failures.
7. Legacy traces, checkpoint records and exception records remain readable.
8. Queue workers do not leak transaction state; asynchronous jobs do not inherit live database transactions.
9. Uncertainty is represented honestly (`Unknown`) instead of guessed.
10. Test suite passes on supported Laravel versions and at least one real savepoint-capable DB; limitations are documented.

## 10. Suggested implementation sequence

1. Write event-order probe tests for Laravel transaction events (manual, callback, nested) against SQLite and MySQL/MariaDB. Record actual levels before deciding the normalizer.
2. Implement scoped `TransactionTracker` and connection/frame identity with focused unit tests.
3. Add listener wiring and enrich Eloquent records without changing existing diffs.
4. Append lifecycle JSONL records and update reader validation.
5. Implement conservative per-event outcome reconstruction and cross-partition handling.
6. Add small status badges/timeline affordances to viewer.
7. Run the entire A–G matrix, including real exception reporting and queue event ordering; document unsupported drivers and recovery gaps.

**Instruction for implementation agent:** Do not shortcut nested transaction tests with synthetic event payloads. First confirm Laravel's real event semantics and the actual database result; if a requested guarantee cannot be supported, preserve `Unknown` and report the limitation rather than manufacturing certainty.
