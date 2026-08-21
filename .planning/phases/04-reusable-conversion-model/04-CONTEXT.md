# Phase 4: Reusable Conversion Model - Context

**Gathered:** 2026-08-21
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase introduces a versioned, explainable reusable conversion model that supplies editable universal mass and volume defaults plus narrowly scoped approximate food-type profiles. It must preserve Grocy's existing behavior and native editing surfaces, expose the winning source, and prove compatibility on both maintained branches before any rule becomes active. It does not remove or rewrite existing product-specific conversions; their reviewed cleanup belongs to Phase 6.

</domain>

<decisions>
## Implementation Decisions

### Universal Conversion Baseline
- **D-01:** Supply editable universal mass and volume conversions only: mg, g, kg, oz, lb; and mL, L, tsp, tbsp, cups, fl oz, pints, quarts, and gallons. Temperature and length are out of scope.
- **D-02:** Use standard US customary kitchen measures, anchored to metric mL/L factors. Retain full precision internally and use Grocy's existing quantity-decimal setting only for display rounding.
- **D-03:** Edit universal defaults through Grocy's normal quantity-unit conversion screen. Keep the normal product conversion screen for product-specific overrides such as pack, can, bottle, piece, or measured density.
- **D-04:** Grocy AI may propose a package-derived product conversion only from verified package data. It is created only after explicit review and the normal Grocy save flow; automatic creation is deferred to the audited preview/apply model.

### Approximate Food-Type Profiles
- **D-05:** Food-type mass-to-volume conversions use a small, sourced profile set and are always visibly marked approximate.
- **D-06:** Start with calibrated common liquids and simple ingredients. Broader coverage is an eventual goal, but an unprofiled food type must state that no estimate is available rather than guess.
- **D-07:** Every profile records and exposes its named source and source version with its factor.
- **D-08:** A food-type profile can apply only after explicit Phase 3 taxonomy assignment. Provider suggestions and Grocy product-group evidence may guide classification but must never activate a conversion rule.

### Resolution and Explanation
- **D-09:** Expose the winning conversion both in the normal product conversion screen and the resolved-conversions view. Default copy is concise (for example, approximate profile plus source/version); factor and full provenance are available on demand.
- **D-10:** Conflicting effective paths block resolution and identify the conflict for correction. The system never silently chooses a conflicting path or nearest factor.
- **D-11:** An approved sourced food-type profile may offer a clearly labeled estimate. If no approved profile exists, state that no estimate is available and do not invent one.

### Safety and Compatibility
- **D-12:** A dual-branch characterization of Grocy's resolved-conversion/cache behavior is a hard release gate before reusable rules become active. Implement the smallest compatible projection it establishes.
- **D-13:** Prove equivalent stock, recipe, purchase, consumption, price, quantity-display, transfer, and meal-plan behavior before release.
- **D-14:** Leave existing product-specific conversions unchanged in Phase 4. Phase 6 owns any reviewed modification or removal.
- **D-15:** A universal-rule edit cannot activate unless it shows its unit pair, precise factor, source/version, and a conflict/cycle-free impact result.

### the agent's Discretion
- Select the exact safe starter profiles, their authoritative sources, factor representation, source-version format, and concise UI wording within the explicit approximate/provenance contract.
- Determine the smallest compatible cache/resolution projection through the required dual-branch characterization, without broadening rule scope or altering protected Grocy behavior.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract and Prior Decisions
- `.planning/ROADMAP.md` — Phase 4 goal, success criteria, dependency, and Phase 6 cleanup boundary.
- `.planning/REQUIREMENTS.md` — Authoritative `CONV-01` through `CONV-09` requirements and the prohibition on universal mass-to-volume/package-count conversions.
- `.planning/PROJECT.md` — Human-control, native-Grocy, custom-module, deployment, and data-safety constraints.
- `.planning/STATE.md` — Current phase position and the mandatory dual-branch characterization concern.
- `.planning/phases/03-food-taxonomy-categorization-pilot/03-CONTEXT.md` — Explicit taxonomy assignment, evidence-only product groups, and frozen/preserved identity boundary.
- `.planning/phases/03-food-taxonomy-categorization-pilot/03-VERIFICATION.md` — Phase 3 verified contracts and its narrow module-owned classification boundary.

### Grocy Conversion Consumers and Native Editing
- `services/StockService.php` — Stock conversion reads from `cache__quantity_unit_conversions_resolved` across stock and substitution paths.
- `services/RecipesService.php` and `controllers/RecipesController.php` — Recipe conversion consumers that must remain equivalent.
- `controllers/StockController.php` — Native quantity-unit, product conversion, and resolved-conversion view data sources.
- `views/quantityunitform.blade.php` and `views/quantityunitconversionform.blade.php` — Native universal conversion editing surfaces.
- `views/productform.blade.php` and `public/viewjs/productform.js` — Native product-specific conversion surfaces and normal-save behavior.
- `views/quantityunitconversionsresolved.blade.php` and `public/viewjs/quantityunitconversionsresolved.js` — Resolved-conversion inspection surface.
- `public/viewjs/quantityunitconversionform.js` — Native conversion input/validation flow.

### Module and Release Boundaries
- `custom/grocy_AI/README.md` — Module route, test, portable-parity, and stable-release conventions.
- `custom/grocy_AI/src/GrocyAiTaxonomyService.php` — Explicit food-taxonomy assignment and evidence read boundary.
- `CUSTOMIZATIONS.md` — Required upstream-extension documentation and branch-adapter rules.
- `.planning/codebase/ARCHITECTURE.md` and `.planning/codebase/CONVENTIONS.md` — PHP/SQLite/module patterns and custom-file isolation requirements.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `cache__quantity_unit_conversions_resolved`: Grocy's current shared projection consumed by stock, recipes, transfers, meal plans, and product amount pickers.
- Native quantity-unit and product conversion forms: existing editable UI and normal persistence paths that Phase 4 must preserve rather than replace.
- `GrocyAiTaxonomyService.php`: namespaced module migration/service pattern and explicit assignment guard needed before food-type profiles can apply.

### Established Patterns
- Custom behavior belongs in `custom/grocy_AI/` and `public/custom/grocy_AI/`; core changes remain minimal, documented, portable, and parity-tested.
- External or inferred information is evidence, not a durable write. User review and Grocy's normal save path remain authoritative.
- Closed allowlists, named reasons, explicit errors, and deterministic test fixtures are required at module boundaries.

### Integration Points
- Characterize and then extend the resolved conversion/cache path without changing existing consumer semantics.
- Present reusable-rule provenance in native product conversion and resolved-conversions views.
- Read only explicit taxonomy assignments when resolving food-type profiles; never write classification, product group, stock, or existing conversion rows as part of rule resolution.

</code_context>

<specifics>
## Specific Ideas

- Universal kitchen conversions should feel native to Grocy and remain editable in its standard quantity-unit screens.
- Approximate answers are useful when clearly identified and sourced; unknown profiles must remain visibly unavailable.
- Product/package contents can help formulate a proposal, but the user keeps the final creation action in Grocy's existing product conversion screen.

</specifics>

<deferred>
## Deferred Ideas

- Automatic creation of package-derived product conversions belongs to Phase 5's audited preview/apply workflow.
- Any modification or removal of existing product-specific conversions belongs to Phase 6's reviewed cleanup workflow.
- Interactive conversion graphs remain the v2 visualization item.

</deferred>

---

*Phase: 04-reusable-conversion-model*
*Context gathered: 2026-08-21*
