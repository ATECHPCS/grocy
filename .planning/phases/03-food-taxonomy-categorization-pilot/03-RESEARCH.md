# Phase 3 Research: Food Taxonomy & Categorization Pilot

**Date:** 2026-08-17  
**Scope:** TAX-01 through TAX-07

## Summary

Phase 3 should add a module-owned taxonomy projection rather than reuse Grocy product groups, `should_not_be_frozen`, userfields, or quantity-conversion rows. The existing enrichment `food_type` is deliberately evidence-only with no local destination, which makes it the correct upstream input to a new explicit classification review—not an automatic write.

The safest path is a small versioned SQLite schema plus a narrow authenticated module API. The assignment operation must validate a fixed taxonomy leaf and evidence record, then write only a current classification record within a short transaction. It must never send the browser through Grocy's generic product update endpoint, because that endpoint serializes the whole product form and could mutate unrelated fields.

## Existing Architecture Findings

### Migration and schema constraints

- `services/DatabaseMigrationService.php` scans only the root `migrations/` directory and stores numeric migration IDs in the shared `migrations` table. A custom taxonomy migration therefore needs a documented, collision-safe integration seam; a module-local migration runner with its own namespaced ledger avoids claiming an arbitrary upstream numeric ID.
- `migrations/0256.php` is a precedent for a small PHP migration using PDO, a transaction, and a precondition audit that blocks safely rather than rewriting household data.
- `products.product_group_id` is Grocy's master-data grouping and appears in multiple views/reports. It must not be repurposed as food taxonomy.
- `products.should_not_be_frozen` means “should not be frozen”; it is a handling property, not a food identity. Frozen/preserved must remain outside taxonomy leaves.

### Extension and API constraints

- `routes.php` conditionally includes `custom/grocy_AI/routes.php`; current module routes are authenticated JSON routes under `/api/grocy-ai`.
- `GrocyAiApiController` uses `MASTER_DATA_EDIT` for sensitive routes and converts expected errors into bounded responses. Phase 3 should use the same permission, numeric product-ID validation, and closed DTO approach.
- The module's Phase 2 enrichment contract permits a `food_type` suggestion only as visible evidence. `custom/grocy_AI/README.md` explicitly prohibits a Phase 2 taxonomy surrogate.
- The module's standalone PHP harness and Playwright product-form suite already offer deterministic contract and review behavior tests without live providers.

### Product UI constraints

- `StockController::ProductEditForm` supplies the current product and existing master-data lists to `views/productform.blade.php`; edit mode is the appropriate single-product entry point.
- `public/viewjs/productform.js` saves a serialized full form through generic product REST endpoints. Taxonomy assignment must be a separate narrow module request, not a hidden added form field, so unrelated product/stock state cannot be overwritten.
- The enrichment card (`product-enrichment.js`, `grocy-ai.css`) already establishes Bootstrap 4, mobile-friendly, evidence-local rendering, explicit selection, and stale-state handling patterns.

## Recommended Implementation Shape

1. Create a namespaced taxonomy schema owned by `grocy_AI`: taxonomy releases/nodes, provider mapping rules, evidence snapshots, and one-current-leaf product classification. Enforce stable slugs, parent/leaf structure, exactly one active classification per product, and exclusion of baby/pet slugs in data and service validation.
2. Seed one versioned broad two-level household taxonomy. Stable slug/ID values are source-controlled; labels are presentation data. Include no frozen/preserved leaf.
3. Build a module migration bootstrap with a namespaced migration ledger and idempotent, transactional application. Include dry validation of the seed/mapping set and collision tests against upstream schema names.
4. Expose read endpoints for taxonomy/classification evidence and a tightly typed assignment endpoint. The write accepts only a validated local leaf or explicit `Unclassified`, rechecks the product and version, and writes only module tables.
5. Add a focused edit-product classification panel showing current state, suggestion/evidence, ruleset version, confidence, reason, explicit Unclassified action, and manual leaf selection. Do not alter normal Save behavior.
6. Add a maintainer validation command/report that evaluates all in-scope existing products without changing them, identifies excluded/unknown/low-confidence evidence, and records the frozen/preserved decision.

## Risks and Required Guards

| Risk | Guard |
|---|---|
| Taxonomy silently becomes product-group or stock semantics | Separate module tables; tests compare upstream product/stock/recipe/location data before and after assignment. |
| Provider labels create unstable types or reintroduce excluded domains | Closed mapping rules resolve only known provider categories; baby/pet terms fail closed; no dynamic taxonomy creation. |
| One product receives conflicting current leaves | Database uniqueness on current classification plus a transactional replace/Unclassified operation. |
| Later migration collision with Grocy | Namespaced table/index names, module-owned migration ledger, schema audit, and stable Docker/runtime inclusion checks. |
| Browser submits stale or arbitrary evidence/leaf data | Server owns evidence lookup, validates product/leaf IDs and ruleset version, and ignores browser-provided confidence/reason. |
| Product form save mutates unrelated data | Classification has its own module endpoint; no hidden fields or generic `objects/products` write. |

## Validation Architecture

### Automated coverage

- PHP schema/service tests: seed/version stability, hierarchy and exclusion validation, mapping outcomes, explicit Unclassified, one-current-leaf enforcement, and mutation isolation.
- PHP route/controller tests: authentication/permission, malformed IDs, unknown leaf, stale ruleset, excluded mapping, and closed evidence DTOs.
- SQLite fixture tests: migration idempotence, namespaced-object collision audit, transaction rollback, and inventory-wide read-only validation report.
- Browser tests: edit-product classification panel renders evidence, leaves uncertainty Unclassified, sends only the narrow classification request, and preserves ordinary Save controls/serialized product data.
- Release/parity tests: portable module bytes and stable adapter/cache/deployment paths include any new migration bootstrap and product-form hook.

### Manual verification

- On the stable image, open an existing product, inspect a suggested classification, select a different permitted leaf, choose Unclassified, reload, and verify only taxonomy records changed.
- Run the inventory validation report against the production-shaped data and retain its redacted counts plus the recorded frozen/preserved boundary decision.

## Plan Ordering

1. Establish RED tests/fixtures and module migration contract.
2. Implement the taxonomy schema, seed, service, and read-only inventory validation.
3. Implement secured evidence/assignment API and mutation-isolation coverage.
4. Implement product edit review panel and browser coverage.
5. Complete portable/stable parity, deployment/cache, and release evidence only after all deterministic gates pass.

## Sources

- `.planning/phases/03-food-taxonomy-categorization-pilot/03-CONTEXT.md`
- `services/DatabaseMigrationService.php`
- `migrations/0256.php`
- `migrations/0146.sql`
- `custom/grocy_AI/routes.php`
- `custom/grocy_AI/src/GrocyAiApiController.php`
- `custom/grocy_AI/README.md`
- `CUSTOMIZATIONS.md`
- `controllers/StockController.php`
- `views/productform.blade.php`
- `public/viewjs/productform.js`
- `public/custom/grocy_AI/product-enrichment.js`
