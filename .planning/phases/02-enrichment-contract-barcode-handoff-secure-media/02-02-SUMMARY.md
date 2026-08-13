---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "02"
subsystem: enrichment-contract
tags: [php, python, json, duplicate-detection, zero-write-review]

requires:
  - phase: 02-01
    provides: raw adversarial fixtures, exact RED markers, deployed target/dependency preflight
provides:
  - duplicate-aware all-or-nothing contract-v2 PHP trust boundary
  - closed Python contract-v2 producer without provider dictionaries or external URLs
  - authenticated zero-write name review with provenance and blank-only preselection
affects: [02-03-contract-expansion, 02-04-barcode-ownership, 02-05-field-review, 02-07-secure-media]

tech-stack:
  added: []
  patterns: [recursive raw JSON object walk, closed DTO validators, redacted contract-invalid boundary, textContent-only review rendering]

key-files:
  created:
    - custom/grocy_AI/src/GrocyAiContract.php
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py
  modified:
    - custom/grocy_AI/src/GrocyAiDiagnostic.php
    - custom/grocy_AI/src/GrocyAiService.php
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/browser/specs/contract-review.spec.js
    - views/productform.blade.php
    - public/custom/grocy_AI/product-enrichment.js
    - custom/grocy_AI/module-version.json
    - custom/grocy_AI/portable-files.txt
    - custom/grocy_AI/README.md
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py

key-decisions:
  - "Walk raw JSON recursively and compare decoded member-name tokens per object scope before any full-document decode."
  - "Expose only the closed v2 review DTO and owned trace ID; provider dictionaries, URLs, nutrition families, and parser details remain behind the boundary."
  - "Preselect a name only for a blank native value plus high, structured-direct, canonical-match evidence; review itself never stages or persists data."

patterns-established:
  - "Contract invalidation: every schema, duplicate, trace, provenance, or raw-URL defect becomes one finite contract_invalid service error."
  - "Browser defense in depth: exact decoded member checks precede textContent-only rendering and malformed envelopes produce no partial row."

requirements-completed: [ENR-01, ENR-05, ENR-09]

duration: 16min
completed: 2026-08-13
---

# Phase 02 Plan 02: Closed Contract v2 and Name Review Summary

**Raw duplicate-aware PHP validation and a closed Python producer now deliver one authenticated, provenance-rich, zero-write name decision without leaking provider payloads or external URLs.**

## Performance

- **Duration:** 16 min
- **Started:** 2026-08-13T21:14:00Z
- **Completed:** 2026-08-13T21:30:00Z
- **Tasks:** 2
- **Files modified:** 16 across two repositories

## Accomplishments

- Added a package-free recursive JSON lexical/object walk that decodes member-name tokens, tracks keys independently at every object depth, and rejects top-level, nested, and escaped-equivalent duplicates before ordinary JSON decoding.
- Added strict all-or-nothing validation for exact version 2, barcode identity, outcomes, suggestions, media, warnings, diagnostics, IDs, enums, timestamps, target shapes, provenance, uniqueness, and forbidden raw URLs.
- Replaced permissive PHP response normalization with raw-byte contract validation and one redacted `contract_invalid` service boundary while retaining the 12-second total, 2-second connect, no-redirect, API-key, and owned-trace request policy.
- Added a closed Python producer for canonical barcode context and direct structured name evidence; raw image URLs and provider-specific dictionaries remain internal and media is reserved for the secure-media slice.
- Replaced the v1 summary/apply rendering path with a side-by-side current/suggested name review using fixed DOM structure and `textContent` only.
- Made blank direct structured canonical evidence visibly preselected, protected non-empty names from preselection, and proved valid, non-empty, and malformed-contract paths issue zero mutation calls.

## Task Commits

Each task was committed atomically in every repository it modified:

1. **Task 1: Implement the duplicate-aware closed producer and consumer**
   - `cb27bbf0` — Grocy raw walker, strict validator, service boundary, and PHP regression migration
   - `a989349` — Companion v2 producer, public adapter, and Python regression migration
2. **Task 2: Wire the authenticated name-review happy path**
   - `fe866cb8` — Authenticated route loading, name review, asset/manifest/docs update, and browser acceptance coverage

## Files Created/Modified

- `custom/grocy_AI/src/GrocyAiContract.php` — Raw duplicate-aware recursive descent plus closed contract-v2 validators.
- `custom/grocy_AI/src/GrocyAiService.php` — Raw-byte delegation, owned-trace agreement, and redacted `contract_invalid` mapping.
- `custom/grocy_AI/src/GrocyAiDiagnostic.php` — Closed diagnostic error-code support for `contract_invalid`.
- `custom/grocy_AI/routes.php` — Loads the contract class before dependent service/controller routes.
- `custom/grocy_AI/tests/run.php` — Contract fixture group and v2 service regressions.
- `public/custom/grocy_AI/product-enrichment.js` — Exact browser DTO checks and independent name comparison/preselection rendering.
- `views/productform.blade.php` — Fixed localized review copy and module asset token `2.0.0`.
- `custom/grocy_AI/tests/browser/specs/contract-review.spec.js` — Blank, non-empty, malformed, and zero-write acceptance paths.
- `custom/grocy_AI/module-version.json`, `custom/grocy_AI/portable-files.txt`, `custom/grocy_AI/README.md` — Portable registration, cache token, and v2/zero-write contract documentation.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py` — Closed producer and canonical name suggestion construction.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py`, `grocy_mcp/server.py` — Existing provider orchestration adapted to emit v2 without adding legacy image tokens.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py`, `tests/test_http_api.py` — Existing producer and route guarantees migrated from incompatible v1 assertions.

## Decisions Made

- Duplicate detection is a pre-decode invariant. Escaped-equivalent member names are compared after token-level JSON decoding, and each object nesting scope has an independent key set.
- The Grocy service accepts no v1 compatibility envelope and performs no partial member filtering. Any defect rejects the complete response and discloses only `contract_invalid` through the closed diagnostic path.
- Contract v2 public diagnostics contain only the owned trace ID. Provider stages, timings, raw bodies, exception text, URLs, headers, credentials, and tokens do not enter the DTO.
- The first vertical review slice owns only the name decision. It records transient selection state but intentionally does not stage a native field; normal Grocy Save remains the sole persistence authority.
- Nutrition Facts, allergens, dietary content, and medical content remain rejected/deferred. Phase 1 phone evidence remains untouched and `SKIPPED — NOT ACCEPTED`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Added `contract_invalid` to the diagnostic error allowlist**

- **Found during:** Task 1
- **Issue:** The plan required one finite `contract_invalid` service error, but the existing diagnostic allowlist would have normalized that new code back to generic `provider_error`.
- **Fix:** Added the closed code to `GrocyAiDiagnostic::ERROR_CODES` and verified service exceptions preserve only that finite code.
- **Files modified:** `custom/grocy_AI/src/GrocyAiDiagnostic.php`, `custom/grocy_AI/tests/run.php`
- **Verification:** Contract group and full PHP harness pass.
- **Committed in:** `cb27bbf0`

**2. [Rule 3 - Blocking regression migration] Migrated incompatible v1 tests and added missing decoded-boundary browser coverage**

- **Found during:** Tasks 1 and 2
- **Issue:** Existing companion tests required v1 `product/images/sources` dictionaries and opaque-token injection, while the locked v2 contract forbids those public fields. The original browser RED covered only the blank happy path, not non-empty protection or malformed decoded recovery.
- **Fix:** Reasserted the same provider/auth/deadline behavior through v2 suggestions/media/warning codes, removed legacy public-URL expectations, and added non-empty and unknown-member zero-write browser cases.
- **Files modified:** `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py`, `/Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py`, `custom/grocy_AI/tests/browser/specs/contract-review.spec.js`
- **Verification:** 20 companion tests and 3 focused Chromium-mobile tests pass.
- **Committed in:** Companion `a989349`; Grocy `fe866cb8`

**3. [Rule 1 - State serialization] Corrected the progress percentage written by the state SDK**

- **Found during:** Plan close-out
- **Issue:** `state.update-progress` reported 46% and updated the body progress bar, but serialized `progress.percent: 0` in STATE frontmatter.
- **Fix:** Corrected the frontmatter percentage to match 11 of 24 completed milestone plans and the SDK's reported result.
- **Files modified:** `.planning/STATE.md`
- **Verification:** STATE frontmatter, body progress bar, completed-plan count, and ROADMAP's Phase 2 summary count now agree.
- **Committed in:** Plan metadata commit.

---

**Total deviations:** 3 auto-fixed (1 missing critical functionality, 1 blocking regression migration, 1 state serialization bug).
**Impact on plan:** Both changes enforce the locked incompatible v2 boundary and add proof for required safety behavior; no feature scope or persistence authority expanded.

## Issues Encountered

- The sandbox denied the Playwright fixture server's loopback bind with `EPERM`. The required focused suite was rerun with approved local-server permission and passed all three tests.
- Starlette emitted its existing `TestClient` deprecation warning for HTTPX integration; all companion assertions passed and no dependency change was made.

## Authentication Gates

None. Existing authenticated route middleware and `MASTER_DATA_EDIT` enforcement were retained; no credential value was printed or persisted.

## Known Stubs

- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py` intentionally emits `media: []` in this first name-review slice. Plans 02-06 and 02-07 own the named secure-media RED gate and bounded opaque-handle implementation; returning raw URLs or provisional handles here would violate this plan's trust boundary.

## Threat Model Verification

- **T-02-02-01:** Raw object members are walked before full decode; repeated top-level, nested, and escaped-equivalent names reject wholesale. Exact schema/type/enum/provenance/uniqueness checks then reject all remaining drift.
- **T-02-02-02:** The established session middleware and `MASTER_DATA_EDIT` permission check execute before companion service construction/work; all module routes remain authenticated GET operations.
- **T-02-02-03:** Provider values reach fixed DOM nodes only through `textContent`; no external URL, raw provider dictionary, secret, parser detail, response body, or exception appears in the DTO or browser review.
- **T-02-02-04:** Search and review remain GET-only. Valid blank, protected non-empty, and contract-invalid browser cases recorded zero product, barcode, stock, file, and Save mutations.

No unplanned network endpoint, authentication path, schema change, durable file-access pattern, or new persistence boundary was introduced.

## User Setup Required

None - no package installation, environment variable, or external configuration was added.

## Next Phase Readiness

- Plan 02-03 can expand adversarial contract coverage against one implemented validator rather than adding alternate parsing paths.
- Barcode ownership and field-review plans can consume the exact scanned/canonical/equivalents and typed target fields already enforced by v2.
- Secure-media plans have an explicit empty public seam and can add only opaque bounded handles without removing a legacy raw-URL browser contract.
- Nutrition Facts and Phase 1 physical-phone acceptance remain explicitly outside this plan's completion claims.

## Self-Check: PASSED

- Both created contract files and all fourteen modified files exist in their owning repositories.
- Grocy commits `cb27bbf0` and `fe866cb8` and companion commit `a989349` exist.
- PHP lint, 11 contract fixtures, 86 full Grocy checks, 20 companion tests, 3 focused Chromium-mobile tests, JavaScript syntax, permission/contract wiring checks, forbidden-URL scans, and `git diff --check` passed.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
