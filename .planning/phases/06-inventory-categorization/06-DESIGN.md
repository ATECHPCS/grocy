# Phase 6 — Inventory Categorization (design)

**Re-scoped:** 2026-09-08 (grilled). **Depends on:** Phase 5 (bulk engine), Phase 3 (taxonomy scorer + storage). **Rigor:** mvp.

## Problem

Apply the proven models to *existing* household inventory via reviewed bulk work. A read-only live-prod fact-find (Grocy 4.6.0 @ 10.10.0.156, 434 products) invalidated the original premise and reshaped the phase:

- **0 product-specific conversions exist** — all 62 `quantity_unit_conversions` rows are global (`product_id NULL`). The "~101 unwanted conversions" figure is stale. → conversion cleanup becomes a no-op audit.
- **Classification bulk-apply already exists.** `GrocyAiBulkService::GeneratePlan` already emits `assign_taxonomy_leaf` / `set_unclassified` items over full inventory using `GrocyAiTaxonomyService`, with exclusion/skip counts, checksum, apply, rollback, export. Its registry comment already reserves "Conversion-cleanup and other operations … registered later in Phase 6." So DATA-01/02 plumbing is largely built.
- **Grocy product group is the taxonomy scorer's primary evidence** (`ValidateInventoryTaxonomy` → `evidence_source=grocy_product_group`). **214 products (~49%) have no group**, so they score unclassified/low-confidence. Fixing grouping first is what materially improves classification — this is the real new build.
- No baby/pet/non-food groups exist; 0 inactive products. Exclusion (via taxonomy `mapping_rules` disposition `excluded`) guards only ~2 Supplements items — kept as config + tripwire.

## Locked decisions (grilled 2026-09-08)

| Q | Decision |
|---|----------|
| Q1 | Test data: clone live prod → light scrub (secrets only) → local gitignored snapshot + documented refresh command |
| Q2 | Three independent reviewed passes, each own preview/apply/rollback + DATA-06 zero-diff verify |
| Q3 order | (1) product-group suggestion for the 214 ungrouped → (2) food classification → (3) conversion audit |
| Q4 scope | Grocy product-group + active flag; exclusion via taxonomy `mapping_rules`; per-product override userfield (migration-seeded); near-vestigial, kept as tripwire |
| Q5 engine | Reuse the Phase 5 apply/rollback/idempotency/export spine unchanged; add profilers/operations only |
| Q6 store | Classification stays in module tables (`grocy_ai_taxonomy_classifications`/`_evidence`); no Grocy userfield |
| Q7 conflicts | Reuse Phase 3 evidence scoring; conflict = suggestion contradicts existing group signal OR two candidate types tie; review order conflicts → low-confidence → confident; below threshold → Unclassified |
| Q8 conversion | ~~No-op operation: assert 0 product-specific conversions~~ **RE-PLANNED 2026-09-12.** The 09-12 snapshot has 80 conversions = 62 global + **18 product-specific across 9 products** — every one a legitimate package-size definition (product's own Piece/Can unit → a measured g/oz/ml unit, real product, positive factor, not a global-duplicate), added via product-intake. New 06-06 = a **classifying read-only audit**: product-specific rows are EXPECTED unless they fail explicit integrity rules (orphaned product_id; non-positive/non-finite factor; neither side is the product's stock/purchase unit; exact global-duplicate). The tripwire fires only on SUSPICIOUS rows, never on legitimate package data. Records the baseline (62 global + 18 expected / 9 products). Prior impl to the superseded "product_id NOT NULL = regression" invariant is on branch `codex/phase6-06-audit`. |
| Q9 verify | Per-table row-count + content-hash before/after each pass; only approved rows/columns may change, any other delta fails the pass |
| Q10 rollback | Per-pass atomic `BEGIN IMMEDIATE` + pre-apply snapshot of every touched row into the audit record; restores from that record (works with no full DB backup); rehearsed on the local snapshot before prod |
| Q11 output | Profilers emit standard BULK plan rows; confidence/evidence rides along as audit payload into export/rollback; no engine changes |

## What already exists (reuse, do not rebuild)

- `GrocyAiBulkService`: `GeneratePlan`, `RegisteredOperations`/`ResolveOperation`, `SetItemSelection`, `ReadPlan`, `ReadPlanAudit`, `ExportPlan`, `SelectedDiff`, `DetectApplyConflicts`, `ApplyPlan`, `PreviewRollback`, `RollbackPlan`, `ChecksumForPlan`. Registry currently holds only `assign_taxonomy_leaf` + `set_unclassified`.
- `GrocyAiTaxonomyService`: `ValidateInventoryTaxonomy`, `ReadProductTaxonomy`, `AssignProductTaxonomy`, evidence via `grocy_product_group`, exclusion via `grocy_ai_taxonomy_mapping_rules`.
- `GrocyAiBulkMigration` pattern for a namespaced migration ledger.

## New surface (Phase 6 adds)

1. **Snapshot tooling** (`custom/grocy_AI/bin/snapshot-refresh.sh` + docs): obtain a copy of the prod SQLite from `/etc/komodo/grocy` (via the Komodo host 10.10.0.162 / `docker cp`, since direct SSH to 10.10.0.156 has no key), light-scrub secrets, land a gitignored local `*.sqlite`, re-runnable. **This is the gate for all profiling + rollback rehearsal.**
2. **Product-group suggestion operation** — a new registered bulk operation `suggest_product_group` writing `products.product_group_id` (native, reversible field) for ungrouped products, evidence from name + enrichment + taxonomy mapping. Reviewed like any plan.
3. **Per-product exclusion override userfield** — migration-seeded, default included; consulted by the group/classification profilers.
4. **Conversion audit operation** — read-only `audit_conversions`: asserts 0 product-specific conversions + records the 62 globals; fails (tripwire) if product-specific rows appear.
5. **DATA-06/07 harness** — per-table checksum diff + rollback rehearsal driver over the snapshot.

## Plan waves (outline — plans written incrementally)

- **06-01** — Snapshot tooling + refresh script + gitignore (the gate). *This plan.*
- **06-02** — DATA-06 per-table checksum harness (RED first) + rollback-rehearsal driver over the snapshot.
- **06-03** — Exclusion override userfield migration + Supplements mapping-rule seed; wire into the profilers' scope.
- **06-04** — `suggest_product_group` operation (group the 214 ungrouped): generate → review → apply/rollback through the existing engine.
- **06-05** — Classification pass hardening: conflict-first ordering (Q7), re-run after grouping; confirm `assign_taxonomy_leaf`/`set_unclassified` on real snapshot data.
- **06-06** — **RE-PLANNED 2026-09-12, executing.** Classifying read-only conversion audit + integrity tripwire (product-specific = legitimate package definition unless it fails explicit rules). Superseded impl on branch `codex/phase6-06-audit`.
- **06-07** — UI: surface the passes on the bulk-review page; DATA-06/07 acceptance on the snapshot. Unblocked once 06-06 lands. NOTE: the group/classification ops (06-04/06-05) route via `ResolveOperation()`, NOT `RegisteredOperations()` (registry tests pin it to the 2 taxonomy ops) — the UI must not assume the registry enumerates them.

## Boundary

Group-suggestion writes `products.product_group_id` (a native, fully reversible field) — the first Phase 6 native write, and only through the audited `ApplyPlan`/`RollbackPlan` path with pre-apply row snapshots. Classification writes stay in module tables. Conversion audit is read-only. Nothing deletes conversions.
