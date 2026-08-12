# Feature Landscape

**Domain:** Reviewable, mobile-first household food inventory enrichment and maintenance for Grocy
**Project:** grocy_AI
**Researched:** 2026-08-12
**Overall confidence:** HIGH for barcode/review/image behavior; MEDIUM for the proposed household taxonomy and food-specific conversion profiles, which require validation against the live inventory

## Product Principle

The extension should shorten Grocy's existing product-creation and maintenance workflows without becoming a second inventory system. External metadata, search results, classifications, and conversion rules are evidence presented to the user. Grocy's normal product/barcode/save flows remain authoritative, and no suggestion changes persisted data until a user approves a clearly bounded action.

## Table Stakes

Missing any of these behaviors makes the extension unreliable for routine household use.

| Feature | Why Expected | Complexity | Testable behavior |
|---------|--------------|------------|-------------------|
| Camera scan plus manual GTIN entry | Kitchen/store use is phone-first, while damaged barcodes and denied camera permission require an escape hatch | Medium | From the product workflow, a user can scan or type EAN-8, UPC-A/GTIN-12, EAN-13, or GTIN-14; leading zeroes remain visible; denied camera permission leaves manual entry usable; invalid length/check digit produces a specific warning without changing data |
| Immediate scan feedback and bounded waiting | A scan that appears ignored encourages repeat scans and duplicate work | Medium | Within 250 ms of a decoded/entered barcode, the UI shows the exact code and a loading state; a repeated scan while the same request is active is coalesced; the user can cancel or retry; the workflow never leaves a button indefinitely disabled |
| Explicit result states | “Not found,” invalid input, provider failure, timeout, and local permission failure require different recovery actions | Low | The UI renders distinct states for invalid barcode, existing Grocy match, provider match, no provider match, timeout, companion unavailable, and image unavailable; each state offers the appropriate retry/manual-continue path |
| Duplicate-safe Grocy barcode handoff | Grocy already owns product barcodes and external barcode lookup; enrichment must not create parallel or duplicate records | High | Before offering creation, check both the scanned value and its provider-normalized leading-zero equivalent against Grocy barcodes; an existing match opens/selects the existing product; for a new match, the barcode is staged in Grocy's normal product-barcode workflow and is written exactly once only after normal Save; cancel/back leaves product and barcode row counts unchanged |
| Field-by-field metadata review | Open Food Facts explicitly disclaims completeness and reliability; current household values can be more trustworthy | Medium | Show current and suggested values side by side for name, brand, package quantity/size, product group, stock/purchase quantity unit, and food type; show source and confidence/reason; each field has an independent Apply action; conflicting non-empty current values are never overwritten by a bulk “apply” without being named in the confirmation |
| Review summary before persistence | Applying controls to a form can still obscure what the eventual Save will change | Medium | Immediately before normal Grocy Save, a compact change summary identifies applied fields, staged barcode, and selected image; cancel returns to the populated form; closing without saving creates no master-data, barcode, file, or stock write |
| Real front-package image selection | The image must help identify the exact retail package, not merely resemble it | Medium | Prefer an Open Food Facts `selected_images.front` image for the requested GTIN and interface/product language; label every candidate with source and match confidence; never preselect a search-engine candidate; require an explicit selection; selected content still passes opaque-handle, MIME, signature, and size validation before attachment |
| Image mismatch and failure recovery | Search results, old packaging, and mobile transfers are fallible | Medium | A user can enlarge/inspect candidates, choose “no image,” replace a previous selection, and re-search after an expired token; a failed download does not clear other reviewed fields or submit the form; after Save/reload the chosen local Grocy image is displayed |
| Same-origin, demand-loaded previews | Eager external images leak browsing activity and multiply slow mobile requests | Medium | Candidate thumbnails are fetched through a bounded trusted proxy or only after explicit/viewport demand; opening one result does not fan out to all candidate hosts; HTTP-only candidates are rejected when the deployment is HTTPS |
| Mobile save/reload workflow | Phase 1 is not complete until the whole phone path works, including browser form integration | High | On the supported household phone/browser matrix, scan/search, review, Apply, image choice, Save, reload, retry, and back-navigation work without horizontal scrolling, obscured controls, double submission, or dependence on hover; minimum touch targets are 44 CSS px |
| Measured LAN performance budget | “Slow” must be observable and actionable | Medium | The browser shows progress immediately; on the household LAN, cached/existing-Grocy matches target p95 <= 1 s, provider metadata target p95 <= 5 s, and image attachment target p95 <= 5 s; no individual provider request may exceed a 10 s interactive deadline without returning a named timeout and retry path; thresholds are re-baselined only from recorded measurements |
| Curated household food taxonomy | A finite controlled vocabulary is required before safe categorization or type-level conversions | Medium | Every in-scope food has exactly one leaf food type or `Unclassified`; parent groups are derived, not separately edited; baby food and pet food have no selectable types; labels and stable IDs are versioned; changing a label does not change the ID |
| Explainable single-product classification | Food type is useful only if a user can understand and correct it | Medium | Show proposed food type, confidence band, matched provider categories/keywords, and alternative(s); low-confidence or conflicting evidence defaults to `Unclassified`; acceptance is explicit; a correction is recorded as provenance without silently rewriting other products |
| Dimension-safe universal conversions | Mass-to-mass and volume-to-volume relationships are physical constants and should not be duplicated per product | High | Supply one canonical set for mass (`kg`, `g`, `lb`, `oz`) and volume (`L`, `mL`, US `gal`, `qt`, `pt`, `cup`, `fl oz`, `tbsp`, `tsp`) with declared locale/system and precision; inverse and multi-hop results agree within configured tolerance; rules never cross mass and volume dimensions |
| Narrow food-specific conversion profiles | Cross-dimension conversions require food-specific measured density/portion evidence | High | A food-type rule may relate volume and mass only for a narrowly named profile such as water-like liquids, all-purpose flour, granulated sugar, fine salt, uncooked rice, or butter; every rule displays source, basis, precision, and “approximate”; broad parents such as `Dairy` or `Pantry` cannot own density rules |
| Package/count semantics stay product- or barcode-bound | “Pack,” “can,” “bottle,” and “piece” are not universal quantities | Medium | Package quantity from the UPC suggestion can populate a barcode amount/quantity unit or the normal purchase-to-stock factor only after review; no universal rule claims `1 pack = N g/mL/items`; two GTINs for different package sizes can coexist without changing one another's amount |
| Deterministic conversion precedence and conflict detection | Reusable rules add another scope and can otherwise yield ambiguous paths | High | Resolution order is documented and deterministic: barcode/package amount, explicit product exception, narrow food-type rule, then universal same-dimension rule; if two valid paths disagree beyond tolerance, mark a conflict and block affected bulk changes rather than selecting one silently |
| Dry-run bulk categorization | Existing inventory cannot be safely reclassified from an opaque one-click operation | High | User chooses an explicit scope; preview lists every product's current value, proposed value, confidence, evidence, and conflict; rows can be accept/reject/override; filters expose low confidence and conflicts; previewing performs zero writes and produces stable results for the same ruleset/data snapshot |
| Stock-safe bulk execution | Classification and conversion cleanup must not alter quantities, history, prices, or expiry data | High | Execution changes only approved taxonomy/conversion/master-data fields; it never modifies `stock`, `stock_log`, recipe amounts, prices, due dates, or file data; products whose stock quantity unit would need changing are blocked for separate manual handling |
| Auditable, rollback-safe maintenance | A household deployment still needs recovery from a bad mapping or rule | High | Each run records ID, actor, timestamp, ruleset version, input snapshot/checksum, approved row diffs, skipped/conflicted rows, and outcome; execution is transactional or bounded into resumable batches; re-running is idempotent; rollback preview shows the exact inverse diff and refuses when later edits conflict |
| Conversion cleanup equivalence check | Deleting approximately 101 product rules is safe only if resolved behavior remains equivalent | High | For each candidate deletion, compare all currently resolvable relevant unit pairs before/after; remove only rules replaced within tolerance; retain/name genuine package or product exceptions; block cycles, zero/negative factors, dimension mismatches, and divergent paths |
| Stage-level operational visibility | Mobile connection errors must be attributable to the browser/LAN, Grocy, companion, metadata provider, or image host | Medium | Every lookup gets a correlation ID and stage durations; the user sees a concise failing stage and retry advice; local diagnostics record status class, duration, timeout/cancel, cache outcome, and provider name while excluding API keys, session identifiers, response bodies, and sensitive query strings |
| Core Grocy remains usable during enrichment failure | The companion is optional and synchronous; an outage must not stop inventory work | Medium | With companion/metadata/image providers offline, normal Grocy product create/edit, barcode, purchase, consume, and inventory pages still load and save; enrichment controls show degraded/unavailable status and allow manual completion |

## Recommended Household Food Taxonomy

Use a deliberately small two-level controlled vocabulary, not the full Open Food Facts or FoodEx2 hierarchy. The leaf is the stored classification; the parent exists for navigation and reporting. Validate the final labels against the current inventory before freezing version 1.

| Parent | Initial leaf types | Boundary rule |
|--------|--------------------|---------------|
| Produce | Fruit; Vegetables; Herbs & fresh aromatics | Fresh, frozen, canned, or dried form does not change the underlying food type; storage/location remains separate |
| Meat & seafood | Beef; Pork; Poultry; Seafood; Other meat | Use primary ingredient; mixed ready-to-eat dishes belong under Prepared foods |
| Dairy & eggs | Milk & cream; Cheese; Yogurt & cultured dairy; Eggs; Other dairy | Plant-based analogues go under their underlying beverage/prepared/spread type unless a future household need justifies a separate leaf |
| Grains & bakery | Bread & bakery; Breakfast cereal; Rice & whole grains; Pasta & noodles; Tortillas & wraps | Classify the product as sold, not the recipe it may become |
| Pantry ingredients | Flour & starch; Sugar & sweeteners; Baking ingredients; Spices & seasoning; Dry beans & legumes; Nuts & seeds | Keep conversion-bearing ingredient leaves narrow enough to attach a measured profile only when justified |
| Condiments, sauces & spreads | Condiments; Sauces; Dressings; Spreads; Cooking oils & fats | Do not infer one density across this parent |
| Canned and preserved foods | Preserved fruit; Preserved vegetables; Soups & broths; Pickles & ferments; Other preserved food | Use only when preservation defines the household use; otherwise prefer the underlying food leaf to avoid packaging-driven categories |
| Snacks & sweets | Savory snacks; Candy & chocolate; Cookies & snack bars; Desserts | Excludes frozen dessert when the household wants freezer-specific reporting; that can be a future leaf, not an initial special case |
| Beverages | Water; Soft drinks; Juice; Coffee & tea; Alcoholic beverages; Other beverages | Beverage packages can share a volume unit but not necessarily a mass-density rule |
| Frozen foods | Frozen produce; Frozen protein; Frozen prepared food; Frozen dessert | Use only if freezer workflow is more useful than ingredient identity; decide this boundary consistently during taxonomy validation |
| Prepared foods | Ready meals; Meal components; Deli/prepared foods; Mixes requiring preparation | Includes multi-ingredient items where no single ingredient type is operationally dominant |
| Other food | Specialty/other food; Unclassified | `Unclassified` is a valid safe outcome, never an error to be auto-filled |

**Taxonomy decision to validate:** `Canned and preserved foods` and `Frozen foods` mix processing/storage with food identity. Keep them only if current household reporting and conversion behavior benefits; otherwise classify by underlying food and use Grocy location/product group for storage. Confidence: MEDIUM.

## Differentiators

These are not required for basic correctness, but they make this extension materially better than a generic barcode import.

| Feature | Value Proposition | Complexity | Testable behavior |
|---------|-------------------|------------|-------------------|
| Evidence-weighted candidate ranking | Exact GTIN evidence should outrank a visually similar web result | High | Ranking score is decomposable into exact requested GTIN, selected-front status, brand/name/size agreement, source trust, and language; the UI exposes these factors; a search-only image cannot receive “exact” confidence without corroborating package/GTIN evidence |
| Conflict-first review queue | Human attention is spent on uncertain or consequential rows instead of rubber-stamping easy matches | Medium | Bulk preview groups conflicts and low-confidence items first, supports keyboard/mobile approve/reject, and shows counts remaining; high-confidence rows are still reviewable and never auto-executed |
| Provenance and freshness on every applied suggestion | Users can later understand why a value exists and decide whether to refresh it | Medium | Store/display provider, provider record identifier where available, retrieval time, ruleset/model version, and user decision for extension-managed fields; refreshing creates a new suggestion and diff, not an overwrite |
| One-confirmation “apply reviewed set” | Reduces phone taps without weakening human control | Medium | User selects a set of suggested fields/images, then sees one complete diff and confirms it; no unchecked field is applied; database persistence still waits for Grocy Save |
| Conversion coverage inspector | Makes reusable-rule cleanup understandable rather than a raw table-editing task | High | For any product/type, visualize resolved unit pairs, rule source, factor, missing paths, cycles, conflicting paths, and redundant product overrides; compare coverage before/after a proposed rule change |
| Learned local mapping hints | Repeated household corrections should reduce repetitive review without outsourcing control | High | A user-approved mapping from a provider category/brand pattern to a local leaf becomes a visible local rule; future matches cite that rule and remain suggestions; deleting the rule stops future use and changes no existing product |
| Diagnostic “copy report” action | Makes intermittent mobile/LAN failures supportable without exposing secrets | Low | A user can copy a redacted report containing correlation ID, timestamps/durations, stage/status, browser/network class, module version, and provider names; automated tests assert secrets, cookies, full response bodies, and image tokens are absent |
| Preview snapshot export | Gives an independent recovery/review artifact before large cleanup | Medium | Dry-run results can be exported as JSON/CSV containing stable product IDs and before/proposed values; importing/using a stale snapshot requires a fresh conflict check against current records |

## Anti-Features

Explicitly do not build these; each undermines the project's safety, scope, or upstream compatibility.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Autonomous product/category/conversion/stock/image writes | Provider data is incomplete and household conventions are local; hidden writes violate the core value | Generate a preview/diff and require an explicit user-approved action followed by normal Grocy persistence |
| Auto-create on scan | A noisy/double scan or wrong database match can create duplicates before review | Search existing Grocy first, stage the barcode/product form, and persist once on Save |
| Treat provider normalization as the stored truth | Leading-zero normalization is useful for lookup but can collapse visibly different scanned forms and obscure duplicate handling | Preserve/display the scanned identifier, use canonical equivalents for provider lookup and duplicate checks, and show the exact barcode being saved |
| Treat a SearXNG image hit as proof of product identity | Search ranking is not a GTIN guarantee; visually similar variants and old packaging are common | Rank as a fallback candidate, expose evidence/source, and require explicit selection |
| AI-generated or generic stock imagery for valid packaged goods | It harms visual identification and can invent packaging | Use verified real-package front images or no image |
| Eagerly load every third-party image candidate | Leaks household browsing activity and causes mobile latency/failure fan-out | Use proxied bounded thumbnails and demand loading |
| Mirror the complete Open Food Facts/FoodEx2 taxonomy | Huge evolving taxonomies create unusable pickers, unstable mappings, and irrelevant medical/regulatory distinctions | Maintain a small versioned local vocabulary and retain provider category IDs only as provenance/mapping inputs |
| Put baby food, pet food, chores, or batteries into the taxonomy work | Explicitly outside this household's inventory scope | Omit these types and leave existing Grocy feature flags disabled |
| Use storage location or package shape as food type by default | “Frozen,” “canned,” and “bottle” can describe handling, not what the food is, and produce inconsistent classification | Prefer ingredient/use identity; use Grocy location/product group or a separate handling facet when needed |
| Universal mass-to-volume conversion | Density varies by food and preparation; a mathematically neat global factor silently corrupts recipe/stock quantities | Permit cross-dimension conversion only through narrow sourced food profiles marked approximate |
| Universal `pack`, `can`, `bottle`, or `piece` conversion | Package/count sizes differ by GTIN and even by variant of the same product | Store package amount/QU on the barcode or normal product purchase-to-stock relationship |
| Recreate product-specific conversion sprawl | It preserves the current maintenance problem and hides which exceptions are real | Use universal same-dimension rules, narrow food-type profiles, and named product exceptions only where evidence requires them |
| Change stock quantity units during bulk categorization | Existing stock, logs, recipes, and prices depend on the current base unit | Block those rows and handle them through a separate, characterized Grocy migration with explicit equivalence tests |
| Unbounded all-inventory mutation | A partial failure or bad mapping becomes hard to audit and recover | Explicit scope, immutable preview, bounded transactional batches, conflict detection, run manifest, and rollback preview |
| Delete old conversions before equivalence verification | Existing recipes and unit selections may depend on indirect resolved paths | Prove before/after resolved-pair equivalence and retain named exceptions |
| Replace Grocy's product form/API persistence | Creates a second validation and authorization path and increases fork/upstream conflict | Attach reviewed values to existing fields and call established Grocy Save/barcode/file flows |
| Native mobile app or offline inventory engine | Duplicates authentication, UI, synchronization, and persistence outside this milestone | Improve and test Grocy's responsive PWA-style web frontend; show graceful online-provider degradation |
| Nutrition, allergen, diet, or medical recommendations | Not part of household inventory onboarding and introduces high-stakes semantics | Limit structured data to identity, package, grouping, units, and operational inventory fields |
| Write-back to external product databases | Requires external accounts, moderation, licensing UX, and a separate data-quality scope | Treat providers as read-only sources for this milestone |
| Language or naming rewrites beyond selected fields | Can erase preferred local product names and expands scope | Present source text as a suggestion; keep current names unless explicitly applied |
| Public/cloud telemetry by default | Household inventory and network diagnostics are private, and external monitoring adds another dependency | Keep bounded structured diagnostics local; allow deliberate redacted export by the user |

## Feature Dependencies

```text
Stage telemetry + error model
    -> mobile end-to-end verification
    -> reliable performance baselines

Scan/manual entry
    -> barcode normalization-equivalence check
    -> existing-Grocy duplicate check
    -> staged normal barcode/product Save

Structured provider response + provenance
    -> field-by-field review
    -> exact-package image ranking
    -> explainable food-type suggestion

Versioned local taxonomy
    -> reviewed single-product classification
    -> local mapping rules
    -> dry-run bulk categorization

Canonical quantity units + dimension model
    -> universal same-dimension conversions
    -> narrow food-specific conversion profiles
    -> deterministic resolution/conflict detection
    -> conversion coverage/equivalence report
    -> approved cleanup of redundant product rules

Dry-run diff engine + audit manifest + conflict detection
    -> bounded bulk categorization
    -> rollback-safe conversion cleanup
```

## MVP Recommendation

Prioritize in this order:

1. **Operational baseline and mobile workflow verification** — instrument stages, establish the phone/browser test matrix and latency budgets, then close Phase 1 gaps before expanding the form.
2. **Duplicate-safe UPC handoff** — connect scan/search to Grocy's existing product/barcode lifecycle with cancel/no-write and save-once tests.
3. **Structured review contract** — add brand, package size, product group, quantity unit, and food type as independently reviewable suggestions; harden real-front-image ranking and recovery.
4. **Taxonomy v1 and single-product categorization** — validate the proposed local vocabulary against every current in-scope food before bulk actions.
5. **Reusable conversion model** — implement dimensions, universal rules, narrow food profiles, precedence, tolerance, and conflict reporting before touching the 101 existing overrides.
6. **Shared dry-run/audit/rollback machinery** — use one safety substrate for bulk taxonomy assignment and conversion cleanup.
7. **Bulk execution and cleanup** — run conflict-first review, bounded approved changes, equivalence validation, and rollback rehearsal against a copy of production data before household deployment.

Defer the differentiators `learned local mapping hints`, interactive conversion graph visualization, and snapshot re-import until the core preview/audit data model is proven. They add value but should not delay safe categorization and cleanup.

## Acceptance-Criteria Themes

Requirements derived from this research should consistently assert:

- **No hidden writes:** capture relevant row/file counts before search, preview, cancel, timeout, and failed image download; they remain unchanged.
- **Exact scope:** every bulk preview and execution reports included, excluded, skipped, conflicted, changed, and unchanged counts.
- **Stable identity:** tests use product/barcode IDs, taxonomy IDs, and ruleset versions, not display labels alone.
- **Evidence visibility:** every non-manual suggestion names its source, confidence band, and reason.
- **Safe uncertainty:** unknown, conflicting, or low-confidence classifications remain `Unclassified`; ambiguous conversions do not resolve.
- **Invariants:** stock amount/history, recipes, prices, due dates, authentication, and normal Grocy operation remain unchanged by enrichment/categorization cleanup unless a separately approved requirement explicitly names them.
- **Mobile recovery:** permission denial, timeout, provider 404/429/5xx, companion outage, image expiry, navigation back, and repeat taps all have deterministic outcomes.
- **Idempotence and rollback:** rerunning an approved batch produces no additional diffs, and rollback refuses to overwrite subsequent manual edits.

## Sources

### Primary / official (HIGH confidence)

- [Grocy repository: external barcode lookup, responsive PWA, and feature flags](https://github.com/grocy/grocy#barcode-lookup-via-external-services) — confirms that enrichment should integrate with the existing in-place product picker and responsive web app rather than replace them.
- [Grocy releases](https://github.com/grocy/grocy/releases) — documents current quantity-unit behavior and optimization of product completion after external barcode lookup.
- [Open Food Facts API introduction](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/) — current API status, rate limits, license conditions, data reliability disclaimer, image/taxonomy capabilities, and distinct not-found/service-error expectations.
- [Open Food Facts barcode scan guidance](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/tutorials/scanning-barcodes/) — supports immediate scan feedback, manual entry, clear found/not-found/network-error states, and check-digit handling.
- [Open Food Facts barcode normalization](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/ref-barcode-normalization/) — documents EAN/UPC leading-zero equivalence and server-side normalization; this motivates canonical duplicate checks without obscuring the scanned value.
- [Open Food Facts product image schema](https://openfoodfacts.github.io/documentation/docs/Product-Opener/schemas/schemas/product_images/) — identifies `selected_images.front` and language-specific display/small/thumb variants as the preferred display images.
- [Open Food Facts data-quality controls](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/tutorials/how-to-create-data-quality-controls-in-your-app/) and [data-quality overview](https://support.openfoodfacts.org/help/en-gb/21-manage-my-products/2-how-can-we-be-sure-that-the-products-presented-on-open-food-facts-present-original-and-correct-data) — supports prevention, visible validation, provenance/photos, and retaining human review for crowd-sourced fields.
- [GS1: how GTINs and barcodes work](https://support.gs1.org/support/solutions/articles/43000734095-how-do-gs1-gtins-and-barcodes-work-) — establishes the GTIN as a product identity with a check digit and a one-identifier-to-one-product assignment in the GS1 system.
- [NIST customary-to-metric conversion reference](https://www.nist.gov/pml/owm/metric-si/unit-conversion/approximate-conversions-us-customary-measures-metric) — authoritative same-dimension mass and volume factors; supports separating universal unit math from food density assumptions.
- [USDA FoodData Central Foundation Foods documentation](https://fdc.nal.usda.gov/Foundation_Foods_Documentation/) — states that portion weights are measured for specific foods and can vary by data type, supporting narrow sourced portion/density profiles rather than a global volume-to-mass factor.

### Project-local evidence (HIGH confidence)

- [Project definition](../PROJECT.md) — active scope, explicit exclusions, human-control constraint, provider topology, conversion cleanup target, and mobile/operational priorities.
- [Codebase concerns](../codebase/CONCERNS.md) — direct-external-preview risk, synchronous latency, missing browser/authorization tests, shared SQLite constraints, and operational visibility gaps.
- [`grocy_AI` contract](../../custom/grocy_AI/README.md) — deployed review-before-save behavior, companion fields, opaque image handles, and planned barcode/structured-field handoff.
- [Grocy resolved conversion view](../../migrations/0189.sql) — existing precedence sources and the impact of product/default conversion paths that cleanup must preserve.

## Confidence and Open Validation Items

| Area | Confidence | Validation needed |
|------|------------|-------------------|
| Barcode/review/image behavior | HIGH | Browser-level tests on the actual household phones and stable production branch |
| No-write and normal-save boundary | HIGH | HTTP/browser integration tests, because the current service harness does not exercise DOM/routes/persistence |
| Universal same-dimension conversions | HIGH | Confirm local unit labels, US-volume convention, rounding precision, and existing Grocy paths |
| Food-specific conversion profiles | MEDIUM | Inventory/recipe audit and a cited measured source for every chosen ingredient profile; never infer from the proposed parent taxonomy |
| Proposed taxonomy | MEDIUM | Classify the complete current in-scope inventory in a dry-run workshop; specifically decide whether Frozen/Preserved should be food types or separate handling facets |
| Latency thresholds | MEDIUM | Record current LAN/mobile p50/p95 and adjust once, before using the thresholds as a release gate |
| Bulk rollback design | HIGH at behavior level | Prove implementation against a production-data copy, including stale-preview and post-run-manual-edit conflicts |

