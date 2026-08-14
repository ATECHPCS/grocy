---
phase: 02-enrichment-contract-barcode-handoff-secure-media
reviewed: 2026-08-14T19:39:11Z
depth: standard
files_reviewed: 51
files_reviewed_list:
  - /Users/ian/Documents/Repos/grocy-atech-release/CUSTOMIZATIONS.md
  - /Users/ian/Documents/Repos/grocy-atech-release/Dockerfile.atech
  - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/routes.php
  - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/src/GrocyAiApiController.php
  - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/version.json
  - /Users/ian/Documents/Repos/grocy-atech-release/migrations/0256.php
  - /Users/ian/Documents/Repos/grocy-atech-release/public/viewjs/productform.js
  - /Users/ian/Documents/Repos/grocy-atech-release/views/productform.blade.php
  - /Users/ian/Documents/Repos/grocy-mcp/constraints-phase2.txt
  - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py
  - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py
  - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py
  - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py
  - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py
  - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py
  - /Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py
  - /Users/ian/Documents/Repos/grocy-mcp/tests/test_secure_media.py
  - custom/grocy_AI/README.md
  - custom/grocy_AI/module-version.json
  - custom/grocy_AI/phase2-changed-paths.txt
  - custom/grocy_AI/portable-files.txt
  - custom/grocy_AI/routes.php
  - custom/grocy_AI/src/GrocyAiApiController.php
  - custom/grocy_AI/src/GrocyAiBarcodeService.php
  - custom/grocy_AI/src/GrocyAiContract.php
  - custom/grocy_AI/src/GrocyAiDiagnostic.php
  - custom/grocy_AI/src/GrocyAiGtin.php
  - custom/grocy_AI/src/GrocyAiService.php
  - custom/grocy_AI/tests/assert-expected-red.sh
  - custom/grocy_AI/tests/barcode-handoff.php
  - custom/grocy_AI/tests/browser/fixtures/productform.html
  - custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js
  - custom/grocy_AI/tests/browser/specs/concurrency.spec.js
  - custom/grocy_AI/tests/browser/specs/contract-review.spec.js
  - custom/grocy_AI/tests/browser/specs/diagnostics.spec.js
  - custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js
  - custom/grocy_AI/tests/browser/specs/happy-path.spec.js
  - custom/grocy_AI/tests/browser/specs/preservation.spec.js
  - custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js
  - custom/grocy_AI/tests/browser/specs/secure-media.spec.js
  - custom/grocy_AI/tests/browser/specs/states.spec.js
  - custom/grocy_AI/tests/browser/specs/zero-write.spec.js
  - custom/grocy_AI/tests/browser/support/server.mjs
  - custom/grocy_AI/tests/deployment-gate.sh
  - custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json
  - custom/grocy_AI/tests/release-gate.sh
  - custom/grocy_AI/tests/run.php
  - migrations/0256.php
  - public/custom/grocy_AI/grocy-ai.css
  - public/custom/grocy_AI/product-enrichment.js
  - public/viewjs/productform.js
  - views/productform.blade.php
findings:
  critical: 1
  warning: 3
  info: 0
  total: 4
status: issues_found
---

# Phase 02: Code Review Report

**Reviewed:** 2026-08-14T19:39:11Z
**Depth:** standard
**Files Reviewed:** 51
**Status:** issues_found

## Summary

The Phase 2 contract, barcode handoff, and media flow are broadly isolated and the PHP contract/lint suites pass. However, the companion response boundary remains susceptible to memory/stack exhaustion, and several contract and test gaps can violate the review-before-save evidence model or conceal a live-route regression.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: BLOCKER — Unbounded companion bodies can exhaust a Grocy PHP worker

**File:** `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiService.php:72-79`, `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiService.php:172-182`, `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiContract.php:27-39`, `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiContract.php:71-180`

**Issue:** The companion response is fully materialized with `(string) $response->getBody()` and passed into a recursive duplicate-key walker before any byte or nesting limit is applied. A compromised or malfunctioning authenticated companion can return an arbitrarily large or deeply nested JSON body. This can consume worker memory or recurse until the PHP process fails, turning a single enrichment request into a denial of service.

**Fix:** Enforce a small contract byte budget while streaming the HTTP body (and reject an oversized `Content-Length` before reading). Add a maximum JSON nesting depth to the lexical walk, or replace it with an iterative, depth-limited duplicate-key parser. Reject before `json_decode`/validation, and add boundary tests for oversized and excessive-depth payloads.

## Warnings

### WR-01: WARNING — Duplicate field suggestions pass validation but the browser silently stages only the last one

**File:** `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiContract.php:265-294`, `/Users/ian/Documents/Repos/grocy/public/custom/grocy_AI/product-enrichment.js:1225-1246`

**Issue:** The PHP validator only enforces unique suggestion IDs, not unique `field` values. A valid response containing two different `name` IDs is accepted; the UI renders both rows, but `reviewState.rows[row.field] = row` overwrites the first row. The displayed selection and the final diff can therefore disagree, and an automatic selection can be dropped or replaced without an explicit choice. This was reproduced against `DecodeAndValidateRaw` with two valid `name` suggestions.

**Fix:** Reject a second suggestion for an already-seen field in `ValidateSuggestions`, mirror that check in `validContract`, and add a regression case asserting that duplicate fields produce `contract_invalid` rather than a last-wins review state.

### WR-02: WARNING — The closed media contract does not bind provenance to its evidence classification

**File:** `/Users/ian/Documents/Repos/grocy/custom/grocy_AI/src/GrocyAiContract.php:297-337`, `/Users/ian/Documents/Repos/grocy/public/custom/grocy_AI/product-enrichment.js:599-618`

**Issue:** A `front_package` item with `high`/`structured_direct` evidence is accepted even when `source.id` is `searxng`; conversely, a search alternative can claim `openfoodfacts`. Source labels are also accepted as arbitrary text. This lets a malformed/compromised companion make an unverified search image appear as a high-confidence structured image, contradicting the phase’s evidence distinction.

**Fix:** Use a closed source-ID-to-label map and require `front_package` to be exactly `openfoodfacts` / `Open Food Facts`, while `search_alternative` must be exactly `searxng` / `Search result`. Add negative PHP and browser contract tests for crossed source/kind combinations.

### WR-03: WARNING — HTTP API tests exercise the retired image route rather than the deployed secure-media route

**File:** `/Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py:19-38`, `/Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py:105-149`, `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py:2247-2293`

**Issue:** The test constructs a private Starlette app with legacy `GET /v1/products/images/{token}` wired to `_selected_product_image` and mutates `_image_selections`. Production now serves `GET /v1/products/images/{variant}/{token}` through `_secure_product_image` and `SecureMediaService`. The existing tests can pass while the live route’s variant binding, authentication, expiration, or capability redemption is broken.

**Fix:** Test `build_app()` (with controlled environment/configuration) or mount the real `_secure_product_image` handler at the real variant route. Stub `default_secure_media_service()` and assert unauthorized requests do no resolver/fetch work, correct variants redeem, and bad/expired capabilities return the production-safe error.

---

_Reviewed: 2026-08-14T19:39:11Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: standard_
