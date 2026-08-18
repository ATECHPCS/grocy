---
phase: 03-food-taxonomy-categorization-pilot
plan: "02"
subsystem: taxonomy-assignment-ui
tags: [php, sqlite, blade, javascript, playwright, taxonomy]
requires:
  - phase: 03-01
    provides: namespaced taxonomy schema and evidence read endpoint
provides:
  - atomic explicit leaf or Unclassified taxonomy assignment
  - edit-only accessible taxonomy review panel with isolated writes
affects: [03-03, food-taxonomy]
tech-stack:
  added: []
  patterns: [narrow module PUT, module-table-only transaction, deterministic mutation counters]
key-files:
  created: [public/custom/grocy_AI/product-taxonomy.js, custom/grocy_AI/tests/browser/specs/taxonomy.spec.js]
  modified: [custom/grocy_AI/src/GrocyAiTaxonomyService.php, custom/grocy_AI/src/GrocyAiApiController.php, views/productform.blade.php]
key-decisions:
  - "Taxonomy assignment accepts only one leaf/ruleset pair or explicit Unclassified/ruleset pair."
  - "Classification is a separate module PUT and is never serialized into the generic product form."
metrics:
  tasks_completed: 2
  files_modified: 10
---

# Phase 03 Plan 02: Explicit Taxonomy Assignment and Edit Panel Summary

**Single-product food classification now writes only the module-owned classification row and keeps ordinary Grocy Save behavior independent.**

## Accomplishments

- Added transactional validation and replacement for exactly one permitted taxonomy leaf or explicit Unclassified state.
- Added a MASTER_DATA_EDIT-protected PUT endpoint that ignores browser-supplied evidence and rejects stale, excluded, ambiguous, or unavailable assignments.
- Added an edit-only responsive taxonomy card showing current classification, evidence, confidence, ruleset, leaf radios, and explicit Unclassified action.
- Added focused browser counters proving classification requests never call generic product, stock, recipe, location, conversion, or userfield writes.

## Task Commits

1. Task 1 RED contract — `a1983936`
2. Task 1 assignment implementation — `6ca54507`
3. Task 2 edit panel and browser coverage — `ffb89a21`
4. Task 2 asset-token regression check — `65ce2b27`

## Verification

- `php custom/grocy_AI/tests/run.php taxonomy-assignment` — passed
- `php custom/grocy_AI/tests/run.php` — passed (113 checks)
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @tax03` — passed (Chromium mobile and WebKit mobile)
- `php -l custom/grocy_AI/src/GrocyAiTaxonomyService.php` — passed
- `php -l custom/grocy_AI/src/GrocyAiApiController.php` — passed

## Deviations from Plan

### Auto-fixed Issues

1. [Rule 2 - Missing critical test infrastructure] Added taxonomy fixture panel and static-server allowlist.
- **Found during:** Task 2
- **Issue:** The isolated Playwright fixture could not load or exercise the dedicated taxonomy asset, so it could not prove the required zero unrelated-write behavior.
- **Fix:** Added the deterministic fixture markup, taxonomy request counter, and explicitly allowlisted asset path.
- **Files modified:** `custom/grocy_AI/tests/browser/fixtures/productform.html`, `custom/grocy_AI/tests/browser/support/server.mjs`
- **Commit:** `ffb89a21`

## Known Stubs

None. An absent current or suggested leaf is the intentional explicit Unclassified state.

## Self-Check: PASSED

- Verified all created UI and browser-spec files exist.
- Verified commits `a1983936`, `6ca54507`, `ffb89a21`, and `65ce2b27` exist in Git history.
