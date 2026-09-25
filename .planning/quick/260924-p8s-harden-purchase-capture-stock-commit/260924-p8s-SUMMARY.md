---
quick_task: 260924-p8s
title: Harden the Phase 8 purchase commit before release
date: 2026-09-24
subsystem: purchase-capture
requirements-touched: [CAP-05, CAP-06, CAP-08]
---

# Quick task 260924-p8s: Purchase commit transaction safety

## Result

Two stock-write defects found during the Phase 8 production review are fixed on the isolated `codex/production-phases1-8` branch. No stable branch, production image, or household database was changed.

1. `StockService::AddProduct()` can call `CompactStockEntries()` while capture owns `BEGIN IMMEDIATE`. When compaction had a split to process, its nested PDO `beginTransaction()` threw `There is already an active transaction`. The new focused test reproduced this failure. Compaction now joins a caller-owned transaction and leaves its commit/rollback to the caller; standalone calls still manage their own transaction. The test verifies the caller's rollback restores both stock rows.
2. `CommitTrip()` checked the reviewed checksum before acquiring its write lock. A line changed in that gap could reach the native stock writer. A deterministic PDO seam changed the quantity immediately before `BEGIN IMMEDIATE` and reproduced the defect. Capture now re-reads trip status and checksum under the lock and refuses the stale review with `checksum_mismatch`, zero stock calls, and no open transaction.

## Verification

- RED reproduction: native compaction failed with `There is already an active transaction`; the interleaved line edit reached stock before the fix.
- `php8.5 custom/grocy_AI/tests/capture_native_transaction.php` — PASS for caller-owned and standalone compaction.
- `php8.5 custom/grocy_AI/tests/run.php capture-invariants` — PASS, including the interleaved line edit.
- `php8.5 custom/grocy_AI/tests/run.php` — **261 checks passed**. Snapshot-dependent checks were skipped because the scrubbed Phase 6 snapshot is absent in this checkout.
- `npm run test:release -- --grep @cap` — **16 passed** across Chromium and WebKit.
- `npm run test:release` — **206 passed** across Chromium and WebKit.
- `GROCY_AI_PHP=php8.5 bash custom/grocy_AI/tests/release-gate.sh bulk` — PASS.
- PHP lint on changed PHP and `git diff --check` — PASS.

## Remaining release work

- Complete Phase 8 Plan 08-06 with a real native `AddProduct()` purchase exercise on a disposable production-shaped database, updated snapshot evidence, and signed-in mobile acceptance. The existing browser spec is already present and green, but the plan has no completion summary.
- Mirror the complete Phase 4–8 module and documented core hooks to `atech-release`, prove parity and migrations, build an immutable candidate, and rehearse rollback before deploying.
- Complete Phase 1 physical-phone and Phase 5/6 human checkpoints against the exact candidate. Reusable conversion activation remains closed because no projection is selected.
