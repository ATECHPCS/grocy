# 06-03 — Exclusion override userfield + Supplements mapping-rule seed + GrocyAiInventoryScope predicate (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 159 checks passed** (was 147; +12 from the new `inventory_scope` suite). All arg-based taxonomy-* and bulk-* suites still pass.

## The in-scope predicate (single owner)

`GrocyAiInventoryScope` (`custom/grocy_AI/src/GrocyAiInventoryScope.php`) is the SINGLE owner of the predicate. A product is **in scope** iff ALL of:

1. **active** — `products.active = 1` (treated active when the `active` column is absent, e.g. module unit fixtures), AND
2. **group not excluded** — its Grocy product group is not held out by a seeded exclusion mapping-rule (`grocy_ai_taxonomy_mapping_rules.disposition = 'excluded'`, keyed on the normalized group name), AND
3. **override != excluded** — its per-product override userfield is not `'excluded'` (default `'included'`).

An **ungrouped** product stays in scope (grouping is a later reviewed pass, 06-04, not an exclusion). The predicate is a pure function of committed DB state — the same snapshot yields the same in-scope set on every run (DATA-01, supports DATA-07). It issues **no writes**.

Public API: `IsInScope(int $productId): bool` and `InScopeProductIds(): array<int>` (ordered). The class is defensive about native-table/column absence (`products.active`, `product_groups`, `userfields`/`userfield_values`), so it is correct on prod and safe on the module's lean unit fixtures.

### Wiring (no restated predicate)

- `GrocyAiTaxonomyService` holds a lazy `Scope()` and exposes `IsProductInScope(int): bool`. `ValidateInventoryTaxonomy` now consults the owner first: out-of-scope products are counted `excluded` before any evidence outcome is computed (bucket-sum invariant preserved).
- `GrocyAiBulkService::GeneratePlan` (the classification profiler) skips out-of-scope products via `Taxonomy->IsProductInScope(...)`, so they never produce an actionable plan item. The future group profiler (06-04) reuses the same owner.
- Runtime loader `routes.php` and the CLI `bin/validate-inventory-taxonomy.php` now `require_once` the new class; it is added to `portable-files.txt` (deployable). Test loaders: `tests/run.php` (top block + registration after the `inventory_diff` block) and `tests/bulk.php`.

## Excluded-group config constant

`GrocyAiTaxonomyMigration::EXCLUDED_PRODUCT_GROUPS = ['Supplements']` — the reviewable, source-controlled, git-versioned excluded-group list. The migration seeds one closed exclusion mapping-rule per entry (normalized key `supplements` → `disposition='excluded'`, `target_slug=NULL`, `version='v1'`) via `INSERT OR IGNORE`, so exclusion runs through a mapping-rule, not ad-hoc SQL, and reruns identically. On current prod this holds out exactly the 2 Supplements items (group id 19); it is the tripwire if the non-food footprint grows.

## Override userfield definition

Grocy `userfields` row (entity `products`), seeded idempotently by the migration (skipped when the native `userfields` table is absent):

| column | value |
|---|---|
| `entity` | `products` |
| `name` | `grocy_ai_scope_override` (`SCOPE_OVERRIDE_USERFIELD`) |
| `caption` | `Grocy AI inventory scope` |
| `type` | `preset-list` |
| `config` | `included` \n `excluded` (closed value set) |
| `default_value` | `included` (`SCOPE_OVERRIDE_INCLUDED`; excluded value = `SCOPE_OVERRIDE_EXCLUDED`) |

## RED-first evidence

`tests/inventory_scope.php` (registered, runs in the default suite via the shared `check()`), before `GrocyAiInventoryScope` existed, fails on `inventory_scope: the single in-scope predicate owner GrocyAiInventoryScope is available` (verified: "1 of 1 failed" with the src file removed). With the implementation it is GREEN. It asserts: active/non-excluded → in; Supplements group → out; ungrouped → in; override=excluded → out (group fine); inactive → out; explicit override=included → in; non-existent id → out; `InScopeProductIds() === [1,3,6]`; and determinism/reproducibility across repeated evaluations and a fresh instance.

## Deviations / notes

- **VERSION not bumped.** The plan asked to "bump its VERSION," but `GrocyAiTaxonomyMigration::VERSION` doubles as the taxonomy **ruleset_version** used pervasively (nodes/evidence/classifications/mapping-rules keys, plus `runTaxonomy*`/`bulk-generate` assertions of `ruleset_version === 'v1'`, and `taxonomy-schema` asserting `grocy_ai_taxonomy_migrations` count === 1). Bumping it would break the green suite wholesale. Instead the additive changes (Supplements rule + override userfield) are re-asserted idempotently on **every bootstrap** (the existing pattern for source-controlled mapping additions), keeping `VERSION='v1'` and the ledger at one row. This satisfies additive + idempotent + reproducible; it declines only the literal const bump.
- Key normalization is centralized as `GrocyAiTaxonomyMigration::NormalizeCategoryKey` (identical formula to `GrocyAiTaxonomyService::ProviderCategoryKey`), reused by the migration seed and the scope owner so a group name resolves to the same rule from every caller.
- `IsGroupExcluded` guards on `product_groups` / `grocy_ai_taxonomy_mapping_rules` existence — required because `bin/validate-inventory-taxonomy.php` runs `ValidateInventoryTaxonomy` on a bootstrap-disabled DB without a native `product_groups` table.
