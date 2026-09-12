# 06-06 (re-planned) — classifying read-only conversion audit + integrity tripwire (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 249 checks passed** (was 222; +27 from the new `conversion_audit` block). All pre-existing arg-based `taxonomy-*`/`bulk-*`/`conversion-*` suites still pass unchanged.

This plan closes DATA-03/04/05 **by verification**, using the corrected real-data invariant. The old 06-06 (branch `codex/phase6-06-audit`) asserted "0 product-specific conversions; any `product_id NOT NULL` = regression". The 09-12 snapshot proved that stale: it holds **80 conversions = 62 global + 18 product-specific across 9 products**, every one a legitimate package-size definition added via product-intake. This audit reclassifies rather than condemns: product-specific rows are EXPECTED unless they fail explicit integrity rules, and the tripwire fires only on genuinely suspicious rows. **No deletion, cleanup, or comparison-write code is built — the audit issues only SELECTs.**

## The four integrity rules (the invariant)

A product-specific `quantity_unit_conversions` row is **EXPECTED** (legitimate package definition) iff ALL hold; failing any one makes it **SUSPICIOUS**, named with the first rule it failed:

| # | Rule | Suspicious identifier when failed |
|---|------|-----------------------------------|
| 1 | `product_id` references an existing `products` row (referential integrity). | `orphaned_product_id` |
| 2 | `from_qu_id` OR `to_qu_id` equals the product's `qu_id_stock` or `qu_id_purchase` (defines the product's own discrete unit in measured terms). | `neither_side_product_unit` |
| 3 | `factor` is finite and > 0. | `non_positive_or_non_finite_factor` |
| 4 | It is not an exact `(from_qu_id, to_qu_id, factor)` match of a global (`product_id IS NULL`) row. | `global_duplicate` |

Rules are applied in order; an orphaned row is named by rule 1 (its product units can't be read). A product row that shares a global's `from`/`to` but carries a **different** factor is NOT a duplicate — proven by a dedicated assertion.

## Classification result on the real 06-01 snapshot

`custom/grocy_AI/.snapshots/grocy-prod.sqlite` (symlinked, read-only; opened with `PRAGMA query_only = 1`):

- **62 global** conversions (`product_id IS NULL`) — generic unit defaults.
- **18 product-specific** conversions across **9 products** (#186, 499–503, 505–507) — each a product's own Piece/Can unit → a measured g/oz/ml unit plus its trigger-generated inverse (9 authored → 18 stored).
- **18 EXPECTED / 0 SUSPICIOUS** → the CLI reports `62 global + 18 expected / 9 products` and **exits 0**.

**Baseline recorded:** 62 global + 18 expected across 9 products, 0 suspicious.

## The tripwire behavior

`bin/audit-conversions.php` exits **non-zero iff any SUSPICIOUS row exists**, printing each offender's `id`, `product_id` (+ product name when present), `from_qu_id`, `to_qu_id`, `factor`, and the failed `rule`. It does NOT fire on legitimate per-product package definitions. A future orphaned reference, non-positive/non-finite factor, a conversion touching neither of the product's own units, or a redundant global-duplicate is caught deterministically — the durable regression guard the requirement asked for, without flagging real package data.

## Read-only guarantee (provably zero-write)

- The library (`auditConversions(PDO): array`) and CLI issue only `SELECT` + `PRAGMA query_only = 1`; the audit source contains **no** `INSERT`/`UPDATE`/`DELETE`/`REPLACE`/`DROP`/`ALTER` (asserted by a source-grep gate in the test).
- Zero-write proven by **row-VALUE equality** of `quantity_unit_conversions` before/after repeated audit calls (per MISTAKES.md — `total_changes()` is NOT used, since a rolled-back write would still bump it). The 62 globals + 18 package definitions are left byte-identical; verified against the real snapshot too (before/after `SELECT` hash identical after a CLI run).

## Suite growth (RED-first)

`conversion_audit` (27 checks) runs in the default no-arg suite via the shared `check()`, registered in `run.php` after the `group_suggestion` block, requiring `bin/audit-conversions.php` guarded by `is_file`. RED-first verified: with the bin removed the suite fails on `conversion_audit: the read-only audit library bin/audit-conversions.php is available` (223 checks, 1 failed); GREEN once restored (222 → 249). Covers: EXPECTED-on-legit-package-def with exact baseline counts; a trip + correct rule name for each of the four suspicious forms (orphan / zero / negative / non-finite factor / neither-side / global-duplicate); the differing-factor non-duplicate; row-value equality; the source-grep read-only gate; and an asserting snapshot baseline (62/18/9, 0 suspicious) guarded CI-safe.

## Files

- **New:** `custom/grocy_AI/bin/audit-conversions.php` (classifier library + read-only CLI); `custom/grocy_AI/tests/conversion_audit.php` (RED-first proof).
- **Changed:** `custom/grocy_AI/tests/run.php` (register `conversion_audit` after `group_suggestion`).
- **Superseded (not merged):** the old no-op audit on branch `codex/phase6-06-audit` — inspected for CLI scaffolding only; the new classifying invariant replaces it.

## Deviations / notes

- The non-finite factor case is exercised by inserting the SQL literal `1e309` (overflows to `INF`, round-trips as non-finite) — a bound PHP `INF` stores as the string `"INF"` which casts to `0.0` and would be caught by the non-positive branch instead, so the literal is used to hit the `is_finite` branch directly.
- **Uncertainty:** none material — the full pre-existing suites pass unchanged and the CLI result on the real snapshot matches the recorded baseline exactly (exit 0, byte-identical table).
