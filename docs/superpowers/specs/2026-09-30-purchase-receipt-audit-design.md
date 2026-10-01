# Purchase receipt audit for capture trips

## Outcome

A household user scans purchases in Grocy on a phone, attaches one or more receipts to the trip, reviews every receipt line and its proposed match, confirms the price and quantity of every included purchase, then commits the reviewed batch to stock. A receipt can contain items the user deliberately kept out of Grocy. Those lines remain in the receipt audit and never enter stock unless the user explicitly includes and matches them.

This extends the deployed Phase 8 purchase capture flow. It does not change the rapid scan loop or automatically create Grocy products.

## Decisions already confirmed

- A trip may have multiple receipts.
- A completed receipt audit is mandatory before purchase commit so prices can be confirmed.
- Each receipt line is explicitly **Include**, **Ignore**, or **Needs review**. Receipt-only lines default to excluded from Grocy and need an explicit inclusion action.
- Ignored receipt lines remain visible and auditable; they do not create a product or stock entry.
- Users can correct OCR text, matching, price, and quantity before commit. Stock changes only through the existing explicit Commit purchase action.

## Architecture

Keep the review UI, receipt state, and commit guard in the `grocy_AI` extension. Add a versioned extension migration for receipt headers, receipt lines, receipt-to-capture-line allocations, and append-only receipt audit events. Keep receipt images in the existing persistent Grocy data path, outside the public web root, with authenticated read endpoints. Use bounded JPEG/PNG/WebP uploads, image validation, a size and page count limit, and opaque image identifiers. Do not put receipt photos or extracted personal data in logs or URLs.

Add a receipt extraction endpoint to the `grocy-mcp` companion, following its existing provider pattern. It accepts a bounded image from the authenticated Grocy server and returns structured suggestions: merchant, date, line description, quantity, line total, discounts/tax where recognized, confidence, and a provider diagnostic. The module validates and stores a normalized response. OCR failure leaves the receipt in a manual-entry state; it never makes a trip committable by itself. The provider name and exact model remain deploy configuration, so the module contract does not depend on a specific OCR service.

The capture review page gains an **Add receipt** camera/file control and a receipt list. Each receipt has a review panel showing the image, extracted lines, editable values, match suggestions, and an Include/Ignore/Needs review decision. Matching uses scanned trip lines first, then known Grocy products as suggestions. No suggested match is applied silently. One receipt line may allocate to more than one unit of a scanned line, and a scanned line may be reconciled across receipts; allocations cannot exceed confirmed quantities without an explicit correction. Tax, deposits, discounts, and other noninventory charges remain receipt audit lines and default to Ignore for stock. Their monetary values still contribute to receipt reconciliation.

Each receipt carries its own merchant and optional Grocy shopping location. This allows receipts from different stores in one trip. Included capture lines use their confirmed receipt shopping location at commit; a line split across stores is represented as separate commit allocations. Unmatched scanned lines and duplicate matches remain visible as review issues. Receipt-only lines stay excluded unless a user chooses Include and assigns a known Grocy product or creates one through the existing product form, then returns to review.

## State and commit rules

Receipt upload starts a receipt in `processing` or `needs_review`; extraction may populate suggestions but never approves a line. A user finishes a receipt only after all its lines have an explicit Include or Ignore decision and its monetary reconciliation is acknowledged. Editing a finished receipt reopens its audit. A trip is ready only when at least one receipt exists, all attached receipts are finished, no included line is unresolved, and each selected stock allocation has a confirmed positive quantity, nonnegative price, and known product. A user may enter or correct values manually when OCR cannot read them. The review UI shows blocking issues and the server repeats the same checks at commit.

The existing trip checksum must cover the selected stock allocations, confirmed prices and quantities, receipt IDs and revision numbers, matching decisions, and receipt completion state. `CommitTrip` verifies readiness and checksum before and after acquiring its SQLite write lock. Receipt OCR and other network work happen before the lock. Receipt events and stock writes stay auditable. The existing idempotent/partial commit behavior remains, but unresolved receipt audit never starts a new stock write. Already committed trips remain read-only; existing uncommitted trips must attach and audit receipts before their next commit.

## API and compatibility

Add STOCK_PURCHASE-gated endpoints to upload/list/read receipts, request extraction or retry it, edit a receipt line or allocation, and finish/reopen a receipt. Preserve existing capture trip and line response shapes because the JavaScript validates exact keys. Return receipt data through separate versioned response shapes or an additive endpoint. The client never sends a bare path or arbitrary image URL for the server to fetch. User edits are validated against trip ownership and committed state. Failed writes return explicit errors without losing the current review context.

The existing `grocy-mcp` instance has no receipt OCR endpoint today. Deploy the companion capability and extension in an order that leaves capture usable; the UI must clearly indicate provider unavailability and permit manual transcription. Once the extension commit guard is deployed, no unreviewed trip can commit even when OCR is unavailable.

## Verification and rollout

Contract tests cover multiple receipts, duplicate/partial matches, receipt-only Ignore and Include, missing price, manual OCR fallback, reopen after edit, multi-store allocations, checksum changes, and the server-side commit guard. Browser tests cover iPhone-sized capture/review controls, photo upload, correction, ignored lines, and blocked/ready commit states. Run PHP syntax and module contract tests in a runtime with `pdo_sqlite`; the local PHP environment previously lacked that extension. Check that receipt images and SQLite data survive a container rebuild through `/etc/komodo/grocy`. Verify a real phone receipt, including one purchased item deliberately excluded from Grocy, before production release.

## Scope boundary

Background research or automatic creation of unknown products is a separate feature. Receipt OCR supplies reviewable evidence and candidate matches. It never creates products, changes stock, or silently includes receipt-only purchases.
