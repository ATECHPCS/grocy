---
phase: 01-safety-baseline-mobile-diagnostics
plan: "09"
subsystem: release-engineering
tags: [stable-adapter, immutable-image, docker, authenticated-smoke, zero-write]

requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    plan: "08"
    provides: immutable seven-file portable stable baseline at a full commit SHA
provides:
  - Stable framework adapters with a separate cache-invalidation commit
  - Immutable Grocy deployment pinned to the adapter revision and image ID
  - Redacted authenticated, unauthorized, continuity, degraded-state, and zero-write prerequisites for physical acceptance
affects: [01-10-physical-phone-acceptance, stable-release-deployment]

tech-stack:
  added: []
  patterns: [portable-then-adapter commits, OCI revision-to-image provenance, aggregate zero-write fingerprints, live-smoke-plus-deterministic-degradation evidence]

key-files:
  created:
    - .planning/phases/01-safety-baseline-mobile-diagnostics/01-09-SUMMARY.md
  modified:
    - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/src/GrocyAiApiController.php
    - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/routes.php
    - /Users/ian/Documents/Repos/grocy-atech-release/views/productform.blade.php
    - /Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/version.json
    - /Users/ian/Documents/Repos/grocy-atech-release/CUSTOMIZATIONS.md
    - .planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md

key-decisions:
  - "Preserve stable's controller, middleware, route, view, and normal-Save seams while adapting only the five documented stable paths."
  - "Use live authenticated route and asset smoke for the deployed boundary, but use deterministic release fixtures for forced degraded states because changing the household companion was not safe."
  - "Record only aggregate counts, hashes, HTTP statuses, finite outcome names, and component versions; leave all phone and normal-Save evidence to Plan 01-10."

patterns-established:
  - "Deployment provenance: a full stable revision label and content-addressed image ID must agree with the running container."
  - "Zero-write live smoke: compare aggregate row counts and canonical table/file-tree hashes before and after all enrichment reads."

requirements-completed: [MOB-02, MOB-05, MOB-07, MOB-08]
portable_stable_sha: 217a7a0e98889cf4953d3fb7bdc2bf038be4ce7f
stable_adapter_sha: 770ba4f11b362061fbd7bf5c66549840235f1152
stable_image_digest: sha256:d1c133275fe5d458ff5ecc83d25b0435f52e0236d8a810feec8352e972686957

duration: 43min
completed: 2026-08-13
---

# Phase 01 Plan 09: Stable Adapter and Immutable Deployment Summary

**Stable-specific Grocy adapters now run from a content-addressed image with authenticated read-only enrichment, preserved household data, current route/view assets, and redacted zero-write evidence.**

## Performance

- **Duration:** 43 min
- **Started:** 2026-08-13T02:22:00Z
- **Completed:** 2026-08-13T03:05:05Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments

- Created stable adapter commit `770ba4f11b362061fbd7bf5c66549840235f1152` as the direct child of portable commit `217a7a0e98889cf4953d3fb7bdc2bf038be4ce7f`, touching exactly the five documented stable paths.
- Built and deployed immutable image `sha256:d1c133275fe5d458ff5ecc83d25b0435f52e0236d8a810feec8352e972686957` with the exact adapter revision label, while preserving the read-write `/etc/komodo/grocy:/config` mount.
- Reconfirmed continuity after restart with 220 products and 979 product-picture files; the database remained present and the rollback Compose backup plus prior image were preserved.
- Exercised authenticated status, enrichment, selected-image, and product-form routes successfully; all three unauthenticated API routes returned HTTP 401.
- Proved zero enrichment writes through identical before/after aggregate counts and SHA-256 fingerprints for products, product barcodes, stock, stock log, and the product-picture tree.

## Deployment and Verification Evidence

| Gate | Result |
|---|---|
| Stable ancestry/scope | PASS — adapter is the direct portable child and contains exactly five plan-owned paths |
| SHA-pinned portable parity | PASS — 7 identical, 0 mismatched, 0 missing |
| Stable native contract | PASS — all 84 checks passed |
| Browser smoke | PASS — 4 tests passed across Chromium-mobile and WebKit-mobile |
| Full deterministic browser release suite | PASS — all 78 tests passed |
| Running provenance | PASS — running image ID and revision label equal the recorded immutable deployment |
| Cache invalidation | PASS — marker changed from `ATECHPCS-grocy_AI-2` to `ATECHPCS-grocy_AI-3`; route, controller, view, version, JavaScript, and CSS hashes match deployed source |
| Persistent continuity | PASS — read-write mount, database, 220 products, and 979 product-picture files remain present |
| Authenticated live smoke | PASS — status, enrichment, selected-image, and product form returned HTTP 200; JavaScript and CSS returned HTTP 200 |
| Unauthorized live smoke | PASS — status, enrichment, and selected-image returned HTTP 401 |
| Degraded behavior | PASS — deployed boundaries were live-smoked; finite timeout, companion-unavailable, provider-error, and partial-image behavior passed deterministic fixtures without changing household provider configuration |
| Zero enrichment writes | PASS — protected table and product-picture counts/hashes were identical before and after live reads |
| Cleanup/rollback | PASS — temporary build directory removed; pre-deployment Compose backup and prior image retained |

## Task Commits

Each task was committed atomically in its owning checkout:

1. **Task 1: Adapt stable framework seams and create the separate adapter commit** — `770ba4f11b362061fbd7bf5c66549840235f1152` (feat, stable `atech-release` worktree)
2. **Task 2: Deploy the immutable adapter commit and record stable prerequisites** — `ce9af3835757b9e3b9ca03f3d640efc451044f41` (docs, planning `atech-main` checkout)

## Files Created/Modified

- `/Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/src/GrocyAiApiController.php` — Stable permission-first controller mapping finite diagnostic and image outcomes.
- `/Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/routes.php` — Stable class-based JSON middleware and authenticated GET-only route registration.
- `/Users/ian/Documents/Repos/grocy-atech-release/views/productform.blade.php` — Stable product-form integration retaining the normal Save lifecycle.
- `/Users/ian/Documents/Repos/grocy-atech-release/custom/grocy_AI/version.json` — `ATECHPCS-grocy_AI-3` route/view cache marker.
- `/Users/ian/Documents/Repos/grocy-atech-release/CUSTOMIZATIONS.md` — Exact portable-versus-adapter boundary and stable integration record.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md` — Redacted deployment, continuity, route, degraded-state, and zero-write prerequisites.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-09-SUMMARY.md` — Plan execution and immutable deployment record.

## Decisions Made

- Retained stable's existing `BaseApiController`, class-based `JsonMiddleware`, root conditional hook, product-form conventions, and normal Save handlers. The portable contract crossed only the five documented adapter seams.
- Did not alter the live companion or provider configuration to manufacture failures. The deployed boundary received authenticated live smoke, while the 84 native and 78 browser tests supplied deterministic finite timeout, unavailable, provider-error, and partial-image evidence.
- Used only an existing host-configured API credential in process memory. Its value and all product/GTIN values, request/response bodies, cookies, headers, URLs, and image handles were suppressed from output and evidence.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - State serialization] Corrected the progress percentage written by the state SDK**
- **Found during:** Plan close-out
- **Issue:** `state.update-progress` reported 90% but serialized `progress.percent: 0` in the STATE.md frontmatter while the body correctly showed 90%.
- **Fix:** Corrected the frontmatter value to match 9 of 10 completed plans and the SDK's reported result.
- **Files modified:** `.planning/STATE.md`
- **Verification:** Frontmatter, body progress bar, roadmap plan count, and on-disk summary count all agree at 90% / 9 of 10.
- **Committed in:** Plan metadata commit.

---

**Total deviations:** 1 auto-fixed (1 state serialization bug).
**Impact on plan:** Metadata consistency only; deployment behavior and verification scope were unchanged. The user-authorized continuation explicitly allowed deterministic release-suite evidence when live provider shaping could not be performed safely.

## Issues Encountered

- The first local browser-smoke attempt could not bind the loopback fixture server inside the filesystem sandbox. The same approved suite was rerun outside that restriction and passed 4/4; the full suite then passed 78/78.
- A first redacted response validator assumed host PHP existed on PersonalDocker. Validation was moved into the deployed container runtime; that interrupted pass had made only the authenticated status read and no enrichment request or mutation.

## Authentication Gates

None. The approved, already-configured host authentication mechanism was available and was used without printing or persisting its value.

## Known Stubs

None. The existing GTIN input placeholder is user guidance, not an implementation stub; the companion version's closed `unknown` status value is truthful deployed diagnostic output.

## Threat Model Verification

- **T-01-09-01:** Exact ancestry, five-path commit scope, cache bump, lints/contracts, and SHA-pinned parity passed.
- **T-01-09-02:** The running container image ID and OCI revision label match the immutable adapter build.
- **T-01-09-03:** The exact read-write persistent mount, database, 220 products, and 979 product-picture files survived restart.
- **T-01-09-04:** Authenticated reads succeeded and the same three API boundaries denied unauthenticated access with HTTP 401.
- **T-01-09-05:** Evidence contains only approved aggregate/provenance fields and closed outcomes; no household or authentication values were recorded.
- **T-01-09-06:** GET-only route inspection, native/browser contracts, and unchanged table/file fingerprints establish zero enrichment writes.

No unplanned network endpoint, authentication path, schema change, file-access pattern, or new trust boundary was introduced beyond the plan threat model.

## User Setup Required

None - the existing deployment and authenticated smoke mechanism were already configured.

## Next Phase Readiness

- Plan 01-10 can use the recorded adapter SHA, image ID, deployment timestamp, cache marker, continuity results, and stable-smoke prerequisites without inferring any moving ref.
- Physical phone timing samples, device/browser metadata, interaction checklist evidence, and explicit normal-Save/reload evidence remain intentionally pending.
- Rollback remains available from the preserved pre-deployment Compose backup and prior image.

## Self-Check: PASSED

- The summary and acceptance files exist.
- Stable task commit `770ba4f11b362061fbd7bf5c66549840235f1152` and planning task commit `ce9af3835757b9e3b9ca03f3d640efc451044f41` exist in their owning repositories.
- Exact adapter ancestry, parity, native contract, browser smoke, full deterministic browser suite, deployed provenance, continuity, route/asset smoke, and zero-write fingerprints all passed.

---
*Phase: 01-safety-baseline-mobile-diagnostics*
*Completed: 2026-08-13*
