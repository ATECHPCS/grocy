---
phase: 04-reusable-conversion-model
plan: "01"
subsystem: conversion-characterization
tags: [php, sqlite, cache, triggers, fixture-only, safety-gate]
provides:
  - disposable dual-checkout conversion cache characterization
  - immutable main/stable schema and protected-output evidence
  - fail-closed data-path and manifest validation
affects: [04-02, 04-08, 04-10]
key-files:
  created: [custom/grocy_AI/tests/conversion-characterization.php, custom/grocy_AI/tests/conversions.php, custom/grocy_AI/tests/fixtures/conversion-characterization-main.json, custom/grocy_AI/tests/fixtures/conversion-characterization-stable.json, .planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md]
  modified: [custom/grocy_AI/tests/run.php]
key-decisions:
  - "No reusable projection is selected from native-cache characterization alone."
  - "All reusable conversion state remains inactive pending candidate-specific immutable parity evidence."
requirements-completed: [CONV-08, CONV-09]
---

# Phase 04 Plan 01: Dual-Branch Characterization Summary

**A focused fixture gate now characterizes the cache and native conversion-trigger behavior on main and stable without opening household data.**

## Accomplishments

- Added a focused `conversion-characterization` test command without changing the existing Phase 1–3 default dispatch.
- Validates both checkout roots and migrations, uses fresh temporary SQLite files outside the configured data path, records only redacted schema/cache/query-plan metadata, and deletes each fixture.
- Seeds and re-exercises one native default plus one product override on each branch; proves cache aggregate and stock, recipe, purchase, consumption, price, transfer, meal-plan, and quantity-display outputs are unchanged.
- Fails closed for a missing branch manifest and a fixture root at/below `GROCY_DATAPATH`.
- Captured current main/stable commit, migration hash, cache-object, trigger, and protected-output evidence in `04-CHARACTERIZATION.md`.

## Verification

- `php custom/grocy_AI/tests/run.php conversion-characterization` — passed.
- `php -l custom/grocy_AI/tests/conversion-characterization.php` — passed.
- `php -l custom/grocy_AI/tests/conversions.php` — passed.
- `php -l custom/grocy_AI/tests/run.php` — passed.

## Decision and next step

No reusable projection was selected. Native default/product-override parity is necessary evidence, not proof that a reusable projection is safe. Keep profiles and projections inactive until later work exercises a named candidate against current immutable dual-branch evidence.
