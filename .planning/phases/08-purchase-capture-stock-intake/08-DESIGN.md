# Phase 8 — Purchase Capture & Deferred Stock Intake (design)

**Added:** 2026-09-01 (post-milestone). **Depends on:** Phase 2. **Rigor:** lean (build + test + deploy).

## Problem

Scan groceries fast at the store, review/audit later at home, then commit selected items to Grocy stock as one auditable purchase batch. Today the module only does single-product master-data enrichment inside the product form; there is no capture queue and no stock-write path.

## Locked decisions (grilled 2026-09-01)

| # | Decision |
|---|---|
| Q1 | Build fresh in `grocy_AI` |
| Q2 | Live connection; server-side queue (no offline/sync) |
| Q3 | v1 known-barcode → stock; unknowns flagged, handed to `/product/new` enrichment |
| Q4 | Commit = native Grocy purchase via `StockService::AddProduct`, smart defaults |
| Q5 | Discrete trips: `open → reviewing → committed` |
| Q6 | Same barcode coalesces into one incrementing line |
| Q7 | Scan count = purchase units; apply purchase→stock factor + barcode amount override at commit |
| Q8 | Dedicated `/grocyai/capture` + `/grocyai/capture/review` pages + sidebar tile |
| Q9 | Partial commit (selected known items go; rest stays in trip) |
| Q10 | Unknowns auto re-resolve by barcode on review open |
| Q11 | Lean per-line edits + trip-level location/store defaults |
| Q12 | Mirror Phase 5 safety: checksum + per-item `applied_at` ledger + in-lock re-resolve |
| Q13 | Lean rigor; portable/stable mirror deferred to Phase 7 |
| Q14 | Committed trips archive read-only with the Grocy `transaction_id` |
| def | Reuse enrichment scan component; gate on `STOCK_PURCHASE` |

## Schema (`grocy_ai_capture_*`, namespaced, `CREATE TABLE IF NOT EXISTS`, never alters a native table)

```
grocy_ai_capture_migrations(version PK, applied_at)

grocy_ai_capture_trips(
  id PK, created_at, created_by,
  status CHECK(status IN ('open','reviewing','committed')),
  default_location_id NULL, default_shopping_location_id NULL,
  transaction_id NULL, committed_at NULL, checksum NULL, module_version)

grocy_ai_capture_lines(
  id PK, trip_id FK, seq,
  scanned_barcode, canonical_gtin NULL, resolved_product_id NULL,
  status CHECK(status IN ('known','unknown','conflict')),
  quantity DEFAULT 1, price NULL, best_before_override NULL,
  selected CHECK(selected IN (0,1)) DEFAULT 1,
  applied_at NULL, outcome NULL, created_at, updated_at)
-- coalescing: UNIQUE index on (trip_id, COALESCE(canonical_gtin, scanned_barcode))

grocy_ai_capture_audit(
  id PK, trip_id FK, line_id NULL, actor, action,
  before_json NULL, after_json NULL, transaction_id NULL, created_at)  -- append-only
```

## Service surface — `GrocyAiCaptureService` (new; the only module code allowed to write stock)

- `StartTrip(actor)` → trip row (`open`)
- `ScanIntoTrip(tripId, barcode)` → resolve via `GrocyAiBarcodeService::ResolveOwner`; coalesce on canonical/barcode; increment qty
- `LoadTrip(tripId)` → trip + lines; re-resolves `unknown` lines by barcode (Q10)
- `UpdateLine(tripId, seq, {quantity|price|selected|delete})`, `SetTripDefaults(tripId, location, store)`, `SetStatus(tripId, 'reviewing')`
- `ChecksumForTrip(tripId)` → SHA-256 over selected known lines (mirrors `GrocyAiBulkService::ChecksumForPlan`)
- `CommitTrip(tripId, actor, confirmedChecksum)`:
  1. recompute + `hash_equals` checksum → refuse `checksum_mismatch` before any write
  2. `BEGIN IMMEDIATE`
  3. for each **selected + known** line with null `applied_at`: re-resolve owner in-lock (changed owner → `conflict`, skip); `StockService::AddProduct(productId, qty·purchase→stock factor / barcode override, bestBefore=override|auto, 'purchase', today, price, location, store, &txnId, …, note)` sharing one `transaction_id`; stamp `applied_at`
  4. single `COMMIT` (or `ROLLBACK` on throw)
  5. write audit rows; if every selected line applied → trip `committed` + `committed_at` + `transaction_id`; else stays `reviewing` (partial, Q9)

## Routes

```
POST /api/grocy-ai/capture/trips                         StartTrip
POST /api/grocy-ai/capture/trips/{id}/scan               ScanIntoTrip {barcode}
GET  /api/grocy-ai/capture/trips/{id}                    LoadTrip (re-resolves unknowns)
PUT  /api/grocy-ai/capture/trips/{id}                    SetTripDefaults / SetStatus
PUT  /api/grocy-ai/capture/trips/{id}/lines/{seq}        UpdateLine
POST /api/grocy-ai/capture/trips/{id}/commit             CommitTrip {confirmed_checksum}
GET  /grocyai/capture            (page) scan loop
GET  /grocyai/capture/review     (page) trip list + review
```
All `/api/*` under the existing Cors+Json middleware and `STOCK_PURCHASE`; enrichment handoff link uses the existing `/product/new` (MASTER_DATA_EDIT).

## Reuse / boundary

- **Reuse:** `GrocyAiBarcodeService::ResolveOwner` (known/unknown), `GrocyAiService::EnrichByUpc` (unknown handoff), `GrocyAiBulkMigration` pattern, `GrocyAiBulkService` checksum/idempotency shape, native `StockService::AddProduct`.
- **Boundary crossing:** the module's `PROTECTED_CONSUMER_CATEGORIES` invariant (never write `stock`/`purchase`) is intentionally superseded **only** through `CommitTrip`'s audited path. Capture/scan/review remain zero-stock-write. This is the single documented exception.

## Native-purchase facts this relies on (verified)

- `AddProduct` requires only `amount`; best-before auto-computes from `default_best_before_days`; price/location/store optional; multiple adds sharing `transaction_id` group as one batch. (`services/StockService.php:112`, `controllers/Api/StockApiController.php:85`)
- Unknown barcode hard-throws with no auto-create → validates the known/unknown split. (`StockService.php:837`)
- `qu_factor_purchase_to_stock` + `product_barcodes.qu_id/amount` exist; the native API stores `amount` as-passed, so the factor is applied in `CommitTrip`, not by the API. (`migrations/0103.sql`)
