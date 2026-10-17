# Shopping List Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let shoppers view a native Grocy list during capture, curate UPCs against its products, and safely record shared collection progress.

**Architecture:** Add a namespaced shopping-capture service and migration, a separate versioned API/controller, and a scoped scanner panel. Native lists and catalog mappings stay authoritative; durable scan operations distinguish capture, curation and collection settlement. Reuse current approval and stock boundaries.

**Tech Stack:** PHP 8.5, SQLite/PDO, existing LessQL/Slim, vanilla JavaScript, Blade and Playwright Chromium/WebKit. No new runtime dependencies.

**Spec:** `docs/superpowers/specs/2026-10-16-shopping-list-capture-design.md`

## Global Constraints

- Stock changes only at Commit purchase; no provider calls or production writes in tests.
- One remembered selected native list per trip; exact product matches precede direct generic-parent matches.
- Explicit immediate catalog approval; shared done state has guarded Undo and durable idempotency.
- Preserve exact existing capture/research/receipt DTO shapes and permission gates.
- Verified unit conversions only; ambiguous quantities require an explicit list-unit amount.
- Preserve prior restore-document edits in the main checkout; never remove the unmerged production-phases1-8 worktree.
- User explicitly authorized autonomous execution after written-spec approval, overriding repeated plan/execution confirmation steps. Use native execution with one fresh whole-branch reviewer.

## Review Focus

- Native list edits can produce ABA state changes; tombstoned monotonic versions must prevent stale collection and Undo (Task 1 tests).
- A saved scan can outlive a failed list settlement or catalog confirmation response; retry must not duplicate scans/products (Tasks 1–3 tests).
- Parent products and packaging units differ; only a verified conversion or explicit list-unit amount can satisfy a row (Task 1 tests).
- A later scan may still fulfill the row after Undo; do not reopen a fulfilled row or undo another shopper's state (Task 1 tests).
- A late browser response after a trip/list switch must not curate or collect into the new selection (Task 3 browser tests).

## Task 1: Durable shopping capture core

**Files:** Create `custom/grocy_AI/src/GrocyAiShoppingCaptureMigration.php`, `GrocyAiShoppingCaptureService.php`, and `custom/grocy_AI/tests/shopping_capture.php`; modify `GrocyAiCaptureService.php` and `GrocyAiCaptureResearchService.php` only for transaction-safe scan reuse and local targeted drafts if needed.

**Interfaces:** `GrocyAiShoppingCaptureService(PDO $db, bool $bootstrap = true)` exposes `Read(int $tripId, string $actor): array`, `SelectList(int $tripId, ?int $listId, string $actor, bool $confirmChange = false): array`, `Scan(int $tripId, string $actor, string $operationId, string $barcode, ?int $rowId, ?string $expectedVersion): array`, `Settle(int $tripId, string $actor, string $operationId, int $rowId, string $expectedVersion, ?float $listAmount = null): array`, `Undo(int $tripId, string $actor, string $operationId): array`, `SetDone(int $tripId, string $actor, string $operationId, int $rowId, string $expectedVersion, bool $done): array`, and `Operation(int $tripId, string $actor, string $operationId): array`.

`Read` returns contract_version 1, selected_list_id, lists, items, operations and can_change_list. Items carry id/list_id/product_id/name/note/amount/qu_id/unit/done/version/collected_amount/remaining_amount/has_children. Operations carry operation_id/state/line_id/row_id/product_id/needs_amount and a closed result/error code. Define and pin exact DTO keys in tests before UI consumption. DTO numeric IDs are integers and nullable references remain null. Do not include native credentials or other users' trips.

- [ ] Write a fixture-backed PHP suite with explicit assertions: selecting/reading never scans or writes stock; exact match beats parent; known wrong owner is never reassigned; two rows require choice; three one-unit scans complete amount 3; an identical operation retry leaves quantity 3; a new operation increments; missing conversions stay pending until explicit amount; all foreign/cancelled/committed trip mutations reject.
- [ ] Run `php8.5 custom/grocy_AI/tests/shopping_capture.php`; observe the missing-service RED.
- [ ] Implement additive tables for selection, immutable operation identity/request, contribution state, audit and native-row versions/tombstones; install native insert/update/delete version triggers. Use parameterized statements and guarded transactions. Keep the migration idempotent.
- [ ] Implement the interfaces, native list reads, exact/parent eligibility, verified conversions, explicit amount handling and state recovery. Reuse capture coalescing and auditing; targeted unknowns get local curation state without starting provider work. Preserve untargeted research behavior.
- [ ] Extend tests for native ABA/delete/recreate, concurrent amount/unit/product changes, pre-done rows, repeated Undo, later contributions fulfilling after Undo, allocation-history/reviewing rejection, selection changes, list deletion, free-text manual done, nonfinite inputs, operation ID input mismatch, captured-but-conflicted settlement, and no mutations to other shoppers' trips. Assert no stock changes for every operation.
- [ ] Run the focused suite and existing `capture_dashboard.php`, capture/research/receipt tests affected by scan reuse; commit `feat: add durable shopping-list capture operations`.

## Task 2: API and immediate catalog curation

**Files:** Create `custom/grocy_AI/src/GrocyAiShoppingCaptureController.php` and `custom/grocy_AI/tests/shopping_capture_api.php`; modify `custom/grocy_AI/routes.php`, `GrocyAiCaptureProductService.php` / research service only as needed to reuse established explicit approval.

**Interfaces:** Add under `/api/grocy-ai/capture/trips/{tripId}/shopping`: GET root, POST `/selection`, POST `/scan`, GET `/operations/{operationId}`, POST `/operations/{operationId}/settle`, POST `/operations/{operationId}/undo`, POST `/done`, and POST `/operations/{operationId}/curate`. JSON writes reject extra keys. `curate` accepts existing-product mode or branded-child approval fields and an expected draft/operation revision; it uses current canonical ownership, native product/barcode creation, parent compatibility and audit behavior. Its response includes saved catalog outcome plus current operation; subsequent settlement never repeats approval.

- [ ] Write controller integration tests for authentication-derived actors, STOCK_PURCHASE plus SHOPPINGLIST reads, SHOPPINGLIST_ITEMS_ADD collection writes, MASTER_DATA_EDIT catalog writes, invalid IDs/types/extra keys, and consistent 400/403/404/409 failures. Requests cannot pass actor or arbitrary SQL identifiers.
- [ ] Run the new suite; witness RED for missing controller/routes.
- [ ] Implement controller methods calling Task 1 interfaces; load/route new classes without changing legacy response contracts. Add targeted draft creation/adaptation to current product approval logic rather than a parallel barcode ownership policy.
- [ ] Test immediate existing-product linking, original/canonical UPC conflict, branded-child create under existing parent, required fields and unit incompatibility, permission-denied curation, local-only behavior, approval retry and settlement failure recovery. Assert product creation/link happens once and stock remains unchanged.
- [ ] Run both shopping suites and the existing product approval/research suites, PHP lint and diff check; commit `feat: expose shopping capture and UPC curation API`.

## Task 3: Scanner list panel and curation controls

**Files:** Create `public/custom/grocy_AI/capture-shopping-list.js`; modify `capture.js`, `views/grocyai_capture.blade.php`, `public/custom/grocy_AI/grocy-ai.css`, and the browser fixture server; add `custom/grocy_AI/tests/browser/specs/capture-shopping-list.spec.js` and a focused fixture as needed. Reuse product approval field validation/choices; extract a shared helper only when necessary, preserving review behavior.

**Interfaces:** `window.GrocyAiShoppingCapture` is a small coordinator attached to the scanner root, with `load(tripId)`, `scan(barcode): Promise<legacy capture line>`, `hasPendingWrites(): boolean`, `hasUnsavedDrafts(): boolean`, and a settled-line callback to the existing scanner renderer. The shopping coordinator routes list-aware scans to Task 2; capture without a list retains its existing POST. Keep target/list/trip request generation guards and durable operation IDs across ambiguous responses. Local curation controls collect the existing approval fields and never send unsolicited provider requests.

- [ ] Add failing browser cases for selected list persistence, choosing a row before camera/manual scan, 1 of 3 progress/shared done, direct and branded-child confirmation, free-text manual done, known product mismatch, no-list fallback, and explicit stock commit boundary. Run Chromium focused cases and observe RED before UI implementation.
- [ ] Implement the collapsible list panel, row targeting, progress and collected visibility. Render dynamic data with text nodes. Add safe list-switch confirmation and disable/reconcile ambiguous scans instead of generating a new operation ID.
- [ ] Implement curation modal/panel with Link existing product and Create branded child choices, required approval fields, parent preselection, permissions and explicit confirmation. Keep target cleared after a settled scan and retain failed drafts. Display catalog-saved/list-pending outcomes separately.
- [ ] Implement quantity confirmation, manual done and Undo; include their pending/draft state in existing camera and Back/finish safeguards. Explain persistent list/catalog effects during trip cancellation when relevant. Refresh shared state only while visible and do not overwrite edits or allow stale response ownership.
- [ ] Add tests for double taps, lost scan/approval/settlement responses, Undo conflict/partial quantities, list deleted elsewhere, another shopper done, trip/list switch during save, keyboard focus, 320/390px/no overflow, light/night and 44px controls. Verify Chromium and WebKit focused suites.
- [ ] Commit `feat: shop and curate UPCs from capture sessions`.

## Task 4: Release integration and complete verification

**Files:** Modify `custom/grocy_AI/tests/run.php`, `custom/grocy_AI/README.md`, `CUSTOMIZATIONS.md`, capture asset references and related fixtures; record results in `docs/superpowers/plans/2026-10-16-shopping-list-capture-verification.md`. Verify Dockerfile directory COPY includes new assets/classes.

- [ ] Add module registration/packaging checks and advance capture asset token and customization marker using the next available values after inspecting HEAD. Record this feature's native-list version triggers and backup/restore requirements.
- [ ] Run `php8.5 custom/grocy_AI/tests/run.php`, both shopping PHP suites and affected standalone capture/research/receipt suites, `node --test public/custom/grocy_AI/*.test.js`, changed PHP/JS syntax checks, and `git diff --check`. Expected: all pass, with any pre-existing real-data snapshot skips described accurately.
- [ ] Run the full serial browser release suite with `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test:release -- --workers=1`. Inspect the complete log for failure blocks. Inspect generated populated light/night screenshots.
- [ ] Obtain one fresh whole-branch code review using the most capable available model through the current provider catalog. Include this plan, the approved spec, baseline SHA, progress ledger and Review Focus. Fix important findings with RED→GREEN coverage, then run affected suites and required final gates.
- [ ] Commit `docs: verify shopping-list capture release`. Create/link a draft PR into `atech-release` if the GitHub remote is accessible. Do not merge/deploy automatically unless the current session's authorization clearly covers the new feature's production rollout; otherwise the tested PR is the concrete reviewable result. Do not create a real product or purchase in production during smoke.

## Execution record

Use the executing-plans workspace ledger to preserve task bases, RED/GREEN evidence, tests and any rulings. All four tasks implement one connected subsystem and use the exact service/controller contracts above. The user requested uninterrupted autonomous execution; no further design or plan approval checkpoint is needed unless implementation exposes a genuinely unresolved user-facing decision.
