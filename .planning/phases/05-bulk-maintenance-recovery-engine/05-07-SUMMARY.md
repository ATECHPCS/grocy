# Plan 05-07 Summary — Single BEGIN IMMEDIATE idempotent apply (BULK-06, BULK-07)

**Status:** Complete. `bulk-apply` GREEN; all other bulk/taxonomy/conversion/default suites green
(one pre-existing, branch-dependent characterization failure noted below).

## What was delivered

- **`GrocyAiBulkService::ApplyPlan(int $planId, string $actor, ?string $confirmedChecksum = null)`** —
  the durable apply. One short `BEGIN IMMEDIATE` transaction; exactly one `COMMIT`; `ROLLBACK` on any
  throw; every applied item routed through the closed registered typed operation (`ResolveOperation` →
  `AssignProductTaxonomy(..., $joinExistingTransaction = true)`); checksum-bound idempotency; TOCTOU-free
  conflict re-check inside the lock. Plus private helpers `RecomputePlanChecksum` and `ApplyResult`, and
  the `APPLY_TRANSACTION_FAILED` constant.
- **`GrocyAiApiController::BulkPlanApply`** — authenticated, `MASTER_DATA_EDIT`-gated apply action.
  Closed confirmation body `{ "checksum": "<64-hex>" }` (`array_intersect_key`), resolves the session
  user (`GROCY_USER_ID`) as actor, delegates to `ApplyPlan`, maps blockers → 409 / `InvalidArgument` →
  400 / `Runtime` → 404.
- **`routes.php`** — one new route `POST /api/grocy-ai/bulk/plans/{planId}/apply`. No CLI apply path.
- **`tests/bulk.php`** `runBulkApply()` + **`tests/run.php`** `bulk-apply` dispatch.

## Files modified

- `custom/grocy_AI/src/GrocyAiBulkService.php`
- `custom/grocy_AI/src/GrocyAiApiController.php`
- `custom/grocy_AI/routes.php`
- `custom/grocy_AI/tests/bulk.php`
- `custom/grocy_AI/tests/run.php`
- `MISTAKES.md` (four observations from this slice)

## Transaction scheme (locked invariants honoured)

- Write lock taken up front with `$this->Db->exec('BEGIN IMMEDIATE')`; single `$this->Db->exec('COMMIT')`;
  `$this->Db->exec('ROLLBACK')` on any `\Throwable`. No PDO `beginTransaction()`/`commit()`/`rollBack()`/
  `inTransaction()` anywhere in `ApplyPlan` (asserted by a body-scoped source check).
- Each applied item dispatches the registered operation, whose delegate calls
  `AssignProductTaxonomy($objectId, $assignment, true)` — joining the outer transaction, opening/committing
  nothing of its own. Assignment shape + `ruleset_version` come only from the closed registry, never request
  input.
- No per-item commit (proven behaviourally: a mid-apply throw rolls back item 1's already-succeeded write).
- No network/provider call while the lock is held (delegates are native/module writes only).

## Idempotency & rollback — how the tests actually prove it

- **Checksum binding (BULK-07 entry gate):** `RecomputePlanChecksum` rebuilds the SHA-256 over the
  persisted immutable items and `hash_equals`-checks it against the stored `checksum` (and, when supplied,
  the caller's confirmed checksum) *before any write*. A tampered stored checksum, or a confirmed checksum
  differing from the reviewed plan, returns `blockers=['plan_checksum_mismatch']` with a byte-identical
  snapshot and no classification written.
- **Per-item completion ledger:** a successfully written item stamps `outcome='applied'` + `applied_at`
  in the same transaction. The ledger is consulted *before* conflict detection for each item, so an
  already-applied item is skipped (`skipped` count), never re-mutated and never false-flagged as a drift
  conflict against its own reviewed before-image. Re-applying the same plan reports
  `outcomes=['applied'=>0,'conflict'=>0,'skipped'=>2]` and issues **zero row changes** — proven by both
  row-value equality *and* a `total_changes()` delta of 0 (the plan-status UPDATE is conditional, so a
  no-op re-apply executes no INSERT/UPDATE/DELETE).
- **Byte-identical rollback:** a `BEFORE INSERT` trigger aborts product 2's classification write while all
  reads (incl. TOCTOU conflict detection) pass, so the throw genuinely fires mid-apply after item 1 was
  written in-txn. `ApplyPlan` returns `blockers=['apply_transaction_failed']`, and a full snapshot
  (native + module taxonomy + both bulk tables) is proven equal to the pre-apply snapshot by row-value
  equality (not `total_changes()`, which SQLite does not decrement on rollback). No item stamped, plan
  still `draft`, item 1's write undone.
- **Resume equals exactly one clean apply:** clearing the fault and re-running lands both reviewed values
  with exactly one classification row per product, and the resulting classification rows are asserted equal
  to a reference single clean apply on an identical fresh fixture.
- **Cross-plan / drift non-collision:** two plans over the same scope share a checksum but distinct rows;
  applying plan A leaves plan B's own item rows `pending` (not falsely already-applied); a plan whose stored
  checksum drifted is refused before the ledger is consulted.

## Conflict handling (D-07, TOCTOU-free)

`DetectApplyConflicts($planId)` is re-run after the lock is acquired, inside the transaction; a freshly
drifted, not-yet-completed item is recorded `outcome='conflict'` (never written), and the write re-fetches
`proposed_value_json` by `seq` (the apply-set does not carry the proposed value). A conflicted item keeps its
drifted current value; the valid sibling applies; plan status → `partially_applied`.

## Test results

- `php8.5 custom/grocy_AI/tests/run.php bulk-apply` → **`Bulk apply tests passed`** (EXIT 0).
- Other bulk modes green: `bulk-contract`, `bulk-schema`, `bulk-generate`, `bulk-registry`,
  `bulk-selection`, `bulk-conflict`.
- **`bulk-invariants` stays RED (expected):** its gate `bulkEngineSurfaceComplete()` requires
  `GeneratePlan, SetItemSelection, ApplyPlan, PreviewRollback, ExportPlan`. `ApplyPlan` now exists, so the
  RED message advanced to naming the still-missing `PreviewRollback, ExportPlan` (Plans 05-09/05-10). It
  will not go green until those land.
- All `taxonomy-*` (5) and `conversion-*` (13) modes green, except `conversion-characterization`, which
  fails with `branch_commit_mismatch` — a pre-existing, git-branch-dependent condition in this
  single-checkout worktree, unrelated to this slice (it references none of the files changed here).
- Default suite: `All 122 grocy_AI checks passed`.
- `php8.5 -l` clean on all five touched PHP files.

## Decisions

- **Result DTO:** `{ plan_id, checksum, status, blockers[], outcomes{applied,conflict,skipped}, actor }`.
- **Runtime failure blocker `apply_transaction_failed`** names the fail-closed rollback (distinct from the
  review/refusal blocker vocabulary), mirroring conversion's `activation_transaction_failed`. `unknown_operation`
  is preserved as a defensive rollback blocker (conflict detection already intercepts off-registry ops as
  conflicts, so it is unreachable in normal flow).
- **Optional `confirmedChecksum` 3rd arg** keeps the canonical `ApplyPlan($planId, $actor)` call valid while
  letting the endpoint's reviewed-checksum confirmation flow through the engine and return a bounded outcome.
- **Status vocab:** any conflict among the selected set → `partially_applied`; otherwise `applied`. The
  plan-status UPDATE is conditional (only when it changes) so idempotent re-apply is a true no-op.
- **Audit ledger (`grocy_ai_bulk_audit`) writes are deliberately NOT added here** — owned by Plan 05-08;
  the actor is accepted and threaded (echoed in the result) only.

## Not completed / out of scope

- Audit-ledger writes (05-08), rollback preview (05-09), export (05-10). `bulk-invariants` remains RED until
  those exist, by design.
