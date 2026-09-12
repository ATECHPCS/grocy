---
phase: 05-bulk-maintenance-recovery-engine
plan: "01"
subsystem: bulk-engine-contract
tags: [php, sqlite, tdd, red-gate, contract]
provides:
  - RED bulk-engine contract suite fixing every Phase 5 DTO, registry, checksum, and vocabulary shape
  - deterministic non-household plan/registry fixtures
key-files:
  created:
    - custom/grocy_AI/tests/bulk.php
    - custom/grocy_AI/tests/fixtures/bulk-plan-cases.json
    - custom/grocy_AI/tests/fixtures/bulk-registry-cases.json
    - .planning/phases/05-bulk-maintenance-recovery-engine/05-01-SUMMARY.md
  modified:
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The plan/plan-item/audit DTO key sets, closed count keys, closed status/outcome/blocker/reason vocabularies, and the closed two-operation registry are fixed in fixtures and pinned as hardcoded expectations in the test, so a fixture edit alone cannot widen the contract."
  - "The plan checksum is a lowercase 64-hex SHA-256 over item identities, before/proposed values, operation types, and ruleset version via a canonical-JSON idiom; reorder-stable, mutation-sensitive."
  - "bulk-contract goes green once 05-02..05-04 land the schema, GeneratePlan/ChecksumForPlan, and RegisteredOperations; bulk-invariants stays RED until the full engine surface (ApplyPlan/PreviewRollback/ExportPlan) exists in later plans (beyond wave 4)."
requirements: [BULK-01, BULK-02, BULK-03, BULK-04, BULK-05, BULK-06, BULK-07, BULK-08, BULK-09, BULK-10]
---

# Phase 05 Plan 01: Bulk-Engine RED Contract Summary

**The Phase 5 bulk-engine contract is locked as a failing (RED) test suite before any production behavior is written.**

## Accomplishments

- Added `custom/grocy_AI/tests/bulk.php` exposing `runBulkContract()` and `runBulkInvariants()`, plus `bulk-contract` / `bulk-invariants` dispatches in `run.php` (mirroring the `conversion-characterization` conditional pattern).
- Fixed the plan DTO (`id, created_at, created_by, ruleset_version, operation_type, scope_json, counts_json, checksum, status, module_version`), the plan-item DTO (`id, plan_id, seq, object_type, object_id, operation, before_image_json, proposed_value_json, reason, provenance, selected, outcome, applied_at`), and the audit DTO (`id, plan_id, plan_item_id, actor, event, event_at, module_version, before_json, after_json, outcome`) as exact, closed key sets.
- Fixed the closed count keys (`included, excluded, skipped, conflicted, changed, unchanged`), the closed outcome vocabulary (`pending, applied, conflict, skipped, rejected, rolled_back`), the closed blocker vocabulary (`unknown_operation, before_image_stale, plan_checksum_mismatch, not_selected, already_applied, manual_edit_after_apply`), the plan status vocabulary, and the generation reason vocabulary.
- Fixed the SHA-256 plan checksum contract (lowercase 64-hex, reorder-stable, mutation-sensitive) and the closed two-operation registry (`assign_taxonomy_leaf`, `set_unclassified`), each delegating to the shipped `AssignProductTaxonomy` write with a fixed assignment key set; any free-form operation is rejected with the single `unknown_operation` blocker.
- Added deterministic, non-household fixtures `bulk-plan-cases.json` (DTO shapes, count keys, vocabularies, checksum reorder/mutation cases) and `bulk-registry-cases.json` (closed resolve set + fail-closed reject set).
- No production file under `custom/grocy_AI/src`, `public/`, or `routes.php` was changed; the plan stops at the intentional RED gate.

## Verification

- `php8.5 custom/grocy_AI/tests/run.php bulk-contract` — exits nonzero, prints `EXPECTED_RED: bulk.contract_shapes` (RED_OK).
- `php8.5 custom/grocy_AI/tests/run.php bulk-invariants` — exits nonzero, prints `EXPECTED_RED: bulk.engine_invariants` (RED_OK).
- `php8.5 -l` — clean on `bulk.php` and `run.php`; both fixtures parse as JSON.
- `git status` — only `tests/bulk.php`, `tests/fixtures/bulk-*.json`, and `tests/run.php` changed; no `src`/`public`/`routes.php` change.
- Phase 1-4 regression — default suite, `taxonomy-assignment`, and `conversion-rules` still pass.

## Decision and next step

The contract is immovable: later plans are graded against these shapes. Plan 05-02 adds the inactive, namespaced, idempotent bulk schema whose columns must equal these DTO shapes.
