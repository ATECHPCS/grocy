# Project Research Summary

**Project:** grocy_AI
**Domain:** Reviewable, mobile-first household food inventory enrichment and maintenance
**Researched:** 2026-08-12
**Confidence:** HIGH on product boundaries and safety requirements; MEDIUM on taxonomy, live-data cleanup, and the final food-type conversion projection

## Executive Summary

grocy_AI should remain a narrow extension of the existing Grocy modular monolith, not become a second inventory system. Grocy's authenticated PHP/Blade/JavaScript application and SQLite database remain authoritative for products, barcodes, files, units, conversions, and all durable writes. The Python companion remains an untrusted-data adapter for Open Food Facts, SearXNG, image hosts, and optional model-assisted classification. Every external value is a versioned, provenance-bearing suggestion that the user reviews before it enters Grocy's normal save flow or a named bulk operation.

The milestone should first make the deployed mobile enrichment flow measurable and reproducible, then stabilize duplicate-safe barcode handoff and structured field review. A versioned household taxonomy must be piloted before food-type rules or bulk classification. Universal same-dimension conversions belong in Grocy's native conversion model; cross-dimension rules must be narrow, sourced, approximate food-type profiles, while pack/count quantities remain product- or barcode-bound. Bulk categorization and conversion cleanup should share one immutable preview, compare-and-apply, audit, idempotency, and conflict-aware rollback engine.

The dominant risks are hidden or stale provider writes, ambiguous conversion graphs, preview/apply drift, image-proxy SSRF/content attacks, and production drift between `atech-main` and `atech-release`. Mitigate them with strict versioned DTOs, deterministic conversion precedence and invariant tests, short `BEGIN IMMEDIATE` transactions with freshness hashes, same-origin bounded image proxying, privacy-safe correlated telemetry, real-device tests, and a dual-branch/container release gate. Before conversion implementation, resolve the research disagreement between resolver-view integration and provenance-tagged native-row materialization through a branch-specific characterization spike; the invariant is that canonical food-type rules remain module-owned and existing Grocy consumers keep their resolved/cache read contract.

## Key Findings

### Recommended Stack

Preserve the deployed stack and add only narrow contract, schema, telemetry, and test capabilities. Avoid a new frontend framework, web framework, database, job queue, observability platform, or direct companion access to `grocy.db`. Detailed evidence and version rationale are in [STACK.md](./STACK.md).

**Core technologies:**

- **Grocy 4.6 / PHP 8.5 / Slim 4.15.2 / Blade / plain JavaScript:** authenticated UI and sole durable mutation owner — preserves upstream behavior and the normal product, barcode, file, and quantity-unit flows.
- **`custom/grocy_AI` and `public/custom/grocy_AI`:** feature-gated extension seam — keeps core hooks small, documented, and portable across the two maintained branches.
- **SQLite >=3.40 through Grocy PDO:** native domain state plus namespaced `grocy_ai_*` taxonomy, rule, plan, and audit tables — fits household scale and the existing backup/deployment model.
- **Python >=3.11 Starlette/FastMCP companion:** provider fan-out, normalization, classification proposals, and opaque image handles — it returns evidence, never writes Grocy data.
- **Pydantic 2.13.4, exact direct pin:** strict companion request/response models with `extra='forbid'`, bounded fields, enums, validators, and generated contract fixtures.
- **Open Food Facts API v3.6 adapter:** explicit versioned provider boundary using selected fields — required because the schema is active and provider data is incomplete; keep `/v1` stable and add a versioned richer contract.
- **Existing Guzzle 7.15.1 and shared HTTPX client:** explicit connect/read/total budgets, connection reuse, transfer timing, bounded concurrency, and trace propagation.
- **W3C Trace Context, `Server-Timing`, and redacted JSON logs:** local fault attribution without adding an observability backend or putting request telemetry in SQLite.
- **Playwright Test 1.62.1 in a test-only workspace:** Chromium/WebKit mobile, timeout, offline, retry, and duplicate-submit coverage; retain a physical-phone acceptance pass.
- **Existing PHP CLI harness and Python `unittest`:** extend current contract and invariant suites rather than introducing PHPUnit or pytest for this milestone.

### Expected Features

The product principle is “suggest, review, then use Grocy's established persistence.” Detailed behavior and acceptance themes are in [FEATURES.md](./FEATURES.md).

**Must have (table stakes):**

- Camera scan plus manual GTIN entry, checksum/length feedback, bounded waits, cancel/retry, and distinct invalid/not-found/timeout/offline states.
- Duplicate-safe barcode handoff that checks normalized equivalents, routes existing owners correctly, stages new barcodes in Grocy's normal workflow, and writes exactly once only after Save.
- Field-by-field review for name, brand, package size, product group, quantity units, food type, and real front-package image, with current value, source, confidence/reason, and a final change summary.
- Same-origin demand-loaded image previews and explicit selection/recovery, with opaque handles and bounded MIME/signature/size/dimension validation.
- End-to-end phone save/reload behavior, accessible progress/status, no stale-response or double-submit effects, and measured LAN latency/failure attribution.
- A small versioned local taxonomy with stable IDs, one reviewed leaf or `Unclassified`, explainable proposals, and explicit baby-food/pet-food exclusions.
- Dimension-safe universal conversions, narrow sourced food-specific density profiles, product-bound package/count semantics, deterministic precedence, and conflict rejection.
- Dry-run bulk categorization and cleanup with exact scope, immutable diffs, equivalence checks, stock-domain invariants, audit, idempotency, and guarded rollback.
- Normal Grocy product and inventory workflows remain usable when the companion or any provider is unavailable.

**Should have (differentiators):**

- Evidence-weighted candidate ranking that clearly favors exact GTIN/front-image matches.
- Conflict-first mobile review queues and one-confirmation application of only the explicitly reviewed set.
- Provenance/freshness for extension-managed suggestions and a redacted “copy diagnostic report” action.
- Conversion coverage inspection showing effective source, missing paths, cycles, conflicts, and before/after cleanup coverage.
- Exportable preview snapshots for independent review and recovery evidence.

**Defer (v2+):**

- Learned local mapping hints, interactive conversion-graph visualization, and snapshot re-import until the audit/preview model is proven.
- Native/offline mobile applications, public/cloud telemetry, nutrition or medical advice, provider write-back, and a full external taxonomy mirror are not milestone features.
- Autonomous writes, AI-generated packaging, universal mass-to-volume or package conversions, and stock-unit mutation during categorization are anti-features, not future shortcuts.

### Architecture Approach

Use a server-owned anti-corruption layer: the browser speaks only to authenticated same-origin module APIs; Grocy validates and maps typed proposals; the companion isolates provider drift; Grocy alone owns durable state. Separate read-only preview work from short typed write transactions. Keep universal conversions native and expose food-type resolution behind Grocy's existing resolved/cache shape with precedence `product override > food type > universal`; the exact projection mechanism requires the Phase 4 spike noted below. See [ARCHITECTURE.md](./ARCHITECTURE.md).

**Major components:**

1. **Browser module UI** — scans/accepts UPCs, presents evidence and conflicts, stages normal form/file values, and requests named preview/apply/rollback actions without calling providers directly.
2. **Grocy API/controllers and typed services** — authenticate, authorize, validate limits/contracts, stage enrichment, own taxonomy and conversion policy, and expose safe status/error responses.
3. **Python companion adapters** — call providers with bounded budgets, normalize versioned proposals, score evidence, and manage short-lived opaque media handles without Grocy credentials or database access.
4. **Module-owned SQLite schema and migrations** — store stable taxonomy IDs, reviewed assignments, canonical rules, module runtime state, immutable bulk plans/items, and a separate module migration ledger.
5. **Conversion policy/resolver seam** — validate dimensions, factors, reciprocal/cycle consistency, precedence, and effective-path uniqueness while preserving Grocy's existing consumer contract.
6. **Bulk planner, typed executor, and rollback service** — persist immutable bounded plans, revalidate current-state hashes, apply atomically on one PDO connection, record before/after images, and refuse stale or conflicting compensation.
7. **Telemetry and release adapters** — correlate browser/Grocy/companion/provider timings with redacted logs and keep branch-specific bootstrap/controller differences thin and explicitly tested.

### Critical Pitfalls

The complete prevention and detection guidance is in [PITFALLS.md](./PITFALLS.md).

1. **Provider suggestions become authoritative or arrive stale** — bind results to the requested UPC/form token, re-check barcode ownership at Save, keep risky unit/factor changes out of generic Apply, and verify no database delta before normal Save.
2. **Conversion semantics or reciprocal triggers corrupt effective factors** — model dimensions and scope first; keep package/count rules product-bound; derive inverse edges; reject competing paths; characterize native triggers/resolved sources; prove stock/recipe equivalence before deleting any override.
3. **Bulk apply differs from the reviewed preview** — persist an immutable checksumed plan with exact before-images, use field-level freshness checks and idempotency, apply typed operations in one short transaction, and roll back only when current values still match recorded after-images.
4. **The image proxy is an SSRF or decompression boundary in name only** — redeem only short-lived opaque handles, validate all resolved/redirected addresses, prefer origin allowlists, stream with byte/time/pixel limits, re-encode safe formats, and proxy thumbnails same-origin.
5. **Custom schema or release work silently diverges from upstream/stable production** — use namespaced tables and an independent migration ledger, test production-shaped upgrades on both branches, assert portable-file parity, build/smoke the stable image, invalidate caches, preserve the data volume, and retain a rehearsed rollback.

## Implications for Roadmap

Based on the combined research, use seven phases. Upstream/release parity begins in Phase 1 and remains an exit gate throughout; Phase 7 consolidates and rehearses the operating process.

### Phase 1: Safety Baseline & Mobile Diagnostics

**Rationale:** Later failures cannot be localized or released safely without correlated timing, privacy controls, real-device evidence, and a dual-branch gate.
**Delivers:** Trace IDs and `Server-Timing`, allowlisted structured logs, explicit UI error states/deadlines, Playwright mobile failure-path coverage, physical-phone baseline results, and branch/container smoke checks.
**Addresses:** Bounded waiting, explicit result states, measured LAN performance, operational visibility, and graceful degradation.
**Avoids:** Secret/payload logging, retry amplification, desktop-only confidence, stale assets/routes, and unexplained mobile hangs.

### Phase 2: Enrichment Contract, Barcode Handoff & Secure Media

**Rationale:** Establish the untrusted-provider and human-review boundary once before adding taxonomy or bulk consumers.
**Delivers:** Strict versioned Pydantic/PHP DTOs; duplicate-safe normalized barcode handoff; independent structured-field review; same-origin bounded thumbnails/full-image redemption; stale-request cancellation; and normal Save integration.
**Addresses:** Scan/manual input, no-hidden-write behavior, exact-package imagery, provenance, review summary, and outage recovery.
**Avoids:** Raw provider schema leakage, duplicate barcodes, quantity-unit side effects, stale async results, SSRF/DNS rebinding, and unbounded image processing.

### Phase 3: Food Taxonomy & Categorization Pilot

**Rationale:** Bulk classification and food-type conversion rules need stable local identities and reviewed boundaries first.
**Delivers:** Module migration runner; versioned two-level taxonomy; stable IDs/slugs and exclusions; provider mappings as evidence; `Unclassified`; one-product assignment/review; and a representative inventory pilot.
**Addresses:** Curated taxonomy, explainable classification, provenance, and explicit baby-food/pet-food exclusion.
**Avoids:** External labels as identities, accidental flattening of a provider DAG, false confidence, and forced low-confidence assignments.

### Phase 4: Reusable Conversion Model

**Rationale:** Conversion semantics and the compatibility seam must be correct before touching approximately 101 product-specific overrides.
**Delivers:** Unit dimensions; native universal rules; narrow sourced food-type profiles; product/package exceptions; deterministic precedence; reciprocal, cycle, tolerance, and conflict tests; and a branch-characterized resolved/cache integration.
**Addresses:** Reusable universal/food-type conversions, conflict detection, coverage inspection foundations, and equivalence reporting.
**Avoids:** Universal density/package factors, ambiguous paths, reciprocal-trigger mistakes, accidental stock-unit changes, and unsupported cleanup.

### Phase 5: Bulk Maintenance & Recovery Engine

**Rationale:** Build one safety substrate over already-proven single-item taxonomy and conversion operations rather than separate batch write paths.
**Delivers:** Immutable bounded plans, typed operation registry, exact before/after hashes, selection/conflict handling, `BEGIN IMMEDIATE` compare-and-apply, idempotency, audit journal, guarded rollback, and integrity/domain reconciliation.
**Addresses:** Dry-run review, exact scope/counts, stock-safe execution, audit, recovery, and preview export.
**Avoids:** Preview/apply drift, arbitrary CRUD/SQL, long network-held transactions, partial commits, unsafe retry, and rollback overwriting later edits.

### Phase 6: Inventory Categorization & Conversion Cleanup

**Rationale:** Only after taxonomy, conversion semantics, and the bulk engine are stable can live household data be changed safely.
**Delivers:** Production-snapshot profiling; conflict-first categorization preview; reviewed assignments; classification of logical conversion pairs/sources; before/after effective-path equivalence; selected cleanup; idempotent rerun; and rollback rehearsal.
**Addresses:** Existing inventory categorization, removal of redundant conversion sprawl, retained named exceptions, and conversion coverage reporting.
**Avoids:** Unbounded mutation, deleting synthesized or purchase-to-stock rules, row-count-only validation, stock/history/recipe changes, and untested live-data assumptions.

### Phase 7: Upstream & Stable Release Sustainment

**Rationale:** The final integrated milestone must be reproducibly promotable without losing module schema, custom assets, or persistent inventory.
**Delivers:** Rebase/merge playbook, portable-core and adapter manifests, production-shaped migration fixtures, exact artifact/version metadata, stable image promotion by digest, cache-marker verification, persistent-volume smoke test, mobile UAT, and prior-image/database recovery rehearsal.
**Addresses:** Low-conflict upstream synchronization and dependable household deployment.
**Avoids:** Migration-ID collisions, stable adapter omissions, destructive updater use, mutable-tag ambiguity, stale routes/assets, and unrehearsed rollback.

### Phase Ordering Rationale

- Observability and release safety precede schema work so every later failure is attributable and testable on the actual production topology.
- Enrichment establishes the versioned suggestion/review boundary used by taxonomy; taxonomy provides stable IDs required by food-type conversion rules.
- Conversion semantics precede the generic batch layer's cleanup adapters, so bulk code orchestrates proven domain operations instead of inventing them.
- The actual inventory migration is last among feature phases because it depends on all models, invariants, audit, and rollback behavior.
- Release sustainment is continuous, with a final integrated promotion phase rather than a late attempt to port untested work to `atech-release`.

### Research Flags

Phases likely needing deeper research during planning:

- **Phase 2:** Inspect the companion's actual provider concurrency, timeout, cache, auth, and SearXNG media-resolution code; verify Open Food Facts v3.6 fixtures and image-host policy.
- **Phase 3:** Validate the proposed taxonomy against the complete in-scope inventory, especially whether Frozen/Preserved are identities or storage/handling facets.
- **Phase 4:** Mandatory schema spike on both branches to choose resolver-view/cache integration versus provenance-tagged native-row materialization; characterize all triggers, sources, tolerances, and consumers before migration SQL is approved.
- **Phase 6:** Profile a scrubbed production snapshot for logical conversion pairs, factors, malformed/duplicate data, recipe/stock references, and category edge cases.
- **Phase 7:** Verify the current stable adapter, Docker overlay, cache marker, persistent-volume behavior, and backup/restore process against production-shaped data.

Phases with sufficiently documented standard patterns (skip broad research-phase):

- **Phase 1:** W3C correlation, Guzzle/HTTPX timing, redacted logging, and Playwright failure-path patterns are well documented; planning needs measurement, not ecosystem research.
- **Phase 5:** Immutable plan, optimistic concurrency, typed operations, one-transaction apply, idempotency, and compensating rollback are already specified; planning should focus on fixtures and acceptance tests.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Existing runtime and integration seams are repository-verified; Pydantic, HTTP timing, W3C, SQLite, and Playwright choices use official documentation. OFF mapping quality remains MEDIUM. |
| Features | HIGH for workflow and safety; MEDIUM for domain data | Review/no-write, barcode, image, mobile, and bulk expectations are well supported. Taxonomy labels, density profiles, and latency thresholds need household validation. |
| Architecture | HIGH on ownership/boundaries; MEDIUM on conversion projection | Grocy-as-writer, companion-as-adapter, namespaced schema, typed bulk flow, and one-writer transactions are strong. The resolver/materialization mechanism is unresolved. |
| Pitfalls | HIGH | Risks are grounded in local migrations/code, official SQLite/OWASP/OFF guidance, and known branch/test gaps. Real-device and production-data behavior still require execution evidence. |

**Overall confidence:** HIGH for roadmap order and safety architecture; MEDIUM for the final taxonomy, conversion integration SQL, and cleanup plan.

### Gaps to Address

- **Conversion projection conflict:** Architecture favors extending the existing resolved/cache seam; stack research favors provenance-tagged materialization to avoid owning the recursive view. Resolve in Phase 4 using the smallest dual-branch implementation that preserves precedence, ownership, cache invalidation, feature-disable semantics, and exact consumer behavior.
- **Real device and latency baseline:** Record supported phone/browser versions plus LAN p50/p95 under normal, slow, disconnected, background/foreground, and image-attachment scenarios before freezing release thresholds.
- **Live inventory profile:** Use a scrubbed snapshot to confirm the approximate override count, logical reciprocal pairs, factor distribution, malformed rows, active references, and taxonomy coverage before Phase 6 plans are generated.
- **Taxonomy and density corpus:** User-review every initial leaf/boundary and require a cited measured source, context, precision, and approximate label for each food-specific conversion profile.
- **Companion/media implementation:** Inspect DNS, redirect, streaming, token binding, rate limits, provider cache/error handling, and schema-version behavior directly; this repository research establishes the boundary but not all implementation details.
- **Disaster recovery:** The audit ledger supports logical compensation, not database-file corruption; separately validate Online Backup API or `VACUUM INTO` snapshots and a full restore rehearsal.

## Sources

### Primary (HIGH confidence)

- [PROJECT.md](../PROJECT.md), [STACK.md](./STACK.md), [FEATURES.md](./FEATURES.md), [ARCHITECTURE.md](./ARCHITECTURE.md), and [PITFALLS.md](./PITFALLS.md) — project scope, repository findings, cross-source recommendations, and open questions.
- Local Grocy module, core services, migrations, resolver/cache SQL, browser components, tests, and deployment customization documents cited by the research files.
- [SQLite transactions](https://www.sqlite.org/lang_transaction.html), [SQLite JSON](https://www.sqlite.org/json1.html), and [SQLite Backup API](https://www.sqlite.org/backup.html) — one-writer behavior, `BEGIN IMMEDIATE`, JSON support, and safe snapshots.
- [Open Food Facts API](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/), [schema](https://openfoodfacts.github.io/documentation/docs/Product-Opener/schemas/schemas/product/), and [change log](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/ref-api-and-product-schema-change-log/) — versioning, fields, limits, and data-quality caveats.
- [OWASP SSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) and [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html) — image-fetch and telemetry security controls.
- [W3C Trace Context](https://www.w3.org/TR/trace-context/) and [Server Timing](https://www.w3.org/TR/server-timing/) — cross-service correlation and browser-visible stage timing.
- Official Pydantic, Guzzle, HTTPX, Playwright, GS1, and NIST references cataloged in the four research documents.

### Secondary (MEDIUM confidence)

- Proposed local taxonomy and food-specific density/profile design — grounded in domain references but requires validation against this household's actual products and recipes.
- Initial mobile latency targets and cache/timeout values — informed starting points that must be calibrated on the deployment LAN and device matrix.

### Tertiary (LOW confidence)

- None used as roadmap authority. Provider/model output is explicitly treated as reviewable evidence rather than truth.

---
*Research completed: 2026-08-12*
*Ready for roadmap: yes*
