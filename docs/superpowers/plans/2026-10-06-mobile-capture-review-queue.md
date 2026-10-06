# Mobile Capture Review Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the long mobile purchase review with one scanned item or receipt-only item at a time, including its receipt match, quantity, and price controls.

**Architecture:** A pure queue builder derives stable cards and a single owner for each receipt-line editor from existing trip and receipt payloads. The receipt component reuses its current save and draft behavior while mounting line editors into the corresponding queue cards; the review controller owns active-card navigation and keeps the existing trip-level readiness and commit boundary.

**Tech Stack:** Grocy PHP 8.5/Blade, plain browser JavaScript and CSS, Bootstrap 4, Playwright browser fixtures, standalone PHP contracts.

**Spec:** `docs/superpowers/specs/2026-10-06-mobile-capture-review-queue-design.md`

## Global Constraints

- Work in the existing isolated Grocy worktree on `codex/mobile-capture-review-queue`, based on `origin/atech-release`; preserve the user's other worktrees.
- Keep new behavior in `public/custom/grocy_AI/` and the capture review Blade view; document and version the fork boundary.
- No new server write endpoint, schema change, auto pairing, auto product approval, or stock write. Existing receipt readiness and Commit purchase remain authoritative.
- The saved pairing takes precedence over active allocation links. A receipt line has exactly one editable DOM instance. Multi-scan allocations use a dedicated receipt card.
- Mobile is below Bootstrap `md` (768px). At 320px and 390px, no horizontal page overflow; buttons and form controls meet the established 44px touch target.
- Keep original UPC visible, current permissions and double confirmations intact, and desktop review usable.
- A failed save preserves edits and position. A late response cannot reset a newer selection. Card navigation never implicitly saves or discards edits.

## Review Focus

- One receipt line allocated to two scans appears once as an editable receipt card, with links from both scan cards; Task 1 model and Task 3 browser tests.
- An unpaired receipt-only item and a tax/savings adjustment needing review remain in the queue; Task 1 and Task 3 tests.
- Swipe over a number input or vertically scrolling card does not change cards; Task 3 tests.
- Unsaved receipt edits survive navigation and keep Commit purchase disabled; a rejected save stays on the same card; Task 2 and Task 3 tests.
- A delayed older trip/research/receipt response does not change the active key after a successful save or newer trip selection; Task 3 tests.

---

### Task 1: Derive stable review cards

**Files:**
- Create: `public/custom/grocy_AI/capture-review-queue.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js`

**Interfaces:**
- Produce `GrocyAICaptureReviewQueue.build(lines, receiptViews): { cards, receiptOwnerByKey }`, where each card has `key`, `kind`, `scanLineId` or `receiptId`/`receiptLineId`, and `receiptKeys`; each receipt key is `receipt:<receipt id>:<line id>` and maps to one card key.
- Produce `GrocyAICaptureReviewQueue.retain(cards, previousKey, previousIndex): string | null`: keep a surviving key, otherwise choose the nearest surviving index, otherwise null.
- `kind` is `scan`, `receipt`, or `adjustment`; scan keys are `scan:<line id>`. Input is the existing closed capture lines and `receiptReadiness.receipts` view shape. An adjustment requiring review gets its own card.

- [ ] **Step 1: Write failing queue tests.** Load the queue script directly in the Playwright fixture for this task. Assert a paired one-to-one line belongs to its scan card, a line allocated to two scan IDs has one receipt card and references on both scans, an unpaired item and a needs-review adjustment each have cards, and `retain()` preserves keys after reorder/removal.
- [ ] **Step 2: Run red checks.** Focus the new queue cases in both `purchase-capture.spec.js` and `purchase-receipts.spec.js`; they fail because the queue module is absent.
- [ ] **Step 3: Implement the pure queue builder.** Use saved `paired_capture_line_id` first, otherwise distinct active allocation `capture_line_id`s. Do not infer a match from display text or UPC. Give every receipt line exactly one owner and keep queue order deterministic: scan sequence, then unowned receipt lines by receipt and line order.
- [ ] **Step 4: Run green checks.** Both focused cases pass in Chromium mobile and WebKit mobile; `node --check public/custom/grocy_AI/capture-review-queue.js` passes.
- [ ] **Step 5: Commit.** `feat: derive stable mobile capture review queue`.

### Task 2: Reuse receipt editors inside cards

**Files:**
- Modify: `public/custom/grocy_AI/capture-receipts.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js`

**Interfaces:**
- Extend `GrocyAIReceipts(host, options)` with optional `options.lineHostFor(receipt, line): Element | null` and `options.inputRoot: Element`. When provided, receipt-level summaries remain in `host`, each line editor mounts once in the returned card host, and no full receipt-line list is appended under the summary.
- Return `{ dispose(): void, hasUnsavedEdits(): boolean }`. `dispose` removes any event listener attached to `inputRoot`; existing callers that ignore the return value remain valid. Preserve `options.state` drafts/uploads, existing API requests, `onBusy`, and reload semantics.

- [ ] **Step 1: Write failing browser cases.** Invoke `GrocyAIReceipts` with test card hosts and `lineHostFor` in the fixture. Assert one-to-one editors are inside scan cards, multi-scan and unpaired editors appear only in their own receipt cards, summaries retain merchant/store/image/totals/difference/Finish controls, unsaved edits survive card changes and a rejected save, and existing desktop receipt editing still works without `lineHostFor`.
- [ ] **Step 2: Run red checks.** Focus the new `purchase-receipts.spec.js` cases in Chromium mobile and WebKit mobile; the current renderer places all editors in the vertical receipt list.
- [ ] **Step 3: Extract the receipt-line mounting boundary.** Reuse the existing `renderLine` and allocation editor for both destinations. Attach input tracking once to `inputRoot || host`; return a disposer to prevent duplicate handlers after reload. In queue mode, render receipt-level controls in `host` and each line through `lineHostFor` without duplicating the editor.
- [ ] **Step 4: Run green checks.** Focused receipt tests pass, existing receipt tests pass, and `node --check public/custom/grocy_AI/capture-receipts.js` passes.
- [ ] **Step 5: Commit.** `refactor: mount capture receipt editors in review cards`.

### Task 3: Mobile card navigation and review integration

**Files:**
- Modify: `public/custom/grocy_AI/capture-review.js`
- Modify: `public/custom/grocy_AI/capture-product-research.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js`

**Interfaces:**
- Consume `GrocyAICaptureReviewQueue.build()` and `.retain()` from Task 1 and `GrocyAIReceipts(...).dispose()/hasUnsavedEdits()` from Task 2.
- Keep `activeReviewKey` and index in `attachCaptureReview()`. Render one visible card below 768px, with `#grocyai-review-prev`, `#grocyai-review-next`, `#grocyai-review-progress`, and a status/live region. Use existing line, research, receipt, readiness, and commit handlers.
- Pass `options.editState` (a trip-scoped object keyed by capture line ID) to `GrocyAIProductResearch` so unsaved research field values survive an unrelated receipt save/re-render; clear only fields successfully saved, approved, or linked.

- [ ] **Step 1: Write failing browser cases.** At 320px and 390px, assert one card visible, no horizontal overflow, 44px controls, UPC/research plus related receipt price in one card, receipt-only Include/Ignore, multi-receipt progress, and trip-level totals/readiness/commit. Assert button and edge swipe navigation, focus/announcement, no swipe from form fields or vertical scroll, first unresolved card on trip switch, stable active key after save/reload, stale-response guard, legacy four-field research results, unchanged permission/double-confirm gates, and unsaved/failed edit behavior.
- [ ] **Step 2: Run red checks.** Focus the new mobile cases in Chromium and WebKit; current page displays the full vertical list.
- [ ] **Step 3: Implement mobile orchestration.** Build and mount all stable cards once per trip render; attach research to scan cards and receipt editors by `lineHostFor`; hide inactive cards only at mobile widths. Preserve pending research fields through unrelated re-renders with `editState`. Use a horizontal gesture threshold on non-interactive card surfaces and explicit Previous/Next controls. Preserve active key through refresh and keep desktop expanded controls usable.
- [ ] **Step 4: Run green checks.** Focused mobile and existing capture/receipt cases pass in Chromium mobile, Chromium desktop, and WebKit mobile; JS syntax and CSS width assertions pass.
- [ ] **Step 5: Commit.** `feat: review capture items one card at a time on mobile`.

### Task 4: Version, verify, and release

**Files:**
- Modify: `views/grocyai_capture_review.blade.php`
- Modify: `custom/grocy_AI/tests/browser/fixtures/capture-review.html`
- Modify: `custom/grocy_AI/version.json`
- Modify: `custom/grocy_AI/README.md`
- Modify: `CUSTOMIZATIONS.md`
- Test: `custom/grocy_AI/tests/run.php` only if its exact asset/marker assertion needs updating

**Interfaces:**
- Consume Tasks 1–3 UI contracts. Load `capture-review-queue.js` before `capture-review.js` and advance the capture review asset token and customization marker together.

- [ ] **Step 1: Write failing version/asset assertions.** Check the queue script order in Blade and browser fixture, current review token, and marker in the existing module test or Blade fixture; run it red.
- [ ] **Step 2: Update Blade and docs.** Document mobile queue, receipt-only and multi-scan behavior, gesture/button fallback, save/approval/commit boundaries; bump token and marker monotonically.
- [ ] **Step 3: Run final gates.** `php8.5 custom/grocy_AI/tests/run.php`, relevant standalone capture/receipt PHP contracts, serial `npm test` in `custom/grocy_AI/tests/browser`, changed JS `node --check`, PHP lint if changed, and `git diff --check` pass.
- [ ] **Step 4: Obtain independent whole-branch review.** Resolve Critical/Important findings and rerun affected gates; commit release docs/version.
- [ ] **Step 5: PR and deploy.** Push `codex/mobile-capture-review-queue`, create and link a PR into `atech-release`, merge after tests/review, deploy the Grocy Komodo stack, and perform read-only live smoke. Verify trip #12, receipt and queue visibility, and unchanged product/stock counts. Do not approve products or Commit purchase.
