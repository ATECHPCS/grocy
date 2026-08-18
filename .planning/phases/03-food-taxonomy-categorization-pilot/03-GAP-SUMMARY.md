---
phase: 03-food-taxonomy-categorization-pilot
plan: gap-closure
subsystem: taxonomy-evidence-validation
tags: [php, sqlite, taxonomy, maintainer-cli]
requires:
  - phase: 03-03
    provides: module-owned taxonomy evidence and read-only aggregate validation
provides:
  - server-owned Phase 2 food-type evidence reconciliation
  - configured-database read-only maintainer taxonomy report
affects: [phase-03-verification, stable-mirror]
key-files:
  created:
    - custom/grocy_AI/bin/validate-inventory-taxonomy.php
  modified:
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/src/GrocyAiTaxonomyService.php
    - custom/grocy_AI/tests/taxonomy.php
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/README.md
decisions:
  - "Only the closed, server-validated companion response may update module evidence; browser payloads never provide taxonomy evidence."
  - "The maintainer report requires GROCY_DATAPATH and disables taxonomy bootstrap so it is read-only against the configured Grocy database."
commits:
  - b85abae5
---

# Phase 3 Gap Closure Summary

**Production taxonomy evidence now comes from the validated Phase 2 enrichment result, and maintainers can emit a redacted, read-only report from the configured Grocy database.**

## Closed Blockers

- The authenticated enrichment endpoint reconciles only its server-validated `food_type` suggestion for an existing local product. It upserts provider category, confidence, reason, and ruleset version in `grocy_ai_taxonomy_evidence`; a validated response without a food-type suggestion clears only that stale module snapshot. No leaf is assigned and no upstream product data is changed.
- `custom/grocy_AI/bin/validate-inventory-taxonomy.php` requires an absolute `GROCY_DATAPATH`, opens its `grocy.db`, disables bootstrap/migration behavior, and prints only the aggregate validation JSON. It never emits household product data or performs database writes.

## Verification

- `php custom/grocy_AI/tests/run.php taxonomy-production-paths` — passed.
- `php custom/grocy_AI/tests/run.php` — passed (113 checks).
- `bash custom/grocy_AI/tests/release-gate.sh taxonomy` — passed.
- `npm --prefix custom/grocy_AI/tests/browser run test:release` — passed (148 tests).

## Stable Mirror Required

The stable worktree was intentionally untouched. Its later mirror/adaptation must include these exact main-repository paths:

- `custom/grocy_AI/bin/validate-inventory-taxonomy.php`
- `custom/grocy_AI/src/GrocyAiApiController.php`
- `custom/grocy_AI/src/GrocyAiTaxonomyService.php`
- `custom/grocy_AI/tests/run.php`
- `custom/grocy_AI/tests/taxonomy.php`
- `custom/grocy_AI/README.md`

## Deviations from Plan

None — this closure directly supplies the two missing production pathways identified by `03-VERIFICATION.md`.

## Self-Check: PASSED

- Commit `b85abae5` exists and contains the production and deterministic test changes.
- The maintainer command is tested against a file-backed configured-database fixture with before/after snapshots proving no writes.
