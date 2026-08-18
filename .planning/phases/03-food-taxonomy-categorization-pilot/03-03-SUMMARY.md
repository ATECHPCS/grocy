---
phase: 03-food-taxonomy-categorization-pilot
plan: "03"
subsystem: taxonomy-validation-release
tags: [php, sqlite, release-gate, stable-parity, taxonomy]
requires:
  - phase: 03-02
    provides: explicit module-only taxonomy assignment and edit panel
provides:
  - read-only aggregate taxonomy-v1 inventory validation
  - SHA-pinned portable/stable taxonomy release proof
affects: [phase-03-completion, phase-06]
key-files:
  modified: [custom/grocy_AI/src/GrocyAiTaxonomyService.php, custom/grocy_AI/tests/taxonomy.php, custom/grocy_AI/tests/release-gate.sh, custom/grocy_AI/portable-files.txt]
key-decisions:
  - "Validation reports only local aggregate outcomes and treats frozen/preserved as handling or location concerns."
  - "Stable taxonomy promotion is two commits: byte-identical portable source, then narrowly scoped stable adapters."
metrics:
  tasks_completed: 2
  portable_files_verified: 19
  browser_tests: 148
---

# Phase 3 Plan 03: Taxonomy Validation and Release Proof Summary

**Read-only, redacted taxonomy-v1 validation plus exact portable/stable parity proof, with no bulk classification or deployment.**

## Accomplishments

- Added `ValidateInventoryTaxonomy()` to count in-scope mapped, Unclassified, excluded, conflicting, and low-confidence outcomes using only local SQLite data.
- Proved every fixture table remains byte-for-byte unchanged by validation; output contains no product names, barcodes, provider URLs, or raw evidence.
- Documented frozen/preserved as a handling/location boundary and reserved bulk preview/apply/recovery for Phase 6.
- Added a taxonomy release-gate mode that checks portable coverage, rejects `product_groups` and `should_not_be_frozen` taxonomy writes, validates the stable recursive module/asset overlay, and runs the read-only report.
- Mirrored 19 portable paths to stable commit `da51142e`, then added the five-path stable adapter commit `368ff411` for routes, controller, product form, cache marker, and customization record.

## Task Commits

1. Task 1 — `3ab9a0fe` `feat(03-03): add read-only taxonomy validation report`
2. Task 2 — `16cce160` `test(03-03): gate taxonomy release parity`
3. Task 2 regression coverage — `72af3ab5` `test(03-03): account for taxonomy panel bootstrap`

## Stable Commits

1. Portable mirror — `da51142e` `feat(03-03): mirror portable taxonomy module`
2. Stable adapter — `368ff411` `feat(03-03): adapt stable taxonomy integration`

## Verification

- `php custom/grocy_AI/tests/run.php taxonomy-validation` — passed.
- `php custom/grocy_AI/tests/run.php` — passed (113 checks).
- `bash custom/grocy_AI/tests/release-gate.sh taxonomy` — passed.
- `custom/grocy_AI/tests/check-portable-parity.sh --stable-sha 368ff4115464641e3cd4cec4c7319d6bf1559f75` — passed (19 identical paths).
- `npm --prefix custom/grocy_AI/tests/browser test -- --reporter=line` — passed (148 tests across Chromium and WebKit mobile).

## Deviations from Plan

### Auto-fixed Issues

1. [Rule 3 - Stable-only integration location] `Dockerfile.atech` exists only in the stable repository.
   - The existing stable Dockerfile already recursively copies both custom module trees, so no Dockerfile change was required.
   - Added the stable adapter commit and a release-gate assertion for those exact overlay lines instead of duplicating a Dockerfile into main.

2. [Rule 1 - Browser regression] Existing smoke tests assumed two custom assets and treated the taxonomy panel's initial read as an enrichment request.
   - Updated the deterministic smoke assertions for the third asset and reset request accounting after panel bootstrap.

## Known Stubs

None. Empty classification remains the explicit, intentional Unclassified state.

## Self-Check: PASSED

- Verified main commits `3ab9a0fe`, `16cce160`, and `72af3ab5` and stable commits `da51142e` and `368ff411` exist.
- Verified both worktrees are clean after validation.
