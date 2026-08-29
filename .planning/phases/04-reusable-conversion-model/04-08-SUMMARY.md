---
phase: 04-reusable-conversion-model
plan: "08"
subsystem: conversion-activation
tags: [php, sqlite, release-gate, fail-closed, activation, evidence-ledger]
provides:
  - sole evidence-bound ActivateVerifiedRuleset transaction
  - immutable dual-branch activation evidence ledger
  - portable/stable conversions release gate
  - Phase 4 portable manifest catch-up and activation-authority documentation
affects: [04-10, 06]
key-files:
  created: []
  modified:
    - custom/grocy_AI/src/GrocyAiConversionMigration.php
    - custom/grocy_AI/src/GrocyAiConversionService.php
    - custom/grocy_AI/tests/conversions.php
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/release-gate.sh
    - custom/grocy_AI/portable-files.txt
    - custom/grocy_AI/README.md
    - CUSTOMIZATIONS.md
key-decisions:
  - "The characterization document on disk is the only activation authority; a bundle that disagrees with it in any field is stale by construction."
  - "The generic native pre-save hook keeps returning the fixed inactive-revision rejection and writes no module row, so the read-only validation endpoint stays provably zero-write."
  - "Coverage diagnostics report `ready` only from a recorded evidence row that at least one active revision is bound to."
requirements-completed: [CONV-02, CONV-03, CONV-05, CONV-08, CONV-09]
---

# Phase 04 Plan 08: Evidence-Gated Reusable Activation Summary

**Reusable conversion rules can now only change the resolved cache through one transaction that
re-proves current immutable dual-branch evidence, and the generic native boundary stays fail-closed
before and after activation.**

## Accomplishments

### Task 1 — the sole activation and projection transaction

- Added two idempotent module tables. `grocy_ai_conversion_rule_revisions` owns every reusable
  universal and profile candidate (source, source version, precise factor, revision hash,
  `inactive`/`active` lifecycle, evidence binding); `grocy_ai_conversion_activation_evidence` is the
  immutable ledger of main/stable revisions, characterization checksum, selected adapter, cache key
  schema, query-plan checksum, pinned migration hashes and cache objects, and a protected-output
  checksum. Bootstrap seeds 12 universal and 3 profile revisions, all `inactive`.
- Added `GrocyAiConversionService::ActivateVerifiedRuleset()` as the only operation permitted to
  transition a revision active or to create a reusable native/cache projection. In one transaction it
  re-reads `04-CHARACTERIZATION.md`, requires the supplied bundle to equal it in every field,
  validates every revision hash and the whole rule graph, records the evidence row, activates the
  named revisions, then calls only the selected adapter.
- Missing, stale, altered, failed, or unequal evidence returns `inactive` with exactly one bounded
  blocker and leaves module records, native rows, and cache snapshots byte-identical. Sixteen such
  cases are asserted, each with a full before/after snapshot comparison.
- Added the one supported adapter, `universal_native_rows_v1`. It writes universal
  `quantity_unit_conversions` rows for activated mass and volume rules whose units Grocy already has
  and stops. No module code issues cache SQL, modifies a product row, or performs Phase 6 cleanup.
- Coverage diagnostics now read the ledger: `ready` / `present` / `passed` only when a recorded
  evidence row has at least one active revision bound to it. The report shape and its closed
  vocabulary are unchanged, so no browser or DOM asset needed a change.

### Task 2 — release parity and documentation

- Added `release-gate.sh conversions`. It proves the Phase 4 portable manifest (membership, sorted
  uniqueness, path safety, every declared path present), the two immutable branch revisions read out
  of the characterization document, byte-equal characterized `0208.sql`/`0225.sql` on both revisions
  against the recorded hashes, the cache table/index/trigger contract on both revisions, all eight
  protected-consumer proofs, the evidence-ledger column contract, the single-activation-statement
  invariant, absence of ad-hoc cache SQL and of Phase 6 cleanup, then runs the whole conversion suite
  and every changed PHP lint. Verified fail-closed against a tampered main commit
  (`conversions_main_commit_exists`), a tampered migration hash (`conversions_migration_evidence_hash`),
  and a dropped portable path (`conversions_portable_manifest`).
- Made the gate environment-portable: `sha256sum`/`shasum` are both supported, the stable side
  resolves from `GROCY_AI_STABLE_REPO` or falls back to the same checkout when both maintained
  branches live in one repository, and `GROCY_AI_PHP` selects the required interpreter.
- Completed the portable manifest: 20 → 35 paths, adding all 15 Phase 4 module artifacts (the five
  the handoff named plus the conversion service/migration/tests/fixtures/spec/DOM tests).
- Documented the single activation authority, the native trigger/cache behavior, the fail-closed
  generic boundary, the stable-only adapter differences, and the Phase 6-only cleanup boundary in
  `custom/grocy_AI/README.md` (new "Reusable conversion model" section) and `CUSTOMIZATIONS.md`.

## Characterized behavior confirmed during implementation

- Grocy's own `quantity_unit_conversions_INS` trigger derives the inverse of every conversion, so the
  five gate-created rows in the fixture become ten native universal rows without any module write.
- `cache__quantity_unit_conversions_resolved` is product-scoped. A universal rule never produces a
  `product_id IS NULL` cache row; it appears as resolved rows per product. The tests assert this
  directly rather than assuming a symmetric universal cache row.

## Verification

- `bash custom/grocy_AI/tests/release-gate.sh conversions` — PASS (21 checks).
- `php custom/grocy_AI/tests/run.php conversion-release-gate` — passed.
- `php custom/grocy_AI/tests/run.php conversion-post-activation-bypass` — passed.
- `php custom/grocy_AI/tests/run.php conversion-native-save-hook` — passed.
- `php custom/grocy_AI/tests/run.php` — all 122 grocy_AI checks passed.
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` — 36 passed.
- `npm --prefix custom/grocy_AI/tests/browser test` — 184/184 passed.
- `node --test public/custom/grocy_AI/conversion-*.test.js` — 36 passed.
- `php -l` on all four changed PHP files, `sh -n` on the release gate — clean.

## Decisions and deviations

- **No projection is selected in production.** `04-CHARACTERIZATION.md` still records
  "No projection is selected", so every real `ActivateVerifiedRuleset()` call fails closed with
  `selected_projection_absent`. The success path is proved against a disposable copy of the document
  whose only difference is a named adapter. Production behavior is unchanged by this plan.
- **The pre-save hook writes no module row on rejection.** The plan permitted either recording a
  named inactive revision or returning a fixed inactive-revision result. The fixed-result branch was
  taken because `ValidateNativeConversionBeforeWrite` is shared with the read-only validation GET
  endpoint, whose zero-write guarantee is asserted comprehensively (schema, state, and
  `total_changes`). Adding a write there would have broken that contract, and the plan's Task 1 file
  list excludes `controllers/Api/GenericEntityApiController.php`, so the controller was not touched.
- **Bounded rejection reasons after activation are two, not one.** A generic universal POST/PUT that
  exactly restates an already-projected rule is rejected as `reusable_scope_inactive`; one that
  tampers with the factor is rejected earlier as `factor_tolerance`. Both fail closed before any
  native trigger or cache work; the tests assert each explicitly.
- **Superseded 04-05/04-06 fixture placeholder.** Those plans pre-created a placeholder
  `grocy_ai_conversion_activation_evidence` table with a different shape. Their fixtures now insert
  into the real ledger instead, so their zero-write assertions cover the actual Plan 04-08 table.

## Known follow-ups

- `release-gate.sh` still hardcodes `[ "$portable_count" -eq 12 ]` in its Phase 2 `candidate`/
  `predeploy`/`evidence` modes. That count was already stale at 20 paths before this plan and is now
  35. It fails closed rather than passing falsely, and fixing it belongs with the Phase 4 stable
  mirroring work, not here.
- The Phase 4 module files are not yet mirrored to `atech-release`. Stable byte parity for them
  requires the Phase 4 stable portable/adapter commits.
- `php custom/grocy_AI/tests/run.php conversion-characterization` requires two checkouts at the two
  immutable commits and does not run in the single-checkout Linux workspace. It is unchanged by this
  plan and is not part of the 04-08 verification set.

## Next step

Task 3 is a blocking human-verify checkpoint: confirm the fail-closed reusable gate and normal
product-scoped save behavior on both maintained branches, then Plan 04-10 adds the maintainer-only
CLI that supplies an evidence bundle to `ActivateVerifiedRuleset()`.
