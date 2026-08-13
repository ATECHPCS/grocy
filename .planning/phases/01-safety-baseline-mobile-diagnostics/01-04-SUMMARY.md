---
phase: 01-safety-baseline-mobile-diagnostics
plan: "04"
subsystem: api
tags: [python, asyncio, httpx, gtin, trace-context, diagnostics, privacy, provider-timeouts]

# Dependency graph
requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "03"
    provides: Shared GTIN-8/12/13/14 GS1 vectors and bounded phone enrichment request contract
provides:
  - Trusted companion-boundary GS1 checksum validation before provider orchestration
  - Strict owned-boundary W3C v00 trace handling that terminates before external providers
  - Closed versioned diagnostic stages, outcomes, caches, error codes, and clamped timings
  - 10.5-second provider-work deadline with 2-second connect and 6-second read limits
  - Metadata-preserving partial-image behavior and true not-found/error/timeout separation
affects: [01-05, 01-06, mobile-diagnostics, companion-api, provider-observability]

# Tech tracking
tech-stack:
  added: []
  patterns: [frozen provider result values, allowlist diagnostic construction, layered remaining-time budgets, trusted-boundary validation, trace termination]

key-files:
  created:
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/diagnostics.py
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_diagnostics.py
  modified:
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/lookup.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/images.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py

key-decisions:
  - "Validate string-preserving GS1 GTINs at the Starlette endpoint and again at orchestration entry so invalid input cannot reach any provider."
  - "Propagate strict W3C trace context only across owned Grocy-to-companion boundaries; external provider clients receive neither traceparent nor tracestate."
  - "Build diagnostics exclusively from closed constructors and enforce one 10.5-second wall-clock provider budget with 2-second connect and 6-second read limits."

patterns-established:
  - "Finite provider result: provider helpers pair their value with an allowlisted stage whose name, status, error code, cache state, and duration are closed values."
  - "Partial is usable: successful metadata remains available with outcome partial_image when optional image work fails or lacks remaining budget."

requirements-completed: [MOB-01, MOB-02, MOB-05, MOB-07]

# Metrics
duration: 11 min
completed: 2026-08-13
---

# Phase 01 Plan 04: Bounded Companion Diagnostics Summary

**GS1-validating companion orchestration with finite privacy-safe provider stages, strict owned trace handling, and layered 10.5s/2s/6s deadlines**

## Performance

- **Duration:** 11 min
- **Started:** 2026-08-13T00:44:23Z
- **Completed:** 2026-08-13T00:55:46Z
- **Tasks:** 2
- **Files modified:** 8

## Accomplishments

- Mirrored the browser's valid and invalid GTIN-8/12/13/14 GS1 vectors, including leading-zero input, and proved invalid checksums return a fixed 400 before the orchestration mock is called.
- Added strict W3C v00 trace parsing/replacement with non-zero trace and fresh parent IDs while deliberately omitting `tracestate` and terminating owned trace headers at the external-provider boundary.
- Introduced closed diagnostic constructors for four stage names, seven statuses, four cache values, five outcomes, bounded error codes, schema version 1, contract version `1`, companion version, and clamped non-negative timings.
- Preserved concurrent federation/Open Food Facts lookup while distinguishing `not_found`, `timeout`, `unavailable`, `error`, and `malformed` instead of collapsing failures into empty results.
- Enforced a 10.5-second provider-work deadline, 2-second connect and at-most-6-second read limits, remaining-budget image skipping, and no automatic whole-request retry.
- Kept usable metadata on image failure through `partial_image` and retained generic unexpected HTTP 502 plus existing API-key middleware behavior.

## TDD Evidence

### RED

The targeted 24-test command failed after the contract commit only on the intended missing behavior: absent `grocy_mcp.diagnostics`, checksum-only acceptance, missing trace handoff, missing monotonic/deadline seams, unbounded scalar provider timeouts, and absent finite outcomes. The pre-existing 13 focused tests passed before the RED additions, proving the environment and imports were healthy.

### GREEN

The targeted suite now passes 25 tests, the complete companion discovery suite passes 31 tests, and `python -m compileall -q grocy_mcp tests` succeeds.

## Acceptance Evidence

| Criterion | Result |
|---|---|
| Trusted GTIN boundary | PASS — invalid length/checksum vectors return fixed `Invalid GTIN` 400 responses and invoke zero enrichment/provider orchestration mocks |
| Strict trace contract | PASS — valid non-zero v00 trace IDs are retained, invalid/missing/all-zero values are replaced, and every request receives a fresh owned parent ID |
| Provider privacy boundary | PASS — federation, Open Food Facts, SearXNG, and selected-image HTTPX spies receive neither `traceparent` nor `tracestate` |
| Closed diagnostics | PASS — unknown constructor/stage fields are rejected; response diagnostics contain only approved keys and enum values |
| Privacy canaries | PASS — GTIN/key/cookie/bearer/header/URL/query/token/payload/exception canaries are absent from serialized diagnostics |
| Deadline layering | PASS — outer provider work is bounded at 10500ms, connect at 2000ms, read at 6000ms, and stage durations at 10000ms |
| Concurrent timing | PASS — overall duration is measured from injected wall clock and remains lower than the sum of concurrent provider stages |
| Outcome separation | PASS — true not-found requires completed no-match metadata stages; provider timeout remains timeout; image failure with metadata becomes partial_image |
| No retry/persistence expansion | PASS — metadata providers are called once concurrently, enrichment disables retail retry, and no dependency, database, or Grocy write authority was added |
| Repository boundary | PASS — companion commits contain only grocy-mcp files on `main`; Grocy stayed clean on `atech-main` during both task commits |

## Task Commits

Each task was committed atomically in `/Users/ian/Documents/Repos/grocy-mcp`:

1. **Task 1: Specify companion trace, deadline, outcome, and privacy contracts** — `546fe3a` (test)
2. **Task 2: Implement bounded companion stages and finite envelopes** — `521c9b0` (feat)

## Files Created/Modified

- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/diagnostics.py` — String-preserving GS1 validation, strict trace context, frozen provider result, closed stage/diagnostic constructors, and timing clamps.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py` — Outer/remaining deadline orchestration, finite aggregation, partial-image preservation, and versioned diagnostic envelope.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/lookup.py` — Concurrent typed federation/Open Food Facts results with bounded HTTPX timeouts and failure classification.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/images.py` — Typed SearXNG stage results and bounded search/fetch clients without owned trace forwarding.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py` — Trusted endpoint GTIN validation and strict owned trace acceptance before enrichment.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_diagnostics.py` — Closed enum, trace, timing, unknown-field, checksum, and privacy-canary coverage.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py` — Partial/not-found/timeout, concurrent wall-clock, remaining-budget, provider timeout, call-count, and header-spy coverage.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py` — Safe invalid-input 400, zero orchestration calls, trace replacement, API-key preservation, and generic 502 coverage.

## Decisions Made

- Retained the legacy enrichment product/image fields for the deployed Grocy adapter while adding `outcome` and the closed `diagnostics` object, avoiding a breaking response rewrite.
- Kept provider trace correlation local: stages are timed under the owned trace ID, but household correlation headers do not leave the companion.
- Used the existing `grocy_mcp.__version__` as the companion version and added no dependency, persistence mechanism, retry layer, or new external service.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- The Context7 CLI fallback was unavailable, so no new documentation lookup could run; the phase research's already-cited official Python and HTTPX documentation supplied the version-specific timeout contract.
- `unittest discover -s tests -t .` rejected the non-package test directory; the repository-compatible `unittest discover -s tests` command ran all 31 tests successfully.

## Authentication Gates

None.

## TDD Gate Compliance

- **RED:** PASS — `546fe3a` precedes implementation and fails on the intended missing checksum, trace, diagnostic, outcome, and budget behavior.
- **GREEN:** PASS — `521c9b0` adds the minimum companion contract and makes targeted/full suites green.
- **REFACTOR:** Not needed; all implementation stayed within the existing companion provider/orchestration/HTTP boundaries.

## Known Stubs

None. Empty collections, `None` defaults, and the guarded optional diagnostic import in changed files are intentional runtime/test initialization rather than unwired behavior.

## Threat Model Verification

- **T-01-04-01:** Mitigated by field-by-field closed diagnostic construction, enum validation, and forbidden-canary serialization tests.
- **T-01-04-02:** Mitigated by checksum validation at endpoint and orchestration boundaries plus strict non-zero trace parsing/replacement.
- **T-01-04-03:** Mitigated by provider header spies covering metadata, SearXNG, and selected-image HTTP clients.
- **T-01-04-04:** Mitigated by one 10.5-second provider-work budget, 2-second connect/6-second read limits, remaining-budget checks, and single-call assertions.
- **T-01-04-05:** Mitigated by finite stage/outcome values, injected monotonic wall-clock timing, and explicit not-found/timeout/error separation.

No unplanned endpoint, authentication mechanism, schema, persistence path, or external trust boundary was introduced.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Ready for Plan 01-05 to consume the companion's versioned finite envelope at Grocy's authenticated 12-second/2-second boundary.
- Plan 01-06 can use the stable trace/outcome/stage contract for browser state mapping and redacted diagnostic copy.
- The existing Starlette `TestClient` deprecation warning remains non-blocking and does not require a dependency change in this plan.

## Self-Check: PASSED

- All eight created/modified companion files exist at their documented paths.
- Companion task commits `546fe3a` and `521c9b0` exist on `main` with no tracked-file deletions or cross-repository content.
- Targeted 25-test, full 31-test, compileall, diff, branch, repository-boundary, closed-enum, privacy-canary, and timeout/header-spy claims were re-run and verified.
- Stub and threat-surface scans found no goal-blocking stub or unplanned trust boundary.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
