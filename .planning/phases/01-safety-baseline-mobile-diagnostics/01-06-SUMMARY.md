---
phase: 01-safety-baseline-mobile-diagnostics
plan: "06"
subsystem: ui
tags: [javascript, playwright, mobile, xhr, trace-context, diagnostics, privacy, concurrency]

# Dependency graph
requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "05"
    provides: Closed Grocy outcome/diagnostic envelope, strict owned trace propagation, and fixed transport budgets
provides:
  - Finite mobile enrichment states with exact cancel/offline/timeout/not-found/companion/provider/partial/success recovery copy
  - Monotonic request ownership with normalized-GTIN stale guards, same-intent coalescing, and lifecycle cancellation
  - Browser-generated W3C traceparent propagation and a closed request-scoped diagnostic clipboard report
  - Deterministic two-engine race, privacy-canary, and zero-write form/file preservation coverage
affects: [01-07, 01-08, 01-09, mobile-release-gate, stable-branch-parity]

# Tech tracking
tech-stack:
  added: []
  patterns: [single active intent, invalidate-before-abort, explicit retry only, closed browser diagnostic serializer, deterministic browser clock]

key-files:
  created:
    - custom/grocy_AI/tests/browser/specs/states.spec.js
    - custom/grocy_AI/tests/browser/specs/concurrency.spec.js
    - custom/grocy_AI/tests/browser/specs/diagnostics.spec.js
    - custom/grocy_AI/tests/browser/specs/preservation.spec.js
  modified:
    - public/custom/grocy_AI/product-enrichment.js
    - public/custom/grocy_AI/grocy-ai.css
    - views/productform.blade.php
    - custom/grocy_AI/tests/browser/package.json
    - .gitignore

key-decisions:
  - "Use request sequence plus normalized GTIN—not trace identity—as the sole browser UI ownership guard."
  - "Treat the server diagnostic DTO as untrusted and reconstruct the browser report from closed stage/version/enum primitives while keeping the browser request trace authoritative."
  - "Make reconnect and pageshow restoration control-only; only an explicit Retry creates a new request and trace."

patterns-established:
  - "Invalidate before abort: increment the sequence, clear active ownership and the deadline, then abort so queued callbacks cannot render."
  - "Request-scoped diagnostics: collapsed trace suffix/outcome/duration summary with a separately visible copy action and a selected read-only fallback."

requirements-completed: [MOB-02, MOB-03, MOB-04, MOB-05, MOB-06, MOB-07]

# Metrics
duration: 24 min
completed: 2026-08-13
---

# Phase 01 Plan 06: Mobile State and Diagnostic Interaction Summary

**Race-safe phone enrichment with explicit recovery, owned W3C trace correlation, closed redacted clipboard diagnostics, and zero-write preservation across every degraded path**

## Performance

- **Duration:** 24 min
- **Started:** 2026-08-13T01:18:25Z
- **Completed:** 2026-08-13T01:42:18Z
- **Tasks:** 3
- **Files modified:** 9

## Accomplishments

- Replaced the loose XHR lifecycle with one request record carrying a monotonic sequence, normalized GTIN, XHR, reason, start time, and cryptographically generated W3C `traceparent`; every enrichment callback requires current object, sequence, and GTIN ownership.
- Added exact finite mobile states, explicit Cancel/Retry controls, a clock-verifiable 15,000ms deadline, same-intent coalescing, different-intent replacement, edit/cancel/pagehide/background invalidation, and zero automatic retry on reconnect or pageshow.
- Added request-scoped diagnostics that retain the browser trace across success, timeout, and cancel, reconstruct only closed allowlisted fields, remain collapsed by default, copy with exact success feedback, and provide the exact selected read-only fallback when clipboard access is blocked.
- Preserved the Wave 3 review-only preview path, selected-image flow, all ordinary form/file values, and both normal Save controls while browser tests prove no product, barcode, stock, file, or save write endpoint is called.
- Established deterministic Chromium/WebKit coverage for exact state copy, held-response races, duplicate intent, trace hops, provider non-propagation, privacy canaries, selected-image failures, and form/file persistence.

## TDD Evidence

### RED

- `9f01e2f4` added 32 state/race executions across Chromium and WebKit: 14 existing cases passed and 18 failed only on missing explicit Retry/trace, exact timeout, offline/provider/partial rendering, and stale lifecycle invalidation.
- `f1f1acc4` added diagnostic/privacy/preservation coverage and demonstrated the intended missing `traceparent`, disclosure/copy/fallback controls, and degraded-state preservation mappings while the existing happy path continued to run.

### GREEN

- `5a8004e7` implemented the finite request state machine, diagnostic allowlist, localized controls/copy, responsive styling, exact release test command, and legacy-envelope compatibility. The complete 64-test browser release suite passes in both engines.

## Acceptance Evidence

| Criterion | Result |
|---|---|
| Exact finite states and recovery | PASS — cancel, offline, exact 15,000ms timeout, not found, companion unavailable, provider error, partial image, and success use the approved copy/classes and clear `aria-busy` |
| Duplicate/retry request counts | PASS — ten repeated taps/scans coalesce to one request; one explicit Retry creates exactly one new request and trace; online/pageshow create zero requests |
| Stale-response safety | PASS — held A is inert after edit, different scan/B, Cancel, pagehide/Back, and visibility hidden; late resolution cannot change DOM or controls |
| Trace correlation | PASS — every browser enrichment XHR has a valid `traceparent`; browser→Grocy and modeled Grocy→companion trace IDs match; provider capture contains neither `traceparent` nor `tracestate` |
| Real server/provider boundary | PASS — all 84 Grocy PHP checks and all 25 companion correlation/provider tests pass |
| Closed diagnostics/privacy | PASS — copied/fallback JSON has only schema/time/version/trace/outcome/online/stage/duration/deadline fields and eleven distinct forbidden canaries remain absent from DOM, clipboard/fallback, and console |
| Form/file/Save preservation | PASS — cancel, offline, timeout, companion/provider errors, partial image, and image-download failure retain byte/name-identical file plus ordinary values and enabled Save controls |
| Zero write | PASS — browser counters remain zero for product/barcode/stock/file/save operations and the enrichment source contains no persistence API call or write endpoint |
| Full browser release | PASS — 64/64 Playwright cases pass in Chromium mobile and WebKit mobile |

## Task Commits

Each task was committed atomically:

1. **Task 1: Specify finite states, request races, and explicit recovery** — `9f01e2f4` (test)
2. **Task 2: Specify diagnostic allowlist and zero-write preservation** — `f1f1acc4` (test)
3. **Task 3: Implement the complete mobile state and diagnostics interaction** — `5a8004e7` (feat)

## Files Created/Modified

- `custom/grocy_AI/tests/browser/specs/states.spec.js` — Exact finite state copy, virtual 15-second deadline, keyboard behavior, lifecycle no-retry, and terminal UI assertions.
- `custom/grocy_AI/tests/browser/specs/concurrency.spec.js` — Ten-action coalescing, explicit retry/new trace, held A/B, edit, cancel, pagehide, and visibility stale-response tests.
- `custom/grocy_AI/tests/browser/specs/diagnostics.spec.js` — Three-hop trace recorder, provider-header absence, closed clipboard JSON, fallback selection, and privacy-canary tests.
- `custom/grocy_AI/tests/browser/specs/preservation.spec.js` — Byte-identical selected-file, manual field, Save-control, and zero-write assertions across degraded and image failure paths.
- `public/custom/grocy_AI/product-enrichment.js` — Finite request renderer, active-intent ownership, trace generation/header, explicit recovery, lifecycle invalidation, safe diagnostic serializer, and preserved preview/image actions.
- `public/custom/grocy_AI/grocy-ai.css` — Bootstrap-compatible status/diagnostic spacing, touch targets, night-mode boundary, wrapping, and reduced-motion support.
- `views/productform.blade.php` — Localized finite-state strings, Retry action, collapsed diagnostic summary, copy feedback, and fallback markup.
- `custom/grocy_AI/tests/browser/package.json` — Added the plan-required `test:release` alias without changing dependencies.
- `.gitignore` — Ignores macOS `.DS_Store` metadata generated in the repository during browser execution.

## Decisions Made

- Kept correlation and stale protection separate: trace IDs identify a request across owned services, while only sequence/object/GTIN equality grants DOM ownership.
- Preserved backward compatibility with the already-green Wave 3 envelope by deriving legacy success/not-found only when `outcome` is absent; finite Wave 5 outcomes remain authoritative when present.
- Classified HTTP 503 as companion unavailable and HTTP 502/provider-stage failures as provider error so recovery copy remains specific without exposing raw server text.
- Made the compact diagnostic summary and copy action visible while report details remain collapsed; clipboard failure alone reveals the same redacted JSON in a selected read-only textarea.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Preserved Wave 3 envelopes and distinct HTTP failure classification**
- **Found during:** Task 3 GREEN browser run
- **Issue:** The first implementation required the new `outcome` field and treated all provider-error HTTP statuses alike, which regressed the existing happy-path envelope and collapsed a 503 companion failure into provider-error copy.
- **Fix:** Derived success/not-found only for legacy envelopes without `outcome`, kept finite outcomes authoritative, and mapped HTTP 503 to companion unavailable while retaining 502/provider failures as provider error.
- **Files modified:** `public/custom/grocy_AI/product-enrichment.js`
- **Verification:** The six-case cross-engine legacy/deadline/503 check and the full 64-case browser release suite pass.
- **Committed in:** `5a8004e7`

**2. [Rule 1 - Bug] Removed virtual-clock action drift from the exact deadline assertion**
- **Found during:** Task 3 GREEN browser run
- **Issue:** Installing a running clock before page navigation allowed interaction time to count toward the 14,999ms assertion.
- **Fix:** Paused the installed clock at its deterministic epoch before navigation, then advanced exactly 14,999ms and one final millisecond.
- **Files modified:** `custom/grocy_AI/tests/browser/specs/states.spec.js`
- **Verification:** The exact deadline passes in Chromium and WebKit and remains green in the full release run.
- **Committed in:** `5a8004e7`

**3. [Rule 3 - Blocking] Added the missing plan-level browser release script**
- **Found during:** Overall verification
- **Issue:** PLAN.md required `npm ... run test:release`, but the nested browser package had no such script.
- **Fix:** Added `test:release` as a direct alias to the existing full `playwright test` matrix; no package or lockfile changed.
- **Files modified:** `custom/grocy_AI/tests/browser/package.json`
- **Verification:** The exact plan command runs 64 tests across both configured engines and exits 0.
- **Committed in:** `5a8004e7`

**4. [Rule 3 - Blocking] Prevented generated macOS metadata from polluting execution state**
- **Found during:** Task 3 pre-commit status check
- **Issue:** An untracked root `.DS_Store` appeared during execution and violated the executor's clean generated-output requirement.
- **Fix:** Added the standard root ignore entry without staging or reading the metadata file.
- **Files modified:** `.gitignore`
- **Verification:** Post-commit `git status --short` is clean.
- **Committed in:** `5a8004e7`

---

**Total deviations:** 4 auto-fixed (2 Rule 1 bugs, 2 Rule 3 blockers)
**Impact on plan:** All changes were required to preserve the existing contract, make the specified deadline deterministic, execute the exact verification command, or keep generated output out of version control. No persistence, retry, provider, schema, or architectural scope was added.

## Issues Encountered

- The sandbox initially denied binding the loopback Playwright fixture server (`EPERM` on `127.0.0.1:4173`). The same local-only test commands were rerun with approved fixture-server permission.
- Context7 MCP and its `ctx7` CLI fallback were unavailable. Playwright Clock, offline-context, and route semantics were checked against the official Playwright documentation before the tests were written.

## Authentication Gates

None.

## TDD Gate Compliance

- **RED:** PASS — `9f01e2f4` and `f1f1acc4` precede production implementation and failed only on the intended state/race/diagnostic/preservation gaps.
- **GREEN:** PASS — `5a8004e7` follows both test commits and makes the focused and full two-engine suites green.
- **REFACTOR:** Included in GREEN because the request lifecycle replacement was the minimal implementation needed to satisfy the locked behavior; no separate behavior-neutral commit was needed.

## Known Stubs

None. Empty strings, arrays, nullable diagnostic fields, and the GTIN input placeholder in changed files are intentional finite defaults or user-input affordances, not unwired UI/data sources.

## Threat Model Verification

- **T-01-06-01:** Mitigated by invalidate-before-abort plus object/sequence/normalized-GTIN checks in every enrichment XHR callback and held-response evidence for all specified invalidations.
- **T-01-06-02:** Mitigated by same-intent coalescing, one explicit Retry/new trace, exact browser deadline, and zero request creation on reconnect/pageshow.
- **T-01-06-03:** Mitigated by closed enum/version/stage reconstruction and distinct canaries absent from DOM, clipboard/fallback, console, and report output.
- **T-01-06-04:** Mitigated by finite status mapping and fixed localized copy; raw response text, exceptions, stacks, and arbitrary fields are never rendered or copied.
- **T-01-06-05:** Mitigated by preserving ordinary fields/file/Save controls and proving zero product/barcode/stock/file/save calls across all degraded paths.

No unplanned endpoint, authentication path, database/file access pattern, schema change, external request, automatic retry, or new trust boundary was introduced.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 01-07 can add responsive/accessibility and release-evidence layers on top of a deterministic finite mobile state machine.
- Plan 01-08 can adapt the portable view/JavaScript/CSS behavior to stable while preserving the same trace, privacy, and zero-write contracts.
- No implementation blocker remains; the known physical-phone/LAN measurement concern remains intentionally assigned to the later release-evidence plan.

## Self-Check: PASSED

- All nine unique implementation/test/tracking-support files plus this summary exist at their documented paths.
- Task commits `9f01e2f4`, `f1f1acc4`, and `5a8004e7` exist on `atech-main` in RED/RED/GREEN order and contain no tracked-file deletions.
- The post-implementation 64-browser/84-PHP/25-companion release gate, JavaScript syntax check, privacy/zero-write source gate, and `git diff --check` all pass.
- Stub and threat-surface scans found no goal-blocking placeholder or unplanned security surface.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
