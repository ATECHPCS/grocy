---
phase: 08-purchase-capture-stock-intake
plan: "02"
subsystem: purchase-capture-queue
tags: [php, sqlite, slim, capture, stock-purchase, no-stock-write]
provides:
  - GrocyAiCaptureService live-capture core (StartTrip, ScanIntoTrip+coalesce, LoadTrip+re-resolve, SetStatus, SetTripDefaults, UpdateLine)
  - five STOCK_PURCHASE-gated capture endpoints under the module Cors+Json group
key-files:
  created:
    - custom/grocy_AI/src/GrocyAiCaptureService.php
    - .planning/phases/08-purchase-capture-stock-intake/08-02-SUMMARY.md
  modified:
    - custom/grocy_AI/tests/capture.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/routes.php
key-decisions:
  - "The 08-01 RED gate is split into a wave-2 CORE surface guard (StartTrip/ScanIntoTrip/LoadTrip/SetStatus/SetTripDefaults/UpdateLine) behind capture-contract — now GREEN — and the FULL surface guard (adds ChecksumForTrip/CommitTrip) behind capture-invariants, which stays RED until the commit wave (mirrors bulk-contract green / bulk-invariants deferred)."
  - "The behavioral assertions (lifecycle, known/unknown via ResolveOwner, coalescing, UpdateLine edits, Q10 review re-resolve, and the zero-stock-write boundary) live in capture-contract and drive the service GREEN."
  - "ScanIntoTrip coalesces on COALESCE(canonical_gtin, scanned_barcode) — exactly the migration's UNIQUE index key — incrementing the one existing line; a non-checksum-valid barcode is an unknown line with a null canonical, coalesced on its raw value."
  - "The capture service constructs with bootstrap=true (idempotent CREATE TABLE IF NOT EXISTS) since no in-repo migration runner bootstraps the capture schema yet; this diverges from bulk's bootstrap=false, which relies on an external bootstrap."
  - "Ownership resolution reuses GrocyAiBarcodeService::ResolveOwner via an ownerLookup closure bound to the service's own PDO, so it resolves against the same connection (works with in-memory fixtures and prod alike). owner_product_id !== null => known; else unknown."
requirements: [CAP-01, CAP-02, CAP-08]
---

# Phase 08 Plan 02: Live Purchase-Capture Queue Summary

**A STOCK_PURCHASE user can start a trip, rapid-scan barcodes into it (each resolved known/unknown and coalesced), review it, and edit lines — over a live connection, writing only capture rows and zero stock.**

## Service / endpoint map

`GrocyAiCaptureService` (constructor lazily bootstraps `GrocyAiCaptureMigration`):

| Method | Behavior |
|---|---|
| `StartTrip(actor)` | Insert an `open` trip; return the closed trip DTO; audit `start_trip`. |
| `ScanIntoTrip(tripId, barcode, actor)` | `ResolveOwner` → known/unknown; coalesce on `COALESCE(canonical_gtin, scanned_barcode)` (increment qty) or append a seq'd line; audit; return the line DTO. Refuses a committed trip. |
| `LoadTrip(tripId, actor)` | Return `{trip, lines}` ordered by seq; re-resolve still-`unknown` lines by barcode (Q10), flipping to `known` when an owner now exists. |
| `SetStatus(tripId, 'reviewing', actor)` | open → reviewing only; any other transition throws. |
| `SetTripDefaults(tripId, location, store, actor)` | Set the trip-level location/shopping-location defaults. |
| `UpdateLine(tripId, seq, change, actor)` | Closed change set `quantity`(>0) / `price`(nullable) / `selected`(bool), or `{delete:true}` alone; return `{trip, lines}`. |

Every mutating action writes one append-only `grocy_ai_capture_audit` row. No path references `stock`/`stock_log`.

Routes (module `/api/grocy-ai` group, Cors+Json middleware, each `User::PERMISSION_STOCK_PURCHASE`-gated with a server-derived `GROCY_USER_ID` actor and closed request bodies):

```
POST /api/grocy-ai/capture/trips                      StartCaptureTrip   (201)
POST /api/grocy-ai/capture/trips/{tripId}/scan        ScanCaptureTrip    (201, body {barcode})
GET  /api/grocy-ai/capture/trips/{tripId}             CaptureTrip        (LoadTrip)
PUT  /api/grocy-ai/capture/trips/{tripId}             UpdateCaptureTrip  ({status:'reviewing'} | {default_location_id?, default_shopping_location_id?})
PUT  /api/grocy-ai/capture/trips/{tripId}/lines/{seq} UpdateCaptureLine  ({quantity|price|selected} | {delete:true})
```

## Coalescing evidence

Fixture (`012345678905` → product 101 owned; `4006381333931` unused):
- Scan `012345678905` → `known`, `resolved_product_id=101`, qty 1, seq 1.
- Scan `4006381333931` → `unknown`, `resolved_product_id=null`, qty 1, seq 2.
- Scan `012345678905` again → same line id, qty **2** (coalesced, not a new line); trip still has 2 lines.
- After inserting a product_barcode owner for `4006381333931`, `LoadTrip` re-resolves that line to `known`/product 102.
- `stock` and `stock_log` snapshots are byte-identical (empty) across start/scan/coalesce/load/re-resolve/edit.

The migration's UNIQUE index also rejects a second same-canonical raw INSERT (asserted in `capture-contract`).

## Verification

- `php8.5 run.php capture-contract` — **GREEN** (`Capture contract shapes passed`): schema shapes + lifecycle + known/unknown + coalescing + UpdateLine + Q10 re-resolve + zero-stock-write boundary.
- `assert-expected-red.sh 'EXPECTED_RED: capture.engine_invariants' -- php8.5 run.php capture-invariants` — still RED at the deferred `ChecksumForTrip, CommitTrip` (commit wave).
- All 5 capture methods are `User::PERMISSION_STOCK_PURCHASE`-gated (per-method-body grep, GREP-01).
- Real Slim app registers exactly the 5 capture routes with the correct HTTP methods under the Cors+Json group.
- `php8.5 -l` clean on `GrocyAiCaptureService.php`, `GrocyAiApiController.php`, `routes.php`.
- Regression: default suite `All 127 grocy_AI checks passed`; `bulk-contract` and `bulk-generate-endpoint` still pass.

## Decision and next step

The live-capture queue is green with a proven zero-stock-write boundary. The next wave adds `ChecksumForTrip` + `CommitTrip` (the single audited stock-write exception via `StockService::AddProduct`, checksum + per-item `applied_at` idempotency, partial commit) to turn `capture-invariants` green.
