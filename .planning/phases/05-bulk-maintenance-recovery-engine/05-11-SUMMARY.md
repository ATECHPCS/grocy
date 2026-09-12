# Plan 05-11 Summary — Integration, routes, portable parity, and the `bulk` release gate

**Status:** Automatable work (Tasks 1–2) complete and green. Task 3 is HUMAN-VERIFY and was NOT
performed; its maintainer checklist is below.

## What was done

### 1. Export route registered; every bulk route defined exactly once
- Added `GET /api/grocy-ai/bulk/plans/{planId}/export` → `ExportBulkPlan` in
  `custom/grocy_AI/routes.php` (the controller method already existed from 05-10).
- Verified every bulk route is defined exactly once on the `MASTER_DATA_EDIT`-gated
  `/api/grocy-ai` group: plan read, selection, selected-diff, apply, audit, rollback-preview,
  rollback, export, plus the `GET /grocyai/bulkreview` page render.
- Verified `require_once` for `GrocyAiBulkMigration`, `GrocyAiBulkService`, and
  `GrocyAiBulkController` is present exactly once each. No other route/controller change was needed;
  all other bulk endpoints and controller methods (apply, audit, rollback, rollback-preview) were
  already wired by 05-05/05-07/05-08/05-09.

### 2. `portable-files.txt` reconciled (36 → 44 paths, sorted-unique)
Added the union of new Phase 5 manifest-tracked files:
- `custom/grocy_AI/src/GrocyAiBulkController.php`
- `custom/grocy_AI/src/GrocyAiBulkMigration.php`
- `custom/grocy_AI/src/GrocyAiBulkService.php`
- `custom/grocy_AI/tests/bulk.php`
- `custom/grocy_AI/tests/fixtures/bulk-plan-cases.json`
- `custom/grocy_AI/tests/fixtures/bulk-registry-cases.json`
- `public/custom/grocy_AI/bulk-review.js`
- `public/custom/grocy_AI/bulk-review.test.js`

`views/grocyai_bulkreview.blade.php` is deliberately NOT in the manifest — Blade views live in the
core `views/` tree and ship through the branch-adapter / changed-paths mechanism (matching the
untracked `views/grocyai_conversioncoverage.blade.php`). The `bulk` gate asserts it stays out of the
manifest and exists on disk for adapter carry.

### 3. `bulk` release-gate mode added (`custom/grocy_AI/tests/release-gate.sh`)
`bulk_release_gate()`, modeled on the single-arg `conversions`/`taxonomy` modes, dispatched via
`[ "$#" -eq 1 ] && [ "$1" = bulk ]`. It asserts, fail-closed:
- manifest membership + LC_ALL=C sorted-unique + safe paths for every Phase 5 file; Blade view
  excluded-from-manifest-but-present.
- closed operation registry + sole durable delegate `->AssignProductTaxonomy(`; no
  `INSERT|UPDATE|DELETE|REPLACE` against `products|grocy_ai_taxonomy_classifications|quantity_unit_conversions|cache__`;
  no `UPDATE|DELETE|REPLACE` against `grocy_ai_bulk_audit` (append-only).
- **no network primitive** in `GrocyAiBulkService.php` (`curl_`, `file_get_contents('http`,
  `fsockopen`, `stream_socket`, `new GrocyAiService`, `->EnrichByUpc(`, `->FetchImage(`) — proving
  apply/rollback never touch the network while the `BEGIN IMMEDIATE` lock is held.
- single `BEGIN IMMEDIATE` + single `COMMIT` + `hash_equals` checksum idempotency gate; audit table
  created in the migration.
- apply and rollback each permission-check `PERMISSION_MASTER_DATA_EDIT` (per-method body slice, per
  MISTAKES.md GREP-01); export route wired exactly once; **no `bin/*.php` calls `->ApplyPlan(` or
  `->RollbackPlan(`** (no maintainer CLI apply, D-13).
- runs all 11 `bulk-*` unit modes (incl. 05-01 `bulk-contract`/`bulk-invariants`/`bulk-schema`,
  now GREEN), `php8.5 -l` on the new/changed PHP, and `node --test bulk-review.test.js`.

### 4. Carry-forward fixes (`custom/grocy_AI/tests/run.php`)
- (a) Extended the Blade asset-token coverage to `views/grocyai_bulkreview.blade.php`: single
  `$grocyAiAssetVersion` == `module-version.json` (2.5.0), exactly two `{{ $grocyAiAssetVersion }}`
  tokens, `permission-MASTER_DATA_EDIT` scoping, and no `<form>` write action (Blade comments
  stripped before the check to avoid matching prose that mentions `<form>`).
- (b) The optional clean-404-on-unbootstrapped-schema is already satisfied: controllers construct
  the service with `bootstrap=false`, and a missing-table `PDOException` extends `RuntimeException`,
  which the existing `catch (\RuntimeException)` maps to a bounded 404. No change made (low-risk,
  already correct).

### 5. Docs
- `custom/grocy_AI/README.md`: new "Bulk maintenance & recovery engine" section (workflow, closed
  named-operation registry, checksum + optimistic-concurrency + single-transaction + idempotency
  contract, append-only audit, non-authoritative export, guarded rollback, the authority boundary,
  the route list, and how to run the gate).
- `CUSTOMIZATIONS.md`: ownership / no-parallel-write statement — Grocy is the sole durable authority;
  apply/rollback are audited in-app `MASTER_DATA_EDIT` actions, not a CLI; Phase 6 owns the sweep.

## Verification (all green on this checkout)
- `release-gate.sh bulk` → `RELEASE_GATE: PASS (bulk)` (22 PASS lines).
- Full default suite → `All 127 grocy_AI checks passed`.
- All 11 `bulk-*` unit modes → PASS.
- `node --test bulk-review.test.js` → `# tests 27 / # pass 27 / # fail 0`.
- `php8.5 -l` clean on `routes.php`, `GrocyAiApiController.php`, `run.php`, `GrocyAiBulkService.php`.
- Fail-closed confirmed: dropping a bulk path from the manifest makes the gate exit non-zero with
  `FAIL: bulk_portable_manifest`.

## Dual-branch parity (environmental limitation)
This is a single-checkout worktree. The 2-arg cross-branch release-gate mode and
`conversion-characterization` require live main/stable branch commits and fail with
`branch_commit_mismatch` — PRE-EXISTING and ENVIRONMENTAL, untouched here. The `bulk` gate is
structured like the single-arg `conversions` gate (same-checkout fallback via
`GROCY_AI_STABLE_REPO`/`GROCY_AI_STABLE_REF`, `GROCY_AI_PHP` runner) so it is branch-identical and
does not depend on dual-branch git evidence.

## Task 3 — HUMAN-VERIFY checklist (NOT performed; for the maintainer)
Sign in with `MASTER_DATA_EDIT` on the deployed UI (`/grocyai/bulkreview`) and confirm, incl. at
320px width:
1. Generate a bulk plan; confirm exact included/excluded/skipped/conflicted/changed/unchanged counts
   and that generation writes nothing native.
2. Select/reject individual items; confirm the selected diff equals the apply set.
3. Apply with explicit confirmation; confirm only selected non-conflicted items changed, a drifted
   item is refused as a conflict with no partial write, and re-applying repeats no mutation.
4. Open the audit trail; confirm actor, previewed/applied times, module/version, per-item outcome,
   and before/after values.
5. Download JSON and CSV export; confirm redaction (no secrets/tokens/handles/unrelated household
   detail) and the non-authoritative marker.
6. Preview rollback; confirm a manually edited field is refused (`manual_edit_after_apply`) and a
   clean item is reversible; execute rollback and confirm the audit records it.
7. Confirm no maintainer CLI apply exists.

## Not completed
- Task 3 human verification (intentionally deferred to the maintainer).
- Dual-branch 2-arg gate / characterization: pre-existing environmental failure, out of scope.
