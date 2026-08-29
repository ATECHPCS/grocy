# Phase 04 Conversion Characterization

## Gate result

The fixture-only dual-branch gate passed for the immutable checkouts below. Reusable rules, profiles, and any projection remain **inactive**: this characterization proves the existing native-cache contract only and does not test or select a reusable projection.

| Checkout | Immutable commit |
| --- | --- |
| main | `f53665ca19c0e2abcd095f79a93865cfbf396a1d` |
| stable | `6605ae6c2034c6679381de185cc567d80d38db79` |

## Cache and trigger facts

- `migrations/0208.sql` SHA-256: `5f7ce1eca78e557c9fb3114d31c7e340d1d9d00885b7a675dbb79868442add60` on both branches.
- `migrations/0225.sql` SHA-256: `f937c33c46c8becc38ee26be0db09f8c8de99399e2e3a039a056ccd67e9eff0d` on both branches.
- The matching cache objects are `cache__quantity_unit_conversions_resolved`, its performance index, and the `quantity_unit_conversions_INS`, `_UPD`, and `_DEL` cache-maintenance triggers.
- Each disposable fixture defines its resolver and cache from the actual branch `0208.sql` and the cache/trigger prefix of `0225.sql`, including native INSERT, UPDATE, and DELETE conversion triggers.
- The migration SQL is read from `HEAD` only after the two migration paths are clean, and each fixture manifest binds its expected immutable branch commit; identical checkout roots are rejected.
- The disposable fixture exercised one native default and one product override. Baseline and probe cache aggregates both held seven row-key/factor/path records with SHA-256 `9944d97bc06a8c684f8a688f097ae0ea946db79926a606f07fbe91d941b69c5b`.
- The eight behavior-specific fixture plans each use `ix_cache__quantity_unit_conversions_resolved_performance1` for the cache key `(product_id, from_qu_id, to_qu_id)` on both branches; any cache aggregate or query-plan difference fails closed.
- The deterministic redacted manifest has query-plan SHA-256 `419f0d3a2a7e968c252ff3cb8f7463adb4177d8053da4f4efd81bfd4c1583709` on main and stable.

## Protected-output parity

Both branches produced the same baseline and post-write values through separate stock-adjustment, recipe-ingredient, purchase-package, consumption, price, transfer-balance, meal-plan, and quantity-display fixture tables/queries:

| Protected category | Fixture output | Path |
| --- | ---: | --- |
| stock | 8 | `/1/3/` |
| recipe | 3.5 | `/3/1/` |
| purchase | 1 | `/2/1/` |
| consumption | 4.5 | `/3/1/` |
| price | 5.5 | `/3/1/` |
| transfer | 6 | `/1/3/` |
| meal-plan | 6.5 | `/3/1/` |
| quantity-display | 1.5 | `/2/1/` |

Each temporary SQLite fixture was deleted. The harness resolves the configured `GROCY_DATAPATH` from the environment (or Grocy's checkout-local `data` default), records its opened paths, and directly proves it did not open a path at or below that configured path. It stores no household values, raw database dumps, product names, barcodes, URLs, or secrets.

## Selected projection

**No projection is selected.** The concrete blocker is that the passing fixture evidence covers only the established native default and product-override behavior; it does not establish safe precedence, ownership, or consumer parity for any proposed reusable-rule projection. Later work must retain the inactive gate until a named candidate projection is exercised against current immutable main/stable evidence and all protected outputs remain equal.
