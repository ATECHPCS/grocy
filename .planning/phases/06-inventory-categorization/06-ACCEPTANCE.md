# Phase 6 — full-phase acceptance (06-07, DATA-06/07)

End-to-end acceptance of the three Phase 6 passes on the 06-01 production snapshot
(`custom/grocy_AI/.snapshots/grocy-prod.sqlite`). Every run operated on a **temp COPY**
(`tempnam()` → `copy()`); the snapshot itself was never mutated (verified: `sha256sum -c` OK after the
run, size 20,209,664 bytes unchanged).

**Honesty note.** The raw snapshot predates enrichment capture — it has **0 `grocy_ai_taxonomy_evidence`
rows** and no product name contains a group name, so the group-suggestion pass yields **0 suggestions on
raw data**. Raw-snapshot reality and the seeded demonstration used to exercise the DATA-06/07 mechanics are
recorded **separately** below; no seeded number is ever presented as a raw-snapshot result.

## Commands

```
# native suite (grew 249 -> 272 with the new categorization endpoint-surface suite)
php8.5 custom/grocy_AI/tests/run.php

# browser matrix (chromium-mobile + webkit-mobile), incl. the new categorization spec
cd custom/grocy_AI/tests/browser && npx playwright test specs/categorization.spec.js
cd custom/grocy_AI/tests/browser && npm run test:smoke
cd custom/grocy_AI/tests/browser && npx playwright test

# read-only report + DATA-06 harness libraries used by the acceptance driver
custom/grocy_AI/bin/audit-conversions.php        # auditConversions(PDO)
custom/grocy_AI/bin/verify-inventory-diff.php    # inventoryDiffManifest / inventoryDiffCompare

# full-phase acceptance driver (operates on a temp COPY of the snapshot)
php8.5 <driver> "$(pwd)"
```

The driver stages a copy, bootstraps the module tables, drives generate → apply → rollback → rerun through
the shipped `GrocyAiBulkService` (`GenerateGroupPlan`, `GenerateClassificationPlan`, `ApplyPlan`,
`PreviewRollback`, `RollbackPlan`, `ReadPlan`) and the `auditConversions` library, and diffs per-table
manifests with `inventoryDiffCompare`.

---

## RAW snapshot reality (no seeding)

| Fact | Value |
|---|---|
| products (total) | 444 |
| ungrouped active products | 224 |
| `grocy_ai_taxonomy_evidence` rows | 0 |
| **GROUP pass — raw suggestions** | **0 items** (counts: `included:0, excluded:2, skipped:442`) |
| **CLASSIFICATION — raw confident count** | **57** (from existing Grocy product-group signals on the already-grouped ~51%) |
| **CONVERSION AUDIT — baseline** | **62 global + 18 expected across 9 products, 0 suspicious, ok=true** |

The group pass produces **0** suggestions on the raw snapshot — truthfully, because there are 0
enrichment-evidence rows and no product names normalize to a group name. The DATA-06/07 group mechanics are
therefore demonstrated only with **seeded evidence** (below); the "ungrouped count drops" criterion is
demonstrable **only** with that seeded evidence.

---

## SEEDED demonstration (temp copy — clearly labeled, NOT raw-snapshot results)

Seeded **LOW-band** provider evidence (`confidence_band = low`) for 3 ungrouped in-scope products
(ids 283, 284, 285) with `provider_category` set to existing active group names
(`Produce` / `Seafood` / `Beverages`), mirroring the 06-05 approach. Low-band evidence does **not** classify
a product on its own (it stays `low_confidence`), but it **does** drive a confident group suggestion — so
the confident-classification rise below is attributable solely to grouping.

### Pass 1 — product-group suggestion (native `products.product_group_id` write)

Plan: 3 items, 3 pre-selected (high), `counts = {included:3, excluded:2, skipped:439, conflicted:0,
changed:3, unchanged:0}`, checksum `f31efa83e98b77ea7dd97c6c37583d776a415f8f1f32a08ba286750521a3990c`.

**DATA-06 (approved-only diff):** apply status `applied`. `inventoryDiffCompare` violations = **`[]`**.
Changed tables:

- `products` — cells_changed: **`product_group_id` only** (the sole authoritative change; approved column).
- `grocy_ai_bulk_plans` / `grocy_ai_bulk_plan_items` / `grocy_ai_bulk_audit` — the append-only module ledger.
- `cache__quantity_unit_conversions_resolved` — **derived cache, rebuilt by a native Grocy trigger** (see
  finding). Approved as a derived cache; its **logical conversion content is byte-identical**
  (`SELECT product_id, from_qu_id, to_qu_id, factor, path` hash equal before/after — only the surrogate
  autoincrement `id` values are regenerated; row count unchanged at 224,602).

**Ungrouped count drops (seeded only):** 224 → **221** (drop of 3) after apply.

**DATA-07 (rollback + zero-diff rerun):** rollback status `rolled_back`;
`products` **byte-identical** to pre-apply (`inventoryDiffCompare` products changes = `[]`); ungrouped count
restored to **224**; regenerating the group plan on the restored DB reproduces the **identical checksum**
(`…a3990c`) → zero-diff rerun **true**.

> **FINDING (native trigger side-effect on the group pass).** On the full snapshot, writing
> `products.product_group_id` fires Grocy's own `products_default_qu_conversions_UPD` trigger, which
> deletes and re-inserts every row of `cache__quantity_unit_conversions_resolved` (224,602 rows) with
> regenerated surrogate ids. The **logical** conversion content is unchanged (proven by the id-excluded
> content hash), and this table is a Grocy-maintained **derived cache**, not authoritative inventory. The
> 06-04 zero-native-write claim ("products + bulk tables only") held only on the trigger-less unit fixture;
> on production-shaped data the group pass's footprint is `products.product_group_id` **plus** this
> trigger-driven cache rebuild. It is approved here with that rationale; the authoritative write is still
> only `products.product_group_id`, fully restored on rollback.

### Grouping raises confident classifications (seeded, isolated)

CLASSIFICATION confident count **57 → 60** after applying the group suggestions for the 3 seeded products
(the low-band evidence alone left them `low_confidence`; the +3 is attributable solely to grouping).

### Pass 2 — conflict-first classification (module-table write only)

**DATA-06 (approved-only diff):** apply status `applied`. `inventoryDiffCompare` violations = **`[]`**.
Changed tables: `grocy_ai_taxonomy_classifications` + the bulk ledger only — **no native table changed**
(classification writes the module store, never `products`, so no Grocy trigger fires and the resolved cache
is untouched).

**DATA-07 (rollback + zero-diff rerun):** rollback status `rolled_back`; `products` **byte-identical**
after rollback (changes = `[]`); regenerating the classification plan reproduces the **identical checksum**
→ zero-diff rerun **true**.

> **Note (module-store reversal is not byte-identical, by design).** `grocy_ai_taxonomy_classifications`
> is **not** byte-identical after rollback, because the rollback records the reversal *in that same module
> store* (new timestamped rows restoring each product's prior leaf). This is correct — it is the module's
> own authoritative classification store, not native inventory. Logical restoration is proven by the
> **zero-diff rerun checksum equality**: the classification checksum is derived from every product's
> before-image (current leaf), so an identical regenerated checksum means every leaf returned to its
> pre-apply value. DATA-07's native guarantee (`products` byte-identical) holds exactly.

### Pass 3 — conversion audit (read-only report, never a plan)

Baseline (same on raw and seeded copies): **62 global + 18 expected across 9 products, 0 suspicious,
ok = true**. Read-only proven by **row-value equality** of `quantity_unit_conversions` across repeated audit
calls (`SELECT id, product_id, from_qu_id, to_qu_id, factor` hash equal before/after) — **true**. There is
no apply/rollback path for this pass.

---

## Result summary

| Criterion | Raw snapshot | Seeded demonstration |
|---|---|---|
| Group pass produces suggestions | **0** (0 evidence rows) | 3 (pre-selected high) |
| Ungrouped count drops | not demonstrable on raw data | 224 → 221 (−3) |
| Confident classifications | 57 | 57 → 60 after grouping (isolated) |
| GROUP DATA-06 approved-only diff | — | violations `[]` (products.product_group_id + ledger + derived cache) |
| GROUP DATA-07 rollback + zero-diff rerun | — | products byte-identical; rerun checksum identical |
| CLASSIFICATION DATA-06 approved-only diff | — | violations `[]` (module store + ledger only; native clean) |
| CLASSIFICATION DATA-07 rollback + zero-diff rerun | — | products byte-identical; rerun checksum identical |
| Conversion audit | 62 global + 18 expected / 9 products, 0 suspicious | read-only (row-value equality) confirmed |
| Snapshot mutated? | **No** (`sha256sum -c` OK) | operated on a temp copy only |
