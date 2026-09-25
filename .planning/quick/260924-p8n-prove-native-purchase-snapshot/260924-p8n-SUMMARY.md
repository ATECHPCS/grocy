---
quick_task: 260924-p8n
title: Prove real native purchase on a disposable scrubbed snapshot
date: 2026-09-24
subsystem: purchase-capture
requirements-touched: [CAP-05, CAP-06, CAP-08]
---

# Quick task 260924-p8n: Native purchase snapshot acceptance

## Finding and fix

The current production-shaped Grocy database has no `products.qu_factor_purchase_to_stock`; migration 0207 removed it. Capture queried that column when a barcode had no amount override, so a real commit would have failed. The contract fixture now models the current schema and resolved cache, and capture reads `uihelper_product_details.qu_factor_purchase_to_stock`, matching native `StockService::GetProductDetails()`.

## Evidence

- A new consistent copy was obtained through the documented read-only snapshot refresh. The local scrubbed copy contains 445 products, passes `PRAGMA integrity_check`, and has zero API keys and sessions.
- Snapshot-backed PHP suite: **272 checks passed**. DATA-06/07 rehearsal ran against a temp copy; the taxonomy rerun's confident count rose 57 to 63. The conversion audit found 62 global plus 18 expected product package conversions across 9 products and zero suspicious rows.
- The red capture invariant failed with the obsolete column, then passed after switching to the derived view.
- `capture_native_snapshot.php` copied only the scrubbed snapshot into a disposable directory, used a real owned GTIN and native `StockService::AddProduct()`, and verified the booked stock delta, native compaction, and no second stock change on repeat commit. **PASS.** The test prints no product or barcode values.

## Remaining Phase 8 acceptance

Plan 08-06 remains open. Its browser spec is present and green (16/16 focused, 206/206 full matrix from quick task 260924-p8s), but the signed-in deployed capture/review smoke and stable-image parity have not been performed. This snapshot exercise is predeployment evidence, not a production stock write.
