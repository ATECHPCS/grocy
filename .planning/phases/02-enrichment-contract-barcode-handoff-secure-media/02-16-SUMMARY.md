---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "16"
subsystem: enrichment-contract
tags: [php, javascript, playwright, security, validation]
dependency_graph:
  requires: [02-15]
  provides: [field-unique-review-contract, provenance-closed-media, bounded-enrichment-response]
  affects: [02-17, 02-18, 02-19, 02-20]
tech_stack:
  added: []
  patterns: [raw-json-lexical-validation, bounded-psr-stream-read, fail-closed-browser-recovery]
key_files:
  created: []
  modified:
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/browser/specs/contract-review.spec.js
    - custom/grocy_AI/src/GrocyAiContract.php
    - custom/grocy_AI/src/GrocyAiService.php
    - public/custom/grocy_AI/product-enrichment.js
decisions:
  - Enrichment responses are limited to 65,536 bytes before raw contract parsing, with malformed or oversized Content-Length mapped to the existing finite contract-invalid recovery.
  - Front-package media requires the exact Open Food Facts pair and search alternatives require the exact SearXNG/Search result pair in both PHP and browser validation.
metrics:
  duration: 4 min
  completed: 2026-08-14
  tasks_completed: 2
  files_modified: 7
---

# Phase 02 Plan 16: Contract-boundary hardening Summary

Duplicate reviewed fields, forged structured-media provenance, oversized response streams, and overly deep raw JSON now fail closed before review state or persistence-adjacent staging can occur.

## Tasks Completed

1. **Adversarial PHP and browser contract regressions** — `cbd7d745`
   - Added independently runnable PHP RED cases for duplicate fields, crossed source pairs, response limits, and raw nesting depth.
   - Added two named Chromium contract-recovery proofs that assert no review row, final diff, staged control/file, or mutation call.

2. **Bounded, provenance-closed validation** — `de1539af`
   - Enforced unique suggestion fields and exact structured/search media source pairs in PHP and the browser.
   - Added a 64-level raw JSON/decode bound plus a 65,536-byte declared-length and incremental-stream cap.
   - Added a reducer defense that clears and reports contract-invalid instead of overwriting a previously keyed review field.

## Verification

- `php -l custom/grocy_AI/tests/run.php`
- `node --check custom/grocy_AI/tests/browser/specs/contract-review.spec.js`
- All four named PHP RED proofs passed through `assert-expected-red.sh` before production changes.
- Both named browser RED proofs passed through `assert-expected-red.sh` in Chromium before production changes.
- `php custom/grocy_AI/tests/run.php` — 113 checks passed.
- `npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep '@enr01|@enr06|@enr07'` — 17 passed.
- `(cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_http_api tests.test_secure_media)` — 18 passed.
- `git diff --check`

## Deviations from Plan

### Auto-fixed Issues

1. **[Rule 1 - Bug] Normalized existing browser fixtures to the closed source labels**
   - **Found during:** Task 2 focused Chromium regression.
   - **Issue:** Existing responsive and secure-media fixtures used `Open Food Facts alternate` and `SearXNG`, which are intentionally rejected by the new exact source-pair contract; one secure-media assertion also became text-ambiguous once `Search result` was canonical.
   - **Fix:** Replaced the synthetic labels with the required canonical pairs and targeted the source element in the assertion.
   - **Files modified:** `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js`, `custom/grocy_AI/tests/browser/specs/secure-media.spec.js`
   - **Commit:** `de1539af`

## Known Stubs

None. Existing media thumbnail placeholders are intentional UI loading state, not unwired contract data.

## Self-Check: PASSED

All declared implementation/test files and both task commits are present in Git history.
