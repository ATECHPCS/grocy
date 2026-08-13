# Requirements: grocy_AI

**Defined:** 2026-08-12
**Core Value:** Adding and maintaining real household food inventory must be fast, accurate, and dependable from a phone without surrendering control of the data to automatic guesses.

## v1 Requirements

### Mobile Reliability and Diagnostics

- [x] **MOB-01**: User can start product enrichment from a phone by camera scan or manual GTIN entry and receives immediate length/checksum validation.
- [x] **MOB-02**: User sees distinct invalid, not-found, timeout, offline, provider-error, and success states with bounded waits and an available cancel or retry action.
- [x] **MOB-03**: User never receives or applies a stale response after changing the GTIN, navigating back, cancelling, or starting a newer request.
- [x] **MOB-04**: Repeated taps, scans, or retries cannot cause duplicate requests to create duplicate visible results or duplicate persisted changes.
- [x] **MOB-05**: Operator can correlate browser, Grocy, companion, and provider stages using a request/trace identifier and privacy-safe timing data.
- [x] **MOB-06**: User can copy a redacted diagnostic report containing versions, correlation ID, stage statuses, and timings without credentials, cookies, payload bodies, UPC history, or image tokens.
- [x] **MOB-07**: User can continue normal Grocy product and inventory workflows when the companion, SearXNG, Open Food Facts, or an image host is unavailable.
- [x] **MOB-08**: Maintainer can verify explicit LAN/mobile latency budgets and failure behavior through automated mobile-browser coverage plus a physical-phone acceptance pass.

### Product Enrichment and Barcode Handoff

- [ ] **ENR-01**: User receives a strictly validated, versioned enrichment response in which every external suggestion includes source, confidence band, reason, and retrieval freshness.
- [ ] **ENR-02**: User sees the originally scanned barcode while Grocy checks canonical UPC/EAN/GTIN equivalents for duplicates.
- [ ] **ENR-03**: User scanning a barcode already assigned in Grocy is routed to the existing owning product instead of creating a duplicate.
- [ ] **ENR-04**: User can stage a previously unused barcode in Grocy's normal product/barcode workflow, and it is written exactly once only after Save.
- [ ] **ENR-05**: User can independently review suggestions for name, brand, package size, product group, quantity unit, food type, and product image alongside current values.
- [ ] **ENR-06**: User sees one final diff of selected enrichment changes, and no unselected field is changed when the normal Grocy Save action runs.
- [ ] **ENR-07**: User sees an exact structured-source front-package image first when available, while SearXNG candidates are clearly identified as unverified alternatives.
- [ ] **ENR-08**: User can demand-load and select same-origin proxied thumbnails/full images using short-lived opaque handles with URL, redirect, byte, time, MIME, signature, and pixel/dimension safeguards.
- [ ] **ENR-09**: Search, preview, cancel, timeout, and failed image retrieval produce no product, barcode, category, stock, conversion, or file persistence before normal Save.

### Food Taxonomy and Single-Product Classification

- [ ] **TAX-01**: Maintainer can define a small versioned two-level household food taxonomy with stable IDs/slugs independent of provider display labels.
- [ ] **TAX-02**: Taxonomy omits baby-food and pet-food types and prevents provider mappings from silently reintroducing them.
- [ ] **TAX-03**: User can leave a product explicitly `Unclassified` when evidence is absent, conflicting, or below the accepted confidence threshold.
- [ ] **TAX-04**: User can review and assign exactly one current taxonomy leaf to a product without changing stock amount, stock unit, recipes, prices, history, or location.
- [ ] **TAX-05**: User can see the provider category evidence, local mapping/ruleset version, confidence, and reason behind a suggested food type.
- [ ] **TAX-06**: Maintainer can validate taxonomy v1 against all in-scope existing products and explicitly decide whether frozen/preserved state is a food identity or a separate handling/location concern.
- [ ] **TAX-07**: Maintainer can migrate and version module-owned taxonomy data through namespaced schema objects without colliding with upstream Grocy migrations.

### Reusable Conversion Model

- [ ] **CONV-01**: Maintainer can assign quantity units to explicit dimensions so invalid cross-dimension universal conversions are rejected.
- [ ] **CONV-02**: User receives universal, authoritative same-dimension mass and volume conversions without product-specific duplication.
- [ ] **CONV-03**: User can use a narrow, sourced, explicitly approximate food-type mass/volume profile only where the food's measured behavior justifies it.
- [ ] **CONV-04**: Package/count conversions such as pack, can, bottle, or piece remain bound to a product or barcode rather than becoming universal or food-type rules.
- [ ] **CONV-05**: Effective conversion resolution is deterministic with precedence `product override > food type > universal` and exposes the winning source.
- [ ] **CONV-06**: Maintainer receives a blocking conflict for cycles, competing effective paths, reciprocal inconsistency, dimension mismatch, or factors outside configured tolerance.
- [ ] **CONV-07**: Maintainer can inspect effective conversion coverage, missing paths, source, conflicts, cycles, and redundant product overrides before changing rules.
- [ ] **CONV-08**: Maintainer can run a dual-branch characterization spike that selects and documents the smallest safe projection into Grocy's existing resolved/cache consumer contract before conversion schema implementation.
- [ ] **CONV-09**: Existing stock, recipe, purchase, consumption, price, and quantity displays retain equivalent behavior after reusable rules are introduced.

### Bulk Preview, Apply, Audit, and Recovery

- [ ] **BULK-01**: User can generate a bounded dry-run plan that reports exact included, excluded, skipped, conflicted, changed, and unchanged counts before any mutation.
- [ ] **BULK-02**: Each dry-run item records stable object identity, immutable before/proposed values, reason, provenance, ruleset version, and a plan checksum.
- [ ] **BULK-03**: User can select/reject individual proposed items and review the complete selected diff before approving execution.
- [ ] **BULK-04**: Apply refuses stale or conflicting items when current field values no longer match the reviewed before-image.
- [ ] **BULK-05**: Bulk execution accepts only named typed operations and cannot execute arbitrary CRUD payloads or SQL supplied by the browser or companion.
- [ ] **BULK-06**: An approved plan applies through one short `BEGIN IMMEDIATE` transaction without network/provider calls while the write lock is held.
- [ ] **BULK-07**: Applying or retrying the same approved plan is idempotent and cannot repeat already completed mutations.
- [ ] **BULK-08**: Maintainer can audit who previewed/applied a plan, timestamps, module/version identifiers, item outcomes, and exact before/after values.
- [ ] **BULK-09**: User can preview rollback, and rollback refuses to overwrite any field changed manually after the original apply.
- [ ] **BULK-10**: User can export a redacted JSON or CSV preview snapshot for independent review and recovery evidence without treating it as authority on re-import.

### Existing Inventory Classification and Conversion Cleanup

- [ ] **DATA-01**: Maintainer can profile every in-scope existing food product and generate classification suggestions while excluding baby food, pet food, inactive/non-food exceptions, and explicitly out-of-scope records.
- [ ] **DATA-02**: User can review low-confidence and conflicting food classifications first, leave items `Unclassified`, and apply only the explicitly approved assignments.
- [ ] **DATA-03**: Maintainer can profile the existing product-specific conversion set as logical unit pairs with origin/source, factor, usage, duplicates, malformed rows, and dependencies.
- [ ] **DATA-04**: Maintainer can compare effective conversion paths before and after proposed cleanup and block deletion when stock, recipes, purchase units, or named exceptions would lose equivalent coverage.
- [ ] **DATA-05**: User can remove only reviewed redundant product-specific conversions while retaining required package/count, measured-density, purchase-to-stock, and other named exceptions.
- [ ] **DATA-06**: Maintainer can verify after classification/cleanup that product and stock counts, stock amounts, history, recipes, prices, due dates, authentication, and normal Grocy behavior remain unchanged except for approved fields/rules.
- [ ] **DATA-07**: Maintainer can rerun completed classification and cleanup plans with zero additional diffs and rehearse guarded rollback against production-shaped data.

### Upstream and Stable Release Sustainment

- [ ] **REL-01**: Maintainer can identify every ATECHPCS core hook and keep feature implementation inside `custom/grocy_AI/` and `public/custom/grocy_AI/` wherever possible.
- [ ] **REL-02**: Maintainer can verify portable custom files and branch-specific adapters on both upstream-tracking `atech-main` and stable `atech-release` before promotion.
- [ ] **REL-03**: Maintainer can test module migrations and upgrade paths against production-shaped Grocy data on both maintained branches.
- [ ] **REL-04**: Maintainer can build a stable container with exact source/version metadata, invalidate route/view assets, and identify the deployed artifact by immutable digest.
- [ ] **REL-05**: Maintainer can deploy a stable image while preserving `/etc/komodo/grocy` data and confirm database, product images, routes, flags, and module status after restart.
- [ ] **REL-06**: User can complete the end-to-end mobile product workflow on the promoted stable image before the release is accepted.
- [ ] **REL-07**: Maintainer can execute a rehearsed prior-image and database recovery procedure if a promoted module or migration fails.

## v2 Requirements

### Assisted Learning and Visualization

- **V2-01**: User can create and remove visible local mapping hints from repeated reviewed provider-category corrections.
- **V2-02**: Maintainer can explore an interactive conversion graph showing paths, precedence, cycles, gaps, and conflicts.
- **V2-03**: User can re-import an exported preview snapshot as a proposal after a mandatory fresh conflict check.

## Out of Scope

| Feature | Reason |
|---------|--------|
| Rewrite Grocy in Rust, TypeScript, Python, or another language | Loses upstream compatibility and mature inventory behavior for insufficient benefit |
| Native or offline mobile inventory application | Duplicates authentication, synchronization, UI, and persistence; responsive Grocy is the target |
| Autonomous product, barcode, image, taxonomy, stock, or conversion writes | Violates review-before-save and household data control |
| AI-generated packaging for valid products | Real verified package imagery is more useful and avoids invented trade dress |
| Full Open Food Facts, FoodEx2, or other external taxonomy mirror | Too large, unstable, and irrelevant for a household picker |
| Baby-food and pet-food categories | Explicitly unused in this household |
| Chores and battery feature development | Upstream features remain present but disabled |
| Universal mass-to-volume or package/count conversions | Density and package amounts are food/product specific |
| Nutrition, allergen, dietary, or medical recommendations | High-stakes semantics outside inventory onboarding scope |
| External provider write-back | Introduces accounts, moderation, licensing, and data-quality scope |
| Public/cloud telemetry by default | Household inventory and network diagnostics remain local/private |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| MOB-01 | Phase 1 | Complete |
| MOB-02 | Phase 1 | Complete |
| MOB-03 | Phase 1 | Complete |
| MOB-04 | Phase 1 | Complete |
| MOB-05 | Phase 1 | Complete |
| MOB-06 | Phase 1 | Complete |
| MOB-07 | Phase 1 | Complete |
| MOB-08 | Phase 1 | Complete |
| ENR-01 | Phase 2 | Pending |
| ENR-02 | Phase 2 | Pending |
| ENR-03 | Phase 2 | Pending |
| ENR-04 | Phase 2 | Pending |
| ENR-05 | Phase 2 | Pending |
| ENR-06 | Phase 2 | Pending |
| ENR-07 | Phase 2 | Pending |
| ENR-08 | Phase 2 | Pending |
| ENR-09 | Phase 2 | Pending |
| TAX-01 | Phase 3 | Pending |
| TAX-02 | Phase 3 | Pending |
| TAX-03 | Phase 3 | Pending |
| TAX-04 | Phase 3 | Pending |
| TAX-05 | Phase 3 | Pending |
| TAX-06 | Phase 3 | Pending |
| TAX-07 | Phase 3 | Pending |
| CONV-01 | Phase 4 | Pending |
| CONV-02 | Phase 4 | Pending |
| CONV-03 | Phase 4 | Pending |
| CONV-04 | Phase 4 | Pending |
| CONV-05 | Phase 4 | Pending |
| CONV-06 | Phase 4 | Pending |
| CONV-07 | Phase 4 | Pending |
| CONV-08 | Phase 4 | Pending |
| CONV-09 | Phase 4 | Pending |
| BULK-01 | Phase 5 | Pending |
| BULK-02 | Phase 5 | Pending |
| BULK-03 | Phase 5 | Pending |
| BULK-04 | Phase 5 | Pending |
| BULK-05 | Phase 5 | Pending |
| BULK-06 | Phase 5 | Pending |
| BULK-07 | Phase 5 | Pending |
| BULK-08 | Phase 5 | Pending |
| BULK-09 | Phase 5 | Pending |
| BULK-10 | Phase 5 | Pending |
| DATA-01 | Phase 6 | Pending |
| DATA-02 | Phase 6 | Pending |
| DATA-03 | Phase 6 | Pending |
| DATA-04 | Phase 6 | Pending |
| DATA-05 | Phase 6 | Pending |
| DATA-06 | Phase 6 | Pending |
| DATA-07 | Phase 6 | Pending |
| REL-01 | Phase 7 | Pending |
| REL-02 | Phase 7 | Pending |
| REL-03 | Phase 7 | Pending |
| REL-04 | Phase 7 | Pending |
| REL-05 | Phase 7 | Pending |
| REL-06 | Phase 7 | Pending |
| REL-07 | Phase 7 | Pending |

**Coverage:**
- v1 requirements: 57 total
- Mapped to phases: 57
- Unmapped: 0 ✓

---
*Requirements defined: 2026-08-12*
*Last updated: 2026-08-12 after roadmap creation*
