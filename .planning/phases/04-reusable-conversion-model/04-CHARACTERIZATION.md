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
- The disposable fixture exercised one native default and one product override. Baseline and probe cache aggregates both held four row-key/factor/path records with SHA-256 `5e40e7e4877455b777bf15976a909e52e3a8f04570de360293e3693e45334339`.
- The fixture query plan uses the cache key `(product_id, from_qu_id, to_qu_id)` for the protected lookup.

## Protected-output parity

Both branches produced the same baseline and post-write values:

| Protected category | Factor | Path |
| --- | ---: | --- |
| stock | 2 | `/1/3/` |
| recipe | 0.5 | `/3/1/` |
| purchase | 0.001 | `/2/1/` |
| consumption | 0.5 | `/3/1/` |
| price | 0.5 | `/3/1/` |
| transfer | 2 | `/1/3/` |
| meal-plan | 0.5 | `/3/1/` |
| quantity-display | 0.001 | `/2/1/` |

Each temporary SQLite fixture was deleted. The harness records its opened paths and directly proves it did not open a path at or below the configured `GROCY_DATAPATH` test sentinel. It stores no household values, raw database dumps, product names, barcodes, URLs, or secrets.

## Selected projection

**No projection is selected.** The concrete blocker is that the passing fixture evidence covers only the established native default and product-override behavior; it does not establish safe precedence, ownership, or consumer parity for any proposed reusable-rule projection. Later work must retain the inactive gate until a named candidate projection is exercised against current immutable main/stable evidence and all protected outputs remain equal.
