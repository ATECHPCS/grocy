# Automated isolated trip rehearsal verification — 2026-10-12

The integrated backend rehearsal passes on PHP 8.5 with an entirely new temporary SQLite database. No product code changed. No production database, runtime configuration, secrets, or network provider was used.

## Integrated scenario

Run `php8.5 custom/grocy_AI/tests/automated_trip_rehearsal.php`.

The test runs native `DatabaseMigrationService::MigrateDatabase` (256 migration records), then real capture/research/product/receipt services and native `StockService::AddProduct`, `GetProductDetails`, and stock compaction. There is no stock writer fake, stock subclass, query adapter, or reduced native schema. Module migrations bootstrap normally. Test reference rows use an active shelf, explicit piece unit, and two stores. Repository defaults are loaded after clearing process-local `GROCY_*` environment overrides; OCR URL/key are explicitly empty. A temporary `GROCY_DATAPATH` owns the new database and receipt images and is removed on exit.

Five scans produce three capture lines with quantities 2, 2, 1: known `012345678905`, unknown `4006381333931`, and unknown `4012345678901`. Two unknown jobs are claimed and completed through the real research service with deterministic version 1 provider contract results. Real product approval transactions create one standalone product and one branded child plus new generic parent, attach original barcodes, and re-resolve capture ownership. The generic parent receives no barcode or stock.

Two PNGs upload through direct in-process PSR-7 `GrocyAiApiController` calls and persist as private receipt images. Both extraction calls return unavailable-OCR manual entry. Controller receipt edits pair four allocations across two stores, including the same known product at different prices. A receipt-only 2.00 item is ignored without an allocation. The first receipt reconciles at 9.00; the second contains 11.00 of receipt evidence and an explicitly accepted 0.50 difference against printed 11.50, with the authenticated actor audited. Readiness is blocked while the second receipt is unfinished and ready after review.

Complete stock and stock-log snapshots stay identical after scans, research, approval, receipt review, and before explicit commit. The initial stock seed is a native purchase of 4 units with tare handling enabled. Explicit commit applies exactly four allocations; a second call with the confirmed checksum returns `already_committed` and preserves every stock/log row.

| Product | Before | After | Delta |
| --- | ---: | ---: | ---: |
| Known fixture milk (101) | 4 | 6 | +2 |
| Standalone fixture cereal (102) | 0 | 2 | +2 |
| Generic carrots parent (103) | 0 | 0 | 0 |
| Branded carrots child (104) | 0 | 1 | +1 |

Both native stock entries and native purchase logs are checked for these exact allocations:

| Product | Quantity | Unit price | Store |
| --- | ---: | ---: | ---: |
| 101 | 1 | 3.00 | 7 |
| 102 | 2 | 3.00 | 7 |
| 101 | 1 | 4.00 | 8 |
| 104 | 1 | 5.00 | 8 |

Four `commit_allocation` audit rows are required. The ignored item and generic parent receive none.

## Fresh verification commands and results

- `php8.5 custom/grocy_AI/tests/automated_trip_rehearsal.php`: one integrated scenario passed; 256 native migration records; exact deltas/logs and unchanged retry confirmed.
- `php8.5 custom/grocy_AI/tests/run.php`: all **274** module checks passed. Existing module messages explicitly skip snapshot rehearsals because no local snapshot is present.
- `php8.5 custom/grocy_AI/tests/<name>.php`, for each of the following **18** current standalone scripts: all exited 0.
  - `capture_cancellation`, `capture_delete_with_receipt`, `capture_native_transaction`, `capture_quota_recovery`, `capture_research_approval`, `capture_research_backfill`, `capture_research_drafts`, `capture_research_queue`, `capture_research_queue_prerequisites`, `capture_research_worker_api`, `capture_web_search_requeue`.
  - `receipt_api`, `receipt_audit`, `receipt_commit`, `receipt_extractor`, `receipt_rehearsal`, `receipt_scan_matches`, `receipt_storage`.
  - `receipt_commit` specifically passed **17** named cases, including stale checksum/owner checks, applied evidence immutability, partial retries, native tare amounts, and stock/audit rollback.
- `php8.5 -l custom/grocy_AI/tests/automated_trip_rehearsal.php`: no syntax errors.
- `git diff --check --cached`: passed after staging only this regression and report.

An additional optional `php8.5 custom/grocy_AI/tests/capture_native_snapshot.php` exited **2** with `A scrubbed local Phase 6 snapshot is required`. That separate real-data snapshot gate is unavailable; it is not a failure of the isolated scenario. No snapshot was obtained and no production reads were performed.

## Limits

This is service integration plus direct receipt controller execution, not a full HTTP/browser/camera end-to-end run. It uses generated tiny PNGs and unavailable OCR manual fallback; it does not assess photography, image legibility, live OCR accuracy, provider discovery quality, or external-service availability. Deterministic worker results enter the genuine research completion and approval pipeline without calling providers. Router middleware/session startup are outside this script; native controller permission checks run against the isolated migrated admin fixture. The independent controller browser suite is being run separately and was pending when this backend report was written; no browser result is claimed here.

No stock or catalog change reached production. Human trials remain a separate exercise.

## Browser assertion correction and targeted verification

The initial independent full browser run (`npm run test:release`, 583 scheduled tests) stopped with exit 130 after **234 passed, 1 failed, 1 interrupted, 347 not run**. The failed `@flowreview approval retains both confirmations, exact body and created product identity after reload` case expected an approval request without `parent_mode`; the current deliberate standalone selection sends `parent_mode: 'standalone'`. The exact request-body assertion now explicitly includes that field. Both confirmations, the created-product notice after reload, and zero stock commits remain asserted. No application code changed. The separate shared-receipt case was interrupted when the run was stopped, so that result is not classified as a product failure.

Fresh targeted command from `custom/grocy_AI/tests/browser`:

`npx playwright test specs/purchase-capture.spec.js --grep 'approval retains both confirmations, exact body and created product identity after reload' --project=chromium-mobile --project=webkit-mobile`

Result: **2 passed (11.5s)**, exit 0. The isolated PHP rehearsal also passed again after adding an explicit assertion that standalone cereal has `parent_product_id IS NULL`. Syntax and diff checks passed. The full browser rerun is pending; this report does not claim a green full browser suite. Initial full-run log: `/tmp/grocy-20261012-browser-release.log`.

## Completed controller verification

The fresh full browser command `env -u NO_COLOR -u FORCE_COLOR npm --prefix custom/grocy_AI/tests/browser run test:release -- --workers=1` completed with **583 passed (19.8 minutes), exit 0**, after the exact standalone approval-body assertion correction. Final log: `/tmp/grocy-20261012-browser-release-final.log`. This is browser fixture coverage in Chromium mobile/desktop and WebKit mobile, not a real HTTP UI-to-backend run.

The controller independently reran `php8.5 custom/grocy_AI/tests/automated_trip_rehearsal.php`; native 256-migration schema, exact stock/log deltas and unchanged duplicate retry all passed. Final module gate remains274 checks and combined Node tests8 passed. Independent review and scoped correction review approved the test; no production code or household data changed. Human camera, physical permission/keyboard/gesture behavior, actual OCR/provider quality, and real HTTP/session acceptance remain separate.
