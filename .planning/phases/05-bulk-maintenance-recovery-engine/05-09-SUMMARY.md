# Plan 05-09 Summary — Guarded rollback preview + execute (BULK-09)

**Status:** complete (backend). **Branch:** `codex/phase5-bulk-engine`.

## What shipped

### `GrocyAiBulkService` (`custom/grocy_AI/src/GrocyAiBulkService.php`)
- **`PreviewRollback(int|string $planId): array`** — a zero-write preview derived ONLY from the
  append-only `grocy_ai_bulk_audit` ledger. It reads the `applied` item rows (their immutable
  before/after images), re-reads each item's CURRENT written field through the shipped public read path
  (`ReadProductTaxonomy` via the existing `CurrentWrittenValue` helper), and marks an item `reversible`
  only when the current value STILL equals the audited after-image. A drifted item (a manual edit after
  the original apply) is `refused` with the pinned `manual_edit_after_apply` blocker and its inverse
  operation is withheld. Returns `{plan_id, plan_checksum, checksum, items, reversible, refused}`. The
  `checksum` is a deterministic rollback-plan SHA-256 over the full audit-derived reversal set
  (identities, both images, inverse operation types, ruleset version), stable across preview and execute.
- **`RollbackPlan(int $planId, string $actor, ?string $confirmedChecksum = null): array`** — reuses the
  exact forward-apply path: one raw `exec('BEGIN IMMEDIATE')`, one `COMMIT`, one `ROLLBACK` (no PDO txn
  idiom), the 05-04 named-operation registry, the 05-06 optimistic-concurrency re-read, and the
  append-only ledger. Each restore delegates to
  `AssignProductTaxonomy($objectId, <before-image assignment>, $joinExistingTransaction = true)`. A field
  that drifted between preview and execution is recorded per-item `conflict` and never written (no
  partial write). Idempotent: an item already `rolled_back` under this plan is skipped, so re-run or a
  resumed interrupted rollback repeats nothing; on any throw the whole transaction rolls back
  byte-identical and its audit rows vanish with it. Appends `rolled_back` audit rows inside the same
  transaction; on success the plan status becomes `rolled_back`.
- Private helpers `RollbackAppliedLedger`, `RollbackChecksum`, `RollbackResult`; new constants
  `AUDIT_EVENT_ROLLED_BACK = 'rolled_back'`, `ROLLBACK_MANUAL_EDIT = 'manual_edit_after_apply'`,
  `ROLLBACK_TRANSACTION_FAILED = 'rollback_transaction_failed'`.

### Endpoints (`GrocyAiApiController.php` + `routes.php`)
- `GET /api/grocy-ai/bulk/plans/{planId}/rollback-preview` → `BulkPlanRollbackPreview` (zero-write read).
- `POST /api/grocy-ai/bulk/plans/{planId}/rollback` → `BulkPlanRollback` (audited write).
- Both `PERMISSION_MASTER_DATA_EDIT`-gated (checked first), actor from session `GROCY_USER_ID`. The
  execute body is the closed, optional `{ "checksum": "<sha256>" }` only — any item list, value, or
  free-form CRUD/SQL is a bounded 400; a bounded engine outcome maps to 409, never a partial write. Each
  route registered exactly once. No CLI rollback path.

### Tests
- New `bulk-rollback` mode (`tests/bulk.php` `runBulkRollback()` + `tests/run.php` dispatch) proving:
  zero-write audit-derived preview; manual-edit-after-apply refusal that is never overwritten;
  single-transaction revert of eligible items; byte-identical mid-rollback write fault (write-path-only
  trigger; row-value snapshot proof); idempotent re-rollback; append-only `rolled_back` audit growth;
  permission gate + closed body + 409/404 on both endpoints.
- The `bulk-invariants` RED gate now names only the still-missing engine method (`ExportPlan`), via a new
  `bulkEngineMissingMethods()` helper so the gate shrinks as each plan lands.

## Verification (php8.5)
- `bulk-rollback` → `Bulk rollback tests passed`.
- All other bulk modes, `taxonomy-*`, and the default suite (`All 122 grocy_AI checks passed`) pass.
- `bulk-invariants` → `EXPECTED_RED: bulk.engine_invariants` naming only `ExportPlan` (05-10 owns it).
- Pre-existing environmental failure `conversion-characterization branch_commit_mismatch` is unchanged;
  no characterization files were touched.

## Decisions / notes
- **Rollback-plan checksum is stable** (computed over the full audit-derived reversal set, independent of
  per-item completion or live drift), mirroring the forward-apply model where the plan checksum is stable
  and drift is caught per-item. Per-item live drift is the optimistic-concurrency guard.
- **`rollback_transaction_failed`** blocker mirrors the existing `apply_transaction_failed` runtime
  fail-closed marker (distinct from the closed review/refusal vocabulary). A confirmed-checksum mismatch
  reuses the existing `plan_checksum_mismatch` token.
- **Unknown vs. never-applied plan:** an unknown plan id fails closed (RuntimeException → 404). A valid
  never-applied plan returns an empty reversible/refused preview (no write). No new closed-vocabulary
  token was introduced for "nothing to roll back," honoring the pinned-vocab constraint over the plan's
  "one bounded blocker" phrasing.
- **JS surface deferred:** per the backend scope of this task, the read-only rollback panel in
  `public/custom/grocy_AI/bulk-review.js` (+ `bulk-review.test.js`) was left to the UI track; the backend
  BULK-09 slice is complete.
