---
phase: 04-reusable-conversion-model
plan: "05"
subsystem: product-conversion-status
tags: [php, slim, sqlite, read-only, provenance, native-save]
provides:
  - bounded MASTER_DATA_EDIT product conversion-status inspection
  - closed resolver provenance DTO with inactive and blocker factor redaction
  - zero-write lifecycle and native-save independence evidence
affects: [04-09]
key-files:
  created: [.planning/phases/04-reusable-conversion-model/04-05-SUMMARY.md]
  modified: [custom/grocy_AI/src/GrocyAiApiController.php, custom/grocy_AI/routes.php, custom/grocy_AI/tests/conversions.php, custom/grocy_AI/tests/run.php]
key-decisions:
  - "Product conversion status is GET-only and cannot bootstrap, activate, project, refresh cache, or mutate product, taxonomy, native, or module state."
  - "Only a native product conversion may expose a usable precise factor; inactive, unavailable, and blocked inspection results expose factor null."
requirements-completed: [CONV-04, CONV-05]
---

# Phase 04 Plan 05: Product Conversion Status Summary

**The native product workflow now has a permission-checked, bounded conversion provenance read that cannot take persistence authority from Grocy's normal Save path.**

## Accomplishments

- Added `GET /api/grocy-ai/products/{productId}/conversion-status` with a `MASTER_DATA_EDIT` check before inspection.
- Validates the product path ID and `from_unit_key` / `to_unit_key` query values strictly, checks product existence, and maps malformed or missing requests to fixed errors without SQL, evidence, or household detail; resolver-unavailable remains a bounded HTTP 200 inspection DTO.
- Calls the deterministic Phase 04 resolver with bootstrap disabled and exposes only its fixed 14-key status/source/provenance DTO.
- Preserves a precise factor for a native product conversion while redacting the factor for inactive, unavailable, and blocked outcomes.
- Registers exactly one GET route and adds no custom POST, PUT, activation, projection, or cache-refresh route.
- Uses query-only endpoint fixtures and complete before/after snapshots to prove no module lifecycle, activation evidence, projection, cache, taxonomy, product, or native-conversion mutation.
- Proves pre/post activation-fixture inspection remains closed, blocked and unavailable outcomes return HTTP 200 with no usable factor, and native package/count and measured-density saves remain independently governed by Grocy's native object controller.

## Verification

- `php custom/grocy_AI/tests/run.php conversion-product-status` — passed.
- `php custom/grocy_AI/tests/run.php conversion-resolution` — passed.
- `php custom/grocy_AI/tests/run.php conversion-native-save-hook` — passed.
- `php custom/grocy_AI/tests/run.php` — passed with 113 checks.
- PHP lint passed for the custom API controller, routes, conversion tests, and runner.
- `git diff --check` — passed.

## Decision and next step

The endpoint is an inspection boundary only. Plan 04-09 may render its bounded provenance beside native product conversion controls, but only the existing Grocy Save path may persist product-scoped package/count or measured-density rows. Reusable activation and selected projection remain exclusive to the later evidence-gated activation plan.
