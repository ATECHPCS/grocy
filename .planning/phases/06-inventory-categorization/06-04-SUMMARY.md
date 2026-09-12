# 06-04 — `suggest_product_group` operation + generic delegate dispatch (SUMMARY)

**Status:** complete, GREEN. `php8.5 custom/grocy_AI/tests/run.php` → **All 194 checks passed** (was 159; +35 from the new `group_suggestion` suite). All arg-based `taxonomy-*` and `bulk-*` suites still pass (bulk-contract, bulk-invariants, bulk-schema, bulk-generate, bulk-generate-endpoint, bulk-registry, bulk-selection, bulk-conflict, bulk-apply, bulk-audit, bulk-rollback, bulk-export; taxonomy-schema/api/assignment/validation/production-paths).

This is the **first native write in Phase 6** — `products.product_group_id`, only through the audited `ApplyPlan`/`RollbackPlan` path.

## The operation registration

`suggest_product_group` proposes an EXISTING product group for ungrouped, in-scope products, reviewed/reversed through the existing plan engine. It is **not** added to `RegisteredOperations()` (see deviation below); it is dispatched via `ResolveOperation()` to a delegate over `GrocyAiGroupSuggestionService::AssignProductGroup`, which sets ONLY `products.product_group_id`, validates a non-null target against `product_groups` (never invents a group), and joins the caller's outer transaction (`$joinExistingTransaction = true`).

- Plan header `operation_type = 'product_group_assignment'` (new const `GROUP_OPERATION_TYPE`), `ruleset_version = 'v1'` (reused so `SetItemSelection` staleness + checksum stay consistent).
- Items: `operation = 'suggest_product_group'`, `before_image_json = {"product_group_id": <prior|null>}` (null here — the pass targets the ungrouped set), `proposed_value_json = {"product_group_id": <existing id>}`.
- Generator: `GrocyAiBulkService::GenerateGroupPlan()` (sibling of `GeneratePlan`), zero native mutation, writes only the two module bulk tables, deterministic SHA-256 checksum. It counts over all products: out-of-scope → `excluded`; already-grouped in-scope or ungrouped-in-scope with no match → `skipped`; ungrouped-in-scope with a match → `included`/`changed`. Only confident (`high`) suggestions are pre-selected.

## The dispatch generalization (exact before/after)

The engine was leaf-slug-specific. It now reads each item's single written field under an operation-declared **payload key** (`OperationPayloadKey($op)`: `'product_group_id'` for the group op, `'leaf_slug'` for the two taxonomy ops → taxonomy bytes unchanged).

- **ApplyPlan dispatch** — before: `$leafSlug = ...$proposed['leaf_slug']...; delegate($id, $leafSlug)`. after: `$payloadKey = OperationPayloadKey($op); $proposedValue = $proposed[$payloadKey] ?? null; delegate($id, $proposedValue)`.
- **RecomputePlanChecksum / ExportItemRow** — before/proposed decoded via `[$payloadKey]` instead of hardcoded `['leaf_slug']`.
- **WrittenBeforeImage(json, $payloadKey='leaf_slug')** — validates `array_keys === [$payloadKey]`, returns `mixed` (string for taxonomy, int for group id); still fails closed on a malformed/absent image. For taxonomy input (string|null) accept/reject is unchanged.
- **CurrentWrittenValue($op,…)** — returns `mixed`; for `suggest_product_group` reads `products.product_group_id`; taxonomy path (`ReadProductTaxonomy['current_leaf']`) unchanged.
- **RollbackAppliedLedger** — join now also selects `item.operation`; candidate carries `payload_key`; inverse op = `suggest_product_group` for the group op, else the existing `set_unclassified`/`assign_taxonomy_leaf` derivation.
- **RollbackPlan audit JSON** — `[$payloadKey => …]` instead of `['leaf_slug' => …]` (taxonomy rows stay `{"leaf_slug":…}` byte-for-byte).

No change to `RegisteredOperations()`, the checksum idiom, conflict-detection, idempotency, or the single-`BEGIN IMMEDIATE` transaction structure. The method-body grep gates (ApplyPlan/RollbackPlan single-idiom slices, ExportPlan write-token slice, audit append-only regex) all remain satisfied — helpers were placed outside those slices.

## Taxonomy regression — byte-identical (how proven)

1. All pre-existing arg-based `bulk-*`/`taxonomy-*` suites pass unchanged. bulk-apply/bulk-rollback/bulk-export assert the exact taxonomy written bytes, checksums, audit before/after JSON, conflict-detection, idempotency, and transaction idiom — so their green state is the byte-identical proof.
2. `tests/group_suggestion.php` case (e) drives an `assign_taxonomy_leaf` plan through GeneratePlan → ApplyPlan → RollbackPlan on a production-shaped fixture and, via `bin/verify-inventory-diff.php` (`inventoryDiffCompare`), asserts native tables clean, applied audit after-image `{"leaf_slug":"produce"}` / `{"leaf_slug":"dairy-eggs"}`, and rolled_back after-image `{"leaf_slug":null}` — the byte-identical contract.

## Suggestion evidence model

`GrocyAiGroupSuggestionService::Suggest(int): ?array` — deterministic, zero-write. Returns null unless the product is in scope (`GrocyAiInventoryScope`, the 06-03 owner) AND currently ungrouped. Maps to an EXISTING active `product_groups` row by a normalized key:
- **HIGH (pre-selected)** — enrichment `provider_category` (`grocy_ai_taxonomy_evidence`) equals an existing group name (`provider_category_matches_group`), or the product name normalizes exactly to a group (`name_matches_group`).
- **LOW (deselected)** — a group name appears as a whole word/phrase inside the product name (`name_contains_group`), or two+ candidate groups tie on the name signal (Q7 conflict → `ambiguous_group_signal`, first by group id).
- No match → no suggestion. Each suggestion carries `product_group_id`, `group_name`, `confidence`, `reason`, `provenance`, and an `evidence` trail.

## DATA-06 clean-diff result (what changes on apply)

Applying a `suggest_product_group` plan changes exactly:
- **`products`** — the `product_group_id` column only (selected items only; the deselected low-confidence item stays ungrouped), and
- the append-only bulk ledger **`grocy_ai_bulk_plans` / `grocy_ai_bulk_plan_items` / `grocy_ai_bulk_audit`**.

Native `product_groups`, `stock` (and all other native tables) are byte-identical. Rollback restores `products.product_group_id` to its prior value **including NULL**, leaving `products` byte-identical to the pre-apply manifest (DATA-07).

## Files

- **New:** `custom/grocy_AI/src/GrocyAiGroupSuggestionService.php` (suggestion + audited native write); `custom/grocy_AI/tests/group_suggestion.php` (RED-first suite).
- **Changed:** `custom/grocy_AI/src/GrocyAiBulkService.php` (GROUP_OPERATION_TYPE, GenerateGroupPlan, ResolveOperation branch, generic payload-key dispatch, OperationPayloadKey + lazy GroupSuggestions accessor); `custom/grocy_AI/routes.php` (require the new service before the bulk service); `custom/grocy_AI/portable-files.txt` (deploy the new src class); `custom/grocy_AI/tests/run.php` (register the suite after `inventory_scope`); `custom/grocy_AI/tests/bulk.php` (load the new class in its loader block).

## Deviations / notes

- **The group op is NOT in `RegisteredOperations()`.** The closed-registry tests (`bulk-contract`, `bulk-registry`) pin `array_keys(RegisteredOperations()) === ['assign_taxonomy_leaf','set_unclassified']` and require every member to declare `delegate_write = 'AssignProductTaxonomy'`. The group op delegates to `AssignProductGroup` (native `products` write), so adding it there would break those green suites and mis-state the taxonomy contract. It is instead dispatched through a `ResolveOperation()` branch + the payload-key descriptor. This satisfies "reviewable/reversible through the existing engine" while keeping the taxonomy registry byte-identical; it declines only the literal "register in RegisteredOperations" wording.
- **RED-first evidence:** with `src/GrocyAiGroupSuggestionService.php` removed, the suite fails on `group_suggestion: the group suggestion service + GenerateGroupPlan are available` (verified), then GREEN once restored.
- **Test file not in `portable-files.txt`** — matches the 06-02/06-03 precedent (`inventory_diff.php`/`inventory_scope.php` are also omitted); only the runtime src class is portable.
- **Uncertainty:** none material on taxonomy identity — the full pre-existing bulk/taxonomy suites (which assert the exact bytes/checksums/audit JSON/idempotency/transaction idiom) pass unchanged, which is the strongest available regression signal.
