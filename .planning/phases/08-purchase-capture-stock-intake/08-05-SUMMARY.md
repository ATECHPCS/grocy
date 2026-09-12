---
phase: 08-purchase-capture-stock-intake
plan: "05"
subsystem: purchase-capture-commit
tags: [php, sqlite, stock-write, begin-immediate, idempotency, checksum, conflict-safe]
provides:
  - ChecksumForTrip + CommitTrip (one BEGIN IMMEDIATE native purchase batch via StockService::AddProduct)
  - commit endpoint (STOCK_PURCHASE, confirmed_checksum) + trip archive + review-page commit control
key-files:
  modified:
    - custom/grocy_AI/src/GrocyAiCaptureService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/capture.php
    - views/grocyai_capture_review.blade.php
    - public/custom/grocy_AI/capture-review.js
    - public/custom/grocy_AI/capture-review.test.js
  created:
    - .planning/phases/08-purchase-capture-stock-intake/08-05-SUMMARY.md
key-decisions:
  - "CommitTrip mirrors GrocyAiBulkService::ApplyPlan exactly: hash_equals(recomputed, confirmed) refuses BEFORE any write; one raw BEGIN IMMEDIATE with a single COMMIT (ROLLBACK on any Throwable); PDO begin/commit are never used. Commit set = selected=1 AND status='known' AND applied_at IS NULL; each is re-resolved in-lock (drifted owner → conflict, skipped)."
  - "The native stock writer is an injectable seam (constructor arg 3): production uses StockService::GetInstance(); the suite injects CaptureFakeStockService with AddProduct's exact by-ref signature, so the whole commit contract is proven without Grocy's stock schema. The transaction type is the literal 'purchase' so an injected fake never hard-loads the framework."
  - "Stock amount = quantity × (product_barcodes.amount override | products.qu_factor_purchase_to_stock), default 1; best-before passes through as override|null (native auto-computes); the batch shares one transaction_id."
  - "capture-invariants is now GREEN and proves: checksum refuse, partial commit (unknown/deselected remain), factor + barcode-override amounts, one shared transaction_id, idempotent re-commit (no duplicate purchase), full-commit archive (committed + committed_at + transaction_id + checksum, read-only), and in-lock conflict safety."
  - "The trip GET is enriched (controller-only) with the current ChecksumForTrip so capture-review.js can echo it on commit; the pinned service DTOs (LoadTrip = {trip,lines}) are untouched. UpdateLine/SetTripDefaults now refuse a committed trip (AssertMutable)."
requirements: [CAP-05, CAP-06, CAP-07, CAP-08]
---

# Phase 08 Plan 05: Commit to Native Stock Summary

**A reviewed trip commits its selected known lines to real Grocy stock as one auditable, idempotent, conflict-safe native purchase batch — the first and only module stock-write path — leaving anything unresolved behind.**

## Commit mechanics (`GrocyAiCaptureService`)

- **`ChecksumForTrip(tripId)`** — lowercase 64-hex SHA-256 over the selected known lines' commit-relevant content (product, canonical/scanned barcode, quantity, price, best-before override), reorder-stable + mutation-sensitive (mirrors `ChecksumForPlan`).
- **`CommitTrip(tripId, actor, confirmedChecksum)`** — `hash_equals` refuse before any write → `BEGIN IMMEDIATE` → per selected+known+unapplied line: in-lock re-resolve (drift → `conflict`, skip), `amount = quantity × (barcode override | purchase→stock factor)`, `AddProduct(..., 'purchase', today, price, location, store, &txnId, ..., note)` sharing one `transaction_id`, stamp `applied_at`+`outcome='applied'`, append audit → single `COMMIT` (`ROLLBACK` on any `Throwable`). Fully applied → trip `committed`+`committed_at`+`transaction_id`+`checksum` (read-only); else `reviewing`.

## Evidence (asserted GREEN in `capture-invariants`)

| Invariant | Evidence |
|---|---|
| Checksum gate | wrong confirmed checksum → `checksum_mismatch`, 0 applied, **0 stock calls**. |
| Factor + override | product 101: 2×1=**2**; 102: 1×6=**6**; 103: 3×barcode-override-12=**36**. |
| One batch | all three `AddProduct` calls share **one** `transaction_id`, type `purchase`, location 5 / store 7. |
| Partial | selected unknown line not written and **remains**; trip → `reviewing` with the batch `transaction_id`. |
| Idempotent | re-commit (same checksum) → 0 applied, still exactly **3** stock calls (no duplicate). |
| Full archive | after resolving the unknown, commit → `committed` + `committed_at` + `transaction_id` + `checksum`; `UpdateLine` then throws. |
| Conflict-safe | a drifted-owner line → `conflict`, **not written**, stays in the trip. |
| Boundary | the fake stock writer receives calls **only** during commit; source grep confirms `AddProduct`/`StockService` live only in the constructor field + `CommitTrip`; no other module service writes stock. |

## Endpoint + UI

- `POST /api/grocy-ai/capture/trips/{id}/commit` (STOCK_PURCHASE) — closed `{confirmed_checksum:<64-hex>}` body; `checksum_mismatch`/`already_committed`/`commit_failed` → 409 with the result DTO (no write); success → 200. Actor = `GROCY_USER_ID`.
- `GET …/capture/trips/{id}` now returns `{trip, lines, checksum}` (controller-only enrichment) so the review page can echo the checksum.
- `capture-review.js`: a **Commit purchase** button (confirm dialog) POSTs the checksum, renders committed/partial/mismatch, and reloads; a committed trip shows its transaction_id and hides the control.

## Verification

- `capture-invariants` **GREEN** (`Capture engine invariants passed`); `capture-contract` GREEN.
- `php8.5 -l` clean (service, controller, routes); `node --check capture-review.js` clean; `node --test` capture-review 4/4 + capture 4/4.
- `CommitCaptureTrip` STOCK_PURCHASE-gated; `POST …/commit` registers; review Blade compiles.
- Regression: default suite `All 127 grocy_AI checks passed`.

## Decision and next step

The capture→review→commit workflow is complete and stock-write-safe. Wave 6 (08-06) adds the mobile-browser acceptance spec (`purchase-capture.spec.js`) covering coalescing/unknown-lifecycle/partial/idempotency and deploys with a no-regression smoke — the phase's final acceptance.
