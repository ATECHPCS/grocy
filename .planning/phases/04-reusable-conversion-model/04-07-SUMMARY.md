---
phase: 04-reusable-conversion-model
plan: "07"
subsystem: conversion-coverage-diagnostics
tags: [php, slim, blade, javascript, cli, read-only, diagnostics]
provides:
  - read-only aggregate conversion coverage report with a closed redacted DTO
  - MASTER_DATA_EDIT coverage page and refresh read with last-response-wins sequencing
  - bootstrap-disabled maintainer CLI over the configured Grocy database
affects: [04-08, 04-10]
key-files:
  created:
    - custom/grocy_AI/src/GrocyAiConversionController.php
    - views/grocyai_conversioncoverage.blade.php
    - public/custom/grocy_AI/conversion-coverage.js
    - public/custom/grocy_AI/conversion-coverage.test.js
    - custom/grocy_AI/bin/validate-conversion-rules.php
    - .planning/phases/04-reusable-conversion-model/04-07-SUMMARY.md
  modified:
    - custom/grocy_AI/src/GrocyAiConversionService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/routes.php
    - public/custom/grocy_AI/grocy-ai.css
    - custom/grocy_AI/tests/conversions.php
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The gate reports `inactive` until dual-branch activation evidence exists in the database, and every protected consumer category reports `unverified`. The recorded characterization document is not treated as live evidence."
  - "An unavailable or stale module schema yields an inactive report with zero counts and no invented blockers, and is never bootstrapped into existence by a diagnostic."
  - "Blockers are reported as closed categories with counts. Neither the report nor the UI can emit a raw code, row, name, barcode, URL, SQL, or exception text."
  - "The CLI opens the configured database with bootstrap disabled and `PRAGMA query_only = ON`, so it is read-only by construction rather than by convention."
  - "Redundant product overrides are counted and reported only; no diagnostic removes, replaces, or rewrites an existing conversion row."
requirements-completed: [CONV-07]
---

# Phase 04 Plan 07: Conversion Coverage Diagnostics Summary

**Maintainers can now inspect rule health, coverage gaps, effective sources, redundancy, and protected-consumer state through surfaces that are read-only by construction and cannot substitute for the release gate.**

## Accomplishments

- Added `GrocyAiConversionService::ValidateConversionCoverage`, a bootstrap-disabled read returning one fixed eight-key redacted report: ruleset and source versions, a gate block, eight aggregate counts, closed blocker categories with counts, effective-source counts, and the eight protected-consumer categories in fixed order.
- The gate reports `state`, `main_branch_evidence`, `stable_branch_evidence`, and `selected_projection`. With no activation-evidence relation in the module schema, it reports `inactive` / `absent` / `absent` / `none`, and every protected category reports `unverified`. Any blocker moves the state to `blocked`.
- Coverage is computed from the seeded catalog and rules: same-dimension ordered pairs with an explicit rule in either direction are covered, the rest are missing paths. Unavailable profiles count in-scope taxonomy leaves with no sourced profile, skipping the baby, pet, frozen, and preserved exclusions.
- Redundant product overrides count product-scoped conversions whose factor restates what the universal catalog already derives, using a deliberately looser maintainer-facing tolerance than the exact reciprocal checks that block a rule. They are reported only — never removed or replaced.
- An unavailable or stale module schema returns an inactive report with zero counts and an empty blocker list, and the report never creates module tables. `ModuleRelationAvailable` gates every module read.
- Added `GrocyAiConversionController::ConversionCoverage`, a `MASTER_DATA_EDIT` page controller that renders the report through Grocy's normal view path, and `GrocyAiApiController::ConversionCoverage`, the matching `MASTER_DATA_EDIT` refresh read. Both instantiate the service with bootstrap disabled and fail closed to a bounded message.
- Registered `GET /grocyai/conversioncoverage` and `GET /api/grocy-ai/conversions/coverage` exactly once each; the contract asserts both patterns resolve to GET only and that no write route was added.
- Added `views/grocyai_conversioncoverage.blade.php` with the UI-SPEC page title, a summary surface, and the five fixed sections in order — Blocking issues, Coverage and missing paths, Effective sources, Redundant product overrides, and Characterization and protected behavior. Empty sections stay visible with their neutral empty-state copy.
- Added `public/custom/grocy_AI/conversion-coverage.js`, which validates the report as one all-or-nothing closed boundary (exact key sets at every level, closed gate/blocker/source/protected enums, bounded non-negative integer counts, character-restricted versions) before rendering. Anything else becomes the single bounded recovery state.
- The summary uses the exact contract copy: `Ruleset ready`, `Ruleset has {n} blockers`, `Reusable rules are inactive until characterization passed on both branches.`, `No blocking conversion issues were found.`, and `The validation report could not be refreshed. Try again.` The blocker count is always in the heading text, never colour alone, and blocked uses `role="alert"` while other states use `role="status"` with `aria-live="polite"`.
- `Refresh validation report` disables only itself, keeps the last complete report on failure, and uses a request sequence so an older response can never replace a newer one. A malformed refresh response is treated as a failed refresh rather than a new report.
- Added `custom/grocy_AI/bin/validate-conversion-rules.php` following the Phase 3 read-only CLI pattern: it refuses a missing or relative `GROCY_DATAPATH` and an unavailable database with fixed messages and exit code 2, opens the configured database with bootstrap disabled and `PRAGMA query_only = ON`, emits the redacted JSON report to stdout, and replaces any failure with one stable stderr message and exit code 1.
- Added the `conversion-coverage` and `conversion-readonly-cli` suites. Coverage asserts the exact report shape, seeded counts, gate state, the redundant-override count with the row proven unchanged, closed blocker categories under a corrupted catalog factor, the unavailable-schema path with no bootstrap, both route registrations, and the permission failure — all under a `PRAGMA query_only` plus full before/after snapshot of schema, products, native conversions, cache, rules, profiles, revisions, and classifications. The CLI suite runs the real command in a subprocess, checks every refusal path, hashes the configured database before and after to prove no write, and asserts no household product name reaches stdout.
- Extended the native asset-token contract to the coverage view, including assertions that it is scoped to `permission-MASTER_DATA_EDIT` and declares no write verb.

## Verification

- `php custom/grocy_AI/tests/run.php conversion-coverage` — passed (was `EXPECTED_RED: conversion-coverage`).
- `php custom/grocy_AI/tests/run.php conversion-readonly-cli` — passed (was `EXPECTED_RED: conversion-readonly-cli`).
- `node --test public/custom/grocy_AI/conversion-coverage.test.js` — 8 passed.
- `php custom/grocy_AI/tests/run.php` — all 122 checks passed (117 before; the five new checks are the coverage-view asset, permission, and no-write-verb contracts).
- `conversion-rules`, `conversion-resolution`, `conversion-product-status`, `conversion-native-save-hook`, `taxonomy-schema`, `taxonomy-api`, `taxonomy-assignment`, `taxonomy-validation` — all passed.
- `node --test public/custom/grocy_AI/conversion-explanations.test.js` — 28 passed.
- `npx playwright test` (full matrix) — 174 passed, 10 failed; the same pre-existing baseline recorded in `04-09-SUMMARY.md`, unchanged.
- `php -l` on the conversion controller, service, routes, coverage view, CLI, conversion tests, and runner; `node --check` on the coverage script — all clean.
- `git diff --check` — clean.

## Deviations from the plan

- The plan's `files_modified` did not list `custom/grocy_AI/src/GrocyAiApiController.php`. The refresh read belongs beside the module's other bounded API reads and inside the existing `/api/grocy-ai` CORS/JSON group, so `ConversionCoverage` was added there rather than duplicating that middleware chain on the page controller.
- The report has no `validated_at` timestamp. The suites must stay deterministic and the module has no injected clock, so a wall-clock value would have made the contract untestable. The UI-SPEC's validation-time line is therefore not rendered.
- Protected-behavior states are reported as `unverified` rather than reflecting `04-CHARACTERIZATION.md`. That document records a passing fixture-only run; it is not evidence present in the deployed database, and D-12 requires diagnostics to show inactive or blocked until dual-branch release evidence succeeds. Plan 04-08 introduces the evidence relation that can move these to `passed`.
- Redundancy uses a `COVERAGE_REDUNDANCY_TOLERANCE` of 1e-4 rather than the resolver's 1e-12. A hand-entered override such as `2.2046226218` for kg→lb is a redundancy observation, not a blocking inconsistency, and the exact tolerance would never report it.

## Follow-ups for later plans

- `custom/grocy_AI/portable-files.txt` now omits five module files: `public/custom/grocy_AI/conversion-explanations.js`, `public/custom/grocy_AI/conversion-coverage.js`, `custom/grocy_AI/src/GrocyAiConversionController.php`, `custom/grocy_AI/bin/validate-conversion-rules.php`, and `custom/grocy_AI/tests/browser/fixtures/quantityunitconversionsresolved.html`. Plan 04-08 owns that file and must add them or the parity checker will not compare them.
- `custom/grocy_AI/README.md` and `CUSTOMIZATIONS.md` do not yet document the coverage page, its route, the refresh read, the CLI, or the third asset-version literal. Plan 04-08 owns those updates.
- Three Blade templates now carry a `$grocyAiAssetVersion` literal. All three plus `module-version.json` must be bumped together; `run.php` enforces this for each.
- The coverage page has no browser-level Playwright coverage. Its DOM contract is covered by the node suite; a `@conv04` page spec would need a new fixture and is a reasonable addition when Plan 04-08 exercises the activated-gate states.

## Decision and next step

All CONV-07 diagnostics are inspectable and observably read-only, and none of them can activate, project, bootstrap, or clean up anything. Plan 04-08 owns the sole `ActivateVerifiedRuleset` transaction, the dual-branch evidence relation that can move this report's gate and protected-behavior states, and the portable-files and documentation catch-up listed above. It is `autonomous: false` and needs the user before it runs.
