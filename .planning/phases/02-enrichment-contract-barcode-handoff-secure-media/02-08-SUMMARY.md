---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "08"
subsystem: integrated-acceptance
tags: [playwright, blade, zero-write, accessibility, responsive, contract-v2]

requires:
  - phase: 02-07
    provides: closed contract-v2 review, canonical barcode handoff, and explicit secure-media demand loading
provides:
  - default-deny mutation-family evidence spanning every Grocy durable write boundary
  - exact selected-only normal-Save request sequence with at-most-once barcode continuation
  - mandatory real-Blade rendering plus Chromium/WebKit mobile and accessibility coverage
  - migrated Phase 1 safety fixtures that retain their guarantees against the closed v2 DTO
affects: [02-09-stable-parity, release-gates, mobile-uat, deployment-verification]

tech-stack:
  added: []
  patterns: [default-deny fixture classifier, durable-state snapshots, fail-closed Blade group, two-engine viewport matrix]

key-files:
  created:
    - custom/grocy_AI/tests/browser/specs/zero-write.spec.js
  modified:
    - custom/grocy_AI/tests/browser/fixtures/productform.html
    - custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/browser/specs/concurrency.spec.js
    - custom/grocy_AI/tests/browser/specs/diagnostics.spec.js
    - custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js
    - custom/grocy_AI/tests/browser/specs/happy-path.spec.js
    - custom/grocy_AI/tests/browser/specs/preservation.spec.js
    - custom/grocy_AI/tests/browser/specs/states.spec.js

key-decisions:
  - "Treat every unclassified non-read fixture request as an immediate test failure, while maintaining distinct counters for all nine protected durable mutation families."
  - "Accept the closed v2 DTO as the intentional successor to Phase 1 fixture payloads while preserving the original concurrency, diagnostics, GTIN, lifecycle, preservation, and zero-write assertions."
  - "Keep Phase 1 physical evidence read-only and unaccepted; Plan 02-08 records deterministic browser and Blade evidence only."

patterns-established:
  - "Selected-only Save evidence snapshots native values, dirty events, File bytes, object URLs, and write-family counters before and after staging."
  - "Blade acceptance resolves only an explicit override, checkout autoload, or approved deployed/container paths and fails when no real compiler is available."

requirements-completed: [ENR-01, ENR-02, ENR-03, ENR-04, ENR-05, ENR-06, ENR-07, ENR-08, ENR-09]

duration: 27 min
completed: 2026-08-13
---

# Phase 02 Plan 08: Integrated Acceptance Matrix Summary

**A default-deny, selected-only acceptance matrix now proves the composed contract-v2, barcode, media, lifecycle, Blade, mobile, and accessibility boundaries without contacting providers or writing production data.**

## Performance

- **Duration:** 27 min
- **Started:** 2026-08-13T23:21:04Z
- **Completed:** 2026-08-13T23:48:18Z
- **Tasks:** 2
- **Files modified:** 10 test/harness files

## Accomplishments

- Added durable snapshots for native controls, dirty events, selected File metadata/bytes, barcode state, object-URL lifecycle, Save clicks, and every protected mutation family.
- Added a default-deny classifier that blocks unknown mutations immediately and explicitly observes products, product barcodes, userfields, product groups/categories, stock, stock log, conversions, uploads, and deletes.
- Proved that review/diff/back/staging and lifecycle invalidation remain zero-write, while the sole controlled normal Save emits exactly product creation, database-owned barcode recheck, and one barcode insert.
- Added a fail-closed `--group blade` gate that compiles the complete product form, renders the actual feature hook, validates closed target metadata and exact copy, and requires synchronized 2.4.0 asset tokens.
- Added 320/375/390/768 responsive review and image-grid coverage plus checkbox, focus, live-region, night-mode, non-color, and reduced-motion assertions in Chromium and WebKit.
- Migrated the fifteen stale Phase 1 logical fixture cases from the retired summary payload to the closed v2 DTO without changing their underlying safety claims.

## Task Commits

1. **Task 1 RED: integrated zero-write and Save contract** — `2fdee280`
2. **Task 1 GREEN: default-deny classifier and selected-only Save evidence** — `a9887431`
3. **Task 2: real Blade and two-engine mobile/accessibility evidence** — `4ce870df`
4. **Rule 3 repair: migrate stale Phase 1 fixture assumptions** — `f31598df`

## Files Created/Modified

- `custom/grocy_AI/tests/browser/specs/zero-write.spec.js` — cross-slice durable snapshot, mutation-family trap, unknown-write denial, and exact Save sequence.
- `custom/grocy_AI/tests/browser/fixtures/productform.html` — synchronized 2.4.0 production assets and default-deny mutation instrumentation.
- `custom/grocy_AI/tests/run.php` — explicit real-Blade group with checkout/deployed/container autoload resolution and exact render assertions.
- `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js` — exact viewport, side-by-side comparison/diff, image grid, focus, theme, and reduced-motion coverage.
- Seven existing Phase 1 browser specs — closed-v2 payloads and selectors while retaining their original safety assertions.

## Decisions Made

- Unknown writes are security-test failures, not a generic `save` counter. The fixture reports the exact method/path and refuses the request before the server can handle it.
- The selected-only Save proof intentionally selects a live name and unused barcode while leaving product group, quantity unit, Brand, and picture untouched; exact event deltas and request bodies prove no unselected field was staged.
- Structured and unverified-search image candidates remain separate trust groups. Grid assertions apply within each group: one column at 320px, two from 375px, and auto-fill above.
- Nutrition Facts remains rejected/deferred. No test or rendered assertion introduces nutrition, allergen, dietary, or medical suggestion UI.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking stale tests] Migrated retired Phase 1 payload fixtures to the closed v2 DTO**

- **Found during:** Overall regression verification after Task 2
- **Issue:** Fifteen logical cases still supplied the permissive Phase 1 summary shape or clicked the retired image action, producing 30 failures across Chromium and WebKit even though their safety properties remained applicable.
- **Fix:** Replaced only payload/selector assumptions with closed contract-v2 barcode/suggestion/media structures and explicit `Load thumbnail`; retained request coalescing, trace privacy, string-preserving GTIN, lifecycle invalidation, form/File preservation, 15-second timeout, Save availability, and zero-write assertions.
- **Files modified:** `concurrency.spec.js`, `diagnostics.spec.js`, `gtin-validation.spec.js`, `happy-path.spec.js`, `preservation.spec.js`, `responsive-a11y.spec.js`, `states.spec.js`
- **Verification:** 44/44 migrated cases pass in Chromium and 44/44 pass in WebKit; exact plan gates pass 24/24 and 38/38.
- **Commit:** `f31598df`

**2. [Rule 3 - Blocking runtime] Ran repository-owned browser gates with scoped loopback permission**

- **Found during:** Task 1 RED/GREEN and final verification
- **Issue:** The sandbox denied the deterministic fixture server's `127.0.0.1:4173` bind with `EPERM`.
- **Fix:** Reran only the repository-owned Playwright commands with scoped loopback permission.
- **Files modified:** None.
- **Verification:** No live provider, household service, DNS target, or production write route was contacted.
- **Commit:** Not applicable.

---

**Total deviations:** 2 auto-fixed blocking issues.
**Impact on plan:** The stale-test migration strengthens the integrated release gate and preserves all Phase 1 safety guarantees. No product behavior, contract allowance, network boundary, or persistence authority was widened.

## Issues Encountered

- The first broad run intentionally exposed the 30 stale-fixture failures recorded by Plan 02-07. After migration, every changed safety spec passes independently in both engines.
- Playwright's clock rejects pausing at the exact installation timestamp after microtask advancement; the timeout test now pauses one virtual second later and still proves the boundary at 14,999ms versus 15,000ms after request start.

## Authentication Gates

None. All work used deterministic local fixtures and existing checked-in browser/runtime dependencies.

## Known Stubs

None. The GTIN input placeholder and initial not-loaded media state are intentional product affordances, not implementation stubs.

## Threat Model Verification

- **T-02-08-01:** Unknown writes fail immediately; nine named mutation families have independent counters; the only normal-Save sequence is asserted from exact methods, bodies, and count.
- **T-02-08-02:** Held responses and media callbacks remain inert across GTIN edits, Cancel, orientation, pagehide, hidden visibility, and newer requests; object URLs are counted and revoked.
- **T-02-08-03:** Exact mobile widths, long-value wrapping, side-by-side decisions, 44px targets, focus return, live regions, night mode, and reduced motion pass in Chromium and WebKit.

No new production endpoint, authentication path, file-access behavior, schema, or external network surface was introduced.

## Verification Results

- Task 1 exact gate: JavaScript syntax passes and **24/24 Chromium ENR cases pass**.
- Task 2 exact gate: real Blade acceptance passes and **38/38 selected release cases pass** across Chromium/WebKit.
- Migrated safety fixtures: **44/44 Chromium** and **44/44 WebKit** cases pass.
- `git diff --check` passes; no tracked deletion or generated untracked output remains.

## User Setup Required

None. No package, environment variable, secret, browser binary, or external service was added.

## Next Phase Readiness

- Plan 02-09 can consume the integrated contract/zero-write/mobile evidence without inheriting retired v1 fixture failures.
- Phase 1 physical timing evidence remains `SKIPPED — NOT ACCEPTED`; this plan neither modifies it nor claims physical acceptance.
- Live providers and production data writes remain outside this plan and were not exercised.

## Self-Check: PASSED

- All ten key files and this summary exist.
- Commits `2fdee280`, `a9887431`, `4ce870df`, and `f31598df` exist and contain no tracked deletions.
- Both exact plan verification commands pass after all changes.
- Nutrition Facts remains deferred/rejected, and Phase 1 physical evidence remains untouched and unaccepted.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
