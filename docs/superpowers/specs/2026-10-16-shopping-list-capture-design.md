# Shopping list capture and UPC curation

## Intent and approved behavior

A shopper opens a purchase capture session, chooses an existing Grocy shopping list, and sees what remains to collect while scanning products. The list supplies the intended product before an unknown UPC is curated, reducing later identification work. Capture, receipt review and purchase commit remain one continuous flow.

The approved behavior is to remember an explicitly confirmed UPC association immediately, update the shared shopping list as matching items are collected, offer Undo, and change stock only at Commit purchase. A branded child can satisfy its generic parent's list entry. When an unknown UPC represents a new branded product, the shopper can create and approve that child under the listed generic parent.

This design extends the existing capture screen, barcode resolver and product approval service. A separate shopping screen was considered but would duplicate scanner behavior and require extra navigation. A session-only checklist was also considered; it would not provide the approved immediate visibility to other shoppers.

## Session and list selection

The scanner offers a shopping-list selector and a collapsible list panel. One list is selected per session; capture without a list remains available. Selection is stored against the trip and restored on resume. Opening, refreshing or selecting a list never creates a trip, checks an item off, approves a product or writes stock.

Each row shows the product or free-text description, required amount and unit, note, collected progress, and current shared done state. Remaining items appear first; collected items remain accessible. The panel supports the existing 320px mobile layout, light/night modes, 44px touch targets, keyboard navigation and announced save/error states.

Changing lists requires confirmation if the session already has list-linked scans. Existing associations remain attached to their original list rows and are not reassigned to the newly selected list. Returning to a previous list shows those associations again. Deleting a list or row elsewhere produces an unavailable-entry message; capture history and stock review remain usable.

The server reads current native list rows rather than replacing the shared list with a session snapshot. Refresh and a bounded refresh while the panel is visible surface other shoppers' changes. Refresh responses cannot overwrite a newer selected list, active trip or unsaved curation form.

## Scanning and matching

The shopper can scan normally or tap a list row to choose it as the target for the next item. The target is clearly displayed and can be cleared. After a scan is settled, clear the target so it cannot accidentally curate the next unrelated item. A targeted unknown scan pauses for explicit curation: focus moves to Curate UPC, and the shopper completes curation or chooses Keep as normal capture/Undo before entering another UPC. Untargeted unknown and off-list scans retain input focus for continuous keyboard scanning.

A known UPC uses its existing Grocy owner. Within the selected list, an exact product match takes precedence over a generic-parent match. A branded product can satisfy a row for its direct generic parent when the relationship and quantity conversion are valid. No fuzzy name match establishes identity. If multiple rows are eligible at the same priority, the shopper chooses one; one scan cannot count against several rows.

A known UPC belonging to a different product is never reassigned to the selected target. Show the actual product and let the shopper capture it normally, choose a compatible list entry, or cancel the proposed list association. A product outside the selected list remains a normal capture item and is labeled as not on that list.

An unknown UPC without a target follows the existing capture/research workflow. With a target, save the scan and intended row durably, then present explicit curation. It remains unresolved and does not complete the row until identity is confirmed. Merely selecting a list product never silently approves a UPC.

Each accepted scan contributes once to capture quantity. Retrying a list update or recovering a lost response must not scan again. Durable operation IDs distinguish a retry from a deliberate second scan of the same UPC, which increments quantity as it does today.

## Immediate UPC curation

For a specific listed product, show its name and scanned UPC and ask the shopper to confirm the permanent association. On confirmation, use the existing canonical-GTIN ownership checks and native barcode attachment behavior. Preserve the original scanned spelling and existing equivalent spellings. A barcode owned by another product is a conflict, not an invitation to overwrite it.

For a generic listed product, offer the following choices:

- Select an existing active branded child under that parent and confirm its UPC association.
- Create a branded child using the current product approval fields, with the generic parent preselected. The shopper reviews the name, purchase/stock units, required location and any classifications before approval. Preserve existing parent-unit compatibility rules and explicit conversion requirements.
- Cancel curation and retain the unresolved scan for later review.

A product with existing children is presented as a generic parent. For a childless product whose intended role is ambiguous, offer Link this product and Create a branded child rather than inferring its role from its name. Free-text list rows require selecting a real catalog product before a permanent UPC association can be made; manual check-off remains available without a catalog product.

Curation is an explicit catalog write, separately labeled from collection and purchase commit. It retains the current master-data permission requirements and audit trail. Users lacking those permissions can scan known items and retain unknown items for an authorized reviewer.

Targeted curation does not require an external provider lookup to confirm an existing product. Optional research for a new child is explicitly requested; list selection alone must not start a paid lookup. Unknown scans outside targeted curation keep their existing research behavior.

After catalog approval, re-resolve the captured UPC and complete the list association without another scan. If that completion fails, show that the product link was saved and provide a retry of the remaining collection operation. Do not repeat product creation or approval.

## Quantities and shared completion

Collection progress records a scan's contribution to a particular native list row. For a row requiring three cans, the first scan displays 1 of 3 and the third checks it off in native Grocy. Repeated scans and different eligible branded children can contribute to the same parent row. Do not reduce the native requested amount or delete the row when collecting it.

Compare scan purchase units with list units using verified product and parent/child conversions. A missing, ambiguous or incompatible conversion produces a confirmation control for the amount collected in the list's displayed unit. Never equate one package with one kilogram or infer a conversion from a product name. A zero, missing or invalid requested quantity requires manual collection confirmation rather than automatic completion.

The current shared done state remains authoritative. An item already checked off elsewhere is shown as collected, without attributing that collection to this session. Session progress is labeled as this session's contribution; it does not claim to know unrecorded partial quantities collected by other shoppers. Additional scans may still enter the purchase session, but do not toggle an already-done row or multiply its completion.

Free-text rows can be checked off manually using the same guarded shared-list write. Choosing a catalog product for curation associates this session's collection with the row; it does not silently rewrite the native free-text row into a product row.

## Undo and changes by another shopper

After a collection action, offer Undo with its effect stated. In an open trip, undoing a scan collection removes that operation's one scan contribution from the capture quantity and its list progress. Reopen the shared row only if this action actually checked it off and that exact row has not changed since. Never uncheck a row that was already done before the scan.

UPC associations and approved catalog products survive collection Undo. They are independently confirmed catalog changes; Undo does not delete a product or detach its barcode.

Undo is a compensating, audited operation rather than deletion of history. It is idempotent and cannot reduce capture quantities below valid values. If a native list edit prevents safe shared reversal, allow capture-only scan Undo while preserving the shared row and explicitly explaining that its current state was left unchanged. If the capture line itself was edited or has receipt allocation history, or the trip is reviewing/committed, block scan Undo and explain that collection history remains and any shared-list adjustment must be explicit. Do not reverse stock through this feature.

Recompute remaining collection contributions before reopening a row: undoing an earlier scan must not uncheck an item still fulfilled by later scans. Editing purchase-review quantities or excluding a line does not imply that the shopper returned an item to the shelf; shared-list adjustments remain explicit rather than being inferred from receipt review.

Detect changes to native shopping-list rows with a module-owned monotonic version ledger and SQLite triggers on native row insert/update/delete. Keep tombstones so deletion/recreation and a done/undone/done sequence cannot make a stale action appear current. The triggers record versions only; they do not change native list behavior. Reads expose opaque row versions, and collection/Undo transactions compare the expected version with the current row before writing.

If another shopper changes the row after an action, a stale Undo must not overwrite that change. For scan Undo, remove the capture contribution without modifying the shared row when safe capture-only removal is possible. Manual check-off Undo still requires its original current version; a stale manual reversal returns a conflict and leaves ordinary manual list editing available. Do not silently retry a stale shared write using a newly fetched version. Editing list amount, unit or product similarly requires recalculating eligibility and progress before another completion attempt.

Deleting or canceling a capture session does not automatically undo shared-list collection or confirmed catalog changes. Explain this in the cancellation confirmation when the trip has such actions; remaining list adjustments are explicit. Switching sessions or returning to the dashboard has no collection side effects.

## Persistence and service boundaries

Use an additive namespaced migration for trip/list selection, durable collection operations, scan-to-row contributions, row versions and append-only collection audit. Native shopping lists, list items, products and barcodes remain the source of truth. Store stable row/product/line IDs plus the target amount/unit and version needed to validate each operation; display current names and notes rather than duplicating an editable list.

A dedicated shopping-list capture service coordinates list reads, eligibility, unit conversion, progress, completion and Undo. New endpoints return a versioned shopping-list contract. Keep current trip, scan and review payloads compatible; their JavaScript validates exact keys. Existing capture and review continue to work for trips without shopping-list state.

Use a separate list-aware scan operation with a client-generated operation ID. Persist its scan and pending target atomically; use a transaction-aware internal capture operation while keeping existing scanner behavior unchanged. Store the result so an identical retry returns it, while reusing an ID with different input is rejected. Prevent double submissions in the UI, but make server idempotency authoritative.

Known-product collection can settle the capture contribution and guarded list update in one database transaction. Unknown-product operations first persist as awaiting curation. Product approval remains a separately confirmed catalog transaction; after approval, a settlement transaction records the resolved contribution and guarded list completion. Durable states distinguish awaiting curation, pending list settlement, settled, conflicted and undone. Recover by reading operation state after an ambiguous response; never blindly repeat a stock, approval or scan POST.

If a known scan encounters a stale list version, preserve the accepted scan and record the collection operation as conflicted without modifying the native row. A database failure that prevents that transaction from committing saves neither; an authoritative operation read distinguishes those cases before any retry. The UI must not label an uncommitted scan as saved.

Every endpoint derives the actor from authentication and checks trip ownership and lifecycle. Reading lists requires the existing shopping-list permission as well as capture access. Native done-state edits require the same shopping-list item edit permission used by Grocy; permanent product/barcode changes retain their current master-data gate. Permissions are enforced on the server and reflected by available controls.

Catalog curation and shared-list edits do not invoke the stock writer, change receipt allocations, bypass readiness, or auto-commit a purchase. Final purchase review and commit remain authoritative for stock. Commit must not perform an additional list subtraction or completion for rows already handled by these operations. Shopping-list completion is not proof that stock has been added.

## Failure handling and acceptance

Keep the chosen list, scan result and typed curation values visible after failures. A saved scan followed by a failed list update is shown as saved with pending list settlement. Conflicts identify which selection or shared state needs attention without exposing another user's private trip. Existing navigation guards cover unsettled scans, curation and collection writes, and late results cannot affect a different trip.

PHP integration tests use disposable SQLite data and cover exact and parent matches, ambiguous candidates, unknown UPC linking, branded-child approval, canonical ownership conflicts, permissions, list/row deletion, conversions and manual quantities. Verify native list version tracking through native edits, stale and repeated Undo, operation ID retries, deliberate repeated scans, and recovery between curation and settlement. Assert zero stock writes until the existing explicit commit path.

Browser tests in Chromium and WebKit cover list selection/resume, in-session list visibility, scanning while a row is targeted, collection progress, shared done state, Undo, free-text rows, denied curation, failed/lost responses, and trip/list switches. Verify 320px and 390px layouts, touch targets, keyboard/focus behavior and light/night modes. Existing capture, research, receipt and commit regression gates remain applicable.

Before deployment, verify a full backup and preserve the entire persistent data mount and runtime settings. Release through the established reviewed PR and Komodo deployment flow. Live smoke checks are read-only; a deliberate phone acceptance session verifies selected-list scanning, explicit unknown-UPC curation and shared-list updates. No production scan, product approval or stock commit is used as an automated smoke check.

## Scope boundary

This feature supports one selected list at a time, household-visible native list completion, explicit UPC curation and the existing purchase lifecycle. Offline synchronization, simultaneous merging of partial quantities between shoppers, fuzzy substitution recommendations, external shopping-list services, automatic product approval and automatic stock intake are outside this design.
