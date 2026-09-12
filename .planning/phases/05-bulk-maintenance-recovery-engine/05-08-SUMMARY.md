# Plan 05-08 Summary — Append-only audit ledger (BULK-08)

## What was delivered

Every `ApplyPlan` now appends immutable audit records to the `grocy_ai_bulk_audit` ledger **inside the
same single `BEGIN IMMEDIATE` transaction** as the mutations they record, so audit and mutation commit
or roll back together. The ledger is INSERT-only and readable through a new permission-gated endpoint.

### Files modified
- `custom/grocy_AI/src/GrocyAiBulkService.php`
  - Pinned closed audit-event vocabulary: `AUDIT_EVENT_PREVIEWED = 'previewed'`, `AUDIT_EVENT_APPLIED = 'applied'`.
  - `ApplyPlan`: after the write lock is taken, computes one transaction-wide `$appliedAt` (`SELECT
    CURRENT_TIMESTAMP`) used for both the item completion stamp and every audit `event_at`; prepares one
    `INSERT INTO grocy_ai_bulk_audit` statement. Each **applied** item appends a row (`before_json` = the
    exact reviewed before-image, `after_json` = the exact written proposed value, `outcome='applied'`);
    each **conflict** item appends a row (`after_json`=NULL, `outcome='conflict'`). After the loop, when
    at least one item was applied or conflicted, two plan-level rows are appended: a `previewed` event
    (actor = the plan's `created_by`, `event_at` = the plan's immutable `created_at` = previewed
    timestamp) and an `applied` event (actor = the session user, `event_at` = `$appliedAt`, with final
    status/checksum/outcomes in `after_json`). All appends are before the single `COMMIT`.
  - New read-only `ReadPlanAudit(int $planId)` returning `{plan_id, records}` in stable `id` order.
- `custom/grocy_AI/src/GrocyAiApiController.php` — new `BulkPlanAudit` (MASTER_DATA_EDIT-gated, integer-id
  validation, 404 on unknown plan, read-only `ApiResponse` of the ordered ledger).
- `custom/grocy_AI/routes.php` — `GET /api/grocy-ai/bulk/plans/{planId}/audit`.
- `custom/grocy_AI/tests/bulk.php` — new `runBulkAudit()` (`bulk-audit` mode).
- `custom/grocy_AI/tests/run.php` — `bulk-audit` dispatch.
- `GrocyAiBulkMigration.php` was **not** changed: 05-02 already created `grocy_ai_bulk_audit` with the
  exact D-10 columns (id, plan_id, plan_item_id, actor, event, event_at, module_version, before_json,
  after_json, outcome) as an INSERT-only table.

## Invariants held
- **Same-transaction / zero-write-on-rollback:** audit INSERTs live inside the 05-07 `BEGIN IMMEDIATE`
  transaction; a mid-apply throw (write-path fault-injection trigger, per the 05-07 idiom) rolls back the
  already-written first item AND its audit rows — proven with a direct `COUNT(*)=0` on the ledger after a
  rolled-back apply.
- **Append-only (D-14):** the service and migration expose no UPDATE/DELETE/REPLACE against
  `grocy_ai_bulk_audit` — asserted with a `preg_match_all('/(?:UPDATE|DELETE|REPLACE)[^;\']*grocy_ai_bulk_audit/i')
  === 0` source grep over both files, plus `>= 1` INSERT in the service.
- **Idempotency preserved:** a wholly idempotent re-apply (every selected item already completed) does no
  item work and appends **zero** audit rows, so the 05-07 zero-write-on-reapply invariant still holds
  (audit rows are gated on `applied + conflicted > 0`).
- **Actor is session-sourced:** the audit actor is the value threaded into `ApplyPlan`; the endpoint test
  drives apply with a fixed actor and asserts it round-trips to the ledger and the read endpoint verbatim.

## Verification
- `php8.5 custom/grocy_AI/tests/run.php bulk-audit` → **"Bulk audit tests passed"**.
- No regression: `bulk-contract`, `bulk-schema`, `bulk-generate`, `bulk-registry`, `bulk-selection`,
  `bulk-conflict`, `bulk-apply` all pass; `taxonomy-*` all pass; default suite "All 122 grocy_AI checks
  passed". Spot-checked `conversion-coverage`, `conversion-release-gate`, `conversion-post-activation-bypass` pass.
- `bulk-invariants` remains **RED** by design: it gates on the full engine surface including
  `PreviewRollback` (05-09) and `ExportPlan` (05-10), which are not yet implemented.
- `php8.5 -l` clean on every touched PHP file.
- Characterization files untouched; the pre-existing `conversion-characterization branch_commit_mismatch`
  environmental failure is out of scope.
