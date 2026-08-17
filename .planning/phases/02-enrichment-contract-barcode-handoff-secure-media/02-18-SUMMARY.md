---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "18"
subsystem: release-candidate-portability
tags: [git, portable-parity, gtin, stable-release]

requires:
  - phase: 02-17
    provides: immutable fixed main candidate and cache token 2.4.1
provides:
  - immutable 12-path stable portable candidate
  - pinned stable portable SHA and direct parent
  - adapter-independent portable verification boundary
affects: [02-19-stable-adapter, 02-20-release-evidence]

tech-stack:
  added: []
  patterns: [immutable-blob-materialization, exact-path-parity, deferred-adapter-integration]

key-files:
  created:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-18-SUMMARY.md
  modified:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-18-PLAN.md

key-decisions:
  - "Keep c222fb760b2dcd9da843c49a03fc7c6f6d6c97c8 immutable as the complete portable candidate."
  - "Defer stable Blade and migration integration replay until Plan 02-19 supplies its exact adapter child."

patterns-established:
  - "Portable verification proves immutable blob parity and portable GTIN behavior without relying on stable adapter paths."
  - "Cross-scope integration tests run only in a disposable worktree after their required stable adapters exist."

requirements-completed: [ENR-01, ENR-06, ENR-07]

metrics:
  duration: resumed checkpoint
  completed: 2026-08-17
  tasks_completed: 2
  files_modified: 14
---

# Phase 02 Plan 18: Stable Portable Candidate Summary

**The stable release now has an immutable 12-path portable mirror of the fixed main candidate, while framework-specific integration remains isolated for the direct adapter child.**

## Performance

- **Duration:** resumed from the 2026-08-14 checkpoint
- **Completed:** 2026-08-17T04:20:39Z
- **Tasks:** 2
- **Files modified:** 12 stable portable paths, plus this close-out record

## Accomplishments

- Materialized stable commit `c222fb760b2dcd9da843c49a03fc7c6f6d6c97c8` directly from immutable main candidate `e7f8036de05e606745b4b3a92ff6ee8694cb76ce`.
- Confirmed its parent is `9f9ce169e155c9ec1fa01a67745c94276d86b2da` and its diff-tree is exactly the approved 12 portable paths.
- Passed 12/12 SHA-pinned portable byte parity and the stable shared-GTIN portable case.
- Preserved all eight stable adapter paths, manifest provenance, and deployment evidence for Plans 02-19 and 02-20.

## Task Commits

1. **Task 1: Materialize the 12-path stable portable candidate from fixed main blobs** — `c222fb76` (stable, `feat`)
2. **Task 2: Record the portable candidate without advancing adapter or manifest evidence** — documented in the plan metadata commit below

**Plan metadata:** recorded with this summary close-out commit

## Candidate Identities

- **Main portable source:** `e7f8036de05e606745b4b3a92ff6ee8694cb76ce`
- **Stable portable candidate:** `c222fb760b2dcd9da843c49a03fc7c6f6d6c97c8`
- **Stable portable parent:** `9f9ce169e155c9ec1fa01a67745c94276d86b2da`

## Verification

- Exact sorted diff-tree scope: 12/12 approved portable paths — PASS.
- `custom/grocy_AI/tests/check-portable-parity.sh --stable-sha c222fb760b2dcd9da843c49a03fc7c6f6d6c97c8` — 12/12 PASS.
- Stable `barcode-handoff.php --case gtin.shared_predicate` — PASS.
- Main candidate `run.php` — 113/113 PASS; main candidate full barcode suite — 84/84 PASS.

## Decisions Made

- The 19 stable `run.php` Blade/cache failures are expected before the exact eight Plan 02-19 adapter paths exist; they do not indicate a portable-byte mismatch.
- The full stable barcode suite also requires Plan 02-19's `migrations/0256.php` and the committed main preflight. It will be replayed in a disposable worktree after the adapter child exists, using the preflight blob from the immutable main candidate.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking verification mismatch] Split portable and adapter integration verification**
- **Found during:** Task 1 verification
- **Issue:** The original stable full-suite commands required Blade/cache/migration paths explicitly excluded from the 12-path portable commit; the barcode harness also resolved a main-only preflight relative to the stable worktree.
- **Fix:** Retained immutable portable parity plus the portable GTIN predicate as Plan 02-18 verification, and scheduled the two integration replays after Plan 02-19's exact adapter child.
- **Files modified:** `02-18-PLAN.md`, `02-18-SUMMARY.md`
- **Verification:** Main baseline passed 113/113 and 84/84; stable parity passed 12/12; stable shared-GTIN case passed.
- **Committed in:** Plan metadata commit.

---

**Total deviations:** 1 auto-fixed (1 blocking verification correction).
**Impact on plan:** No production or portable candidate bytes changed; the correction makes scope and verification order explicit.

## Issues Encountered

The original stable full barcode invocation fatally read a missing stable `.planning` preflight before Plan 02-19 supplied the migration. This is recorded as an integration-order issue, not passed evidence.

## User Setup Required

None.

## Next Phase Readiness

Plan 02-19 can now create an exact eight-path direct child of `c222fb76…`. Afterward, replay the deferred stable Blade and barcode integration checks in a disposable worktree without changing the portable candidate.

## Self-Check: PASSED

- The stable candidate and direct parent resolve as the recorded full SHA values.
- Stable worktree was clean when the candidate was verified.
- Portable parity and shared-GTIN checks passed.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-17*
