---
phase: 01-safety-baseline-mobile-diagnostics
plan: "05"
subsystem: api
tags: [php, guzzle, gtin, trace-context, diagnostics, server-timing, privacy]

# Dependency graph
requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "04"
    provides: Versioned companion outcomes, provider stages, strict trace handling, and bounded provider timings
provides:
  - Trusted PHP GS1 checksum boundary before any companion request
  - Strict owned W3C v00 trace validation and fresh-parent propagation
  - Exact 12-second total and 2-second connect Guzzle limits with safe transfer timing extraction
  - Versioned closed Grocy diagnostic DTO and bounded allowlisted Server-Timing
  - Permission-first finite controller envelopes without raw exception disclosure
affects: [01-06, mobile-diagnostics, browser-state-machine, stable-branch-parity]

# Tech tracking
tech-stack:
  added: []
  patterns: [closed diagnostic DTO, owned trace boundary, fixed outer transport budget, field-by-field normalization, finite error envelopes]

key-files:
  created:
    - custom/grocy_AI/module-version.json
    - custom/grocy_AI/src/GrocyAiDiagnostic.php
  modified:
    - custom/grocy_AI/src/GrocyAiService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/README.md

key-decisions:
  - "Hard-cap every companion request at 12 seconds total and 2 seconds connect, independent of the larger legacy timeout setting."
  - "Accept trace context only as strict non-zero W3C v00 values, create a fresh owned parent, ignore tracestate, and forward no owned correlation beyond the companion."
  - "Rebuild browser diagnostics and Server-Timing from closed enums, version manifests, and bounded or nullable durations rather than copying arbitrary companion or exception data."

patterns-established:
  - "Owned trace boundary: browser input is validated/replaced by Grocy, one rebuilt traceparent reaches the companion, and tracestate is never propagated."
  - "Finite failure response: controller exception categories map to fixed outcome/stage/error/status values and never serialize exception messages."

requirements-completed: [MOB-01, MOB-02, MOB-05, MOB-06, MOB-07]

# Metrics
duration: 10 min
completed: 2026-08-13
---

# Phase 01 Plan 05: Grocy Diagnostic Boundary Summary

**GS1-validating PHP transport with strict owned trace propagation, exact 12s/2s Guzzle budgets, and versioned privacy-safe diagnostics plus bounded Server-Timing**

## Performance

- **Duration:** 10 min
- **Started:** 2026-08-13T01:01:27Z
- **Completed:** 2026-08-13T01:12:25Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments

- Mirrored the companion/browser GTIN-8/12/13/14 GS1 vectors in PHP and rejected invalid checksums before the injected transport can run.
- Added strict W3C v00 parsing that preserves a valid non-zero trace ID, securely replaces missing/invalid/zero input, always creates a fresh parent ID, and has no `tracestate` field.
- Replaced the configurable companion request budget with exact `timeout=12.0` and `connect_timeout=2.0`, retained disabled redirects, and safely extracted total/connect milliseconds through `on_stats` without reading URIs or headers.
- Added module/contract version metadata plus a closed diagnostic DTO covering finite outcomes, stage/status/error/cache enums, trusted trace identity, and clamped or nullable timings.
- Preserved the existing review-only product/image preview payload while excluding GTINs, API keys, URLs, headers, cookies, payloads, image handles, and raw exceptions from diagnostics and status.
- Preserved all three authenticated GET routes and `MASTER_DATA_EDIT` checks before enrichment and selected-image work; no schema, database, file-write, retry, or new persistence path was introduced.

## TDD Evidence

### RED

The native harness remained syntactically valid and exited once with 25 of 61 checks failing only on the intended missing behavior: checksum rejection, zero transport on invalid GTIN, diagnostic helper availability, trace forwarding, exact transport options, timing callback, and finite normalized outcomes/diagnostics.

### GREEN

The completed native harness passes all 84 checks. PHP syntax checks pass for the harness, diagnostic helper, service, controller, and route bootstrap.

## Acceptance Evidence

| Criterion | Result |
|---|---|
| GS1 validation | PASS — shared valid GTIN-8/12/13/14 vectors and separator normalization succeed; four checksum variants fail before zero transport calls |
| Strict trace boundary | PASS — valid trace ID retained with a new parent; missing, malformed, uppercase, future-version, all-zero trace, and all-zero parent input are replaced; no tracestate is emitted |
| Fixed Guzzle budgets | PASS — injected options observe exact `12.0` total, `2.0` connect, redirects false, and a callable `on_stats` |
| Safe timing extraction | PASS — total/connect seconds become rounded milliseconds; negative values clamp to zero, oversized values clamp to the boundary, and infinite/malformed values become null |
| Finite outcomes | PASS — `success`, `partial_image`, `not_found`, `timeout`, and `provider_error` remain closed and consistent between response and diagnostics |
| Versioned DTO | PASS — diagnostics report schema 1 plus Grocy `4.6.0`, module `1.0.0`, companion `0.1.0`, and contract `1` from bounded version sources |
| Privacy | PASS — diagnostics/status exclude all seeded GTIN, API-key, cookie, bearer, header, URL/query, token, payload, exception, and opaque image-handle canaries |
| Safe controller mapping | PASS — permission precedes trace/service work, expected categories return fixed HTTP envelopes, and controller source contains no exception-message exposure |
| Server-Timing | PASS — only six allowlisted metric names are eligible, finite durations are clamped at 12,000ms, and malformed/foreign metrics are omitted |
| Zero write | PASS — the exact negative gate found no SQL mutation keyword, migration reference, database gateway, or insert/update/delete/store call in the diagnostic/service/controller boundary; `schema_version` remains permitted |

## Task Commits

Each task was committed atomically:

1. **Task 1: Add RED PHP contracts for GTIN, trace, budgets, envelopes, and privacy** — `bbbbf35c` (test)
2. **Task 2: Implement the closed Grocy diagnostic and transport boundary** — `b804e6b3` (feat)

## Files Created/Modified

- `custom/grocy_AI/module-version.json` — Portable module `1.0.0` and diagnostic contract `1` source.
- `custom/grocy_AI/src/GrocyAiDiagnostic.php` — Strict trace construction, finite enums, manifest versions, timing clamps, closed DTO normalization, safe failure envelopes, and allowlisted Server-Timing.
- `custom/grocy_AI/src/GrocyAiService.php` — GS1 checksum validation, owned trace forwarding, exact Guzzle budgets, timing capture, typed service failures, and companion diagnostic normalization.
- `custom/grocy_AI/src/GrocyAiApiController.php` — Permission-first trace acceptance, finite success/failure response mapping, bounded Server-Timing, and fixed selected-image error copy.
- `custom/grocy_AI/routes.php` — Explicit diagnostic helper load before service/controller while retaining GET-only routes and existing middleware.
- `custom/grocy_AI/tests/run.php` — 84 standalone checks for checksum, trace, options, timings, outcomes, privacy, preview safety, and image safety without Composer bootstrap.
- `custom/grocy_AI/README.md` — Exact budgets, trace ownership, version source, privacy contract, GET-only behavior, and zero-write authority documentation.

## Decisions Made

- Kept the legacy `GROCY_AI_REQUEST_TIMEOUT_SECONDS` setting documented for compatibility but removed it from companion request authority; the approved 12s/2s baseline is unconditional.
- Used the trusted Grocy trace ID in returned diagnostics instead of accepting the companion's echoed trace ID as authoritative.
- Kept product URLs and short-lived image handles only in the existing preview payload. The diagnostic and status objects have no fields capable of carrying them.
- Made Guzzle handler connect timing nullable because `getHandlerStats()` is handler-dependent; no synthetic timing is invented when the handler omits it.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

- Context7 MCP and the `ctx7` CLI fallback were unavailable. The implementation was checked against the official Guzzle 7 request-options documentation for `timeout`, `connect_timeout`, `allow_redirects`, and `on_stats` before coding.

## Authentication Gates

None.

## TDD Gate Compliance

- **RED:** PASS — `bbbbf35c` precedes implementation and the aggregate harness failed on only the planned boundary gaps.
- **GREEN:** PASS — `b804e6b3` adds the minimum diagnostic/transport/controller implementation and all 84 checks pass.
- **REFACTOR:** Not needed; the implementation remains within the existing module service/controller/route boundaries.

## Known Stubs

None. Empty arrays and null timing defaults in changed files are intentional closed-envelope initialization, malformed-input fallback, or handler-dependent timing absence rather than unwired behavior.

## Threat Model Verification

- **T-01-05-01:** Mitigated by field-by-field DTO construction, finite values, forbidden-canary serialization checks, and removal of controller exception-message paths.
- **T-01-05-02:** Mitigated by PHP GS1 checksum validation before transport and strict non-zero trace parsing/replacement using `random_bytes`.
- **T-01-05-03:** Mitigated by exact 12s/2s options, disabled redirects, no retry, safe `on_stats`, and bounded timing output.
- **T-01-05-04:** Mitigated by preserving authenticated middleware and checking `MASTER_DATA_EDIT` before enrichment/image work.
- **T-01-05-05:** Mitigated by retaining GET-only routes and passing the no-persistence source gate.

No unplanned endpoint, authentication mechanism, database/file access pattern, schema change, or external trust boundary was introduced.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 01-06 can consume the stable schema/version/trace/outcome/stage/timing DTO and `Server-Timing` header for the mobile state machine and copyable report.
- Plan 01-08 can mirror the two portable new files plus the updated service/controller/routes/tests/docs into `atech-release` using its documented framework adaptations.
- No blocker remains for the deterministic browser implementation.

## Self-Check: PASSED

- All seven created/modified plan files exist at their documented paths.
- Task commits `bbbbf35c` and `b804e6b3` exist on `atech-main` in RED-then-GREEN order and contain no tracked-file deletions.
- The 84-check suite, five PHP syntax checks, manifest parse, exact timeout/trace source assertions, controller privacy scan, GET-only route scan, zero-write gate, Server-Timing clamp/allowlist probe, and `git diff --check` all pass.
- Stub and threat-surface scans found no goal-blocking placeholder or unplanned security surface.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
