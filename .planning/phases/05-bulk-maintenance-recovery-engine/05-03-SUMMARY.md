---
phase: 05-bulk-maintenance-recovery-engine
plan: "03"
subsystem: bulk-generate-plan
tags: [php, sqlite, zero-write, checksum, dry-run]
provides:
  - GeneratePlan producing bounded exact counts, immutable before-images, and a deterministic plan checksum with zero native writes
key-files:
  created:
    - custom/grocy_AI/src/GrocyAiBulkService.php
    - .planning/phases/05-bulk-maintenance-recovery-engine/05-03-SUMMARY.md
  modified:
    - custom/grocy_AI/tests/bulk.php
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "Counts come from ValidateInventoryTaxonomy's aggregate buckets (excluded->excluded; unclassified+low_confidence+conflicting->skipped; mapped->included, split changed/unchanged); conflicted is reserved 0 at generation."
  - "Items are built only for actionable (mapped) objects whose ReadProductTaxonomy yields a suggested leaf; before/proposed images carry ONLY the written field ({leaf_slug: ...}), never volatile evidence, captured once at generation."
  - "The checksum is a public ChecksumForPlan(operationType, rulesetVersion, items): lowercase 64-hex SHA-256 over normalized, identity-sorted items via canonical JSON — reorder-stable and mutation-sensitive."
  - "Default selection rule: changed items pre-selected (selected=1), unchanged no-ops deselected (selected=0)."
requirements: [BULK-01, BULK-02]
---

# Phase 05 Plan 03: Zero-Mutation GeneratePlan Summary

**`GeneratePlan` returns a bounded dry-run plan with exact counts, immutable before-images, and a deterministic checksum, provably without mutating any native Grocy state.**

## Accomplishments

- Added `GrocyAI\Services\GrocyAiBulkService` (constructor takes an optional PDO, bootstraps `GrocyAiBulkMigration`, and holds a shared `GrocyAiTaxonomyService`), plus the public `ChecksumForPlan` and `GeneratePlan`.
- `GeneratePlan` reads current values only through `ValidateInventoryTaxonomy` (bounded per-outcome counts) and `ReadProductTaxonomy` (per-object current/suggested leaves), never ad-hoc cache SQL.
- Projects the five taxonomy buckets into the six closed count keys with the documented mapping; `conflicted` is reserved `0` at generation; `included == changed + unchanged`; the counts reconcile with the bounded scope size.
- Captures each item's before-image and proposed value over the WRITTEN field only (`{leaf_slug: ...}`), records identity/operation/reason/provenance/selection, and persists the plan header + immutable items in the two module tables only.
- Seals the plan with a deterministic checksum reused from the `EvidenceHash` canonical-JSON idiom; proven reorder-stable, mutation-sensitive, and equal to the recomputed checksum; regenerating the same scope yields the same checksum.
- Proved zero-mutation by full before/after snapshots of native and read-only module tables, an unchanged `sqlite_master`, and a `total_changes` delta attributable only to the plan + item inserts (per MISTAKES.md, snapshot-diffing actual rows rather than reasoning about counts).

## Verification

- `php8.5 custom/grocy_AI/tests/run.php bulk-generate` — `Bulk generate tests passed`.
- `php8.5 -l` — clean on `GrocyAiBulkService.php` and `bulk.php`.
- `bulk-schema` still green; `bulk-contract` and `bulk-invariants` still exit 1 (RED, awaiting the 05-04 registry and the later apply/rollback/export surface).
- Phase 1-4 regression — default suite, `taxonomy-assignment`, and `conversion-resolution` pass.

## Decision and next step

Generation is a trustworthy, immutable, checksum-sealed dry-run. Plan 05-04 adds the closed named-typed-operation registry that binds `assign_taxonomy_leaf`/`set_unclassified` to the shipped `AssignProductTaxonomy` write and adds the transaction-nesting guard so a later `ApplyPlan` can wrap the delegate in one outer transaction.
