---
phase: 03
slug: food-taxonomy-categorization-pilot
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-17
---

# Phase 03 — Validation Strategy

## Test Infrastructure

| Property | Value |
|----------|-------|
| Framework | Existing standalone PHP module harness plus Playwright browser workspace |
| Config file | `custom/grocy_AI/tests/run.php`, `custom/grocy_AI/tests/browser/playwright.config.js` |
| Quick run command | `php custom/grocy_AI/tests/run.php` |
| Full suite command | `php custom/grocy_AI/tests/run.php && npm --prefix custom/grocy_AI/tests/browser run test:release` |
| Estimated runtime | Under 60 seconds locally, excluding first browser installation |

## Sampling Rate

- After every task commit: run the focused PHP case named by that task.
- After every plan wave: run the full suite command.
- Before verification: run the full suite on both main and stable after portable/adapter parity checks.
- Max feedback latency: 60 seconds for deterministic tests.

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | TAX-01, TAX-02, TAX-07 | T-03-01-01 | Namespaced schema rejects excluded/dynamic types | PHP/SQLite | `php custom/grocy_AI/tests/run.php taxonomy-schema` | ❌ W0 | pending |
| 03-01-02 | 01 | 1 | TAX-01, TAX-02, TAX-05 | T-03-01-02 | Closed evidence/mapping DTO and authenticated narrow API | PHP | `php custom/grocy_AI/tests/run.php taxonomy-api` | ❌ W0 | pending |
| 03-02-01 | 02 | 2 | TAX-03, TAX-04, TAX-05 | T-03-02-01 | Explicit Unclassified and one-leaf update mutate module rows only | PHP/SQLite | `php custom/grocy_AI/tests/run.php taxonomy-assignment` | ❌ W0 | pending |
| 03-02-02 | 02 | 2 | TAX-03, TAX-04, TAX-05 | T-03-02-02 | Product panel has visible evidence and never invokes normal product Save | Playwright | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @tax03` | ❌ W0 | pending |
| 03-03-01 | 03 | 3 | TAX-06 | T-03-03-01 | Inventory validation is read-only and reports frozen/preserved boundary | PHP/SQLite | `php custom/grocy_AI/tests/run.php taxonomy-validation` | ❌ W0 | pending |
| 03-03-02 | 03 | 3 | TAX-01..TAX-07 | T-03-03-02 | Portable/stable release contains exact taxonomy assets and cache invalidation | release | `bash custom/grocy_AI/tests/release-gate.sh` | ✅ | pending |

## Wave 0 Requirements

- Extend the existing PHP runner with taxonomy case dispatch and SQLite fixtures; do not add a framework or dependency.
- Extend the existing Playwright fixture server/spec pattern with deterministic taxonomy API envelopes and mutation counters.

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Stable product edit experience | TAX-03, TAX-04, TAX-05 | Requires signed-in household UI and real cache/release path | Open an existing product, assign a leaf then Unclassified, reload, and confirm product/stock/recipe/location fields are unchanged. |
| Inventory-wide validation evidence | TAX-06 | Must use production-shaped data without exporting household content | Run the maintainer report; retain only redacted aggregate counts and the frozen/preserved decision. |

## Validation Sign-Off

- [x] Every planned task has deterministic automated verification or an explicit manual follow-up.
- [x] Existing infrastructure covers the required test types; Wave 0 is fixture extension only.
- [x] No watch-mode commands.
- [x] Feedback latency target is under 60 seconds.

**Approval:** 2026-08-17
