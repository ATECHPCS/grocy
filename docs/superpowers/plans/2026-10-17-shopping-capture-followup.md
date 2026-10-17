# Shopping Capture Review Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Native execution and repeated Opus review are user authorized.

**Goal:** Resolve the Opus functionality and flow findings and obtain an independent go.
**Architecture:** Retain durable operations, native row versions, existing approval and stock boundaries. Distinguish safe scan rejection from ambiguous writes; preserve progress through quantity edits using stable row identity.
**Tech Stack:** PHP 8.5, SQLite, vanilla JS, Playwright Chromium/WebKit.
**Spec:** docs/superpowers/specs/2026-10-16-shopping-list-capture-design.md

## Global Constraints
- Preserve legacy DTOs, explicit permanent catalog approval, operation retry identities and Commit purchase as the only stock write.
- Native shared edits must never be overwritten by stale Undo.
- All fixtures disposable; production smoke read-only; real phone acceptance remains separate.

## Review Focus
- Camera rejection before a write must retain camera input and curation draft.
- Multiple branded children require a deliberate choice.
- Native amount changes retain progress without granting stale completion ownership.
- Missing conversions and later confirmations must not let Undo reopen fulfilled rows.
- Off-list and mismatched scans need a clear path to normal capture or safe Undo.

### Task 1: Scanner and curation safeguards
**Files:** capture.js, capture-shopping-list.js, browser/specs/capture-shopping-list.spec.js under their existing directories.
**Interfaces:** shopping.scan rejects with safeToRetry only when no ambiguous mutation occurred; scanner retains queued read on safe rejection.
- [ ] Add failing browser cases for curation-open camera rejection, definitive 404, ambiguous response locking, multi-child blank selection and required location.
- [ ] Run focused browser regressions; expect current recovery/selection assertions to fail.
- [ ] Implement safe rejection classification and deliberate catalog choices, with UPC/product confirmation summary.
- [ ] Run focused browser suite; expect all pass; commit.

### Task 2: Durable progress and guarded Undo
**Files:** custom/grocy_AI/src/GrocyAiShoppingCaptureService.php, GrocyAiShoppingCaptureMigration.php, tests/shopping_capture_native.php.
**Interfaces:** existing service API; progress identity excludes requested amount but includes list/incarnation/product/unit. Existing serialized identities remain readable.
- [ ] Add failing real-schema tests for amount edits, null-target remaining confirmations and capture-only Undo after native note edit/deletion.
- [ ] Run native suite; expect current progress/reopen assertions to fail.
- [ ] Preserve contributions on amount edits; separate capture removal from guarded reopening; retain strict ownership before shared changes. Avoid poll-time bootstrap writes after migration completion.
- [ ] Run PHP shopping suites; expect all pass; commit.

### Task 3: Actionable recovery and release verification
**Files:** shopping service DTO/controller, capture-shopping-list.js, browser specs, release metadata and verification document.
**Interfaces:** additive shopping DTO product name/compatible row IDs; explicit idempotent detach keeps scan while cancelling list association.
- [ ] Add failing tests for mismatch reason/name, compatible-only choices, keep normal capture, unsettled Undo and conflict save feedback, decimal display and this-trip labeling.
- [ ] Run regressions; expect missing controls/copy assertions to fail.
- [ ] Implement clear states and recovery controls; focus saved unknown scan controls; preserve exact-match priority and expose already-collected state clearly.
- [ ] Run full PHP/Node/browser gates; expect all pass except documented pre-existing legacy contract modes; commit release verification.
- [ ] Obtain fresh Opus whole-branch review against original report and spec. User requests continuing fix/review rounds until go, overriding skill's one-review limit. Fix any verified blockers with RED/GREEN and repeat as necessary.
- [ ] Create linked follow-up PR into atech-release, verify registry and stop watch before handoff.
