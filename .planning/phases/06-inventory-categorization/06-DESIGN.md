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
| Q8 conversion | ~~No-op operation: assert 0 product-specific conversions~~ **INVALIDATED 2026-09-12.** The 09-12 snapshot has 80 conversions = 62 global + **18 product-specific across 9 products** (piece↔weight package sizes + auto-inverses, added via product-intake since the 09-08 fact-find; look intentional). The "0 product-specific" premise is false, so the "product_id NOT NULL = regression" tripwire fires on legitimate data. Owner decision 2026-09-12: **pause & re-plan conversions** (06-06 built to old spec on branch `codex/phase6-06-audit`, NOT merged). A new invariant must distinguish intentional per-product package conversions from genuine junk before DATA-03/04/05 can close. |
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
- **06-06** — ~~`audit_conversions` no-op + tripwire~~ **PAUSED 2026-09-12 (premise invalidated — 18 product-specific conversions exist; needs re-plan).** Built to the old spec on branch `codex/phase6-06-audit`, NOT merged.
- **06-07** — UI: surface the passes on the bulk-review page; DATA-06/07 acceptance on the snapshot. **BLOCKED** until conversions (06-06) are re-planned — its `depends_on` includes 06-06.

## Boundary

Group-suggestion writes `products.product_group_id` (a native, fully reversible field) — the first Phase 6 native write, and only through the audited `ApplyPlan`/`RollbackPlan` path with pre-apply row snapshots. Classification writes stay in module tables. Conversion audit is read-only. Nothing deletes conversions.
