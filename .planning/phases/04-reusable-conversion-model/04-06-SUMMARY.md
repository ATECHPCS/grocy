---
phase: 04-reusable-conversion-model
plan: "06"
subsystem: resolved-conversion-provenance
tags: [php, slim, javascript, blade, datatables, read-only, provenance]
provides:
  - bounded MASTER_DATA_EDIT resolved-conversion provenance read keyed to native row identity
  - source/status columns and an accessible per-row details disclosure in the native resolved table
  - one shared unit-key predicate owner for inspection callers
affects: [04-07, 04-08]
key-files:
  created:
    - custom/grocy_AI/tests/browser/fixtures/quantityunitconversionsresolved.html
    - .planning/phases/04-reusable-conversion-model/04-06-SUMMARY.md
  modified:
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/src/GrocyAiConversionService.php
    - custom/grocy_AI/routes.php
    - views/quantityunitconversionsresolved.blade.php
    - public/viewjs/quantityunitconversionsresolved.js
    - public/custom/grocy_AI/conversion-explanations.js
    - public/custom/grocy_AI/conversion-explanations.test.js
    - public/custom/grocy_AI/grocy-ai.css
    - custom/grocy_AI/tests/browser/specs/conversions.spec.js
    - custom/grocy_AI/tests/browser/support/server.mjs
    - custom/grocy_AI/tests/conversions.php
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The resolved row identity is three strictly validated integers (product, from unit, to unit); no unit key, factor, source, or destination is ever accepted from the browser."
  - "The endpoint returns exactly one existing resolver result and adds no second resolved-data source; the PHP contract asserts equality against a direct service call."
  - "A package or count pair is reported as an eligibility outcome with the closed reason `reusable_count_scope` instead of being resolved, so no factor can be borrowed for it."
  - "Enrichment is attached by the native view script after DataTables initialization, then invalidates each painted row from the DOM and redraws once, so the existing filter and colReorder.transpose stay authoritative."
  - "The native table is wrapped in Bootstrap's `.table-responsive`, and the rounded factor and prose move into the expanded details region below 576px, so the table scrolls inside its own container rather than the page."
requirements-completed: [CONV-05, CONV-07]
---

# Phase 04 Plan 06: Resolved Conversion Provenance Summary

**Grocy's resolved-conversions view now names the winning source and outcome for each row and discloses full provenance on demand, while its native filtering, ordering, and row rendering stay exactly as they were.**

## Accomplishments

### Task 1 — resolved-provenance read contract keyed to native rows

- Added `GET /api/grocy-ai/conversions/resolved-provenance` with a `MASTER_DATA_EDIT` check before any lookup, registered exactly once as GET inside the existing `/api/grocy-ai` group with its unchanged CORS/JSON middleware.
- The row identity is `product_id`, `from_qu_id`, and `to_qu_id`, each validated against `^[1-9][0-9]{0,9}$`. Missing, zero-padded, signed, exponential, oversized, array, and injection-shaped values all map to one fixed `400 Invalid resolved conversion request`.
- A missing product returns `404 Product unavailable`; a missing quantity unit returns `404 Quantity unit unavailable`. Nothing else about either row reaches the client.
- The endpoint resolves quantity-unit names to reusable catalog keys through the service, calls `InspectConversionResolution` with bootstrap disabled, validates the exact 14-key DTO and closed status, and redacts the factor for every non-`product_native` outcome.
- A package or count pair — one whose unit has no reusable catalog key — returns the same fixed DTO shape with status `unavailable` and the closed reason `reusable_count_scope`. It is never resolved, so it can never borrow a factor from another rule.
- Added `GrocyAiConversionService::UnitKeyForName` as the single owner of the Grocy-unit-name to catalog-key mapping; the existing private `UnitKey` now delegates to it, so the inspection caller cannot drift from the resolver.
- Added a full PHP contract to the `conversion-resolution` suite: exact DTO key order, equality against one direct resolver call, inactive/blocked/unavailable/count-scope outcomes, an activation fixture that changes nothing, every malformed identity, both not-found paths, the permission failure, and a `PRAGMA query_only` plus write-spy snapshot proving the GET performs no bootstrap, activation, projection, cache, taxonomy, product, or native write.

### Task 2 — source/status/details in the native resolved table

- Extended `views/quantityunitconversionsresolved.blade.php` with `Source`, `Status`, and a details column appended after the existing columns, so `colReorder.transpose(1)` and `(2)` still address the from/to columns. Rows carry their identity only when the view is product-scoped; the global view renders unchanged.
- Each row has a native `<details>` disclosure with a visible `Show conversion details` label, a 44px target, and an `aria-label` naming the from/to pair. It begins collapsed and is keyboard operable.
- Extended `public/custom/grocy_AI/conversion-explanations.js` with `createResolvedProvenanceController`, which keeps one request sequence per row: a superseded answer for the same row is dropped and an answer can only ever paint the row that asked for it. `loadRows` requests each distinct identity at most once per pass.
- Rows reuse `describeProductConversionStatus`, so the resolved table inherits the same closed contract validation, factor redaction, and closed correction categories. A raw blocker code, SQL string, or exception text can never reach a cell.
- Status renders as a visible word plus a supplementary `aria-hidden` icon — never colour alone. Exact, Approximate, Unavailable, and Blocked are all text.
- An unavailable outcome now also names a correction category, but only when its reason maps to a closed one; `explicit_taxonomy_required` and `profile_unavailable` keep their explanatory headline instead of a misleading generic sentence.
- `public/viewjs/quantityunitconversionsresolved.js` attaches enrichment only after the native `DataTable()` call, invalidating each painted row with `row(...).invalidate('dom')` and issuing a single `draw(false)` on completion, so the DataTables cache stays consistent for sorting and search without disturbing paging or filter state.
- Wrapped the native table in Bootstrap's `.table-responsive` and, below 576px, moved the rounded factor and the native "This means" prose out of the table and into the expanded details region. The page no longer scrolls horizontally; the table scrolls inside its own container with source, status, and the details control always reachable.
- Extended the fixture server with resolved-provenance envelopes and a request counter, and added a new `quantityunitconversionsresolved.html` fixture whose jQuery/DataTables adapter models only what the real view script uses — so `public/viewjs/quantityunitconversionsresolved.js` is loaded unmodified and its filter and transpose behaviour is genuinely exercised.
- Added five `@conv04` resolved-table specs (10 runs across Chromium and WebKit): deterministic per-row source/status with no factor for blocked or unavailable rows, collapsed-until-explicit disclosure with a 44px keyboard-operable control, native filter/transpose/row rendering preserved with exactly one invalidation per painted row, a failed read that preserves the table and its filter behind bounded recovery copy, and 320px reachability with the factor and prose relocated into the details region.
- Extended the native asset-token contract in `run.php` to cover the resolved view's own `$grocyAiAssetVersion` literal, so a stale route/view cache cannot serve older custom bytes for this page either.

## Verification

- `php custom/grocy_AI/tests/run.php conversion-resolution` — passed (was `EXPECTED_RED: conversion-resolved-provenance` before the endpoint existed).
- `php custom/grocy_AI/tests/run.php` — all 117 checks passed (113 before; the four new checks are the resolved-view asset-token contract).
- `php custom/grocy_AI/tests/run.php conversion-rules | conversion-product-status | conversion-native-save-hook | taxonomy-validation` — all passed.
- `node --test public/custom/grocy_AI/conversion-explanations.test.js` — 28 passed (7 added this plan; all were RED before the controller existed).
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` — 36 passed.
- `npx playwright test` (full matrix) — 174 passed, 10 failed. All 10 are the pre-existing baseline recorded in `04-09-SUMMARY.md`; the set of failing specs is unchanged.
- `php -l` on the controller, routes, conversion tests, runner, and resolved view; `node --check` on the module, the native view script, the fixture server, and the spec — all clean.
- `git diff --check` — clean.

## Deviations from the plan

- `custom/grocy_AI/src/GrocyAiConversionService.php` is not in the plan's `files_modified`, but the controller needs the Grocy-unit-name to catalog-key mapping. Rather than restate that table in a second place, `UnitKeyForName` was promoted to a public static predicate that the private `UnitKey` delegates to. This follows the module's existing single-predicate-owner convention (`GrocyAiGtin`).
- A package or count pair produces a controller-built `unavailable` DTO rather than a resolver result. This is an eligibility gate, not a second resolved-data source: the shape is identical to the resolver's own unavailable DTO and the PHP contract asserts its exact key order. The alternative — passing an unmapped name into the resolver — would have relied on incidental fall-through behaviour.
- `custom/grocy_AI/tests/browser/fixtures/quantityunitconversionsresolved.html` is a new file the plan did not list. There was no existing fixture for this view, and Task 2's browser evidence cannot exist without one.
- The plan named a DataTables child row for the details region. A native in-cell `<details>` disclosure was used instead — the UI-SPEC's stated equivalent — because it requires no DataTables row API and therefore cannot interfere with `colReorder` or the existing filter.
- The 320px requirement is met by making the table scroll inside Bootstrap's `.table-responsive` rather than the page. A six-column table cannot fit 320px unaided; the UI-SPEC's own instruction is to move the factor and prose into the details region "before allowing horizontal scrolling", which is what this does.
- The `Rounded factor` and `Conversion` detail rows are read from the row Grocy already rendered, not from the API. They are inserted as text nodes like every other detail value.

## Follow-ups for later plans

- `custom/grocy_AI/portable-files.txt` still does not list `public/custom/grocy_AI/conversion-explanations.js`, and now also omits `custom/grocy_AI/tests/browser/fixtures/quantityunitconversionsresolved.html`. Plan 04-08 owns that file and must add both, or the SHA-pinned parity checker will not compare them.
- `custom/grocy_AI/README.md` and `CUSTOMIZATIONS.md` do not yet document the resolved-provenance route, the second asset-version literal, or the `.table-responsive` wrapper. Plan 04-08 owns those updates.
- `views/quantityunitconversionsresolved.blade.php` now carries its own `$grocyAiAssetVersion` literal. Both literals and `module-version.json` must be bumped together; `run.php` enforces this for both templates.

## Decision and next step

Precedence and conflict outcomes are now inspectable everywhere Grocy exposes a resolved row, and every surface remains read-only. Plan 04-07 may add the maintainer coverage report; it must keep using these bounded inspection reads and must not introduce a cleanup, activation, or projection path. Reusable rules stay inactive until Plan 04-08's dual-branch evidence gate passes.
