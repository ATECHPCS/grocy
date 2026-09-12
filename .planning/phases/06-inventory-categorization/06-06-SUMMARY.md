# 06-06 — read-only conversion audit + product-specific tripwire (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 156 checks passed** (was 147).

**Requirements:** DATA-03 / DATA-04 / DATA-05 — addressed by verification (a read-only audit), no deletion/comparison/cleanup machinery built.

> **Deviation from the plan's premise — READ THIS.** The plan (written 2026-09-08) asserted "62 rows, every one `product_id IS NULL`, 0 product-specific." That figure is **stale**. The live 06-01 snapshot (2026-09-12, already recorded as `quantity_unit_conversions=80` in 06-01-SUMMARY) holds **18 product-specific conversions across 9 products** plus **62 global** conversions. The audit and its tripwire were built exactly to the plan's contract; running the CLI against the real snapshot therefore **fires the tripwire (exit 1)** and names all 18 offenders — it does not report a clean "0 product-specific." See "Observed reality" below; this needs a phase-owner decision.

## Artifacts

| Path | Provides |
|---|---|
| `custom/grocy_AI/bin/audit-conversions.php` | Read-only conversion audit. Library `auditConversions(PDO): {product_specific_count, global_count, offenders, ok}` (SELECT-only, performs no writes). CLI: `audit-conversions.php [--db PATH]` — opens the DB `PRAGMA query_only=1`, defaults to the 06-01 snapshot, prints the summary, exits 0 on zero product-specific rows or **non-zero (naming every offending row + product)** when the tripwire finds any. |
| `custom/grocy_AI/tests/conversion_audit.php` | RED-first proof: global-only table passes and the global count is reported; one product-scoped row trips the audit and is named; the audit is zero-write (row-value equality **and** unchanged `total_changes()`). Plus an INFORMATIONAL snapshot report that never fails the suite. Registered in `tests/run.php` (runs in the default suite). |
| `tests/run.php` | Requires `conversion_audit.php` and calls `runConversionAuditSuite()` (appended immediately after the `inventory_diff` registration block). |

## Audit contract

- **Product-specific = regression.** A conversion whose `product_id` is not null is treated as an offender; the audit's `ok` is true only when the product-specific count is zero.
- **Global set is summarized, never touched.** Rows whose `product_id` is null are counted and reported; the audit issues only SELECTs, so those rows are left byte-identical.
- **Tripwire.** Any product-specific row → the CLI names each offending row (`conversion id`, `product_id` + resolved product name, `from_qu_id`, `to_qu_id`, `factor`) and exits non-zero.
- **Zero-write, proven three ways.** (1) Test: the conversions table is byte-identical (`SELECT *` row-value equality) after two audit calls; (2) Test: `total_changes()` is unchanged across the audit (valid — the audit path executes no write, per MISTAKES.md); (3) CLI smoke: the snapshot file md5 is identical before/after a full CLI run.

## Observed reality (live 06-01 snapshot, 2026-09-12)

- **62 global** conversions (`product_id IS NULL`).
- **18 product-specific** conversions across **9 products** — all package-size conversions (piece ↔ gram/ounce/pound), which look like *intentional* per-product Grocy conversions, not junk:
  - 186 cherry tomato · 499 Grimmway Farms Matchstik Carrots, 10 oz · 500 Iberia Evaporated Coconut Milk · 501 Fresh Limes, 2 lb Bag · 502 Conchita Guava Paste · 503 Iberia Calamar Jumbo al Ajillo · 505 Iberia Sardines in Vegetable Oil, 4.2 oz · 506 Kirkland Roasted Seaweed Snack, 17 g · 507 Happy Farms Mexican Style Finely Shredded Cheese, 12 oz.

**Decision needed (phase owner / main):** the plan's premise ("no product-specific conversions exist, so a no-op audit + tripwire suffices") no longer holds. Either (a) these 18 are legitimate per-product conversions and the requirement/tripwire semantics should be revisited (product-specific ≠ regression), or (b) they are unwanted and Phase 6 needs the cleanup path the plan assumed away. The audit built here is the correct instrument to surface this and to guard whichever invariant is chosen; no code change is required to re-point it.

## Notes / decisions

- **Suite stays green on purpose.** The pass/trip/zero-write assertions run against in-memory fixtures. The real-snapshot section is INFORMATIONAL only — it reports the observed counts (`18 product-specific, 62 global`) via a marker line and never asserts pass/fail, so the presence of product-specific rows in the current snapshot does not turn the module suite red. The enforcing path is the CLI (non-zero exit), which is where the tripwire lives.
- **RED-first validated:** with `bin/audit-conversions.php` absent the suite fails on `conversion_audit: the read-only audit library ... is available` (exit 1); present → the nine new checks pass.
- **No deletion code.** Per DESIGN Q8 and the requirement framing, nothing removes conversions; the audit is strictly read-only.
