# Phase 5: Bulk Maintenance & Recovery Engine - Proposed Plan Breakdown

**Drafted:** 2026-08-29
**Status:** Proposal for review — NOT yet expanded into numbered PLAN.md files

This is a proposed wave-ordered decomposition for Phase 5. It follows Phase 4's fine-grained,
TDD-first, single-transaction-authority style: a RED contract slice first, then inactive schema,
then vertical slices that each add one observable capability, closing with a dual-branch release
gate. The individual `05-NN-PLAN.md` files are written only after this breakdown is reviewed.

Requirement legend: `BULK-01`..`BULK-10` from `.planning/REQUIREMENTS.md`.
New artifacts are marked `[NEW]`; everything else already exists in the module.

---

## Wave / dependency overview

| Plan | Wave | Depends on | Requirements |
|------|------|------------|--------------|
| 05-01 | 1 | — | contract for BULK-01..10 |
| 05-02 | 2 | 05-01 | BULK-01, BULK-02, BULK-08 (schema) |
| 05-03 | 3 | 05-02 | BULK-01, BULK-02 |
| 05-04 | 4 | 05-03 | BULK-05 |
| 05-05 | 5 | 05-04 | BULK-03 |
| 05-06 | 6 | 05-05 | BULK-04 |
| 05-07 | 7 | 05-06 | BULK-06, BULK-07 |
| 05-08 | 8 | 05-07 | BULK-08 |
| 05-09 | 9 | 05-08 | BULK-09 |
| 05-10 | 10 | 05-08 | BULK-10 |
| 05-11 | 11 | 05-01..05-10 | BULK-01..10 (integration/release) |

Coverage check: BULK-01 (05-02,05-03), BULK-02 (05-02,05-03), BULK-03 (05-05), BULK-04 (05-06),
BULK-05 (05-04), BULK-06 (05-07), BULK-07 (05-07), BULK-08 (05-02,05-08), BULK-09 (05-09),
BULK-10 (05-10). Every BULK requirement is covered by at least one implementing plan, and 05-11
re-proves them together on both branches.

---

## Proposed plans

### 05-01 — Lock the bulk engine contract with RED tests before production
- **Wave:** 1  **Depends on:** none
- **Requirements:** foundation for BULK-01..10 (no production behavior yet)
- **Likely files:** `custom/grocy_AI/tests/bulk.php` [NEW], `custom/grocy_AI/tests/run.php`,
  `custom/grocy_AI/tests/fixtures/bulk-*.json` [NEW]
- **Acceptance truths:**
  1. A failing (RED) suite fixes the plan DTO, plan-item DTO, typed-operation-registry shape,
     plan-checksum contract, and the closed blocker/outcome vocabulary.
  2. The suite asserts zero-write, immutable-before-image, named-operation-only, optimistic-
     concurrency, single-transaction, idempotency, audit, rollback-refusal, and export claims.
  3. No production file is changed; the plan stops at the intentional RED gate (mirrors 02-01/04-01).

### 05-02 — Add the inactive namespaced bulk schema and migration
- **Wave:** 2  **Depends on:** 05-01
- **Requirements:** BULK-01, BULK-02, BULK-08 (storage scaffolding)
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkMigration.php` [NEW],
  `custom/grocy_AI/routes.php` (require_once wiring), `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. Idempotent `Bootstrap()` creates namespaced `grocy_ai_bulk_plans`, `grocy_ai_bulk_plan_items`,
     and an append-only `grocy_ai_bulk_audit` ledger [NEW] without colliding with existing or
     fixture-spy tables (grep `conversions.php`/`taxonomy.php` per MISTAKES.md).
  2. Creating the tables changes no native Grocy table and leaves resolved-cache behavior untouched.
  3. Schema carries the fields D-02/D-10 require (identity, before/proposed, reason, provenance,
     ruleset version, actor, timestamps, module/version, outcome, before/after).

### 05-03 — Generate a bounded zero-mutation dry-run plan with counts and checksum
- **Wave:** 3  **Depends on:** 05-02
- **Requirements:** BULK-01, BULK-02
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php` [NEW], `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. `GeneratePlan` returns exact included/excluded/skipped/conflicted/changed/unchanged counts for a
     bounded scope.
  2. Each item carries a stable object identity, immutable before-image, proposed value, reason,
     provenance, and ruleset version; the plan carries a deterministic checksum over that content.
  3. Generation is provably zero-write (schema + state + `total_changes` + before/after snapshot).

### 05-04 — Enforce a closed named-typed-operation registry (no arbitrary CRUD/SQL)
- **Wave:** 4  **Depends on:** 05-03
- **Requirements:** BULK-05
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`,
  `custom/grocy_AI/src/GrocyAiTaxonomyService.php` (read/delegate only), `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. Only operations in a closed server-side registry resolve; the first registered ops
     (`assign_taxonomy_leaf` / `set_unclassified`) delegate to shipped `AssignProductTaxonomy`.
  2. Browser/companion-supplied free-form entity, field, CRUD, or SQL payloads are rejected with one
     bounded blocker before any write is attempted.
  3. No new low-level write path is introduced; conversion-cleanup operations are explicitly left for
     Phase 6.

### 05-05 — Per-item select/reject and the complete selected diff before approval
- **Wave:** 5  **Depends on:** 05-04
- **Requirements:** BULK-03
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`,
  `public/custom/grocy_AI/bulk-review.js` [NEW] + `bulk-review.test.js` [NEW], a Blade review view
  or extension [NEW], `custom/grocy_AI/src/GrocyAiApiController.php` (read endpoints)
- **Acceptance truths:**
  1. The user can select or reject each item; selection state persists with the plan.
  2. The selected diff is complete and accurate, and the apply set is exactly the selected,
     non-conflicted items.
  3. Rendering the diff performs no write and reuses the Bootstrap 4 review conventions.

### 05-06 — Refuse stale/conflicting before-images at apply (optimistic concurrency)
- **Wave:** 6  **Depends on:** 05-05
- **Requirements:** BULK-04
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`, `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. An item whose current field value still matches the reviewed before-image applies; one whose
     current value drifted is rejected with a per-item `conflict` outcome.
  2. A conflicted item causes no partial write and does not abort the remaining valid items beyond
     the transaction contract of 05-07.
  3. Conflict detection re-reads current values through existing read paths, not the stored plan.

### 05-07 — Apply once through a single network-free BEGIN IMMEDIATE transaction, idempotently
- **Wave:** 7  **Depends on:** 05-06
- **Requirements:** BULK-06, BULK-07
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`, `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. The approved apply set is written in one short `BEGIN IMMEDIATE` transaction with no network,
     provider, or companion call while the write lock is held.
  2. Re-applying or retrying the same approved plan (keyed on the plan checksum) repeats no completed
     mutation; an interrupted apply resumes without duplication.
  3. On failure the transaction rolls back to a byte-identical prior state (before/after snapshot).

### 05-08 — Append the immutable audit ledger (actor, versions, times, before/after)
- **Wave:** 8  **Depends on:** 05-07
- **Requirements:** BULK-08
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`,
  `custom/grocy_AI/src/GrocyAiBulkMigration.php`, `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. Every apply appends actor, previewed/applied timestamps, module/version, per-item outcome, and
     exact before/after values.
  2. The ledger is append-only; a maintainer can reconstruct exactly who previewed/applied a plan and
     what changed.
  3. The audit write happens inside the same apply transaction as the mutations it records.

### 05-09 — Rollback preview that refuses to overwrite later manual edits
- **Wave:** 9  **Depends on:** 05-08
- **Requirements:** BULK-09
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php`, `custom/grocy_AI/tests/bulk.php`,
  `public/custom/grocy_AI/bulk-review.js`
- **Acceptance truths:**
  1. Rollback preview is zero-write and lists reversible items derived from the audit ledger.
  2. Any field changed manually after the original apply is refused (current value compared to the
     audited after-image), never silently overwritten.
  3. Rollback execution reuses the 05-04/05-06/05-07 named-op, optimistic-concurrency, single-
     transaction, idempotent path.

### 05-10 — Redacted JSON/CSV export snapshot (non-authoritative on re-import)
- **Wave:** 10  **Depends on:** 05-08
- **Requirements:** BULK-10
- **Likely files:** `custom/grocy_AI/src/GrocyAiBulkService.php` or
  `custom/grocy_AI/bin/export-bulk-plan.php` [NEW], `custom/grocy_AI/src/GrocyAiApiController.php`,
  `public/custom/grocy_AI/bulk-review.js`, `custom/grocy_AI/tests/bulk.php`
- **Acceptance truths:**
  1. A plan/preview exports as redacted JSON and CSV for independent review and recovery evidence.
  2. The snapshot omits secrets, tokens, and non-essential household detail and is explicitly marked
     non-authoritative.
  3. The export path accepts no re-import as authority (re-import is v2 `V2-03`).

### 05-11 — Wire routes, dual-branch parity, and the `bulk` release gate
- **Wave:** 11  **Depends on:** 05-01..05-10
- **Requirements:** BULK-01..10 (integration and release proof)
- **Likely files:** `custom/grocy_AI/portable-files.txt`, `custom/grocy_AI/tests/release-gate.sh`,
  `custom/grocy_AI/routes.php`, `routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`,
  `custom/grocy_AI/README.md`, `CUSTOMIZATIONS.md`,
  `custom/grocy_AI/tests/browser/specs/bulk.spec.js` [NEW]
- **Acceptance truths:**
  1. All new Phase 5 files are added to `portable-files.txt`, and a new `release-gate.sh bulk` mode
     proves manifest membership, zero-write generation, named-op-only apply, single-transaction and
     idempotency invariants, audit append-only, and rollback refusal — fail-closed-verified.
  2. Preview/select/export/rollback-preview endpoints are wired under the `MASTER_DATA_EDIT` group and
     the apply action is authenticated and audited per D-13.
  3. The full unit + browser suites pass on both branches (single-checkout fallback), PHP linted with
     `php8.5`; README/CUSTOMIZATIONS document the engine and its authority boundary.

---

## Open questions for review before writing numbered plans

1. **Apply authorization surface (D-13):** roadmap success criteria phrase apply as "User can apply",
   but Phase 4 gated its sole mutation behind a maintainer-only CLI. Proposed default: preview/select/
   export/rollback-preview are user-facing `MASTER_DATA_EDIT` API actions and the durable apply is an
   authenticated, audited action reusing the Phase 4 maintainer-auth conventions. Confirm whether apply
   should be a user API action or a maintainer CLI.
2. **Proving operation scope:** Phase 5 exercises the engine against the shipped taxonomy assignment
   write, which is a real (reviewed, typed, audited) inventory mutation. Confirm this is acceptable in
   Phase 5, or whether Phase 5 must stay fully synthetic and defer all real inventory writes to Phase 6.
3. **Actor identity source for the audit ledger (BULK-08):** the existing Phase 4 evidence ledger is
   actor-less. Confirm the actor should come from the authenticated Grocy session user (and, for a CLI
   apply, the deployment operator), so the audit records a real identity.
4. **Plan lifetime / expiry:** a stored plan's before-images can grow stale between generation and
   apply. Optimistic concurrency (05-06) catches drift at apply, but confirm whether plans should also
   carry an explicit expiry or a maximum bounded scope size.
