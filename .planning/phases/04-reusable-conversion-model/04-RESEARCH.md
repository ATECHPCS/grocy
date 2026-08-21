# Phase 4: Reusable Conversion Model - Research

**Researched:** 2026-08-21  
**Domain:** Grocy/SQLite quantity-unit resolution, provenance-aware reusable conversion rules  
**Confidence:** MEDIUM

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

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

### Deferred Ideas (OUT OF SCOPE)
- Automatic creation of package-derived product conversions belongs to Phase 5's audited preview/apply workflow.
- Any modification or removal of existing product-specific conversions belongs to Phase 6's reviewed cleanup workflow.
- Interactive conversion graphs remain the v2 visualization item.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|---|---|---|
| CONV-01 | Explicit dimensions reject invalid universal cross-dimension conversions. | Separate `mass` and `volume` dimensions in module-owned rules; validate before projection. |
| CONV-02 | Universal authoritative same-dimension mass/volume conversions. | Native default conversion rows plus a source/version registry; use NIST factors. |
| CONV-03 | Narrow sourced approximate food-type mass/volume profiles. | Module-owned profile catalog keyed to an explicit Phase 3 leaf. |
| CONV-04 | Pack/count rules stay product or barcode bound. | Do not put count units in universal/profile rule tables; retain native product conversion editing. |
| CONV-05 | Deterministic precedence and visible winning source. | Compute one validated effective record per product/unit pair with `product > profile > universal` provenance. |
| CONV-06 | Block cycles, paths, reciprocity, dimensions, and tolerance failures. | Validate a candidate ruleset before cache projection; never rely on Grocy's shortest-path selection for conflict policy. |
| CONV-07 | Inspect coverage, missing paths, sources, conflicts, cycles, redundant overrides. | Add a maintainer-only module report/view with read-only diagnostics. |
| CONV-08 | Dual-branch characterization chooses smallest safe projection. | First implementation plan must be a scratch-DB characterization harness on both branches. |
| CONV-09 | Preserve stock/recipe/purchase/consumption/price/display behavior. | Snapshot and compare the existing cache and protected consumer outputs before/after rules. |
</phase_requirements>

## Summary

The safe design is a two-stage system: module-owned, versioned rules and validation determine whether a rule set is eligible; only then is its deterministic winning result projected into Grocy's existing resolved-conversion contract. [VERIFIED: codebase grep] The current Grocy resolver is a recursive SQLite view and its results are materialized in `cache__quantity_unit_conversions_resolved` by triggers; stock and recipe paths query that cache directly. [VERIFIED: codebase grep]

Do not extend Grocy's recursive view with unvalidated food-profile edges. The view selects a first shortest-path result for each product/from/to grouping, whereas Phase 4 requires competing effective paths and reciprocal disagreement to block. [VERIFIED: codebase grep] Instead, validate the complete candidate graph in a module service, materialize only one selected result per permitted pair, and retain a diagnostic record showing why any candidate was withheld. [ASSUMED]

**Primary recommendation:** Make the dual-branch scratch-database characterization the first hard gate; then use module-owned versioned rule/profile tables and a conflict-free projection into the unchanged cache schema, preserving all native Grocy editing and normal Save boundaries. [VERIFIED: codebase grep]

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|---|---|---|---|
| Rule/profile catalog and validation | Database / Storage | API / Backend | SQLite gives atomic validation/projection; PHP owns semantic validation and diagnostics. [VERIFIED: codebase grep] |
| Effective conversion selection | API / Backend | Database / Storage | The module must enforce Phase 4 precedence/conflict policy before a cache row is available to consumers. [ASSUMED] |
| Grocy consumer compatibility | Database / Storage | API / Backend | Existing stock, recipe, and controller code read the cache by product/from/to keys. [VERIFIED: codebase grep] |
| Native universal and product editing | Frontend Server (SSR) | Browser / Client | Existing Blade forms and browser scripts post normal Grocy conversion objects. [VERIFIED: codebase grep] |
| Explanation/coverage UI | Frontend Server (SSR) | Browser / Client | Extend the two existing views with server-provided provenance and a small module-owned report. [VERIFIED: codebase grep] |

## Project Constraints (from AGENTS.md)

- Keep ATECHPCS behavior in `custom/grocy_AI/` and `public/custom/grocy_AI/`; keep unavoidable core changes minimal and documented in `CUSTOMIZATIONS.md`. [VERIFIED: codebase grep]
- Preserve `/etc/komodo/grocy` data across stable image rebuilds; do not put secrets in Git, logs, or build URLs. [VERIFIED: codebase grep]
- Preserve normal Grocy persistence as the explicit human action; use Grocy's PHP, Blade, JavaScript, REST, permission, SQLite migration, and file patterns. [VERIFIED: codebase grep]
- Use tabs and next-line braces; preserve target-file style; run `php -l` for changed PHP; do not add formatting-only upstream churn. [VERIFIED: codebase grep]
- Register/boot module code through `custom/grocy_AI/routes.php` and manual module requires unless Composer is deliberately changed; no browser bundler is present. [VERIFIED: codebase grep]

## Standard Stack

### Core

| Library / facility | Version | Purpose | Why Standard |
|---|---:|---|---|
| PHP + PDO SQLite | PHP 8.5 / SQLite 3.40+ | Module service, atomic validation, migration ledger, read-only report. | Existing module taxonomy implementation already uses this pattern. [VERIFIED: codebase grep] |
| Grocy `quantity_unit_conversions` + resolved cache | Existing schema | Native universal/product conversion editing and consumer contract. | The stock, recipe, controller, and browser paths already use it. [VERIFIED: codebase grep] |
| Blade + existing page JavaScript | Existing | Provenance in product/resolved screens and maintainer report. | Native forms/views already render the relevant rows. [VERIFIED: codebase grep] |
| NIST SP 811 conversion tables | Current online reference | Exact stored US customary-to-metric factors for universal mass/volume defaults. | NIST publishes the requested U.S. customary factors (including tsp, tbsp, fl oz, gallon). [CITED: https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors/nist-guide-si-appendix-b9] |
| USDA FoodData Central release data | April 2026 release | Sourced, versioned basis for food-profile factors. | USDA exposes data types, documentation, API, and dated downloads; record release + FDC item ID in each profile. [CITED: https://fdc.nal.usda.gov/download-datasets/] |

### Supporting

| Facility | Purpose | When to Use |
|---|---|---|
| `custom/grocy_AI/tests/run.php` | Standalone PHP contract suite. | Extend with fixture-only graph validation and projection assertions. [VERIFIED: codebase grep] |
| Existing portable parity/release scripts | Stable-vs-main deployment contract. | Add Phase 4 portable/adaptor paths only after the characterization selects them. [VERIFIED: codebase grep] |
| SQLite scratch DB plus `sqlite3` | Dual-branch schema/trigger characterization. | Use with copied fixture data; never against household data. [VERIFIED: local environment] |

**Installation:** No new package is recommended. [VERIFIED: codebase grep]

## Architecture Patterns

### System Architecture Diagram

```text
Native QU form ──normal Grocy Save──> quantity_unit_conversions (universal/product)
                                      │
Phase 3 explicit assignment ─────────┼──> module rule catalog + profile catalog
                                      │            │
                                      │            v
                                      │      validator / coverage report
                                      │      (dimensions, conflicts, cycles,
                                      │       reciprocity, tolerance, precedence)
                                      │            │ eligible only
                                      v            v
                           Grocy resolver/cache <── deterministic projection
                                      │
                                      v
     stock • recipes • purchase • consume • price • transfer • meal plans • displays
                                      │
                                      v
               product conversion screen + resolved-conversions provenance UI
```

The projection must preserve the cache's existing fields and key semantics (`product_id`, `from_qu_id`, `to_qu_id`, factor/path) until the dual-branch spike proves an additive schema is safe. [VERIFIED: codebase grep]

### Recommended Project Structure

```text
custom/grocy_AI/
├── src/GrocyAiConversionMigration.php   # module-owned ledger/schema/seed metadata
├── src/GrocyAiConversionService.php     # validation, resolution, projection DTOs
├── bin/validate-conversion-rules.php    # read-only aggregate/diagnostic report
└── tests/conversions.php                 # fixture graph + characterization assertions
public/custom/grocy_AI/
└── conversion-explanations.js            # only if a native page needs client behavior
```

### Pattern 1: Validate before project

**What:** Store universal rules, approximate profiles, source versions, and explicit ruleset version separately from Grocy's cache; construct candidate effective paths in PHP/SQLite; fail closed before writing any active projection. [ASSUMED]

**When to use:** Every universal edit, profile release, taxonomy re-assignment, or ruleset-version activation. [ASSUMED]

**Required checks:** source/to unit dimensions equal for universal rules; food profiles bridge only mass↔volume; no zero/non-finite/non-positive factors; inverse agrees within a documented relative tolerance; every product/from/to has at most one precedence winner; all non-identical paths either agree within tolerance or block; all cycles multiply to one within tolerance. [ASSUMED]

### Pattern 2: Explicit precedence, not SQL row order

**What:** Rank candidates by `product_override (1)`, `food_profile (2)`, `universal (3)` and select only after detecting same-rank/effective-path conflicts. [ASSUMED]

**Why:** The current resolver's CTE chooses `FIRST_VALUE` ordered by path depth for grouped results and does not implement the Phase 4 conflict policy. [VERIFIED: codebase grep]

### Pattern 3: Preserve native authority

**What:** Universal factors continue to be created/edited through the normal quantity-unit conversion form; package proposals only prefill/review on the native product conversion form and require its Save. [VERIFIED: codebase grep]

**Anti-patterns to Avoid**

- **Putting package/count units in reusable rules:** `pack`, `can`, `bottle`, and `piece` are product/barcode facts, not universal/profile facts. [CITED: https://github.com/grocy/grocy-docs/blob/master/tutorials/food.md]
- **Activating rules from Phase 2/provider/product-group evidence:** Phase 3 evidence is not an explicit classification. [VERIFIED: codebase grep]
- **Changing existing product conversion rows:** Phase 4 must be additive; Phase 6 owns reviewed cleanup. [VERIFIED: codebase grep]
- **Relying on cache refresh side effects:** characterize trigger scope and cache rebuild behavior on both branches first. [VERIFIED: codebase grep]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| Same-dimension unit factors | Approximate conversion constants | NIST-published factors, stored with citation/version. | Universal factors must be authoritative and reproducible. [CITED: https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors/nist-guide-si-appendix-b9] |
| Food source provenance | Unsourced household density guesses | Fixed FDC item/release references with mass and household-volume weight inputs. | FDC has dated downloads and documents its food data types. [CITED: https://fdc.nal.usda.gov/data-documentation/] |
| Native persistence | A custom conversion Save endpoint | Existing Grocy object API/form flow. | The existing form posts/puts `quantity_unit_conversions`; user constraints reserve normal Save authority. [VERIFIED: codebase grep] |
| Consumer conversion logic | Per-consumer conversions in Stock/Recipes/controllers | Existing cache contract after safe projection. | Existing consumers query the cache by product/from/to. [VERIFIED: codebase grep] |

## Common Pitfalls

### Pitfall 1: Resolver explosion or nondeterministic results

**What goes wrong:** Dense "each-to-each" defaults expand the recursive resolver heavily; multiple valid-looking paths can resolve differently by depth/order. [CITED: https://github.com/grocy/grocy/issues/2297]  
**How to avoid:** Keep the universal graph sparse and canonical (metric anchors), calculate conflict/cycle diagnostics outside the cache query, and block activation before touching projection rows. [ASSUMED]

### Pitfall 2: Trigger refresh is partial, not a semantic validator

**What goes wrong:** cache triggers delete/reinsert paths involving changed native unit IDs and automatically create inverses, but do not meet Phase 4's source/dimension/conflict requirements. [VERIFIED: codebase grep]  
**How to avoid:** Treat trigger execution as cache maintenance only; service-level validation is the activation authority. [ASSUMED]

### Pitfall 3: Profile activation leaks through evidence

**What goes wrong:** Product group and provider food-type evidence can produce a suggested taxonomy leaf without an explicit assignment. [VERIFIED: codebase grep]  
**How to avoid:** Query only `grocy_ai_taxonomy_classifications` with the current taxonomy ruleset and a non-null leaf for profile eligibility. [VERIFIED: codebase grep]

### Pitfall 4: Product-field changes alter historical semantics

**What goes wrong:** Grocy product triggers use resolved conversion factors when changing stock units and the cache participates in stock, recipe, price, and sub-product paths. [VERIFIED: codebase grep]  
**How to avoid:** Test changes separately from a frozen pre-activation snapshot and do not rewrite protected product/conversion rows. [ASSUMED]

## Code Examples

### Candidate validation boundary

```php
// Module pattern; do not call Grocy cache writes until this returns no blockers.
$report = $conversionService->ValidateRuleset($rulesetVersion);
if ($report['blocking_count'] !== 0)
{
	throw new \LogicException('Conversion ruleset has blocking conflicts');
}

$conversionService->ProjectEligibleRuleset($rulesetVersion);
```

This mirrors the existing module's transaction-backed validation/assignment style while keeping Phase 4's projection policy explicit. [VERIFIED: codebase grep]

### Dual-branch characterization matrix

```text
For each branch and a disposable fixture DB:
1. Snapshot cache rows and protected consumer query outputs.
2. Insert one native default, one product override, and each profile-candidate projection.
3. Record cache schema, row keys, factor, path, trigger side effects, and query plan/runtime.
4. Exercise stock, recipe, purchase, consume, price, transfer, meal-plan, and quantity displays.
5. Roll back/discard the fixture DB and compare all protected outputs to baseline.
```

The current implementation uses cache lookups throughout stock and recipe flows, so equality must be measured at those boundaries rather than inferred from schema compatibility. [VERIFIED: codebase grep]

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|---|---|---|---|
| Direct/limited conversion hierarchy | Recursive closure with path tracking and a materialized cache | Grocy migration `0208.sql` / cache migration `0225.sql` | Rules can compose across levels, but graph size and ambiguous paths become material risks. [VERIFIED: codebase grep] |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|---|---|---|
| A1 | A module-owned validate-then-project layer can populate/overlay the cache without changing consumer semantics. | Summary / Patterns | Characterization may require a different adapter or prove this unsafe. |
| A2 | Relative-tolerance graph checks should be implemented in PHP rather than solely in SQLite triggers. | Patterns | Exact implementation boundary could change, but blocking policy remains required. |
| A3 | Starter profiles should be derived from fixed USDA FDC records/releases rather than live API results. | Standard Stack | Planner needs select exact FDC items and compute/verify factors. |

## Open Questions

1. **Projection mechanism — resolved planning input.**
   - The initial catalog, candidate validation, and profile resolution are inactive by default. No catalog/profile row may project or activate merely because it is valid.
   - The only candidate native persistence boundary is `POST /api/objects/quantity_unit_conversions` and `PUT /api/objects/quantity_unit_conversions/{objectId}`, implemented by `GenericEntityApiController::AddObject` and `::EditObject`. Plan 04-02 adds the documented minimal, entity-specific upstream pre-save hook immediately after filtered request parsing and before `createRow()->save()`/`row->update()`; it delegates to the feature-gated module guard and leaves all other entities untouched. The guard repeats candidate validation before native rows or their cache triggers can change. [VERIFIED: codebase grep]
   - The exact active projection adapter remains deliberately deferred to the Plan 04-01 disposable dual-branch characterization. It may be selected only when immutable main/stable commit IDs, schema/trigger/cache-key manifests, and every protected-consumer comparison are all current and equal. Otherwise the hook may permit valid native product-scoped saves but must leave reusable catalog/profile projection inactive and report the gate blocker. [RESOLVED DEFERRED INPUT]

2. **Initial profile/source selection — resolved planning input.**
   - Select at most three starter profiles only from explicit current Phase 3 taxonomy leaves. Each needs a fixed USDA FDC release identifier, FDC item ID, documented portion-to-factor calculation, named source/version, and a source review fixture before it may be cataloged.
   - No broad leaf is assumed representative: absent a defensible fixed record/portion pair, the leaf stays unprofiled and returns `Unavailable` with no factor. Water-like beverage, milk, and a single oil are candidates for research verification only, not pre-approved profiles. [RESOLVED DEFERRED INPUT]

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|---|---|---:|---|---|
| PHP | Module contracts/characterization harness | ✓ | 8.5.9 | — [VERIFIED: local environment] |
| SQLite CLI | Scratch-schema/trigger inspection | ✓ | 3.51.0 | PDO SQLite script [VERIFIED: local environment] |
| Git | SHA-pinned dual-branch comparison | ✓ | 2.50.1 | — [VERIFIED: local environment] |
| Composer | Optional installed Blade/native test integration | ✗ | — | Fixture-only module tests; add human checkpoint before environment install. [VERIFIED: local environment] |

**Missing dependencies with no fallback:** None for the characterization/test design. [VERIFIED: local environment]  
**Missing dependencies with fallback:** Composer command is absent, but the existing module suite and SQLite/PHP scratch harness do not require a package install. [VERIFIED: local environment]

## Validation Architecture

### Test Framework

| Property | Value |
|---|---|
| Framework | Standalone PHP contract runner (`custom/grocy_AI/tests/run.php`) [VERIFIED: codebase grep] |
| Config file | none — extend module test dispatch/fixtures [VERIFIED: codebase grep] |
| Quick run command | `php custom/grocy_AI/tests/run.php conversions` [ASSUMED] |
| Full suite command | `php custom/grocy_AI/tests/run.php` [VERIFIED: codebase grep] |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| CONV-01 | Dimension mismatch rejected | unit | `php custom/grocy_AI/tests/run.php conversions` | ❌ Wave 0 |
| CONV-02 | NIST universal factors and inverses resolve | unit | same | ❌ Wave 0 |
| CONV-03 | Explicit taxonomy + profile source produces approximate result; absent profile returns unavailable | integration | same | ❌ Wave 0 |
| CONV-04 | Pack/count cannot become universal/profile edge | unit | same | ❌ Wave 0 |
| CONV-05 | Precedence and visible provenance are deterministic | unit/render | same | ❌ Wave 0 |
| CONV-06 | Conflict/cycle/reciprocity/tolerance blocks | unit | same | ❌ Wave 0 |
| CONV-07 | Coverage report exposes statuses | integration | same | ❌ Wave 0 |
| CONV-08 | Both branches emit equivalent characterization manifests | integration | branch-specific harness | ❌ Wave 0 |
| CONV-09 | Protected consumer output matrix matches baseline | integration/manual stable smoke | characterization + release gate | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php custom/grocy_AI/tests/run.php conversions` once implemented. [ASSUMED]
- **Per wave merge:** `php custom/grocy_AI/tests/run.php`. [VERIFIED: codebase grep]
- **Phase gate:** exact dual-branch characterization manifest and all protected behavior comparisons green before any rule activation. [VERIFIED: codebase grep]

### Wave 0 Gaps

- [ ] `custom/grocy_AI/tests/conversions.php` plus bounded graph fixtures — CONV-01 through CONV-07. [ASSUMED]
- [ ] Disposable SQLite database builder/manifest comparer runnable in both repository roots — CONV-08 and CONV-09. [ASSUMED]
- [ ] Native view rendering assertions for product/resolved provenance labels — CONV-05. [ASSUMED]

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---|---|---|
| V2 Authentication | yes | Require the existing authenticated Grocy session/API permission before maintainer pages/actions. [VERIFIED: codebase grep] |
| V3 Session Management | no new mechanism | Reuse Grocy middleware; do not add conversion credentials. [VERIFIED: codebase grep] |
| V4 Access Control | yes | Gate rule inspection/edit actions behind `MASTER_DATA_EDIT`, following module API pattern. [VERIFIED: codebase grep] |
| V5 Input Validation | yes | Closed unit/dimension/profile/source enums; numeric finite positive factor and bounded text validation. [ASSUMED] |
| V6 Cryptography | no | No cryptographic feature is required. [ASSUMED] |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---|---|---|
| Silent wrong factor via competing path | Tampering | Validate all paths and block activation; preserve diagnostic evidence. [ASSUMED] |
| Unauthorized global-rule edit | Elevation of Privilege | Existing auth + `MASTER_DATA_EDIT` check before change/report endpoints. [VERIFIED: codebase grep] |
| Household-data disclosure in reports | Information Disclosure | Use aggregate/redacted report output, as the taxonomy validator does. [VERIFIED: codebase grep] |
| Trigger/cache corruption from partial projection | Tampering / DoS | One transaction on a disposable candidate set; characterize and compare cache before release. [ASSUMED] |

## Sources

### Primary (HIGH confidence)

- [Grocy source: recursive resolver and cache triggers](https://github.com/grocy/grocy/blob/master/migrations/0208.sql) — current resolver shape; local `migrations/0208.sql` cross-check. [VERIFIED: codebase grep]
- [Grocy source: cache migration/triggers](https://github.com/grocy/grocy/blob/master/migrations/0225.sql) — materialized cache maintenance; local cross-check. [VERIFIED: codebase grep]
- [NIST SI conversion factors](https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors/nist-guide-si-appendix-b9) — customary mass/volume factor source. [CITED: https://www.nist.gov/pml/special-publication-811/nist-guide-si-appendix-b-conversion-factors/nist-guide-si-appendix-b9]
- [USDA FDC downloadable data](https://fdc.nal.usda.gov/download-datasets/) and [data-type documentation](https://fdc.nal.usda.gov/data-documentation/) — dated profile source/version strategy. [CITED: https://fdc.nal.usda.gov/download-datasets/]

### Secondary (MEDIUM confidence)

- [Grocy food tutorial](https://github.com/grocy/grocy-docs/blob/master/tutorials/food.md) — universal kg→g vs product-specific pack examples. [CITED: https://github.com/grocy/grocy-docs/blob/master/tutorials/food.md]
- [Grocy issue #2297](https://github.com/grocy/grocy/issues/2297) — recursive resolver graph-size failure mode. [CITED: https://github.com/grocy/grocy/issues/2297]

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — entirely existing PHP/SQLite/Grocy facilities; no package installation. [VERIFIED: codebase grep]
- Architecture: MEDIUM — cache/consumer facts are verified; final safe projection is deliberately gated by the mandatory characterization. [VERIFIED: codebase grep]
- Pitfalls: HIGH — cache trigger mechanics and recursive closure are source-verified; the performance warning is confirmed by an upstream Grocy issue. [VERIFIED: codebase grep]

**Research date:** 2026-08-21  
**Valid until:** 2026-09-20 for internal architecture; re-check dated FDC release immediately before selecting profile records. [CITED: https://fdc.nal.usda.gov/download-datasets/]
