# 06-02 — DATA-06 zero-diff harness + DATA-07 rollback/idempotency rehearsal (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 147 checks passed** (was 144).

## Artifacts

| Path | Provides |
|---|---|
| `custom/grocy_AI/bin/verify-inventory-diff.php` | Deterministic per-table `{count, per-column sha256}` manifest + allowlist diff. CLI: `--db PATH (--before FILE \| --after FILE [--approved-tables a,b] [--approved-columns t.c]) \| --print`. Also a library (`inventoryDiffManifest`, `inventoryDiffCompare`) required by the test. |
| `custom/grocy_AI/tests/inventory_diff.php` | Drives the harness + the existing bulk engine (GeneratePlan/ApplyPlan/PreviewRollback/RollbackPlan) to prove DATA-06/07 on an in-memory fixture and, when present, on the 06-01 snapshot. Registered in `tests/run.php` (runs in the default suite). |
| `tests/run.php` | Requires `inventory_diff.php` and calls `runInventoryDiffSuite()` before the summary. |

## Harness contract (the manifest + allowlist model)

- **Manifest**: for each user table (never `sqlite_%` internals) → `{ count, columns: {col => sha256}, keyed }`. Deterministic and independent of physical row order: each cell is bound to its **row identity** (the table's PRIMARY KEY, falling back to the whole-row tuple when a table has none), and the per-column `(identity,value)` pairs are sorted before hashing. Values are type-tagged so `null`, `''`, `'0'`, `0` never collide. The CLI opens the DB `PRAGMA query_only=1` (read-only).
- **Diff + allowlist**: a table "changes" if its row count differs or any column hash differs.
  - `--approved-tables` — the table may change in any way (used for the module write targets).
  - `--approved-columns` `table.column` — that **cell** change is allowed; structural changes (table created/dropped, rows added/removed) still require table-level approval.
  - Any change outside the allowlist → non-zero exit, naming the offending `table` and `column(s)`.

## Rehearsal result shape (what was proven)

Against an in-memory production-shaped fixture (native tables + seeded stock/stock_log) **and** the real 06-01 snapshot (copied to a temp file; seeded evidence for two real unclassified products):

- **DATA-06**: applying a taxonomy plan changes only `grocy_ai_taxonomy_classifications` + the `grocy_ai_bulk_*` ledger; every native table (`products`, `product_groups`, `quantity_unit_conversions`, `cache__quantity_unit_conversions_resolved`, `stock`, `stock_log`) is byte-identical. No allowlist violations.
- **DATA-07 rollback**: after apply→rollback, all native tables are byte-identical to pre-apply, only approved module tables differ, and every product's **effective classification** (non-null leaf) returns to its pre-apply value. (The raw `grocy_ai_taxonomy_classifications` keeps NULL-leaf *tombstone* rows with fresh timestamps — the Phase 5 engine's audited representation of "unclassified" — so DATA-07 is asserted as native byte-identity + semantic restoration, not byte-identity of the module's own audit table.)
- **Idempotency**: a second `ApplyPlan` of the same plan adds **zero diffs across all tables**.
- **CI-safe**: absent snapshot → the rehearsal is skipped (a passing check), not failed.

## Notes / decisions

- The harness correctly catches **trigger cascades**: a CLI smoke test editing `products.name` on the real schema also changed `cache__quantity_unit_conversions_resolved` (a prod cache rebuild), which was flagged — exactly the hidden-change class DATA-06 must detect.
- RED-first was validated: with `bin/verify-inventory-diff.php` absent the suite fails on `inventory_diff: the diff harness ... is available` (exit 1); present → exit 0.
- `check()` is silent on pass, so `runInventoryDiffSuite()` emits one STDOUT marker line (`[inventory_diff] DATA-06 ... rollback ... idempotent ... rehearsal ...`) so the harness's run is visible to the plan's grep-based verifies.
</content>
