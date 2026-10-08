# Mobile purchase flow redesign

## Status and intent

Draft for user review. The user approved the overall direction: apply a shadcn/ui-inspired visual system across Capture, Review, and purchase confirmation. This document specifies the detailed layouts before implementation.

The goal is a clear, comfortable phone workflow: scan purchases quickly, review each product with its receipt evidence, then understand exactly what will enter inventory. Product creation and stock purchase remain distinct actions.

## Implementation boundary

Use the current Grocy PHP/Blade, plain JavaScript, and Bootstrap runtime. Apply the visual treatment through scoped module markup and CSS; do not introduce React, Tailwind, or a second UI runtime. Production remains on `atech-release`.

Keep feature code under `custom/grocy_AI/` and `public/custom/grocy_AI/`, using the existing capture/review Blade hooks. Reuse current API calls, permissions, receipt editor state, queue identities, and server readiness checks. This redesign adds no provider calls, schema changes, unit conversions, or automatic writes.

## Shared visual system

- Neutral surfaces, subtle borders, restrained shadows, consistent corner radii, and a single primary action color. Use Grocy's active theme and accessible light/dark tokens.
- Establish clear heading, body, label, and helper-text sizes. UPCs use readable monospace text and never lose leading zeroes.
- Use a consistent spacing scale: 4, 8, 12, 16, and 24 pixels. Card padding is 16 pixels on ordinary phones, with a compact 12-pixel variant at 320 pixels.
- Interactive controls have at least 44-pixel touch targets. Adjacent actions have at least 8 pixels of space; label groups have at least 12 pixels between them.
- Status badges combine text with color: Researching, Needs details, Ready to review, Product created, Not included, and Committed. Never communicate state through color alone.
- Required product fields retain red asterisks, accessible required state, and the creation-specific legend. Optional fields are clearly optional.
- Errors appear beside the responsible action, retain typed values, and receive appropriate focus/announcements. Success messages survive the reload caused by their action.
- Bottom action areas respect device safe-area insets. Reserve page space for their full height so they cannot cover content. When the keyboard is open, form actions remain reachable without covering focused controls.

## 1. Capture

### Layout, top to bottom

1. Compact title and trip header: Purchase capture, trip identifier, status, item count, and a Review action. Start new trip and Delete trip are secondary actions in a clearly labeled trip-actions disclosure.
2. Primary Scan barcode button, full width on narrow phones. Opening it uses the existing camera scanner. Manual UPC entry follows with its own Add action; the camera control is never overlaid on the input or Add button.
3. Latest scanned item card: product name or Unknown product, original UPC, quantity, and Saved/Researching/Needs details status. Camera confirm/edit behavior already present is preserved.
4. Compact captured-item summary. Show enough recent rows to confirm intake, with an explicit Show all items control for the rest. Counts distinguish individual scans from total quantity.
5. Bottom action area: Review trip as the primary destination once scanning has finished. While reviewing an existing trip, Continue scanning and Finish scanning sit side by side where space permits, with explicit spacing. At very narrow widths, controls may stack without shrinking below the touch-target requirement.

### Behavior

An accepted camera or manual read shows its UPC and save result immediately. A failed or ambiguous save keeps the existing stop-and-review safeguard; it must not invite an unsafe retry. Finish scanning preserves both existing confirmations and opens review without changing stock.

## 2. Review

### Layout, top to bottom

1. Compact trip summary: item count, unresolved count, receipt count, and Upload receipt. Receipt summaries show merchant, total, difference, and Finished/Needs review status; View receipt exposes image and details without expanding every line.
2. Queue navigation: Previous, position (for example 3 of 19), and Next. Include a Jump to next unresolved action. Navigation remains accessible without swiping.
3. One active product or receipt-only card. Its header presents product identity, original UPC where applicable, and review status.
4. Within a scanned-product card, arrange sections in this order:
   - Purchase participation and scanned quantity.
   - Product identity and research suggestions, including source and approval state.
   - Required creation fields: product name, location, purchase unit, and stock unit.
   - Optional classification, generic parent, brand/package research notes, and expanded source evidence.
   - Related receipt matching, quantity, and price review.
   - Clearly separated Save research draft, Approve new product, and Link existing product actions.
5. Bottom action area: compact unresolved summary and Continue to purchase summary. Keep queue Previous/Next near the card; do not duplicate every card action in the bottom bar.

### Product research

Suggested values show provenance and remain editable. Missing units say No suggestion available rather than implying research selected a value. Package size remains a research note; no size-to-stock conversion is inferred. Separate units require the existing verified Grocy conversion rules.

Approve new product explains that it creates a catalog product now, after the existing two confirmations. Success changes the card to Product created with its product identifier. It does not claim stock was added. Required-field and permission errors remain next to this action.

### Receipt-only and adjustment cards

Receipt-only items retain Include, pair/link, and Disregard controls. Disregard preserves receipt amounts and audit history and requires its existing confirmation. Active allocations retain their removal safeguard. Receipt adjustments are labeled as adjustments and never presented as products.

One receipt line has one authoritative editor. Multi-scan relationships retain navigation to that editor. Card navigation neither saves nor discards edits. Unsaved changes remain visible and keep the existing commit guard active.

## 3. Purchase confirmation

Present a summary stage within the existing review page; no new server purchase endpoint is needed. The user can inspect it while blocked and return to the exact product or receipt that requires attention.

### Layout, top to bottom

1. Heading: Confirm purchase, trip identifier, and Ready/Needs review status.
2. Included purchase summary: included product count, total quantity by product/unit, and reviewed prices. Catalog creation is identified separately from inventory changes.
3. Receipt summaries: merchant, printed total, reviewed total, and difference for each receipt. Show whether a difference was reconciled or explicitly accepted. Include ignored items and receipt adjustments in the receipt audit explanation; do not imply the included-stock subtotal must equal the full receipt total.
4. Outstanding checks, grouped by product or receipt, with a Review action that returns to the responsible queue card or receipt detail. Initially show a compact summary rather than a wall of validation text; allow expanding all checks.
5. Final action area: Back to review and Commit purchase. Clearly state that Commit purchase adds the reviewed items to stock. The server's existing readiness and checksum requirements control availability.

### Commit states

During commit, show a visible progress state and prevent repeated submissions. An error keeps the summary and offers the existing safe recovery path. A successful commit shows Purchase committed, the transaction reference if available, and actions to View inventory or Start new trip. A committed trip cannot appear ready to commit again.

## Responsive behavior and accessibility

Phone widths retain the single-card queue. Tablets and desktops use a centered, readable content width; the existing expanded desktop receipt editing may remain available without separate write logic. Verify 320, 390, and 430-pixel phone widths and a desktop viewport.

Use semantic headings and labels, visible keyboard focus, meaningful action names, and polite progress announcements. Destructive actions have explicit text and existing confirmations. Swipe navigation starts only on non-interactive card surfaces and must not intercept field input or vertical scrolling. Disclosures preserve their state during the current trip review.

## Verification and rollout

Verify capture camera/manual entry, UPC display, fresh known/unknown scans, missing suggestion states, required-field feedback, product approval/linking, receipt pairing and disregard, multiple receipts, quantity/price edits, reconciliation and accepted differences, unsaved edits, failed saves, queue retention, and commit blockers.

Use isolated browser/API fixtures for write-path tests. Check light/dark layout, long product names, mobile keyboard behavior, focus, safe areas, and absence of horizontal overflow. Run existing capture/receipt/product contracts and the module release checks. Record physical-phone acceptance separately from automated checks.

Review implementation before merging to `atech-release`; advance asset cache tokens and the customization marker at rollout. Preserve the complete production data path. Do not approve a real product or commit household stock as an automated deployment smoke test.

## Deferred work

Refreshing old classification results to obtain unit suggestions is a separate behavior change. This redesign makes their absence clear but does not rerun paid research. Automatic package-size persistence or conversion creation is also outside this design.
