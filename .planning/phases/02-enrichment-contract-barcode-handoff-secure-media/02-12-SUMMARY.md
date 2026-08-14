---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "12"
subsystem: release-and-deployment-gates
tags: [shell, git, docker, ssh, sha256, playwright, deployment-safety]

requires:
  - phase: 02-11
    provides: immutable main, companion, stable portable, and stable adapter candidates
provides:
  - executable three-repository immutable release gate
  - executable candidate, predeploy, postdeploy, postsmoke, and final live deployment gate
  - exact two-file Phase 1 evidence byte baseline preserving skipped semantics
affects: [02-13-deployment, 02-14-acceptance, phase-2-verification]

tech-stack:
  added: []
  patterns: [closed mode-specific path allowlists, direct system-state evidence, aggregate-only household fingerprints]

key-files:
  created:
    - custom/grocy_AI/tests/release-gate.sh
    - custom/grocy_AI/tests/deployment-gate.sh
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-PHASE1-BASELINE.sha256
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-12-SUMMARY.md
  modified:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-RELEASE-MANIFEST.md

key-decisions:
  - "Release approval compares immutable commits, exact diff-trees, current repository state, portable blobs, markers, and test exit status directly; manifest PASS text is never authoritative."
  - "Deployment evidence stores only immutable image identities and one aggregate fingerprint; the gate independently compares live containers, mount, schema, auth, media, and protected state."
  - "The Phase 1 phone artifact and timing JSONL are byte-baselined exactly as skipped evidence and remain explicitly not accepted."

patterns-established:
  - "Every gate mode has a closed dirty-path allowlist and rejects untracked files individually rather than collapsing directories."
  - "Live checks return only closed assertion names, immutable IDs, and aggregate hashes; household values and credentials remain process-local."

requirements-completed: [ENR-01, ENR-02, ENR-03, ENR-04, ENR-05, ENR-06, ENR-07, ENR-08, ENR-09]

duration: 20 min
completed: 2026-08-14
---

# Phase 02 Plan 12: Release and Deployment Evidence Gates Summary

**Fail-closed release and live deployment gates now derive approval from immutable Git objects, exact path/blob scope, real test exits, running container identity, persistent mount/schema state, authentication boundaries, and one privacy-safe aggregate fingerprint.**

## Performance

- **Duration:** 20 min
- **Started:** 2026-08-14T17:48:57Z
- **Completed:** 2026-08-14T18:08:49Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Added a three-repository release gate with exact branch, full-SHA object, ancestry, HEAD, stable parent, portable/adaptor diff-tree, byte-parity, dependency, marker, test, and mode-specific dirty-scope assertions.
- Added direct negative fixtures proving wrong SHAs, unapproved committed paths, and unrelated dirty paths fail before approval.
- Added a live deployment gate covering baseline immutability, predeploy cleanliness, zero canonical collisions, prior image resolvability, the exact `/etc/komodo/grocy:/config` RW mount, running revisions/image IDs, module/cache markers, migration/index state, authenticated/unauthenticated reads, secure media, and protected aggregate continuity.
- Recorded exactly the two Phase 1 evidence files in a SHA-256 manifest while preserving `SKIPPED — NOT ACCEPTED` and the semantically empty timing corpus.
- Proved candidate, predeploy, and evidence release modes; the read-only live predeploy gate confirmed zero collisions and emitted only immutable prior image IDs plus aggregate fingerprint `0f815d401dbdd948836063d51448aa94d50d66aa6e7a3c6b01b60c062c532cd9`.

## Task Commits

1. **Task 1: Implement direct immutable release assertions** — `3f588c2c`
2. **Task 2: Implement direct deployment comparisons and Phase 1 byte baseline** — `3b7481d8`

## Files Created/Modified

- `custom/grocy_AI/tests/release-gate.sh` — Enforces immutable candidate identity, exact scope/parity, closed mode path sets, synchronized markers, and deterministic suite exits.
- `custom/grocy_AI/tests/deployment-gate.sh` — Enforces Phase 1 baseline bytes and live predeploy/postdeploy/postsmoke/final comparisons without exposing private inputs.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-PHASE1-BASELINE.sha256` — Contains exactly the acceptance artifact and `evidence/phone-timings.jsonl` hashes.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-RELEASE-MANIFEST.md` — Adds the closed post-candidate main path allowlist consumed by the release gate.

## Decisions Made

- Current main may advance beyond the immutable candidate only through the manifest's exact post-candidate allowlist; portable runtime files remain excluded from that later scope.
- Release `candidate`, `predeploy`, and `evidence` modes use separate exact dirty-path sets; deployment candidate creation has its own fixed two-artifact scope plus the release-gate handshake change.
- Live protected-state continuity hashes approved product/barcode/group/quantity/stock tables and the product-picture tree without emitting source rows, filenames, or values.
- Postsmoke authentication uses process-local inputs and records only closed route/media outcomes; evidence prose cannot satisfy a live assertion.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Gate runner bug] Ran companion discovery from the companion checkout**
- **Found during:** Task 1 candidate gate
- **Issue:** Absolute test discovery launched from the Grocy checkout and could not import the companion package.
- **Fix:** Wrapped the companion command in a fixed-directory `sh -c` invocation rooted at `/Users/ian/Documents/Repos/grocy-mcp`.
- **Files modified:** `custom/grocy_AI/tests/release-gate.sh`
- **Verification:** Candidate, predeploy, and evidence modes all report `PASS: companion_unittest`.
- **Committed in:** `3f588c2c`

**2. [Rule 3 - Blocking candidate handshake] Added a closed deployment-candidate scope**
- **Found during:** Task 2 candidate gate
- **Issue:** The release candidate mode correctly permitted only Task 1's manifest/release-gate files, so the nested deployment candidate could not validate its own baseline and deployment-gate files.
- **Fix:** Added a fixed `deployment` candidate stage that permits only the baseline, deployment gate, and the handshake change itself; arbitrary extra paths remain impossible.
- **Files modified:** `custom/grocy_AI/tests/release-gate.sh`
- **Verification:** Deployment candidate passes; unexpected dirty/committed path injection still fails.
- **Committed in:** `3b7481d8`

**3. [Rule 1 - Baseline semantics] Treated whitespace-only JSONL as semantically empty**
- **Found during:** Task 2 baseline gate
- **Issue:** The intentionally empty evidence file contains one newline, so a byte-size-zero assertion contradicted its recorded immutable hash.
- **Fix:** Reject non-whitespace records while retaining exact SHA-256 verification of the one-newline file.
- **Files modified:** `custom/grocy_AI/tests/deployment-gate.sh`
- **Verification:** Both hashes verify and `SKIPPED — NOT ACCEPTED` remains present.
- **Committed in:** `3b7481d8`

---

**Total deviations:** 3 auto-fixed (2 gate bugs, 1 blocking integration requirement).
**Impact on plan:** All fixes strengthen executable gate correctness without widening release paths, mutating live state, or changing evidence semantics.

## Issues Encountered

- Browser release suites required scoped localhost permission for their loopback fixture server; all candidate, predeploy, and evidence runs passed 142/142.
- No authentication gate was encountered because Plan 02-12's live predeploy mode is read-only and does not perform authenticated application smoke.

## User Setup Required

None for candidate/predeploy. Plan 02-13 postsmoke supplies existing authentication and a private GTIN only through process-local environment values; neither is printed or committed.

## Next Phase Readiness

- Plan 02-13 may proceed only after the now-green clean predeploy release and deployment gates.
- Prior companion and stable image IDs are resolvable, the live collision count is zero, and the persistent mount is exactly `/etc/komodo/grocy:/config` with RW enabled.
- Phase 1 evidence bytes remain unchanged and physically unaccepted.

## Self-Check: PASSED

- Task commits `3f588c2c` and `3b7481d8` exist.
- Release candidate, predeploy, and evidence modes pass; wrong-SHA, unexpected committed-path, unexpected dirty-path, and invalid deployment mode fixtures fail closed.
- Deployment candidate and read-only predeploy modes pass.
- Baseline has exactly two entries and both `shasum -a 256 -c` checks pass.
- Main PHP 113/113, barcode 84/84, browser release 142/142, companion 41/41, stable adapter lints, shell syntax, and diff checks pass.
- Main, stable, and companion worktrees are clean.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-14*
