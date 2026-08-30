---
phase: 05-bulk-maintenance-recovery-engine
plan: "05"
subsystem: bulk-plan-review-and-selection
tags: [php, javascript, blade, bootstrap4, read-only, a11y, per-item-selection]
provides:
  - SetItemSelection (single-flag, idempotent, checksum-stable) and ReadPlan/SelectedDiff reads on GrocyAiBulkService
  - MASTER_DATA_EDIT-gated GET/PUT/GET endpoints for plan, selection, and selected diff
  - Bootstrap 4 bulk-review UI: summary counts, per-item select/reject cards, selected-diff panel
key-files:
  created:
    - public/custom/grocy_AI/bulk-review.js
    - public/custom/grocy_AI/bulk-review.test.js
    - .planning/phases/05-bulk-maintenance-recovery-engine/05-05-SUMMARY.md
  modified:
    - custom/grocy_AI/src/GrocyAiBulkService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/src/GrocyAiBulkController.php
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/bulk.php
    - custom/grocy_AI/tests/run.php
    - views/grocyai_bulkreview.blade.php
    - public/custom/grocy_AI/grocy-ai.css
key-decisions:
  - "Backend (commit 2e8fd61c): SetItemSelection writes only the selected column via one UPDATE, is idempotent (no write when the flag already matches), and never re-derives or disturbs the stored checksum. Selection is refused with no write when the plan is terminal (applied/rolled_back), the ruleset_version is stale, the seq is unknown, or the flag is non-boolean."
  - "Backend: SelectedDiff returns exactly the currently selected items verbatim (rejected items omitted) plus an apply-set included count that currently equals the selected count; 05-06 later subtracts apply-time conflicts from that count."
  - "Backend: PUT .../selection accepts only the closed { selected: true|false } body via array_intersect_key against a single-key allowlist — any extra key, wrong type, or missing key is a 400 with no write, matching the ValidateConversion closed-candidate-key idiom."
  - "Frontend: bulk-review.js never trusts a locally held item — every render (summary, items, selected diff) is rebuilt strictly from the payload of the request that produced it. Toggling a row PUTs the selection, renders the returned plan, then independently re-fetches and renders the selected diff from its own endpoint (the PUT response does not carry the diff)."
  - "Frontend: all rendering uses createElement/textContent exclusively — innerHTML is never assigned anywhere in the module — so no server-supplied field (reason, provenance, operation, before/proposed leaf_slug) can be interpreted as markup regardless of its content; a dedicated test asserts the module's source contains no `.innerHTML =` assignment."
  - "Frontend: closed-vocabulary tokens (outcome: pending/applied/conflict/skipped/rejected/rolled_back; counts: included/excluded/skipped/conflicted/changed/unchanged) are rendered verbatim as the literal token text, never remapped to invented copy — color/badge styling is supplementary, not the sole signal, and an outcome of conflict additionally shows a fixed warning sentence that it cannot be part of the apply set."
  - "Frontend: every render triggered by a checkbox toggle rebuilds the whole item list (required by the server-authoritative contract), which would otherwise drop keyboard focus back to the document body; the controller tracks the last-toggled seq and restores focus to its rebuilt checkbox after each server-driven re-render."
  - "Frontend: a monotonic per-controller sequence number guards both the plan render and the selected-diff render independently, so a slow or reordered response (from either endpoint) can never overwrite a newer one — mirroring the conversion-coverage.js 'last response wins' idiom."
requirements-completed: [BULK-03]
---

# Phase 05 Plan 05: Per-Item Bulk Plan Review and Selection Summary

**A maintainer can now open a stored bulk plan, select or reject each proposed change individually, and inspect the complete diff of exactly what is selected — entirely through MASTER_DATA_EDIT-gated reads and one closed selection-flag write, with no apply surface yet exposed.**

## Accomplishments

### Backend (commit `2e8fd61c`, prior to this wave's frontend work)

- Added `SetItemSelection($planId, $seq, $selected)` to `GrocyAiBulkService`: validates the plan is still reviewable (not `applied`/`rolled_back`) and on the current `ruleset_version`, validates `seq` against the plan's own stored items, coerces `$selected` to a strict boolean, and issues a single `UPDATE ... SET selected = ?` — idempotent, and no other column or table is ever touched.
- Added `ReadPlan($planId)` and `SelectedDiff($planId)`, both read-only: `ReadPlan` returns `{ plan, counts, items }` verbatim from stored rows; `SelectedDiff` returns `{ plan_id, checksum, operation_type, ruleset_version, included, items }` for currently-selected items only, with the plan `checksum` unchanged by any selection.
- Added `BulkPlan`, `BulkPlanSetItemSelection`, `BulkPlanSelectedDiff` to `GrocyAiApiController`, each `User::CheckPermission(...MASTER_DATA_EDIT)`-gated before any read, validating `planId`/`seq` with the existing `preg_match` idiom, and mapping `InvalidArgumentException`/`RuntimeException` to bounded 400/404/409 `GenericErrorResponse`s.
- Wired `GET /api/grocy-ai/bulk/plans/{planId}`, `PUT /api/grocy-ai/bulk/plans/{planId}/items/{seq}/selection`, `GET /api/grocy-ai/bulk/plans/{planId}/selected-diff` into `routes.php`, plus the standalone `GrocyAiBulkController::BulkReview` page controller and its placeholder Blade view.
- Extended the `bulk-selection` PHP suite: one-flag idempotent selection, a complete rejected-free selected diff with checksum stability, a full native/cache/taxonomy state snapshot proving zero-write beyond the single flag, and fail-closed coverage (unknown seq, non-boolean flag, unknown/stale/non-reviewable plan) at both the service and controller layer, including permission enforcement and closed-body rejection.

### Frontend (this wave)

- Replaced the placeholder `views/grocyai_bulkreview.blade.php` with the real Bootstrap 4 surface: a `#grocy-ai-bulk-summary` counts panel, a `#grocy-ai-bulk-items` per-item review list, and a `#grocy-ai-bulk-selected-diff` panel, each in its own labelled `<section>`. The outer `#grocy-ai-bulk-review` container keeps its `data-plan-id`, `data-plans-endpoint`, `permission-MASTER_DATA_EDIT` scoping, and the single `{{ $grocyAiAssetVersion }}` token (unchanged at `2.5.0`, matching `module-version.json`); the template declares no POST/PUT/DELETE form action — every write happens through `bulk-review.js`'s `fetch` calls to the Task 2 endpoints.
- Added `public/custom/grocy_AI/bulk-review.js` as a UMD module (matching the `conversion-coverage.js` shape): closed-contract validators (`isPlanPayload`, `isSelectedDiffPayload`) that fail closed to an empty, invalid presentation on any extra/missing key, out-of-range count, unknown outcome token, non-boolean `selected`, or duplicate item `seq`; DOM-free presentation builders (`describePlan`, `describeSelectedDiff`); a `createBulkReviewController` that drives load/toggle with a monotonic sequence guard so a stale response can never win; and pure DOM-writing renderers (`renderSummary`, `renderItems`, `renderSelectedDiff`) that build every node via `createElement`/`textContent` only.
- Each item renders as a card reusing the established `.grocy-ai-field-review` / `.grocy-ai-comparison-grid` / `.grocy-ai-value-cell` / `.grocy-ai-selection-control` classes from the Phase 2 review UI: object identity, operation, before/proposed `leaf_slug` values, reason, provenance, an outcome badge (raw token text, e.g. `conflict`), and a 44px `custom-checkbox` with an explicit `<label>` and `aria-describedby` pointing at the before/proposed/meta regions. A `conflict` outcome additionally renders a fixed `role="alert"` warning that the item cannot be part of the apply set.
- Toggling a checkbox calls the controller, which PUTs `{ selected }`, re-renders the summary and item list strictly from that response, then independently GETs and renders the selected diff from its own endpoint — never fabricating either from prior client state. Because the item list is fully rebuilt on every render, the controller tracks the last-toggled `seq` and restores keyboard focus to its rebuilt checkbox afterward.
- Added `public/custom/grocy_AI/bulk-review.test.js` (`node:test`, the repo's established runner) covering: correct presentation-building for a well-formed plan/selected-diff payload; fail-closed behavior for extended/truncated/duplicate-seq/wrong-type/unknown-outcome/malformed payloads; `load()` rendering both endpoints from independent responses; a toggle proving the PUT is called with exactly one `{seq, selected}` pair and both renders come from server responses (including an `object_id` the test never supplied, proving no local fabrication); a "last response wins" race across two overlapping toggles; a failed-PUT path that announces the toggle error with no render; a successful-PUT-but-failed-diff-refresh path that still shows the updated plan and only flags the diff error; and a static-source check that the module never assigns to `.innerHTML`.
- Added the `.grocy-ai-bulk-*` rules to `grocy-ai.css` (summary list, checksum line, outcome badges, item/diff card spacing, conflict note, night-mode and narrow-viewport overrides), reusing the shared 8pt/16-14-20px/44px-target vocabulary already established for the module rather than inventing a new visual language.

## Verification

- `node --test public/custom/grocy_AI/bulk-review.test.js` — `# pass 11` / `# fail 0`.
- `php8.5 custom/grocy_AI/tests/run.php bulk-selection` — `Bulk selection tests passed` (re-run against the real Blade view; unchanged from the backend commit).
- `php8.5 custom/grocy_AI/tests/run.php` (full default suite) — `All 122 grocy_AI checks passed`.
- `php8.5 custom/grocy_AI/tests/run.php --group blade` — `Blade integrated acceptance passed` (productform regression check; unaffected by this plan).
- Ad hoc `Illuminate\View\Compilers\BladeCompiler` compile of `views/grocyai_bulkreview.blade.php` — compiles and tokenizes as parseable PHP.
- `node --check public/custom/grocy_AI/bulk-review.js` — clean.

## Deviations from the plan

- The plan's file list names `custom/grocy_AI/tests/bulk.php` and `custom/grocy_AI/tests/run.php` as files this plan modifies for Task 3; those PHP test files were already extended by the backend commit (`2e8fd61c`) covering Tasks 1-2, and this wave's frontend work needed no further PHP test scaffolding — `bulk-selection` already exercises the service and controller directly, and the plan's Task 3 verification is the Node suite plus `php -l`/Blade-compile checks, not a new PHP test mode.
- `custom/grocy_AI/portable-files.txt` is not updated; the plan explicitly assigns manifest reconciliation to 05-11.

## Decision and next step

Selection and the selected diff are fully reviewable end to end: a maintainer can open a plan, toggle any item, and see the exact server-derived apply-set diff, with every render bound to a real response and no client-invented state. The apply set is currently "all selected items" (`included` = selected count); Plan 05-06 introduces the optimistic-concurrency apply transaction that re-reads current values, refuses conflicting items, and is where `SelectedDiff`'s `included` count starts subtracting apply-time conflicts. No apply, rollback, or export control exists on this page yet — `bulk-review.js` remains strictly read/selection-only per D-13.
