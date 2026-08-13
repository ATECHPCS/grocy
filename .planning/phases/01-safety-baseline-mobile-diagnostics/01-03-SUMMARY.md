---
phase: 01-safety-baseline-mobile-diagnostics
plan: "03"
subsystem: ui
tags: [gtin, gs1, playwright, chromium, webkit, mobile, xhr, accessibility]

# Dependency graph
requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "02"
    provides: Isolated Playwright mobile harness and intentional RED happy-path contract
provides:
  - Shared GS1 modulo-10 validation for manual and camera GTIN intent
  - Localized phone-first enrichment controls and accessible state feedback
  - Same-origin enrichment XHR with an exact 15-second browser deadline
  - Review-only success preview that preserves normal Grocy Save ownership
affects: [01-04, 01-05, 01-06, 01-07, mobile-enrichment, browser-state-machine]

# Tech tracking
tech-stack:
  added: []
  patterns: [pure string GTIN validation, module-owned cancellable XHR, localized Blade data contract, textContent-safe preview rendering, zero-write browser assertions]

key-files:
  created:
    - custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js
  modified:
    - views/productform.blade.php
    - public/custom/grocy_AI/product-enrichment.js
    - public/custom/grocy_AI/grocy-ai.css
    - custom/grocy_AI/tests/browser/fixtures/productform.html
    - custom/grocy_AI/tests/browser/specs/happy-path.spec.js

key-decisions:
  - "Treat every GTIN as text and validate all accepted lengths with one GS1 modulo-10 algorithm so leading zeroes survive manual and camera paths."
  - "Own enrichment transport inside the module with a direct same-origin XMLHttpRequest and exact 15,000ms timeout; leave shared Grocy.Api.Get and productform Save handlers unchanged."
  - "Keep enrichment output review-only: applying a name or picture stages the existing form, while durable writes remain exclusively behind the normal Grocy Save controls."

patterns-established:
  - "Intent convergence: input, Enter, and matching Grocy.BarcodeScanned events all pass through normalizeGtin and validateGtin before one search request."
  - "Localized state contract: Blade supplies approved copy through escaped data attributes; browser code renders only safe fixed fields with textContent."

requirements-completed: [MOB-01, MOB-02, MOB-04, MOB-07]

# Metrics
duration: 9 min
completed: 2026-08-13
---

# Phase 01 Plan 03: Phone Enrichment Happy Path Summary

**Phone-ready GTIN validation and camera/manual enrichment flow with immediate localized feedback, a bounded direct XHR, and review-only success previews in Chromium and WebKit**

## Performance

- **Duration:** 9 min
- **Started:** 2026-08-13T00:29:07Z
- **Completed:** 2026-08-13T00:38:25Z
- **Tasks:** 2
- **Files modified:** 6

## Accomplishments

- Added deterministic GTIN-8/12/13/14 vectors covering valid and invalid GS1 check digits, leading zeroes, separators, non-digits, manual Enter, matching camera events, unrelated camera targets, and zero-request invalid input.
- Reworked the existing product-form card with a visible localized GTIN label, approved safety copy, Scan barcode/Search product controls, persistent live status, distinct length/checksum errors, and mobile 44px actions.
- Replaced only the enrichment search call with a same-origin direct XHR using `xhr.timeout = 15000`; no automatic retry or persistence request was added.
- Preserved the existing suggestion staging behavior, typed product name, selected picture, both enabled Save actions, and six observed Save clicks while all product/barcode/stock/file/save counters remained zero.
- Verified the card remains above Picture without horizontal overflow at both 320px and 390px in Chromium and WebKit.

## TDD Evidence

### RED

`npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob01` failed before production edits in both engines for the intended missing behavior: no ready/status markup, no input-time length/checksum feedback, no camera-event handoff, and no approved success heading. Separator normalization already passed, proving the harness exercised the real pre-existing asset rather than failing on infrastructure.

### GREEN

The same command passes 14 tests across `chromium-mobile` and `webkit-mobile`, including the original happy-path preservation and zero-write contract.

## Acceptance Evidence

| Criterion | Result |
|---|---|
| Valid lengths and check digits | PASS — one valid and one invalid-checksum vector for GTIN-8, 12, 13, and 14 in both engines |
| Leading zero preservation | PASS — `012345678905` remains text and reaches the enrichment URL unchanged |
| Invalid input response | PASS — exact distinct length/checksum copy and accessible invalid state appear within 250ms with zero request |
| Camera/manual shared intent | PASS — the real jQuery `Grocy.BarcodeScanned` event searches once for the enrichment target and ignores unrelated targets |
| Immediate ready/busy feedback | PASS — ready and `Searching product details…` state are visible within 250ms |
| Browser deadline | PASS — source and syntax checks confirm direct `xhr.timeout = 15000` and no enrichment `Grocy.Api.Get` call |
| Review-only success | PASS — `Product details found` renders while original form name/file state remains unchanged until explicit preview actions |
| Persistence boundary | PASS — exactly one enrichment GET and zero product, barcode, stock, file, or save API calls |
| Save independence | PASS — both Save controls remain enabled and clickable before, during, and after enrichment |
| Responsive placement | PASS — no horizontal overflow at 320px/390px; card precedes Picture; phone actions meet 44px target height |

## Task Commits

Each task was committed atomically:

1. **Task 1: Lock shared GTIN validation and camera/manual intent behavior** — `bdab7668` (test)
2. **Task 2: Make the thin phone enrichment happy path green** — `f04a2bd3` (feat)

## Files Created/Modified

- `custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js` — Shared GS1 vectors, accessibility assertions, request counts, and camera/manual intent coverage.
- `custom/grocy_AI/tests/browser/specs/happy-path.spec.js` — Preserved zero-write/Save contract plus 320px and 390px responsive checks.
- `custom/grocy_AI/tests/browser/fixtures/productform.html` — Deterministic mirror of the approved production markup and direct-XHR request counters.
- `views/productform.blade.php` — Localized labeled controls, camera target, approved state copy, and persistent live region above Picture.
- `public/custom/grocy_AI/product-enrichment.js` — Pure GTIN validation, shared intent routing, bounded direct XHR, safe state rendering, and unchanged preview staging.
- `public/custom/grocy_AI/grocy-ai.css` — Approved typography/spacing, wrapping, 44px controls, no-overflow behavior, and reduced-motion treatment.

## Decisions Made

- Empty input remains the UI-SPEC idle state; invalid length feedback begins after an explicit edit/search intent rather than marking the untouched form invalid on load.
- The visible Scan barcode button delegates to Grocy's existing camera component, while decoded camera events use the exact same validator and request path as typed input.
- Provider image URLs are limited to HTTP(S) before rendering; all preview text continues to use `textContent`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated the deterministic fixture with production markup and direct-XHR observation**
- **Found during:** Task 2 (Make the thin phone enrichment happy path green)
- **Issue:** The Plan 01-02 fixture mirrored the old card and counted only calls made through `Grocy.Api.Get`; the required direct XHR and new stable IDs could not be verified accurately without updating the fixture.
- **Fix:** Mirrored the approved card contract and instrumented native XHR `open()` in the fixture while retaining the same deny-by-default server and non-persistence adapters.
- **Files modified:** `custom/grocy_AI/tests/browser/fixtures/productform.html`
- **Verification:** Smoke and all `@mob01` tests pass in both engines; product/barcode/stock/file/save counters remain zero.
- **Committed in:** `f04a2bd3`

---

**Total deviations:** 1 auto-fixed (1 blocking issue).
**Impact on plan:** The deterministic fixture now observes the planned transport correctly without adding production scope or weakening persistence safeguards.

## Issues Encountered

- The sandbox initially denied the Playwright loopback fixture server (`listen EPERM`); verification was rerun with approved local browser/server execution and passed in both engines.

## Authentication Gates

None.

## TDD Gate Compliance

- **RED:** PASS — `bdab7668` precedes production changes and fails on the intended absent GTIN/camera/approved-markup behaviors.
- **GREEN:** PASS — `f04a2bd3` implements the minimal phone happy path and makes the same suite green.
- **REFACTOR:** Not needed; the production implementation remained inside the existing module boundary.

## Known Stubs

None. Empty arrays and null request state in changed files are intentional runtime/test initialization, not unwired UI data.

## Threat Model Verification

- **T-01-03-01:** Mitigated by string-preserving GS1 validation and zero-request invalid cases.
- **T-01-03-02:** Mitigated by fixed localized state copy, allowlisted HTTP(S) image URLs, and `textContent` rendering for provider fields.
- **T-01-03-03:** Mitigated for this slice by one active module-owned XHR and exact 15-second timeout; full coalescing/race coverage remains assigned to Plan 01-06.
- **T-01-03-04:** Mitigated by unchanged normal Save handlers and passing zero-write/preservation assertions.

No unplanned network endpoint, authentication path, file-access boundary, or schema change was introduced.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Ready for Plan 01-04 to mirror GTIN validation and finite diagnostics at the trusted Grocy service boundary.
- Plan 01-06 still owns complete duplicate-intent coalescing and stale-response lifecycle coverage.
- Physical phone/LAN measurements remain assigned to Plan 01-10.

## Self-Check: PASSED

- All six created/modified implementation and browser-test files exist at the documented paths.
- Task commits `bdab7668` and `f04a2bd3` exist in repository history and contain no tracked-file deletions.
- Smoke, `@mob01`, JavaScript syntax, direct-XHR deadline, unchanged Save handler, responsive placement, preservation, and zero-write claims were re-run and verified.
- Stub and threat-surface scans found no goal-blocking stub or unplanned trust boundary.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
