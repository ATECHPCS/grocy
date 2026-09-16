# Phase 7: Upstream & Stable Release Sustainment - Proposed Plan Breakdown

**Drafted:** 2026-09-12
**Status:** Proposal for review — NOT yet expanded into numbered PLAN.md files. Two open conflicts (D-15 Phase 8 readiness, D-16 conversion premise) must be resolved by the user before the affected plans are finalized.

This is a proposed wave-ordered decomposition for Phase 7. It replicates the Phase 1-3 stable-promotion method exactly: reconcile the release tooling to current reality, audit the ATECHPCS core-hook boundary, mirror the portable module bytes from an immutable dev-head commit, adapt only the stable framework seams as a direct child, bump the runtime/version/cache markers, replay the dual-branch release + deployment-evidence gates against production-shaped data, rehearse recovery, then build one immutable-digest image and deploy by rebuild on prod with the route-cache clear.

Requirement legend: `REL-01`..`REL-07` from `.planning/REQUIREMENTS.md`.
New artifacts are marked `[NEW]`; everything else already exists.

---

## Wave / dependency overview

| Plan | Wave | Depends on | Requirements |
|------|------|------------|--------------|
| 07-01 | 1 | — | REL-01, REL-02 |
| 07-02 | 1 | — | REL-02, REL-03 |
| 07-03 | 2 | 07-01 | REL-01 |
| 07-04 | 2 | — | REL-03 |
| 07-05 | 3 | 07-01, 07-02, 07-03 | REL-02 |
| 07-06 | 4 | 07-05 | REL-01, REL-02 |
| 07-07 | 5 | 07-06 | REL-04 |
| 07-08 | 6 | 07-02, 07-07 | REL-02, REL-03 |
| 07-09 | 6 | 07-04, 07-07 | REL-03 |
| 07-10 | 7 | 07-04 | REL-07 |
| 07-11 | 7 | 07-08, 07-09 | REL-06 |
| 07-12 | 8 | 07-08, 07-09, 07-10, 07-11 | REL-04, REL-05 |
| 07-13 | 9 | 07-12 | REL-05 |
| 07-14 | 10 | 07-13 | REL-06, REL-07 |

Coverage check: REL-01 (07-01,07-03,07-06), REL-02 (07-01,07-02,07-05,07-06,07-08), REL-03 (07-02,07-04,07-08,07-09), REL-04 (07-07,07-12), REL-05 (07-12,07-13), REL-06 (07-11,07-14), REL-07 (07-10,07-14). Every REL requirement is covered by at least one implementing plan.

---

## Proposed plans

### 07-01 — Reconcile the portable manifest and the v1.0 changed-paths contract
- **Wave:** 1  **Depends on:** none
- **Requirements:** REL-01, REL-02
- **Likely files:** `custom/grocy_AI/portable-files.txt`, `custom/grocy_AI/phase-v1-changed-paths.txt` [NEW], `custom/grocy_AI/README.md`, `CUSTOMIZATIONS.md`
- **Acceptance truths:**
  1. Every shippable Phase 4/5/6/8 module file is classified portable / stable-adapter / dev-excluded, and the 16 currently-missing portable files (D-04) are added; the manifest stays LC_ALL=C sorted-unique with safe relative paths.
  2. A v1.0 changed-paths manifest enumerates the exact portable diff-tree expected in the mirror commit, replacing the Phase-2-scoped `phase2-changed-paths.txt` for this release.
  3. Dev-only `bin/snapshot-refresh.sh` / `bin/snapshot-scrub.sql` are excluded from both the manifest and the image; adapter-seam files (`GrocyAiApiController.php`, `routes.php`, `views/grocyai_*.blade.php`) remain OUT of the manifest.

### 07-02 — Fix the release-gate plumbing and add categorization + capture modes
- **Wave:** 1  **Depends on:** none
- **Requirements:** REL-02, REL-03
- **Likely files:** `custom/grocy_AI/tests/release-gate.sh`, `custom/grocy_AI/tests/deployment-gate.sh`
- **Acceptance truths:**
  1. The hardcoded `portable_count -eq 12` (line 565) is replaced by a manifest-derived count and the `phase2-changed-paths.txt` portable-scope compare (line 542) is replaced by the v1.0 changed-paths manifest; both pass against the reconciled 46+-path manifest.
  2. New `categorization` (Phase 6) and `capture` (Phase 8) modes exist, matching the depth of the `bulk`/`conversions` modes (portable-manifest membership, storage-boundary, fail-closed unit suite, PHP lint).
  3. `deployment-gate.sh` is re-pointed at Phase-7 manifest/evidence artifacts rather than the Phase-2 baseline, without weakening any existing gate identity.

### 07-03 — Audit the ATECHPCS core-hook boundary (source audit)
- **Wave:** 2  **Depends on:** 07-01
- **Requirements:** REL-01
- **Likely files:** `.planning/phases/07-upstream-stable-release-sustainment/07-SOURCE-AUDIT.md` [NEW], `CUSTOMIZATIONS.md`
- **Acceptance truths:**
  1. Every native Grocy seam the four phases touch (controller base class, route hook, product-form Blade, Dockerfile COPY, native `GenericEntityApiController` pre-save hook) is enumerated with its justification, confirming feature code stays inside `custom/grocy_AI/` + `public/custom/grocy_AI/` wherever possible.
  2. Each file in the 171-file dev-vs-stable diff is classified as upstream drift, portable module, or ATECHPCS adapter — proving no feature logic leaked into native trees.
  3. The audit output feeds the exact stable-adapter path allowlist used by 07-06.

### 07-04 — Build/confirm the production-shaped snapshot gate for dual-branch migration testing
- **Wave:** 2  **Depends on:** none
- **Requirements:** REL-03
- **Likely files:** `custom/grocy_AI/bin/snapshot-refresh.sh`, `.planning/phases/07-upstream-stable-release-sustainment/07-SNAPSHOT.md` [NEW], `.gitignore`
- **Acceptance truths:**
  1. A current production-shaped snapshot (444 products / 224 ungrouped / 21 groups / 80 conversions) is acquired via direct SSH + `VACUUM INTO` + scp (NOT the Komodo terminal) and light-scrubbed of secrets, gitignored.
  2. The refresh command and integrity/shape assertions are documented and re-runnable.
  3. The snapshot is usable read-write by 07-09/07-10 without touching prod.

### 07-05 — Materialize the portable-mirror commit on atech-release
- **Wave:** 3  **Depends on:** 07-01, 07-02, 07-03
- **Requirements:** REL-02
- **Likely files:** stable worktree (`atech-release`): full portable set per `portable-files.txt`; `custom/grocy_AI/tests/check-portable-parity.sh`
- **Acceptance truths:**
  1. Every portable path is materialized via `git show <dev-head-sha>:<path>` (never a mutable checkout) so the stable commit's sorted diff-tree equals `phase-v1-changed-paths.txt`.
  2. `check-portable-parity.sh --stable-sha <portable-sha>` reports 46+/46+ byte parity; the full-40 portable SHA and its parent are recorded for 07-06.
  3. The commit contains only portable paths — no Dockerfile, controller, route, Blade, or marker change.

### 07-06 — Reapply the stable-adapter direct child (framework seams only)
- **Wave:** 4  **Depends on:** 07-05
- **Requirements:** REL-01, REL-02
- **Likely files:** stable worktree: `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`, `views/grocyai_bulkreview.blade.php`, `views/grocyai_capture*.blade.php`, `views/grocyai_conversioncoverage.blade.php`, `views/productform.blade.php`, `custom/grocy_AI/src/GrocyAiCaptureController.php` (if seam per D-05), `CUSTOMIZATIONS.md`
- **Acceptance truths:**
  1. The adapter commit is a direct child of the 07-05 portable SHA and its sorted diff-tree equals the audited stable-adapter allowlist (07-03) — no portable byte changes.
  2. Stable framework conventions (`Grocy\Controllers\BaseApiController`, class-based `JsonMiddleware`, root conditional route hook, native Save lifecycle) are preserved; permission-first, GET-only-read behavior intact.
  3. SHA-pinned parity still passes at the adapter commit and reports only documented adapter differences.

### 07-07 — Stable-runtime commit: Dockerfile.atech overlay + version/cache markers
- **Wave:** 5  **Depends on:** 07-06
- **Requirements:** REL-04
- **Likely files:** stable worktree: `Dockerfile.atech`, `custom/grocy_AI/version.json`, `custom/grocy_AI/module-version.json`, `views/productform.blade.php` (`$grocyAiAssetVersion`), `CUSTOMIZATIONS.md`
- **Acceptance truths:**
  1. `Dockerfile.atech` (still `FROM lscr.io/linuxserver/grocy:v4.6.0-ls334`) COPYs the new module Blade views and any new viewjs assets; the runtime commit is a direct child of the adapter commit with an exact-scope diff-tree.
  2. `module_version`, stable `version.json` `"Customization"` marker, and `$grocyAiAssetVersion` are bumped in lockstep so the gate's `synchronized_markers` check passes.
  3. No portable or adapter path is re-touched; the full-40 runtime SHA and image-relevant markers are recorded.

### 07-08 — Author the Phase-7 release manifest and replay the dual-branch release gate on prod-shaped data
- **Wave:** 6  **Depends on:** 07-02, 07-07
- **Requirements:** REL-02, REL-03
- **Likely files:** `.planning/phases/07-upstream-stable-release-sustainment/07-RELEASE-MANIFEST.md` [NEW], `custom/grocy_AI/tests/release-gate.sh`
- **Acceptance truths:**
  1. The manifest records lower-case full-40 main / stable-portable / stable-adapter / stable-runtime SHAs, direct-parent equalities, marker values, and a finite post-candidate allowlist — no wildcard or implementation path.
  2. `release-gate.sh candidate` and every per-phase mode (`taxonomy`, `conversions`, `bulk`, `categorization`, `capture`) pass from clean worktrees with `php8.5`.
  3. The full unit + browser suites pass on both branches (single-checkout fallback supported); byte parity and immutable ancestry verified.

### 07-09 — Test module migrations and upgrade path against prod-shaped data on both branches
- **Wave:** 6  **Depends on:** 07-04, 07-07
- **Requirements:** REL-03
- **Likely files:** `custom/grocy_AI/tests/*` migration harness, `.planning/phases/07-upstream-stable-release-sustainment/07-MIGRATION-EVIDENCE.md` [NEW]
- **Acceptance truths:**
  1. All module `Bootstrap()` migrations (conversion, taxonomy, bulk, capture) apply idempotently against the 444-product snapshot on both branches; a second run yields zero additional diffs.
  2. DATA-06-style per-table checksum confirms only expected tables change, whitelisting the `cache__quantity_unit_conversions_resolved` rebuild.
  3. The upgrade path from a Phase-3-shaped stable DB to the v1.0 schema is proven without data loss.

### 07-10 — Rehearse prior-image + database recovery on the snapshot (REL-07)
- **Wave:** 7  **Depends on:** 07-04
- **Requirements:** REL-07
- **Likely files:** `.planning/phases/07-upstream-stable-release-sustainment/07-RECOVERY-RUNBOOK.md` [NEW]
- **Acceptance truths:**
  1. The current prod image digest is captured as the prior-image, and `/etc/komodo/grocy/backup-predeploy-20260912.db` restores cleanly onto the snapshot (integrity + 444-product count verified).
  2. A written rollback runbook (prior-image redeploy + DB restore) is proven executable inside a bounded maintenance window.
  3. Recovery is rehearsed with zero prod mutation.

### 07-11 — Complete the pending Phase 5 and Phase 6 human-verify checkpoints (pre-cutover)
- **Wave:** 7  **Depends on:** 07-08, 07-09
- **Requirements:** REL-06 (preparatory)
- **Likely files:** `.planning/phases/05-bulk-maintenance-recovery-engine/05-11-SUMMARY.md`, `.planning/phases/06-inventory-categorization/06-ACCEPTANCE.md`
- **Acceptance truths:**
  1. The Phase 5 `05-11` Task 3 maintainer human-verify and the Phase 6 human-verify are executed on the production-shaped snapshot / staging, not live prod.
  2. Any defect found blocks the cutover until resolved (or the affected phase is descoped per D-15/D-16).
  3. Sign-off is recorded before 07-12 builds the release image.

### 07-12 — Build the immutable-digest stable image and pass predeploy/evidence gates
- **Wave:** 8  **Depends on:** 07-08, 07-09, 07-10, 07-11
- **Requirements:** REL-04, REL-05
- **Likely files:** `custom/grocy_AI/tests/deployment-gate.sh`, `.planning/phases/07-upstream-stable-release-sustainment/07-DEPLOYMENT-EVIDENCE.md` [NEW]
- **Acceptance truths:**
  1. The image is built only from the exact full runtime SHA and identified by immutable digest; `release-gate.sh predeploy` passes with clean worktrees.
  2. `deployment-gate.sh` predeploy checks pass against the Phase-7 manifest/evidence artifacts.
  3. The digest, source SHA, and marker values are recorded for the cutover.

### 07-13 — Prod cutover: rebuild the Komodo stack and verify persistent continuity (REL-05)
- **Wave:** 9  **Depends on:** 07-12
- **Requirements:** REL-05
- **Likely files:** `.planning/phases/07-upstream-stable-release-sustainment/07-DEPLOYMENT-EVIDENCE.md`, prod (`atech-release` push + stack rebuild) — blocking human-action checkpoint
- **Acceptance truths:**
  1. `atech-release` is pushed and the Komodo stack `grocy` (10.10.0.156, `pull_policy: build`) rebuilds to the recorded source SHA/digest; `/etc/komodo/grocy:/config` data, product images, and DB survive restart.
  2. `GROCY_DATAPATH/viewcache/route_cache.php` is cleared post-rebuild and module routes resolve (no 404); feature flag + companion env confirmed active.
  3. Module migration state, routes, and cache markers reflect the new release after restart.

### 07-14 — End-to-end mobile acceptance on the promoted image; execute recovery on failure (REL-06/REL-07)
- **Wave:** 10  **Depends on:** 07-13
- **Requirements:** REL-06, REL-07
- **Likely files:** `.planning/phases/07-upstream-stable-release-sustainment/07-PHASE-ACCEPTANCE.md` [NEW], `07-DEPLOYMENT-EVIDENCE.md`
- **Acceptance truths:**
  1. A user completes the end-to-end mobile product workflow (scan → enrich → review → save; and the promoted bulk/capture surfaces) on the live promoted image before acceptance.
  2. On any acceptance or migration failure, the rehearsed 07-10 recovery (prior-image redeploy + DB restore) is executed and verified within the window.
  3. Final redacted deployment evidence (digest, continuity, smoke outcomes, zero-write counters) is recorded and the `evidence` gate passes.

---

## Open questions for review before writing numbered plans

1. **Phase 8 release-readiness (D-15):** ROADMAP shows Phase 8 `0/6 Not started`, but the dev HEAD carries the full capture module + a green browser spec. Is Phase 8 actually complete and accepted for this v1.0 promotion, or does it drop from the release set (removing `capture` gate work from 07-02/07-08 and capture files from 07-01/07-05/07-06)?
2. **Conversion premise conflict (D-16):** the paused DATA-03/04/05 re-plan leaves the `06-06` tripwire firing on prod's 18 legitimate product-specific conversions. Is the conversion re-plan closed before Phase 7, or does Phase 6 promote with the conversion-audit pass excluded so the `categorization` gate is green on prod-shaped data?
3. **Single vs staged release (D-01):** confirm the four phases promote as one consolidated v1.0 image (proposed) rather than staged per-phase rebuilds.
4. **Phase 4 activation (D-17):** confirm Phase 4 conversions ship INACTIVE (no projection, `selected_projection_absent` fail-closed) and that the maintainer-auth file + projection selection are NOT cutover prerequisites for this release.
5. **Human-verify placement (D-14):** confirm the pending Phase 5/6 human-verify runs pre-cutover on the snapshot/staging (07-11) rather than on live prod after cutover.
</content>
