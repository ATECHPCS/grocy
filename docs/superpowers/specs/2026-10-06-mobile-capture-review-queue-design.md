# Mobile purchase capture review queue

## Intent

Phone users reviewing a shopping trip should work through one product at a time instead of scrolling through every scanned item and receipt line. A scanned item's research, receipt match, quantity, and price should be visible together. Receipt-only items must remain reviewable so the user can include, match, or ignore them. The trip still requires explicit product approval and an explicit Commit purchase action.

Grocy's production branch is `atech-release`. The existing linked Grocy worktree is reused for this change; the older, unmerged worktrees are preserved. The desktop review may retain its current expanded layout.

## Mobile information layout

At phone widths below the existing Bootstrap `md` breakpoint, the selected trip has three regions:

1. A compact trip header with trip status, inventory location, receipt photo upload and read controls, and receipt totals/difference state. It identifies unfinished receipts and does not imply that the purchase is committed.
2. A single active review card with a counter such as `3 of 19`, a concise status, and 44px or larger Previous and Next controls. A horizontal swipe on the card's non-form surface changes cards; vertical scrolling remains available within a long card. Keyboard-accessible buttons are the primary navigation path. A jump-to-unresolved control may skip completed cards without hiding them.
3. A trip-level readiness summary and the existing Commit purchase control. It stays disabled whenever existing receipt readiness or unsaved-edit rules require it. The control remains clearly separate from Save research draft, Approve new product, and Finish receipt.

The active card is either a scanned item or a receipt-only item. A scanned card shows its original UPC, product identity/research, inclusion and quantity controls, and related receipt line evidence. Its receipt section exposes the existing decision, corrected description, receipt quantity, line total, allocation match, confirmed purchase quantity, and unit price controls as applicable. A receipt-only card shows the same receipt editor plus the choices to pair with a scan, match a known product, or Ignore. No line disappears merely because it has no scan.

The page can show a receipt-level summary for each uploaded receipt, including merchant, store, printed total, entered total, difference, and Finish/Reopen actions. It does not expand every receipt line into another vertical list on mobile. The receipt image remains accessible from its summary.

## Queue identity and relationships

The queue is derived from the currently loaded trip and receipt-readiness payloads, without a new server write path. Each selected or unselected capture line has a stable `scan:<line id>` card. Each receipt item line that is not associated with exactly one scan has a stable `receipt:<receipt id>:<line id>` card. Association uses the saved pairing first, then active allocations to a capture line. A receipt line associated with exactly one scan is edited inside that scan card. A line with multiple scan allocations gets its own receipt card; the related scan cards show a read-only summary and a control that navigates to that receipt card. This keeps one authoritative editor for each receipt line and avoids conflicting copies of unsaved fields.

Non-item receipt lines, such as savings or tax, get a receipt adjustment card when they require a decision or correction. They are identified as adjustments, not products, and remain visible in receipt totals. Existing Include/Ignore decisions, allocation rules, receipt reconciliation, and readiness calculations remain server-owned. Suggested matches remain suggestions until the reviewer saves a pairing or allocation.

The current card is tracked by its stable key, not its array index. After a save or research refresh, the same card remains selected if it still exists. If it was removed or became associated with another card, navigation moves to the nearest surviving card and announces the change. Switching trips resets to that trip's first unresolved card. Unsaved receipt and research edits remain in the existing in-memory draft state when the user navigates; an unsaved indicator is visible and commit remains blocked. Navigation does not silently save, discard, approve, or allocate anything.

## Component boundaries and data flow

`capture-review.js` owns the selected trip, queue ordering, active card, and navigation. `capture-product-research.js` continues to own draft fields, provider status, and product approval for the active scanned item. `capture-receipts.js` exposes a way to render receipt-level controls and one receipt line editor in the active card while retaining its current draft state, API calls, and reload behavior. Shared receipt-line markup and actions are reused by the desktop expanded review so the two layouts do not diverge in write semantics. `grocy-ai.css` provides scoped mobile sizing, overflow, visible focus, and swipe affordance. The Blade view provides any needed labels and increments its asset token; the customization marker advances to invalidate cached views.

No backend schema, product creation, allocation, or stock transaction behavior changes. The existing API payloads and permission gates remain authoritative. Product approval remains a separately confirmed catalog write, and Commit purchase remains the only stock write.

## Errors, accessibility, and safety

- A failed save keeps typed values and the current card visible, with a local error. It never advances the queue automatically.
- A late trip, research, or receipt response cannot replace a newer saved state or change the active card. Existing request-generation guards are retained or extended where needed.
- Swipe begins only on a non-interactive surface; it does not intercept typing, select menus, horizontal control movement, or vertical page scroll. Previous/Next, card status, and errors have accessible names and announcements. Focus moves to the new card heading after button navigation.
- Mobile widths of 320px and 390px have no horizontal page overflow. Buttons and form controls meet the existing 44px touch-target convention. Desktop remains usable without swipe.
- The queue never hides a receipt-only line, receipt adjustment needing action, unresolved conflict, or accepted difference from the readiness summary. A completed receipt can still be reopened through its receipt-level controls.

## Verification and release

Browser tests cover stable navigation across reloads, swipe and button behavior, 320px and 390px layout, an unknown scanned UPC with research, one-to-one and multi-scan receipt relationships, a receipt-only line that is ignored, multiple receipts, unsaved and failed edits, legacy review payloads, and the existing approval/commit gates. Existing PHP capture and receipt contracts remain green. The changed JavaScript passes syntax checks and the module suite passes. Independent review precedes a PR against `atech-release`; production deployment follows only after the review and tests pass. A read-only live smoke check confirms trip #12 still loads and product/stock counts are unchanged.
