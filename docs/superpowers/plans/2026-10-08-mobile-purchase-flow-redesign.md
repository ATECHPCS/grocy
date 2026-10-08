# Mobile Purchase Flow Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Capture, Review, and purchase confirmation a consistent, accessible phone layout while retaining the existing explicit catalog and inventory write boundaries.

**Architecture:** Restyle the existing Blade/Bootstrap/plain JavaScript surfaces with scoped shared CSS. Preserve mounted queue editors and trip-scoped draft state; add a confirmation presentation stage to the same review controller, using its current server readiness, checksum, and commit handler. Execute the three tasks sequentially with an independent review after each.

**Tech Stack:** Grocy PHP 8.5/Blade, Bootstrap 4, plain JavaScript/CSS, existing Playwright Chromium/WebKit fixtures and standalone PHP contracts.

**Spec:** `docs/superpowers/specs/2026-10-08-mobile-purchase-flow-redesign.md` (approved in conversation; its “Draft” status predates approval).

## Global Constraints

- “Use the current Grocy PHP/Blade, plain JavaScript, and Bootstrap runtime.” “Do not introduce React, Tailwind, or a second UI runtime.” No dependency changes.
- “Production remains on `atech-release`.” Implement on `codex/mobile-flow-design`; the controller handles integration after review.
- “Keep feature code under `custom/grocy_AI/` and `public/custom/grocy_AI/`, using the existing capture/review Blade hooks.”
- “Reuse current API calls, permissions, receipt editor state, queue identities, and server readiness checks.” “This redesign adds no provider calls, schema changes, unit conversions, or automatic writes.”
- “Use a consistent spacing scale: 4, 8, 12, 16, and 24 pixels.” “Card padding is 16 pixels on ordinary phones, with a compact 12-pixel variant at 320 pixels.”
- “Interactive controls have at least 44-pixel touch targets.” “Adjacent actions have at least 8 pixels of space; label groups have at least 12 pixels between them.”
- “Verify 320, 390, and 430-pixel phone widths and a desktop viewport.” Keep the existing mobile breakpoint below 768px and expanded desktop receipt editing.
- “One receipt line has one authoritative editor.” “Card navigation neither saves nor discards edits.” Preserve server-scoped blockers, draft guards, stale-response guards, permissions, and all existing confirmations.
- “Bottom action areas respect device safe-area insets.” “Reserve page space for their full height so they cannot cover content.” Prefer a footer in normal document flow; any sticky treatment must yield to keyboard/focused controls.
- “Record physical-phone acceptance separately from automated checks.” “Do not approve a real product or commit household stock as an automated deployment smoke test.” All write-path testing uses isolated fixtures.
- No backend/service/routes edits. Deferred paid research refresh, package persistence, conversion creation, or speculative stock conversion calculations remain out of scope.

## Review Focus

- Core scanner assumes its generated trigger's preceding sibling is the barcode input; preserve that DOM contract when putting Scan barcode visually first (Task 1).
- Repeated UPCs increment one capture line; show separate scan-event and total-quantity counts without treating distinct line count as individual scans (Task 1).
- Late component responses, failed saves, and trip changes must preserve newer typed values, correct editor ownership, and disclosure state (Task 2).
- Receipt-only stock allocations, ignored receipt amounts, adjustments, and multiple prices for one product must not produce a misleading stock subtotal or combined unit quantity (Task 3).
- A commit timeout, partial outcome, mismatch, or late success must retain safe recovery, prevent concurrent submission, and never present a committed trip as ready to commit again (Task 3).

---

### Task 1: Capture layout and shared visual system

**Files:**
- Modify: `views/grocyai_capture.blade.php`
- Modify: `public/custom/grocy_AI/capture.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css`
- Modify: `custom/grocy_AI/tests/browser/fixtures/capture.html`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`

**Interfaces:**
- Consume current `attachCapture(document)`, `render()`, `submitBarcode(rawBarcode, failClosed)`, `finishScanning()` and `Grocy.BarcodeScanned(barcode, target)` behavior; preserve all existing control IDs and `data-target="grocyai-capture-barcode"`.
- Produce root class `.grocy-ai-purchase-flow` on `#grocyai-capture`; Task 2 adds it to review. Scoped CSS variables: `--grocy-ai-flow-surface`, `--grocy-ai-flow-border`, `--grocy-ai-flow-text`, `--grocy-ai-flow-muted`, `--grocy-ai-flow-primary`, `--grocy-ai-flow-radius`; shared `.grocy-ai-flow-card`, `.grocy-ai-flow-actions`, `.grocy-ai-flow-footer`, `.grocy-ai-flow-badge`, `.grocy-ai-flow-upc`. Resolve existing Grocy light/night-mode theme; no global Bootstrap overrides.
- Produce `#grocyai-capture-trip-summary`, `#grocyai-capture-latest`, `#grocyai-capture-show-all`, and a trip-actions disclosure. Keep `#grocyai-capture-lines` as the authoritative list; compact mode shows the three most recent distinct lines. Latest card tracks the last successfully accepted line, including an incremented earlier UPC.
- Counts label distinct items and total quantity separately; individual scan events equal quantity only for the automatic one-unit scan increments. A resumed trip whose quantities may have been edited must label quantity honestly rather than fabricate historical scan-event totals.

- [ ] **Step 1: Add failing `@flowcapture` browser tests.** Assert Scan barcode precedes manual entry visually, is full width on phones, and its native trigger retains the input immediately before it in DOM. Assert manual and camera UPC `012345678905` retains the leading zero; confirmation/edit still precedes camera POST. Scan five distinct UPCs and repeat the first: latest card is the first UPC, compact list has three recent lines, Show all exposes five, quantity is six, and disclosure survives a product-name refresh. Assert start/delete are in trip actions, cancellation sends no mutation, Finish retains both confirmations and pending/ambiguous-save guards. For an edited resumed quantity, assert truthful quantity labels. At widths 320/390/430 and 1280 assert no overflow, 44px controls, 8px action gaps, 12px label spacing, long names wrap, focus is visible, and light/night-mode surfaces and text remain readable.
- [ ] **Step 2: Run the new tests red.** `npm --prefix custom/grocy_AI/tests/browser test -- specs/purchase-capture.spec.js --grep @flowcapture --workers=1`; expect missing new summary/latest/disclosure or layout assertions to fail, not fixture errors.
- [ ] **Step 3: Implement shared tokens and capture rendering.** Add semantic/localized Blade labels and the shared root/classes; update `render(): void` to render header/latest/recent summaries without additional fetches or writes. Use CSS ordering/layout for the generated scanner trigger without breaking core `.prev()` lookup or modifying the core scanner. Keep native confirmation controls and scanner disabled state. Add Delete trip using the existing `/trips/:id/cancel` POST, same two-confirmation/read-only/applied-scan restrictions as review `cancelTrip()`, with no automatic cancellation. Preserve typed UPC and stop-and-review state on failed/ambiguous saves. Footer leads to Review trip; open/reviewing trip state determines Continue/Finish visibility. Do not claim research state unless supplied by existing data; capture can report Saved or Needs details for unknown scans.
- [ ] **Step 4: Verify green and existing safeguards.** Run the focused command, then `npm --prefix custom/grocy_AI/tests/browser test -- specs/purchase-capture.spec.js --workers=1`, `node public/custom/grocy_AI/capture.test.js`, `node --check public/custom/grocy_AI/capture.js`, and `git diff --check`. Browser cases use `page.setViewportSize` for desktop because the existing desktop project only matches `product-research.spec.js`. Preserve the fixture's zero-write harness. Record automated focus/viewport results; physical keyboard/camera acceptance remains separate.
- [ ] **Step 5: Commit only Task 1 files.** `git commit -m "feat: refresh mobile capture layout and purchase flow tokens"`; independently review before Task 2.

### Task 2: Organize review cards and receipt overview

**Files:**
- Modify: `views/grocyai_capture_review.blade.php`
- Modify: `public/custom/grocy_AI/capture-review.js`
- Modify: `public/custom/grocy_AI/capture-product-research.js`
- Modify: `public/custom/grocy_AI/capture-receipts.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css` (consume Task 1 tokens)
- Modify: `custom/grocy_AI/tests/browser/fixtures/capture-review.html`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js`

**Interfaces:**
- Consume Task 1 shared classes/tokens; existing `GrocyAICaptureReviewQueue.build(lines, receiptViews): { cards, receiptOwnerByKey }` and `retain(cards, previousKey, previousIndex): string | null` remain unchanged.
- Consume `GrocyAIProductResearch(host, options)` with existing trip-scoped `editState`, `onEdit`, `reload(notice, seq)`, `dispose()`; keep its request payloads unchanged. Add optional `options.sectionHostFor(lineId: number, section: 'research' | 'actions'): Element | null` so research and action sections can mount around the existing receipt editor. Fallback remains current mounting for callers without this option.
- Review renders `.grocy-ai-flow-participation`, `.grocy-ai-flow-research`, `.grocy-ai-flow-receipt`, `.grocy-ai-flow-product-actions` within each scan card in that order. `lineHostFor(receipt, line)` returns the owner's receipt section; never clone editors. Required creation fields precede optional disclosure, while product actions follow receipt review.
- Produce internal `selectReviewTarget(target: { cardKey?: string, receiptId?: number }, focus: boolean): boolean` in `attachCaptureReview()`: select a stable card through existing navigation or expand/focus `#grocyai-receipt-details-<id>`; return false if target no longer exists. Task 3 consumes this closure for blocker navigation.
- Produce `#grocyai-review-next-unresolved` and `#grocyai-review-summary-button` (“Continue to purchase summary”). The latter safely leads to existing readiness/commit section until Task 3 replaces it with the full summary stage. Keep `#grocyai-review-prev/next/progress`, stable `data-review-key`, and receipt details IDs.
- Keep trip-scoped UI disclosure state separate from research edits and receipt drafts: `{ [tripId]: { [disclosureKey]: boolean } }` in review; pass optional `options.disclosures` to receipt/research components. Disclosure toggles do not count as unsaved edits or write anything.

- [ ] **Step 1: Add failing `@flowreview` tests.** Assert compact counts/upload/receipt merchant-total-difference-status summaries and View receipt; one active phone card; prescribed section order and distinct Save research draft/Approve new product/Link existing product actions. Missing unit candidates display “No suggestion available” beside manually editable units; four required controls retain red asterisks and `aria-required`, optional notes/evidence remain optional. Assert Jump to next unresolved follows server blockers and wraps, no target leaves current card unchanged; shared allocation editor appears once and links select its owner; header blockers expose receipt details. Assert button navigation, swipes exclude fields/vertical gestures, disclosures and drafts survive navigation/unrelated reload, rejected save retains values, late previous-trip responses cannot overwrite current trip, and Product created with ID survives approval reload without claiming stock. Test receipt-only Include/pair/link/Disregard confirmation, active allocation removal guard, labeled adjustments, permissions and two approval confirmations. Cover 320/390/430 plus 1280, light/night mode, 44px controls and no overflow.
- [ ] **Step 2: Run red.** `npm --prefix custom/grocy_AI/tests/browser test -- specs/purchase-capture.spec.js specs/purchase-receipts.spec.js --grep @flowreview --workers=1`; expect new grouping/navigation/copy assertions to fail.
- [ ] **Step 3: Implement review organization.** Refactor DOM grouping inside current `renderDetail()` and component renderers, retaining state stores, disposal and generation guards. Move required controls before optional details and action hosts after related receipt editors, without changing save/approve/link requests or verified unit compatibility. Add compact trip/receipt badges with text plus color, explicit source/approval messages, next-unresolved navigation, and review footer. Route failures to responsible action feedback with focus/live announcements; preserve research notices after reload. Implement `selectReviewTarget` and disclosure state using existing receipt toggle controls. Keep desktop editors expanded and readable; retain current readiness-based commit gate until Task 3.
- [ ] **Step 4: Verify green and contracts.** Run focused tests, then both complete spec files serially; `node public/custom/grocy_AI/capture-review.test.js`; `node --check` each changed JS file; `git diff --check`. Confirm navigation/disclosure/summary-entry actions produce no fixture mutations and actual user save paths retain their existing request bodies.
- [ ] **Step 5: Commit only Task 2 files.** `git commit -m "feat: organize mobile purchase review cards and receipt overview"`; independently review before Task 3.

### Task 3: Purchase summary, commit states, and release integration

**Files:**
- Modify: `public/custom/grocy_AI/capture-review.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css`
- Modify: `views/grocyai_capture.blade.php`
- Modify: `views/grocyai_capture_review.blade.php`
- Modify: `custom/grocy_AI/tests/browser/fixtures/capture.html`
- Modify: `custom/grocy_AI/tests/browser/fixtures/capture-review.html`
- Modify: `custom/grocy_AI/version.json`
- Modify: `custom/grocy_AI/tests/run.php` (exact asset/marker assertions only)
- Modify: `custom/grocy_AI/README.md`
- Modify: `CUSTOMIZATIONS.md`
- Create: `docs/superpowers/plans/2026-10-08-mobile-purchase-flow-verification.md` (check evidence and separate physical-phone acceptance)
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`
- Test: `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js`

**Interfaces:**
- Consume Task 2 `selectReviewTarget`, `#grocyai-review-summary-button`, mounted editors and trip-scoped state. Add internal `setReviewStage(stage: 'review' | 'summary', target?: { cardKey?: string, receiptId?: number }): void`; hide/show stage containers without disposing editors, saving edits, or changing the active card. Reset stage on trip change; preserve stage on same-trip reload.
- Produce `#grocyai-purchase-summary`, `#grocyai-purchase-summary-back`, and grouped outstanding-check Review buttons with stable target keys. Preserve `#grocyai-capture-review-commit`, `#grocyai-capture-review-commit-result`, `#grocyai-receipt-readiness` and their accessible links inside summary. Only summary shows the final commit trigger.
- Consume existing receipt `totals.printed_total/entered_total/difference`, `difference_accepted_amount`, line `decision/kind`, and active allocation `product_id/quantity/unit_price/capture_line_id`; display reviewed purchase quantities by product and purchase unit. Resolve names/unit labels through existing read-only `/api/objects/products/:id` and `/api/objects/quantity_units` routes/cache; unavailable metadata is explicitly unavailable. Never infer stock conversion from package text or combine unlike units/prices. Allocation rows are authoritative reviewed amounts; selected scans awaiting allocation are shown as needing review, never double-counted. Catalog creation notice is separate from purchase quantities.
- Preserve `POST /trips/:id/commit` with `{ confirmed_checksum: currentChecksum }`, confirmation, permissions, authoritative readiness and unsaved guards. Introduce separate `commitInFlight: boolean` so rerender/component busy events cannot unlock pending commit. Programmatic guard also rejects `currentTrip.status === 'committed'`. Existing committed/partial/checksum_mismatch/receipt_review_required/commit_failed outcomes remain supported.
- Release both capture/review and matching fixture asset tokens to `2.6.18`; customization marker to `ATECHPCS-grocy_AI-41`. Keep portable `module-version.json` unchanged; maintain receipt/research/queue/controller script order.

- [ ] **Step 1: Add failing `@flowsummary` tests and marker assertions.** Summary is inspectable while blocked, entry/back emits zero writes, returns to exact card, and keeps unsaved receipt/research drafts guarded. Two receipts with same product at different prices show quantities by unit and individual reviewed prices without duplicating paired scans; include receipt-only allocations, ignored amount, tax/savings adjustments, missing metadata, reconciled/accepted differences, and separated Product created notice. All checks are retained behind compact disclosure; capture and receipt-line blockers return to their card; receipt-header blockers return to receipt detail; unrecognized/global reasons retain readable text and review fallback. Assert commit is disabled for missing readiness/checksum, edits and pending work; repeated click/programmatic triggers create at most one request. Exercise delayed success, HTTP failure, ambiguous timeout, checksum mismatch, receipt-required, partial and committed reload; committed shows “Purchase committed”, transaction if present, View inventory and Start new trip, and no recommit. At all three phone widths and desktop assert summary layout, reachable/focused actions, progress announcements, no footer overlap/overflow and light/night mode. Update PHP asset/marker assertions to the new exact values first and witness red.
- [ ] **Step 2: Run red.** `npm --prefix custom/grocy_AI/tests/browser test -- specs/purchase-capture.spec.js specs/purchase-receipts.spec.js --grep @flowsummary --workers=1`; `php8.5 custom/grocy_AI/tests/run.php`. Expect missing summary and old token/marker failures.
- [ ] **Step 3: Implement summary presentation and guarded commit states.** Build summary from current loaded payloads, not unsaved form values. Clearly label purchase-unit quantities and reviewed prices, full receipt audit totals versus included purchases, and catalog actions versus “Commit purchase adds the reviewed items to stock.” Reuse server reason mapping and Task 2 target navigation; no client totals calculation may override readiness. Keep pending/error/success result in trip-scoped UI state across same-trip reloads. Failure uses current safe reload/recheck recovery, never automatic retry; re-evaluate the complete gate rather than blindly enabling the button. Handle partial purchases as partial, preserving remaining review, and keep stale commit responses isolated from newly selected trips. Implement final actions using current Grocy inventory URL helper and capture start route.
- [ ] **Step 4: Integrate release metadata and docs.** Synchronize both views and fixture asset URLs to `2.6.18`, marker to `ATECHPCS-grocy_AI-41`, and exact PHP assertions. Document capture/review/summary, truthful counts/units, zero automatic writes, recovery and stock/catalog boundaries. Record automated evidence and physical-phone checks as performed or explicitly pending; do not claim physical checks from viewport simulation.
- [ ] **Step 5: Run green and final gates.** Run focused summary tests, then `npm --prefix custom/grocy_AI/tests/browser run test:release -- --workers=1`; `php8.5 custom/grocy_AI/tests/run.php`; `node public/custom/grocy_AI/capture.test.js`; `node public/custom/grocy_AI/capture-review.test.js`; `node --check` each changed JS; `git diff --check`. Run the existing standalone PHP capture/receipt/product contracts serially: `capture_research_queue.php`, `capture_research_queue_prerequisites.php`, `capture_research_worker_api.php`, `capture_research_drafts.php`, `capture_research_approval.php`, `capture_research_backfill.php`, `capture_web_search_requeue.php`, `capture_quota_recovery.php`, `capture_cancellation.php`, `capture_delete_with_receipt.php`, `receipt_audit.php`, `receipt_api.php`, `receipt_commit.php`, `receipt_scan_matches.php`, `receipt_extractor.php` under `custom/grocy_AI/tests/`, using `php8.5`. Investigate regressions; any pre-existing failure needs identical base-commit evidence, not a waived assertion. The old manifest-based `release-gate.sh candidate|predeploy|evidence` is Phase 2 specific; run it only with a valid applicable release manifest, not a fabricated one.
- [ ] **Step 6: Commit and hand off integration.** `git commit -m "feat: add purchase confirmation summary and release mobile flow redesign"`. Obtain Task 3 review and whole-branch review, resolve Critical/Important findings and rerun affected gates. Controller then prepares the PR to `atech-release` and handles the authorized rollout; this plan does not grant additional production mutations. At rollout verify refreshed assets, physical-phone acceptance and read-only live trip visibility; no real approval or stock commit as smoke testing.
