# Phase 7: Upstream & Stable Release Sustainment - Context

**Gathered:** 2026-09-12
**Status:** Ready for planning — all open conflicts resolved by the maintainer 2026-09-12 (D-15/D-16/D-17 locked below)

<domain>
## Phase Boundary

This phase promotes the completed dev-lineage milestone — Phases 4 (Reusable Conversion Model, shipped inactive), 5 (Bulk Maintenance & Recovery Engine), 6 (Inventory Categorization), and 8 (Purchase Capture) — from the `codex/phase5-bulk-engine` dev head onto the divergent STABLE `atech-release` branch, then rebuilds the single household prod instance from that stable branch.

`atech-release` is NOT downstream of the dev lineage; it is a separately maintained stable branch pinned to LinuxServer Grocy `v4.6.0-ls334`, currently at Phase 3 (`6605ae6c`). Promotion is therefore NOT a `codex → atech-release` merge. It follows the discipline already proven for Phases 1-3: (1) **mirror the portable module bytes** into stable via `git show <immutable-sha>:<path>` so the portable implementation is byte-identical on both branches; (2) **adapt only the stable framework seams** (controller base class, route hook, product-form Blade, Dockerfile COPY layout, cache/version markers) as a separate, exact-scope, direct-child commit; (3) **replay the dual-branch release gate + deployment-evidence gate** against production-shaped data; (4) **build one immutable-digest stable image and deploy it by rebuild** on prod while preserving `/etc/komodo/grocy` data.

Phase 7 changes no feature behavior. It reconciles the release tooling to the current 46-path module reality, mirrors/adapts the four phases as one v1.0 release, proves the gates, rehearses recovery, and executes the cutover with the route-cache clear. It does not add new module capabilities, re-open feature scope, or resolve the paused-conversion premise (that is Phase 6 work).

</domain>

<decisions>
## Implementation Decisions

### Promotion Strategy & Ordering
- **D-01:** Promote Phases 4/5/6/8 as **one consolidated v1.0 release**, not four staged releases. Prod builds a single image from `atech-release` via `Dockerfile.atech` (`pull_policy: build`), so a staged promotion would mean four full rebuilds and four route-cache clears with no isolation benefit; the four phases share one module tree, one migration set, and one Blade/asset surface. (REL-02, REL-04)
- **D-02:** Reuse the exact three-commit stable discipline from Phases 1-3: (a) one **portable-mirror** commit materialized from immutable dev-head blobs (`git show <sha>:<path>` per `portable-files.txt`, never copying a mutable checkout), (b) one **stable-adapter** direct-child commit limited to framework-seam paths, (c) one **stable-runtime** direct-child commit for `Dockerfile.atech`, version, and cache markers. Never a branch merge. (REL-02)
- **D-03:** Execution order is manifest+gate plumbing FIRST (blocking), then ATECHPCS core-hook/source audit, then portable mirror → adapter → runtime, then gates on prod-shaped data, then recovery rehearsal + human-verify, then immutable build → cutover → acceptance. Phase boundaries 4/5/6/8 are logical coverage inside the single release, not separate commits. (REL-01, REL-02)

### Manifest & Release-Gate Reconciliation (the KNOWN open gaps)
- **D-04:** Reconcile `portable-files.txt` (currently 46 paths) to cover every shippable Phase 4/5/6/8 module file, classifying each new file as **portable**, **stable-adapter** (rides the adapter commit, stays OUT of the manifest), or **dev-only-excluded** (never ships). Missing portable files found on HEAD: Phase 6 `bin/audit-conversions.php`, `bin/verify-inventory-diff.php`, `tests/categorization.php`, `tests/conversion_audit.php`, `tests/group_suggestion.php`, `tests/inventory_diff.php`, `tests/inventory_scope.php`; Phase 8 `src/GrocyAiCaptureMigration.php`, `src/GrocyAiCaptureService.php`, `tests/capture.php`, `tests/fixtures/capture-cases.json`, `public/custom/grocy_AI/capture.js`, `capture.test.js`, `capture-review.js`, `capture-review.test.js`. (REL-01, REL-02)
- **D-05:** `GrocyAiApiController.php`, `routes.php`, and the capture/bulk/conversion **Blade views** (`views/grocyai_*.blade.php`) plus new `viewjs`/native controller seams stay **stable-adapter** paths (framework-coupled), matching how 01-09/02-19 treated `GrocyAiApiController.php` and `views/productform.blade.php`. `GrocyAiCaptureController.php` is a candidate adapter-seam file pending the same base-class check (the agent's Discretion). Dev-only `bin/snapshot-refresh.sh` and `bin/snapshot-scrub.sql` are excluded from both the manifest and the image. (REL-01)
- **D-06:** Fix the two hardcoded release-gate plumbing defects in `custom/grocy_AI/tests/release-gate.sh`: (1) line 565 `[ "$portable_count" -eq 12 ]` — a Phase-2-era literal that now fails against the 46-path manifest — becomes a manifest-derived count; (2) line 542 compares the stable portable diff-tree against `phase2-changed-paths.txt` (12 Phase-2 paths) — replace with a v1.0 changed-paths manifest covering the full portable set. (REL-02)
- **D-07:** Add per-phase release-gate modes `categorization` (Phase 6) and `capture` (Phase 8) mirroring the existing `taxonomy`/`conversions`/`bulk` modes, so REL-02/03 prove every promoted phase's portable-manifest membership, storage boundary, and fail-closed unit suite. (REL-02, REL-03)

### Dockerfile.atech, Version Metadata & Immutable Digest
- **D-08:** In the stable-runtime commit, extend `Dockerfile.atech` (which stays `FROM lscr.io/linuxserver/grocy:v4.6.0-ls334`) to COPY the new module Blade views (`grocyai_bulkreview`, `grocyai_capture`, `grocyai_capture_review`, `grocyai_conversioncoverage`) and any new `viewjs` assets the four phases require. The whole-tree `COPY custom/grocy_AI` + `COPY public/custom/grocy_AI` lines already carry portable files; only the framework-tree COPY additions are needed. (REL-04)
- **D-09:** Bump the three synchronized markers together so the release-gate `synchronized_markers` check passes and route/view caches invalidate: `module-version.json` `module_version` (currently `2.5.0`), stable `custom/grocy_AI/version.json` `"Customization"` cache marker, and the `$grocyAiAssetVersion` Blade literal. Record the built image by **immutable digest**, not a mutable tag. (REL-04)

### Dual-Branch Gates on Production-Shaped Data
- **D-10:** The dual-branch `release-gate.sh` (candidate/predeploy) and `deployment-gate.sh` are **hard blocking exit gates** for the cutover (REL-02/03). Author a Phase-7 release manifest (mirroring `02-RELEASE-MANIFEST.md`: main/stable-portable/stable-adapter/stable-runtime SHAs, marker values, finite post-candidate allowlist) and a Phase-7 deployment-evidence file rather than reusing the Phase-2 artifacts the scripts currently point at. (REL-02, REL-04, REL-05)
- **D-11:** Run every module `Bootstrap()` migration and the module upgrade path against the **production-shaped local snapshot** (the clone→light-scrub snapshot from the Phase 6 `bin/snapshot-refresh.sh` tooling; prod = 444 products / 224 ungrouped / 21 groups / 80 conversions) on BOTH branches, asserting idempotent re-run and DATA-06-style zero-diff on unrelated tables (whitelisting the `cache__quantity_unit_conversions_resolved` rebuild noted in Phase 6 caveat 3). (REL-03)

### Data-Safety, Recovery Rehearsal & Cutover
- **D-12:** Recovery (REL-07) is rehearsed BEFORE cutover: capture the current prod image digest as the prior-image, and rehearse restoring `/etc/komodo/grocy/backup-predeploy-20260912.db` (VACUUM INTO, integrity ok, 444 products) onto the snapshot. Ship a written rollback runbook = prior-image digest + DB restore, executable inside the maintenance window. (REL-07)
- **D-13:** Cutover (REL-05) = push `atech-release` → rebuild the Komodo stack `grocy` on periphery "Personal Docker (102)" / 10.10.0.156 (compose builds `github.com/ATECHPCS/grocy.git#atech-release`, `image: atechpcs/grocy-ai:4.6`, `pull_policy: build`) → **clear `GROCY_DATAPATH/viewcache/route_cache.php`** (module routes 404 otherwise — the documented route-cache trap) → verify persistent data, product images, routes, feature flags (`GROCY_FEATURE_FLAG_GROCY_AI=true` + companion URL/key already set), and module migration state after restart. (REL-05)
- **D-14:** Human-verify sequencing: the PENDING Phase 5 (`05-11` Task 3) and Phase 6 human-verify checkpoints run on the production-shaped snapshot / pre-cutover, so promotion is not gated on live prod. REL-06 (end-to-end mobile workflow on the promoted image) is the single post-cutover acceptance gate; a REL-06 failure triggers the rehearsed REL-07 recovery. (REL-06, REL-07)

### Resolved Decisions (locked by the maintainer 2026-09-12)
- **D-15 [RESOLVED — INCLUDE]:** Phase 8 (Purchase Capture) IS in the v1.0 promotion set — the release is 4+5+6+8. The capture module ships with its own `capture` release-gate mode (D-07) and its human-verify runs pre-cutover on the prod-shaped snapshot alongside Phase 5/6 (D-14). Update the ROADMAP `0/6` row to reflect that Phase 8 code is complete and promoted via Phase 7. If capture cannot pass its own release-gate/human-verify, THAT is the point to reconsider dropping it — not an unreviewed assumption now.
- **D-16 [RESOLVED — PROMOTE AS-IS, VERIFY ON SNAPSHOT]:** The Phase 6 conversion tripwire was already re-planned before promotion: the merged `06-06` re-plan (dev HEAD) accepts the 18 legitimate product-specific conversions and the module suite is GREEN (`conversion_audit`: `62 global + 18 expected / 9 products, 0 suspicious` — matching prod's 62+18). The categorization/conversion-audit gate is therefore expected to pass on prod-shaped data and is NOT excluded. Plan 07-04/07-08 MUST run it against the real 444-product snapshot and confirm 0 suspicious; only if it fails there is it a blocker. (The STATE.md "paused" wording is stale relative to the merged re-plan.)
- **D-17 [RESOLVED — SHIP INACTIVE]:** Phase 4 conversions ship INACTIVE (`selected_projection_absent`). The maintainer-auth file and projection selection are NOT prerequisites for this cutover; activation remains a separate, later, gated maintainer action. Prod's 18 product-specific conversions stay as-is (unmanaged by the inactive reusable model).

### the agent's Discretion
- Choose the exact form of the manifest-derived portable count (a `portable_path_count` manifest field vs `wc -l` of `portable-files.txt`) and the exact name/shape of the v1.0 changed-paths file (e.g. `phase-v1-changed-paths.txt`) that replaces the Phase-2 `phase2-changed-paths.txt` comparison, provided the SHA-pinned byte-parity contract still holds for every portable path.
- Decide whether `GrocyAiCaptureController.php` is a portable file or a stable-adapter seam by checking whether it extends the branch-specific `Grocy\Controllers\BaseApiController` / class-based `JsonMiddleware` the way `GrocyAiApiController.php` does; classify it accordingly.
- Choose the internal structure of the new `categorization` and `capture` release-gate modes (which storage-boundary and fail-closed assertions to pin) so long as they match the depth of the existing `bulk`/`conversions` modes.
- Choose the snapshot-refresh cadence and whether the prod-shaped snapshot is rebuilt fresh for Phase 7 or the existing Phase 6 snapshot is reused, provided migration/upgrade tests run against 444-product-shaped data on both branches.
- Choose the maintenance-window length and the exact order of the post-cutover REL-05 verification checks, provided the route-cache clear precedes route verification.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract and Prior Decisions
- `.planning/ROADMAP.md` — Phase 7 goal, five success criteria, and the Phase 6 dependency; note Phase 8's "portable/stable mirroring folds into Phase 7" clause and its `0/6` progress row.
- `.planning/REQUIREMENTS.md` — Authoritative `REL-01`..`REL-07`.
- `.planning/STATE.md` — Current position, the KNOWN open promotion gaps (Phase 4 files not mirrored to `atech-release`; `release-gate.sh` Phase-2 modes hardcode a 12-path count against the current manifest; maintainer-auth file + projection unselected; conversion premise invalidated 2026-09-12), and the prod-snapshot acquisition method (direct SSH `root@10.10.0.156`, 1Password `ssh:Personal Docker (102)`, PDO `VACUUM INTO` + scp — NOT the Komodo terminal, 16 MiB frame cap).

### The Established Promotion Pattern to Replicate
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-08-PLAN.md` — the portable-mirror commit (byte-identical, exact file allowlist, recorded 40-hex SHA, no adapter work).
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-09-PLAN.md` — the stable-adapter + immutable-deploy + persistent-data continuity pattern (`check-portable-parity.sh --stable-sha`, cache-marker bump, blocking human-action deploy checkpoint).
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-18-PLAN.md` — materialize the portable candidate from immutable blobs via `git show`, exact diff-tree equals the changed-paths manifest.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-19-PLAN.md` — stable-adapter direct child, replacement manifest provenance, replay closed candidate/predeploy gates.
- `.planning/phases/03-food-taxonomy-categorization-pilot/03-03-PLAN.md` — classify each new module path portable vs stable-adapter, extend the Docker overlay + manifest + release-gate mode; the model for D-04/D-07.

### Tooling and Stable Branch
- `custom/grocy_AI/tests/release-gate.sh` — the dual-branch gate; per-phase modes (`taxonomy`/`conversions`/`bulk` at lines 142/179/346) and the manifest-driven candidate/predeploy/evidence flow; the two defects at lines 542 and 565.
- `custom/grocy_AI/tests/deployment-gate.sh` — live-prod evidence gate (`root@10.10.0.156`, container `grocy`, base `http://10.10.0.156:9283`); currently keyed to Phase-2 baseline/evidence artifacts.
- `custom/grocy_AI/tests/check-portable-parity.sh` — SHA-pinned portable byte-parity checker (`--stable-sha`).
- `custom/grocy_AI/portable-files.txt` — the 46-path manifest to reconcile.
- `origin/atech-release:Dockerfile.atech` — `FROM lscr.io/linuxserver/grocy:v4.6.0-ls334`, the COPY overlay to extend (D-08). NOTE: `Dockerfile.atech` is DELETED on the dev HEAD; it lives only on the stable branch.
- `MISTAKES.md` / MEMORY — run PHP as `php8.5`/`GROCY_AI_PHP`; clear `route_cache.php` after deploy; snapshot acquisition via direct SSH not Komodo terminal.

</canonical_refs>

<code_context>
## Existing Code Insights

### Divergent-Lineage Reality
- `git diff --name-only origin/atech-release..HEAD` over `custom/ views/ public/ routes.php controllers/ migrations/` returns **171 files**, but ~125 are native Grocy files (`controllers/`, `public/js`, `public/viewjs`, `migrations`, core `views/`) that differ purely because `atech-release` tracks LinuxServer Grocy v4.6.0 while the dev lineage tracks a newer upstream. This upstream drift is exactly why promotion is portable-mirror + adapter, never a merge — a merge would drag newer upstream Grocy onto the stable base.
- The true portable set is the `custom/grocy_AI/` + `public/custom/grocy_AI/` module tree; the true adapter set is `GrocyAiApiController.php`, `routes.php`, `Dockerfile.atech`, the module `views/grocyai_*.blade.php`, and version/cache markers.

### Reusable Assets
- The Phase 1-3 stable-mirror plans are directly copyable procedure; only the file lists and gate modes change.
- `bin/snapshot-refresh.sh` + `bin/snapshot-scrub.sql` (Phase 6) already build the clone→light-scrub production-shaped snapshot for migration/rollback rehearsal.
- The pre-deploy prod DB backup `/etc/komodo/grocy/backup-predeploy-20260912.db` (444 products, integrity ok) is the REL-07 restore source.

### Integration Points
- Prod compose: `/etc/komodo/stacks/grocy/personal-102/grocy/compose.yaml`; deploy = update `atech-release` + rebuild the stack on periphery "Personal Docker (102)" (10.10.0.156, port 9283, bind `/etc/komodo/grocy:/config`).
- Prod env already sets `GROCY_FEATURE_FLAG_GROCY_AI=true`, `GROCY_AI_SERVICE_URL`, `GROCY_AI_SERVICE_API_KEY`.
- After rebuild, `GROCY_DATAPATH/viewcache/route_cache.php` MUST be cleared or module routes 404.

</code_context>

<specifics>
## Specific Ideas

- The release is trustworthy only if the tooling matches the code: reconcile the manifest and fix the two hardcoded gate defects BEFORE any mirror commit, or the gate will either false-pass (stale scope) or false-fail (12-path literal).
- Recovery must be rehearsed, not merely documented: prove the prior-image redeploy and the `backup-predeploy-20260912.db` restore against the snapshot before touching prod, so the rollback path is known-good inside the window.
- The route-cache clear is a load-bearing cutover step, not an afterthought — it is the single most likely cause of a "deploy succeeded but every module page 404s" failure.

</specifics>

<deferred>
## Deferred Ideas

- Resolving the paused conversion re-plan (DATA-03/04/05) belongs to Phase 6, not Phase 7; Phase 7 only promotes whatever Phase 6 finalizes.
- `atech-main` (upstream-tracking) promotion is a parallel lineage; this phase's prod cutover targets `atech-release`. Keeping `atech-main` current with the same portable bytes is a parity obligation (REL-02) but not a prod-deploy target.
- v2 items (V2-01 mapping hints, V2-02 conversion graph, V2-03 snapshot re-import) remain out of scope.

</deferred>

---

*Phase: 07-upstream-stable-release-sustainment*
*Context gathered: 2026-09-12*
</content>
</invoke>
