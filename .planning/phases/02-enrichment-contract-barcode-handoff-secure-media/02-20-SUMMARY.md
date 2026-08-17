---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "20"
subsystem: deployment-provenance
tags: [deployment, immutable-images, live-evidence, secure-media]

requires:
  - phase: 02-19
    provides: immutable replacement companion and stable-adapter candidates
provides:
  - replacement containers bound to exact Plan 02-19 revisions
  - redacted passing live deployment evidence
  - preserved protected-state continuity and rollback image identities
affects: [phase-02-verification]

key-files:
  modified:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-DEPLOYMENT-EVIDENCE.md
  created:
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-20-SUMMARY.md

requirements-completed: [ENR-01, ENR-06, ENR-07, ENR-08]
---

# Phase 02 Plan 20: Replacement Deployment Summary

**The live companion and Grocy services now run the immutable Plan 02-19 replacement candidates, with redacted evidence and continuity checks passing.**

## Accomplishments

- Built and deployed only the exact committed companion archive `3861acf34694585cf2201a1f8edbed4e7f6d8627` and stable adapter archive `505d5673e36df96745a37fcfcdaadce768e60eb1`.
- Recorded the new immutable companion and stable image identities, plus still-resolvable rollback image identities, in the redacted deployment evidence.
- Preserved the existing `/etc/komodo/grocy:/config` mount and confirmed the pre-existing canonical migration/index state and protected aggregate fingerprint.
- Replayed authenticated contract/owner/media reads, matching unauthenticated denial, and the `2.4.1` / `ATECHPCS-grocy_AI-9` served markers through the existing signed-in browser session without saving a product.

## Verification

- Predeploy release and live deployment gates — PASS.
- Postdeploy companion and stable identity gates — PASS.
- Postsmoke gate with closed browser attestation — PASS.
- Final live container, image, mount, schema, and protected-state gate — PASS.
- Release evidence gate passed all source, parity, PHP, barcode, and stable lint assertions before its browser/companion runners completed successfully in the already-established offline test environment.

## Deployment Identities

- Companion image: `sha256:fba57a16053a9f3933d4949d38f921c198e9c954d5aab6504d1794e5aab668f0`
- Stable image: `sha256:1903850d12820a2c263928077fdc3025be538fe735aa6dc09255ba7914c9ab58`

## User Data and Privacy

No household rows, schema, credentials, Compose topology, provider configuration, or historical manual acceptance evidence were changed. The only browser action was a focused read-only smoke; the product form remained open and unsaved. The evidence file contains only identities, an aggregate fingerprint, and closed outcomes.

## Next Phase Readiness

All Phase 02 execution plans are complete. Proceed with phase-level verification/review; do not treat the historical manual acceptance as a new run.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-17*
