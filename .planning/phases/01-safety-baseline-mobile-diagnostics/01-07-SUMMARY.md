---
phase: 01-safety-baseline-mobile-diagnostics
plan: "07"
subsystem: testing
tags: [playwright, mobile, accessibility, jsonl, percentile, git-parity]

requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "06"
    provides: finite mobile request lifecycle, redacted diagnostics, preservation, and zero-write browser contracts
provides:
  - Cross-engine responsive/accessibility gate at 320, 375, 390, and 768 pixels
  - Closed redacted physical-phone evidence schema with deterministic nearest-rank budgets
  - Full-SHA read-only stable parity report and explicit portable file manifest
  - Physical acceptance/stable smoke protocol and truthful Nyquist validation map
affects: [01-08-stable-portable-mirror, 01-09-stable-deployment, 01-10-physical-phone-acceptance]

tech-stack:
  added: []
  patterns: [computed touch-target and overflow assertions, closed JSONL evidence, nearest-rank release gate, full-SHA git object comparison]

key-files:
  created:
    - custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js
    - custom/grocy_AI/portable-files.txt
    - custom/grocy_AI/tests/check-portable-parity.sh
    - .planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md
    - .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl
    - .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py
  modified:
    - public/custom/grocy_AI/product-enrichment.js
    - public/custom/grocy_AI/grocy-ai.css
    - custom/grocy_AI/README.md
    - .planning/phases/01-safety-baseline-mobile-diagnostics/01-VALIDATION.md

key-decisions:
  - "Require one supplied full 40-hex stable commit and read blobs with git show; never infer or move a stable ref during parity."
  - "Keep physical evidence empty and failing until the stable deployment and real phone sampling plans provide all locked samples."
  - "Treat orientation change as request invalidation and the diagnostics disclosure as a touch action subject to the 44px contract."

patterns-established:
  - "Responsive gate: measure required targets and document/body overflow in Chromium and WebKit at every locked width."
  - "Evidence gate: accept a closed per-line object, reject unknown/forbidden fields and values, then compute deterministic nearest-rank p50/p95."
  - "Parity gate: compare the current portable manifest against one immutable commit using temporary stable blobs only."

requirements-completed: [MOB-08]

duration: 17min
completed: 2026-08-13
---

# Phase 01 Plan 07: Mobile Release Evidence and Parity Summary

**Two-engine mobile accessibility coverage, threshold-locked redacted phone evidence, and immutable stable parity/smoke gates make the Phase 1 release contract mechanically inspectable.**

## Performance

- **Duration:** 17 min
- **Started:** 2026-08-13T01:49:21Z
- **Completed:** 2026-08-13T02:06:01Z
- **Tasks:** 3
- **Files modified:** 10

## Accomplishments

- Added a no-retry Chromium/WebKit `@mob08` suite covering 320/375/390/768 widths, horizontal overflow, 44px targets, exact ARIA/focus/keyboard semantics, reduced motion, lifecycle restoration, ≤250ms feedback, and the exact 15,000ms transition.
- Added a dependency-free JSONL checker with a closed evidence schema, forbidden key/value rejection, 20-sample requirements, deterministic nearest-rank p50/p95, exact 1000/5000/5000ms thresholds, and an exact 15000ms timeout gate.
- Added a seven-file portable manifest and parity tool that accepts only one supplied 40-hex commit, reads it through `git show`, reports stable adapters separately, and leaves the main working tree unchanged.
- Replaced the draft validation map with real Plan 01-02 through 01-10 IDs/waves/threats/paths and separated passing deterministic Nyquist coverage from pending stable/physical release evidence.

## Verification Evidence

| Gate | Result |
|---|---|
| Focused mobile/accessibility | PASS — 16/16 `@mob08` executions across Chromium and WebKit |
| Full browser release | PASS — 78/78 executions across both engines with retries disabled |
| Grocy native contract | PASS — 84/84 checks |
| Companion contract | PASS — 25/25 tests |
| Timing checker self-test | PASS — 11/11 boundary, ordering, schema, privacy, count, timeout, and zero-write tests |
| Empty production evidence | EXPECTED FAIL — exact missing counts: cached 20, metadata 20, image attachment 20, timeout 1 |
| Stable parity at `1050e600001fa64efe2437914d15d58e56031fdf` | EXPECTED FAIL — 2 missing and 5 mismatched portable paths, proving Plan 01-08 adaptation remains required |
| Parity input safety | PASS — missing, moving-ref, abbreviated, and nonexistent/non-commit values exit 2; forbidden Git mutation command scan is empty |
| Syntax/source hygiene | PASS — Python compile, JavaScript check, shell syntax, `git diff --check`, and branch assertion |

## Task Commits

Each task was committed atomically. TDD tasks have separate RED and GREEN commits:

1. **Task 1 RED: responsive/accessibility release contract** — `3afcbd4d` (test)
2. **Task 1 GREEN: mobile accessibility/lifecycle corrections** — `2dca1676` (fix)
3. **Task 2 RED: phone evidence checker contract** — `fc5a2dbd` (test)
4. **Task 2 GREEN: evidence checker and physical acceptance protocol** — `e0a0f298` (feat)
5. **Task 3: SHA-pinned parity and validation map** — `e448a0d6` (chore)

## Files Created/Modified

- `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js` — Locked viewport, target, ARIA/focus, lifecycle, reduced-motion, feedback, and timeout assertions.
- `public/custom/grocy_AI/product-enrichment.js` — Invalidates active requests and restores controls on orientation change.
- `public/custom/grocy_AI/grocy-ai.css` — Makes Diagnostics a 44px action and disables Font Awesome spinner animation for reduced motion.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py` — Closed schema, self-tests, nearest-rank aggregation, and release policy enforcement.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl` — Comment-free production evidence target, intentionally empty before Plan 01-10.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md` — Device/network metadata, full scenario checklist, Save/reload/restoration spine, and privacy-safe capture procedure.
- `custom/grocy_AI/portable-files.txt` — Exact seven-file portable Phase 1 boundary.
- `custom/grocy_AI/tests/check-portable-parity.sh` — Read-only full-SHA comparison and stable adapter classification.
- `custom/grocy_AI/README.md` — Deterministic gates, separate repository/commit rules, cache-marker requirement, parity command, and stable smoke.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-VALIDATION.md` — Actual Plan/wave/threat/file map with deterministic and external gates truthfully distinguished.

## Decisions Made

- Accepted only a supplied immutable commit as the stable comparison authority. Resolving `atech-release` or another moving ref would make parity non-repeatable and is rejected.
- Kept browser/device emulation and physical evidence separate. Nyquist is green for deterministic behavior, while stable deployment and real LAN/phone evidence remain explicitly pending Plans 01-09 and 01-10.
- Made the JSONL schema deny-by-default at both key and value levels. Device/version metadata is bounded safe text, scenario/status fields are closed enums, and thresholds are executable constants rather than evidence input.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrected diagnostics target size and actual reduced-motion spinner**
- **Found during:** Task 1 RED run
- **Issue:** The Diagnostics summary measured 21px (42px only when wrapped) rather than 44px, and the live Font Awesome `.fa-spin` animation continued under reduced-motion because CSS covered only `.spinner-border`.
- **Fix:** Made the summary a flex-aligned 44px minimum target and disabled both Bootstrap and Font Awesome spinner animations in the reduced-motion query.
- **Files modified:** `public/custom/grocy_AI/grocy-ai.css`
- **Verification:** All four widths in both engines report compliant targets; reduced-motion computed `animation-name` is `none`.
- **Committed in:** `2dca1676`

**2. [Rule 2 - Missing Critical] Added orientation lifecycle invalidation**
- **Found during:** Task 1 behavior implementation
- **Issue:** Page hide and backgrounding invalidated work, but physical orientation changes could leave the prior request active and able to render an obsolete result.
- **Fix:** Routed `orientationchange` through the existing invalidate-before-abort lifecycle path; no retry is created.
- **Files modified:** `public/custom/grocy_AI/product-enrichment.js`
- **Verification:** Cross-engine held-response test restores Search, clears busy/spinner/result state, ignores the late response, and records one request total.
- **Committed in:** `2dca1676`

---

**Total deviations:** 2 auto-fixed (1 Rule 1 bug, 1 Rule 2 missing critical behavior)
**Impact on plan:** Both fixes enforce the already-locked mobile contract without adding a new endpoint, dependency, write path, retry policy, or architectural surface.

## Issues Encountered

- The sandbox denied the Playwright fixture server's loopback bind with `EPERM`. Required browser commands were rerun with approved loopback permission and then passed.
- Context7 MCP and the `ctx7` CLI fallback were unavailable. The implementation used the already-established Playwright Clock/viewport/emulation APIs from the installed locked version and retained existing project patterns.

## Authentication Gates

None.

## TDD Gate Compliance

- **Task 1:** PASS — RED `3afcbd4d` failed on the intended touch-target/reduced-motion contract before GREEN `2dca1676` made 16/16 focused executions pass.
- **Task 2:** PASS — RED `fc5a2dbd` failed all eight initial behavior tests on explicit unimplemented gates before GREEN `e0a0f298` made the expanded 11-test checker suite pass.
- **REFACTOR:** No separate behavior-neutral cleanup commit was needed.

## Known Stubs

- `.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl:1` is intentionally empty. Plan 01-10 populates it only after Plan 01-09 deploys the immutable stable adapter commit; until then the release checker fails closed with exact missing counts.

## Threat Model Verification

- **T-01-07-01:** Fixed sample counts/thresholds and self-tests prevent evidence tampering or silent re-baselining.
- **T-01-07-02:** Closed fields/enums plus key/value privacy patterns reject GTIN/product/inventory/credential/header/cookie/payload/URL/image-token categories.
- **T-01-07-03:** Full-SHA validation, commit-object checks, safe manifest paths, temporary blob comparison, and an empty Git mutation scan preserve repository state.
- **T-01-07-04:** The full 78-test release run retains the diagnostic/privacy canaries across both engines.
- **T-01-07-SC:** The already-approved exact Playwright 1.62.1 lock remains unchanged.

No unplanned network endpoint, authentication path, database/schema change, durable file access, external provider call, or persistence boundary was introduced. The new local evidence/parity file reads are covered by the plan threat model.

## User Setup Required

None - no external service configuration is required by this plan.

## Next Phase Readiness

- Plan 01-08 can mirror exactly the seven portable paths and record a stable full SHA until parity passes.
- Plan 01-09 can apply only the separately documented controller/routes/view/cache/customization adapters and execute the stable smoke.
- Plan 01-10 can populate the closed evidence file and complete physical-phone acceptance after the stable deployment is available.
- No deterministic implementation blocker remains. The expected stable mismatch and empty physical evidence are explicit downstream gates, not Plan 01-07 failures.

## Self-Check: PASSED

- All six created artifacts and four modified implementation/documentation files exist.
- All five task-level RED/GREEN and tooling commits are present in git history.
- The summary and working-tree diff pass structural checks.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
