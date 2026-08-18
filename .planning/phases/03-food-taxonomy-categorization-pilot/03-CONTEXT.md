# Phase 3: Food Taxonomy & Categorization Pilot - Context

**Gathered:** 2026-08-17
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase establishes a small, versioned, two-level household-food taxonomy and lets a user inspect evidence and assign one current taxonomy leaf to one product. It is a pilot: it does not bulk-classify the existing inventory, change Grocy product categories, or introduce conversion rules. Every assignment remains explainable, reviewable, and isolated from stock, units, recipes, prices, history, and location.

</domain>

<decisions>
## Implementation Decisions

### Household Taxonomy Shape
- **D-01:** Taxonomy v1 uses broad household-food groupings with a two-level hierarchy. Stable module-owned IDs and slugs—not provider display labels—define its identities; the exact small leaf set is a planning and validation detail.
- **D-02:** Baby-food and pet-food types are absent from taxonomy v1. Provider evidence or mappings that point to either excluded domain must fail closed rather than create or assign a type.

### Conservative Classification
- **D-03:** When evidence is missing, conflicting, or not accepted at the configured confidence threshold, the product remains explicitly `Unclassified`; no automatic fallback assignment is permitted.
- **D-04:** Suggestions must expose provider category evidence, local mapping/ruleset version, confidence, and reason so the user can make the single-product classification decision.
- **D-05:** A product has at most one current taxonomy leaf. Assignment must change only module-owned classification data and must not mutate Grocy stock, quantities, recipes, prices, history, locations, or product-category ownership.

### Identity vs. Handling
- **D-06:** Frozen and preserved are handling or location concerns, not food identities. They must not become taxonomy leaves in v1; the full-inventory validation records this explicit boundary for later phases.

### the agent's Discretion
- Choose the exact broad taxonomy leaves, stable namespace/version format, confidence thresholds, mapping representation, and review UI within the locked two-level, explainable, explicitly-Unclassified contract.
- Use existing Grocy/module routes, permissions, migration conventions, and responsive Bootstrap patterns; preserve the established normal Save and review-before-write principles where a product form integration is appropriate.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract
- `.planning/ROADMAP.md` — Phase 3 goal, dependency, requirements, UI hint, and observable success criteria.
- `.planning/REQUIREMENTS.md` — Authoritative `TAX-01` through `TAX-07` requirements and later-phase boundaries.
- `.planning/PROJECT.md` — Human-control, baby/pet exclusion, custom-module, stable-release, and data-safety constraints.
- `.planning/STATE.md` — Current phase position and the recorded frozen/preserved validation concern.

### Prior Decisions and Extension Boundary
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-CONTEXT.md` — Review-before-save, normal-Grocy persistence, mobile, privacy, and module-isolation decisions that remain in force.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-CONTEXT.md` — Field-level evidence presentation, existing-value protection, zero-write review, and portable/stable parity decisions.
- `CUSTOMIZATIONS.md` — Permitted ATECHPCS extension seams and upstream-change constraints.
- `custom/grocy_AI/README.md` — Current module routes, feature flags, test harness, and release/parity contract.

### Architecture and Data Guidance
- `.planning/codebase/STACK.md` — PHP/Blade/JavaScript/SQLite runtime and migration constraints.
- `.planning/codebase/ARCHITECTURE.md` — Slim routes, controllers/services, LessQL, SQLite migration, and view-script integration patterns.
- `.planning/codebase/INTEGRATIONS.md` — Existing companion/provider trust boundary and authenticated module endpoint patterns.
- `services/DatabaseMigrationService.php` — Ordered migration execution and applied-migration bookkeeping.
- `custom/grocy_AI/routes.php` — Feature-gated module route registration.
- `custom/grocy_AI/src/GrocyAiApiController.php` — Module permission and API error-mapping boundary.
- `views/productform.blade.php` and `public/custom/grocy_AI/product-enrichment.js` — Existing product-form review surface and browser module conventions.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `custom/grocy_AI/routes.php`, `GrocyAiApiController.php`, and `GrocyAiService.php`: feature-gated, authenticated module endpoint and service boundary suitable for taxonomy evidence and assignment reads/writes.
- `views/productform.blade.php` and `public/custom/grocy_AI/product-enrichment.js`: established responsive review UI and safe browser state-management surface adjacent to product details.
- `custom/grocy_AI/tests/run.php`: no-framework PHP contract-test harness for deterministic taxonomy, mapping, and mutation-isolation tests.

### Established Patterns
- Custom behavior belongs in `custom/grocy_AI/` and `public/custom/grocy_AI/`; any core hook must be minimal, documented, and parity-tested on `atech-main` and `atech-release`.
- PHP validates untrusted provider data at the service boundary; browser code uses closed, safely rendered values and explicit state restoration.
- SQLite schema is applied through ordered migrations, while namespaced module-owned objects avoid upstream migration/object collisions.
- Grocy is the durable mutation authority; suggestions are evidence, and data-changing actions require visible user review.

### Integration Points
- Add a module-owned, versioned taxonomy schema and migrations without touching Grocy's stock or product-category schema.
- Expose only validated taxonomy evidence and one-product assignment operations through the module controller/routes, protected by existing permissions.
- Add the classification review surface alongside the product workflow without changing existing enrichment, Save, or inventory transaction behavior.

</code_context>

<specifics>
## Specific Ideas

- Keep taxonomy v1 intentionally broad for an ordinary household inventory rather than modeling retail-provider granularity.
- Treat `Unclassified` as a deliberate safe outcome, not an error or a hidden default.
- Keep frozen/preserved distinct from the food identity needed by later conversion and bulk workflows.

</specifics>

<deferred>
## Deferred Ideas

- Bulk classification of existing inventory belongs to Phase 6 after the pilot taxonomy and Phase 5 reviewed bulk workflow are proven.
- Conversion behavior or food-type conversion rules belongs to Phase 4; frozen/preserved handling may be addressed only where later requirements explicitly call for it.

</deferred>

---

*Phase: 03-food-taxonomy-categorization-pilot*
*Context gathered: 2026-08-17*
