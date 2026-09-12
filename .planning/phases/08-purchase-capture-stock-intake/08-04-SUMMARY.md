---
phase: 08-purchase-capture-stock-intake
plan: "04"
subsystem: purchase-capture-review
tags: [blade, javascript, review, re-resolve, enrichment-handoff, stock-purchase]
provides:
  - trip review page — trip list + per-line quantity/price/selection/delete + trip-level location/store defaults
  - unknown→/product/new enrichment handoff (barcode-prefilled) and Q10 unknown/conflict re-resolve on load
key-files:
  created:
    - views/grocyai_capture_review.blade.php
    - public/custom/grocy_AI/capture-review.js
    - public/custom/grocy_AI/capture-review.test.js
    - .planning/phases/08-purchase-capture-stock-intake/08-04-SUMMARY.md
  modified:
    - custom/grocy_AI/src/GrocyAiCaptureService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/src/GrocyAiCaptureController.php
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/capture.php
    - public/custom/grocy_AI/product-enrichment.js
key-decisions:
  - "Added a read-only trip-list surface: GrocyAiCaptureService::ListTrips() (closed trip DTOs, newest first) + GET /api/grocy-ai/capture/trips → ListCaptureTrips (STOCK_PURCHASE), enabling the review page's trip list. GET and the existing POST share the /capture/trips pattern."
  - "LoadTrip's Q10 re-resolve now covers status IN ('unknown','conflict') so a prior conflict also flips to known once its product+barcode exist. capture-contract asserts BOTH the unknown and the conflict re-resolve paths, plus ListTrips shape/order."
  - "The unknown-handoff prefill is module-contained: a guarded IIFE in product-enrichment.js reads /product/new?barcode=<gtin> and fills the GTIN field (never auto-searches); no core controller or core Blade was touched. The 'Create product' link carries permission-MASTER_DATA_EDIT while capture pages stay STOCK_PURCHASE."
  - "Per-line edits are lean per Q11: quantity/price/selection/delete only — no per-line location/store/best-before. Location/store are trip-level defaults, populated client-side via Grocy.Api (locations/shopping_locations) so the controller stays trivial and DTOs stay pinned."
  - "capture-review.js follows the UMD pattern with unit-tested pure guards (isTripListPayload/isLoadedTripPayload/productNewUrl/lineLabel); same-origin fetch to the module endpoints; whole-detail re-render after each persisted edit."
requirements: [CAP-03, CAP-04]
---

# Phase 08 Plan 04: Trip Review + Unknown Lifecycle Summary

**A trip can be brought to a fully-known, selected, commit-ready state: review its lines, adjust quantity/price/selection, delete lines, set trip-level location/store defaults, hand unknowns to `/product/new`, and watch them auto-re-resolve to known on return.**

## Review actions

Page `GET /grocyai/capture/review[?trip=<id>]` → `GrocyAiCaptureController::Review` (STOCK_PURCHASE); `views/grocyai_capture_review.blade.php` + `public/custom/grocy_AI/capture-review.js`.

| Action | Wiring |
|---|---|
| List trips (status) | `GET /api/grocy-ai/capture/trips` → `{trips:[…]}`, newest first; click selects a trip. |
| Load a trip | `GET /api/grocy-ai/capture/trips/{id}` (LoadTrip; re-resolves unknown/conflict). |
| Quantity | −/＋ stepper + number input → `PUT …/lines/{seq} {quantity}`. |
| Price (optional) | number input → `PUT …/lines/{seq} {price|null}`. |
| Include in purchase | checkbox → `PUT …/lines/{seq} {selected}`. |
| Delete line | → `PUT …/lines/{seq} {delete:true}`. |
| Trip defaults | location + store `<select>` (client-populated via Grocy.Api) → `PUT …/trips/{id} {default_location_id|default_shopping_location_id}`. |
| Advance status | open→reviewing button → `PUT …/trips/{id} {status:'reviewing'}`. |
| Unknown handoff | "Create product" link → `/product/new?barcode=<scanned>` (MASTER_DATA_EDIT). |

Best-before is never edited (auto). No per-line location/store/best-before (lean, Q11). The page writes no stock.

## Re-resolve evidence (Q10, asserted in `capture-contract`)

- Scan an unused barcode → `unknown` line; insert its product_barcode owner; `LoadTrip` → line flips to `known` with the resolved product. **Asserted.**
- A manually-seeded `conflict` line whose barcode is owned → `LoadTrip` flips it to `known`. **Asserted** (LoadTrip now re-resolves `status IN ('unknown','conflict')`).
- `ListTrips` returns exactly the closed trip DTOs, newest-first. **Asserted.**

The enrichment handoff prefill: opening `/product/new?barcode=<gtin>` fills the GTIN field (guarded IIFE in product-enrichment.js; inert without the param, never auto-searches), so the user searches + creates, then the reopened trip shows the line as known and commit-eligible.

## Verification

- Task 1: `php8.5 -l routes.php` clean; `node --check capture-review.js` clean.
- Task 2: `capture-contract` GREEN (adds the conflict re-resolve + ListTrips assertions); `node --check product-enrichment.js` clean.
- `node --test` capture-review + capture: 8/8 pass.
- Review Blade compiles via the real compiler; routes register: `GET`+`POST /api/grocy-ai/capture/trips`, `GET /grocyai/capture/review`. `ListCaptureTrips` and `Review` are STOCK_PURCHASE-gated.
- Regression: default suite `All 127 grocy_AI checks passed`; `capture-invariants` still RED at the deferred commit surface.

## Decision and next step

Review + the unknown→known lifecycle are complete; a trip reaches a fully-known, selected state. The commit wave adds `ChecksumForTrip` + `CommitTrip` (the single audited stock-write via `StockService::AddProduct`, checksum + per-item `applied_at` idempotency, partial commit), turning `capture-invariants` green and closing the workflow.
