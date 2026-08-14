---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "13"
subsystem: production-deployment
tags: [docker, compose, ssh, chrome, authentication, migration, zero-write]

requires:
  - phase: 02-12
    provides: immutable release and deployment gates
provides:
  - exact Phase 2 companion and stable adapter images running in production
  - authenticated contract, owner, asset, and secure-media smoke evidence
  - unchanged protected household and product-picture aggregate fingerprint
affects: [02-14-acceptance, phase-2-verification]

tech-stack:
  added: []
  patterns: [exact Git-archive Docker builds, Compose-only service replacement, closed browser smoke attestation]

key-files:
  created:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-DEPLOYMENT-EVIDENCE.md
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-13-SUMMARY.md
  modified:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-RELEASE-MANIFEST.md
    - custom/grocy_AI/tests/release-gate.sh
    - custom/grocy_AI/tests/deployment-gate.sh
    - views/productform.blade.php
    - /Users/ian/Documents/Repos/grocy-mcp/Dockerfile
    - /Users/ian/Documents/Repos/grocy-atech-release/views/productform.blade.php

key-decisions:
  - "Build images from exact Git archives and retain the established Compose definitions, ports, environment, and persistent mount."
  - "Use a closed browser smoke attestation when authentication exists only in the signed-in Chrome session; never extract or persist its credential."
  - "Treat live product-form rendering as a blocking deployment assertion in addition to API and container health."

patterns-established:
  - "Authenticated browser checks return only closed statuses, versions, and media outcomes; private request inputs and capability handles remain inside the page process."
  - "Grocy migration verification accounts for its request-triggered migration runner before asserting schema state."

requirements-completed: [ENR-01, ENR-02, ENR-03, ENR-04, ENR-05, ENR-06, ENR-07, ENR-08, ENR-09]

duration: 35 min
completed: 2026-08-14
---

# Phase 02 Plan 13: Production Deployment and Smoke Summary

**The exact Phase 2 companion and stable adapter images are running with immutable revision labels, the original persistent mount, contract-v2 authenticated reads, secure package media, and an unchanged protected-state fingerprint.**

## Performance

- **Duration:** 35 min
- **Started:** 2026-08-14T18:10:30Z
- **Completed:** 2026-08-14T18:45:31Z
- **Tasks:** 2
- **Repositories changed:** 3

## Accomplishments

- Built both images from their exact committed Git archives, recreated only their existing Compose services, and verified running revision labels plus immutable image IDs.
- Preserved `/etc/komodo/grocy:/config` read-write, applied migration 256 through Grocy's normal migration runner, verified the canonical unique index and zero collisions, and retained the exact predeploy protected-state fingerprint.
- Reused the signed-in Chrome session without extracting credentials; status, owner resolution, contract-v2 enrichment, asset marker 2.4.0, authenticated thumbnail/full media, private/no-store handling, and unauthenticated denial passed.
- Kept the deployment evidence redacted to closed outcomes, immutable IDs, and one aggregate fingerprint.

## Task Commits

1. **Task 1: Deploy exact companion and stable images** — `be28c3e2`
2. **Task 2: Run authenticated and zero-write smoke** — `ae03f981`

Supporting deployment fixes were committed as `336d10f1`, `99be9d4e`, and `a492f051` in the main repository, `e49d060` and `9fe07cd` in the companion repository, and the amended exact stable adapter commit `ed3565f0cc051047cc90feec7289fa14fcdc7275`.

## Deployed Identities

- Companion revision: `9fe07cda5f1ddaee08c5d46709a934170e5956bd`
- Companion image: `sha256:464304b235042b727144b7469fa3a6e3b82d461be9fe8e1d3f4d64e51baa54d9`
- Stable revision: `ed3565f0cc051047cc90feec7289fa14fcdc7275`
- Stable image: `sha256:a3d1dfebe4c2cb3a5fe668e62039f65637c9409a594bc1cec41390be7b23a101`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking image reproducibility] Corrected companion distribution-name normalization**
- The first exact companion build failed before producing an image because dotted package names from `pip freeze` did not hash like normalized constraint names.
- A diagnostic build showed that normalization must apply only to distribution names, not version numbers.
- The Dockerfile now canonicalizes the name field, the exact build passes, and the manifest pins the verified commit.

**2. [Rule 1 - Migration trigger] Invoked Grocy's normal request-triggered migration runner**
- The API health route did not execute migration 256, so the first schema assertion correctly failed.
- One normal root request ran Grocy's built-in migration path; the migration record and unique index then appeared with no protected-state change.

**3. [Rule 1 - Live adapter rendering] Fixed a localized placeholder arity error**
- Reloading the deployed product form exposed a server error that compile-only Blade checks had missed.
- The selection-summary translation now supplies its placeholder token, the stable adapter was rebuilt as one exact eight-path commit, and the release gate directly asserts the safe call in both source and stable candidates.
- The corrected page renders normally and exposes the synchronized 2.4.0 asset marker.

**4. [Rule 3 - Browser-only authentication] Added closed signed-in browser attestation support**
- No shell credential was configured, while the approved existing Chrome session was authenticated.
- The deployment gate now accepts one exact closed result string after direct browser checks, while independently enforcing unauthenticated denial and all live identity, schema, mount, and fingerprint assertions.

---

**Total deviations:** 4 auto-fixed (2 blocking integration defects, 1 migration lifecycle seam, 1 authentication transport seam).
**Impact on plan:** The fixes strengthened reproducibility and live verification without changing household rows, exposing credentials, or widening persistence authority.

## Verification

- Release gate `predeploy`: PASS after final companion and stable repins.
- Deployment gate `postdeploy-companion`: PASS.
- Deployment gate `postdeploy-stable`: PASS.
- Deployment gate `postsmoke`: PASS from committed gate and evidence.
- Protected aggregate before/after deployment and smoke: MATCH.
- Phase 1 byte baseline: PASS and still physically unaccepted.

## Next Phase Readiness

- Plan 02-14 may use the existing signed-in Chrome product form for the one operator-chosen owner-to-review-to-Save workflow.
- Automated deployment evidence is complete; only the human judgment checkpoint remains.
- No normal Save was performed during Plan 02-13.

## Self-Check: PASSED

- Exact companion and stable revisions match running image labels and evidence IDs.
- Prior images remain resolvable.
- Persistent mount, migration/index, zero collisions, contract/media reads, unauthenticated denial, and fingerprint continuity all pass.
- Evidence contains no private identifiers, values, credentials, handles, URLs, headers, bodies, or raw errors.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-14*
