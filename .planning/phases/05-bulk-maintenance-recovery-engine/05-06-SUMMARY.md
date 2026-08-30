---
phase: 05-bulk-maintenance-recovery-engine
plan: "06"
subsystem: bulk-optimistic-concurrency
tags: [php, sqlite, read-only, optimistic-concurrency, fail-closed, taxonomy]
provides:
  - DetectApplyConflicts — a zero-write per-item stale/conflicting before-image refusal
  - written-field-only conflict comparison via the shipped ReadProductTaxonomy read path
  - fail-closed conflict on unreadable current value, off-registry operation, or malformed before-image
  - the exact selected-and-non-conflicted apply set 05-07's ApplyPlan will write
affects: [05-07]
key-files:
  created: [.planning/phases/05-bulk-maintenance-recovery-engine/05-06-SUMMARY.md]
  modified: [custom/grocy_AI/src/GrocyAiBulkService.php, custom/grocy_AI/tests/bulk.php, custom/grocy_AI/tests/run.php]
key-decisions:
  - "Conflict detection re-reads only the WRITTEN field (the product's current classification leaf slug via ReadProductTaxonomy['current_leaf']) and compares it to the item's immutable before-image; volatile evidence fields are never compared, so an evidence change never false-conflicts."
  - "Detection is zero-write and fail-closed: it issues only SELECTs, never records the outcome column (05-07 does that inside the write transaction), and treats an unreadable current value, an off-registry operation, or a malformed/absent before-image as conflict rather than a silent match."
  - "No plan-level TTL and no item-count cap are introduced; the only bound is the scope GeneratePlan produced (locked stale-plan-safety decision)."
requirements-completed: [BULK-04]
---

# Phase 05 Plan 06: Optimistic-Concurrency Stale Before-Image Refusal Summary

**The bulk engine now binds apply to present reality: a zero-write per-item check re-reads each selected item's current written value and refuses any item whose value drifted from the reviewed before-image, producing the exact apply set the 05-07 transaction will write.**

## Accomplishments

- Added `GrocyAiBulkService::DetectApplyConflicts($planId)`: for each SELECTED item it extracts the immutable written-field before-image (leaf slug / `null`), re-reads the current written value through the shipped public `GrocyAiTaxonomyService::ReadProductTaxonomy` (`current_leaf` slug — never the private `CurrentLeaf` helper), and compares only that written field with an exact, normalized comparison.
- Returns each selected item annotated `no_conflict` or `conflict` (the pinned closed outcome vocab, reused verbatim), plus the computed apply set (selected AND non-conflicted); a conflicted item drops from the apply set without dropping valid siblings.
- Fails closed to `conflict` on an unreadable current value (object vanished), an operation outside the closed registry, or a malformed/absent before-image — the stored plan is never trusted as its own source of truth.
- Never writes: only SELECTs are issued and the per-item `outcome` column is deliberately left untouched, because recording conflict is 05-07's job inside the apply transaction and keeping detection write-free is what proves it re-reads reality on every call.
- Added the `bulk-conflict` test mode (`tests/bulk.php` + `tests/run.php` dispatch) with focused tests: matching item stays eligible; a drifted item conflicts and drops while its sibling remains; a second call reflects fresh drift (live re-read); a changed volatile-evidence field does NOT false-conflict; a fully-conflicted plan yields an empty apply set; corrupt/absent before-image and unreadable/off-registry items fail closed; mixed drift is deterministic and order-independent; and every conflict path is proven zero-write via full before/after snapshots and `total_changes()`.

## Verification

- `php8.5 custom/grocy_AI/tests/run.php bulk-conflict` — `Bulk conflict tests passed`.
- Re-ran with no regression: `bulk-contract`, `bulk-schema`, `bulk-generate`, `bulk-registry`, `bulk-selection` — all passed; `taxonomy-schema`, `taxonomy-api`, `taxonomy-assignment`, `taxonomy-validation`, `taxonomy-production-paths` — all passed; default suite — passed.
- `bulk-invariants` remains `EXPECTED_RED: bulk.engine_invariants` (unchanged from baseline) — it requires ApplyPlan/PreviewRollback/ExportPlan from 05-07+.
- PHP lint passed for `GrocyAiBulkService.php`, `tests/bulk.php`, and `tests/run.php`.

## Written-field-only proof

The comparison reads only `ReadProductTaxonomy['current_leaf']` (the persisted classification leaf) versus the item's stored `before_image_json` leaf slug. Test 3c mutates only the volatile evidence for a product (`provider_category`, `confidence_band`, `reason_code` — which shifts the read DTO's `suggested_leaf`/`confidence_band`) while the written classification leaf holds; the test first guards against vacuity by asserting the evidence DTO actually changed and the written leaf did not, then asserts the item stays `no_conflict` and remains in the apply set — proving a volatile-evidence change cannot trigger a false conflict.

## Decision and next step

Detection is a pure, fail-closed, order-independent read. Plan 05-07's `ApplyPlan` will consume this apply set inside one `BEGIN IMMEDIATE` transaction and record the per-item `conflict` outcome there; it must re-run this same live re-read under the write lock rather than trusting a previously computed set.
