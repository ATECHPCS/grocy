# Architecture Patterns

**Project:** grocy_AI
**Domain:** Human-reviewed product enrichment, food taxonomy, conversion policy, bulk inventory maintenance, and diagnostics for a Grocy fork
**Researched:** 2026-08-12
**Confidence:** HIGH for Grocy/SQLite boundaries; MEDIUM for the not-yet-inspected `grocy-mcp` internals

## Recommended Architecture

Keep Grocy as the system of record and sole mutation authority. Treat the Python companion as an evidence and suggestion service: it can query providers, normalize metadata, classify products, score images, and report provider timing, but it must not receive a Grocy API key, mount `grocy.db`, or write inventory state. The browser must continue to call authenticated Grocy endpoints rather than the companion directly.

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│ Browser / mobile Grocy UI                                                   │
│ - scans or enters UPC                                                       │
│ - reviews field, taxonomy, image, and bulk candidates                       │
│ - explicitly saves/applies/rolls back                                       │
└───────────────────────────────┬─────────────────────────────────────────────┘
                                │ authenticated same-origin /api/grocy-ai/*
                                ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ Grocy PHP process                                                           │
│                                                                             │
│  custom/grocy_AI                                                            │
│  ┌──────────────────┐  ┌────────────────┐  ┌────────────────────────────┐  │
│  │ API + permissions │→│ typed services │→│ planner / executor / undo  │  │
│  └────────┬─────────┘  └───────┬────────┘  └─────────────┬──────────────┘  │
│           │                    │                         │                 │
│  ┌────────▼─────────┐  ┌───────▼────────┐  ┌────────────▼──────────────┐  │
│  │ companion adapter│  │ rule resolver  │  │ audit + telemetry emitter │  │
│  └────────┬─────────┘  └───────┬────────┘  └────────────┬──────────────┘  │
│           │                    │                         │                 │
│  Core Grocy services/API ──────┴──────────────┬──────────┘                 │
│  (normal product, userfield, file, QU flows)  │                            │
└──────────────────────┬─────────────────────────┼────────────────────────────┘
                       │                         │
              bounded HTTP + API key            │ one local PDO/SQLite writer
              trace context; no DB access        ▼
                       │             ┌───────────────────────────────────────┐
                       ▼             │ GROCY_DATAPATH/grocy.db              │
┌──────────────────────────────┐     │ - upstream-owned Grocy tables        │
│ Python grocy-mcp companion   │     │ - grocy_ai_* module-owned tables     │
│ - provider adapters          │     └───────────────────────────────────────┘
│ - response normalization     │
│ - taxonomy classifier        │
│ - evidence/confidence        │
│ - opaque image-token cache   │
│ - provider telemetry         │
└──────────────┬───────────────┘
               │ outbound provider calls
               ▼
   Open Food Facts / SearXNG / image hosts / optional model provider
```

This preserves the existing Phase 1 seam in `custom/grocy_AI/` and `public/custom/grocy_AI/`. Add capabilities behind that seam rather than introducing a second application database or moving domain rules into Python.

### Component Boundaries

| Component | Responsibility | Must Not Do | Communicates With |
|-----------|----------------|-------------|-------------------|
| Browser module UI | Collect UPC/scope, display evidence and conflicts, stage native form values, request preview/apply/rollback, show trace ID | Call providers/companion directly; persist an unreviewed suggestion; infer conversion factors | Grocy `/api/grocy-ai/*`, existing product form and file input |
| `GrocyAiApiController` family | Authenticate, enforce permissions, validate request shape/limits, map typed errors/status codes, return response/trace headers | Contain domain SQL or provider-specific logic | Browser, custom services |
| Product enrichment service | Normalize UPC, call companion, validate/version the contract, map suggested native/userfield values, proxy bounded images | Write products during lookup; trust provider labels/URLs; retain secrets in responses | Companion adapter, product-form presenter |
| Taxonomy catalog service | Own taxonomy terms, stable slugs, hierarchy, activation/versioning, and one reviewed primary assignment per product | Use provider category strings as database keys; overload Grocy product groups as food types | Module tables, product reads, taxonomy UI |
| Conversion policy service | Own food-type rules, validate dimensions/factors, resolve precedence, expose the same effective product/from/to/factor shape Grocy consumes | Let an LLM perform runtime arithmetic; silently select between inconsistent paths | Taxonomy assignments, native QU tables, resolved conversion cache |
| Bulk planner | Produce a bounded, immutable preview with before/after values, confidence, evidence, conflicts, and row fingerprints | Change target domain rows; hold a write transaction while calling the companion | Read snapshot, companion suggestions, typed operation adapters, audit tables |
| Bulk executor | Revalidate plan freshness, apply all selected items atomically, record audit rows and outcome, make requests idempotent | Execute arbitrary table/column instructions; apply a stale preview; make external HTTP calls inside a transaction | Single PDO connection, typed domain adapters, audit tables |
| Rollback service | Apply a compensating transaction from recorded before-images after verifying current rows still match the recorded after-images | Reuse stock-booking undo for master data; overwrite subsequent edits; promise rollback without a committed audit record | Bulk run/items, same typed domain adapters |
| Telemetry facade | Create/propagate request IDs and W3C trace context, measure boundaries, emit stable structured events, redact data | Log API keys, image tokens, payloads, external URLs, or household inventory by default | Browser timing, PHP/Guzzle, companion/provider instrumentation, stderr/log sink |
| Module migration service | Apply ordered, idempotent `custom/grocy_AI/migrations/` changes and record module schema versions | Put module schema in the companion; mutate a live database ad hoc; reuse upstream migration IDs | Core migration completion, SQLite |
| Python companion | Aggregate providers, normalize candidates, classify against a supplied taxonomy version, score evidence, cache opaque image handles, emit provider timing | Own Grocy taxonomy/rules; mutate Grocy; accept browser sessions; receive Grocy API keys/database | Grocy server, external providers |
| Core Grocy | Remain authoritative for products, product groups, units, native universal/product-specific conversions, userfields, files, permissions, stock and recipes | Learn provider semantics or call AI from stock/recipe paths | Custom module through narrow documented hooks |

### Durable Schema Ownership

Use the existing SQLite file so a Grocy data-volume backup remains a consistent backup of domain state and module audit state. Separate ownership by table prefix and migration stream, not by adding another database.

| Owner | Durable data | Recommended representation |
|-------|--------------|----------------------------|
| Upstream Grocy | Product name, product group, purchase/stock quantity units, barcodes, pictures, product userfields, universal conversions, exceptional product-specific conversions, stock/recipe state | Existing `products`, `product_groups`, `quantity_units`, `quantity_unit_conversions`, `userfields`, `userfield_values`, storage groups and existing services |
| grocy_AI module | Food taxonomy, product-food-type assignment, food-type conversion rules, immutable bulk plan/run history, item before/after images, idempotency keys, module migration version | `grocy_ai_*` tables in `grocy.db`, created only by ordered module migrations |
| Companion | Provider response cache, rate-limit/circuit state, short-lived image selection handles | Ephemeral TTL cache local to companion; loss must only cause a fresh search |
| Operations | Request/provider diagnostics | Stable structured JSON logs to stdout/stderr; optional bounded local diagnostic retention, not the audit ledger |

Recommended module schema boundaries:

```text
grocy_ai_migrations
  migration PK, executed_at

grocy_ai_runtime_state
  singleton PK, rules_enabled, active_schema_version, updated_at

grocy_ai_food_types
  id PK, slug UNIQUE, name, parent_id NULL, active, taxonomy_version,
  created_at, updated_at

grocy_ai_product_food_types
  product_id PK, food_type_id, reviewed_by_user_id, reviewed_at
  -- one current primary food type; confidence/evidence belongs to proposal/audit data

grocy_ai_food_type_conversion_rules
  id PK, food_type_id, from_qu_id, to_qu_id, factor, active,
  created_by_user_id, created_at, updated_at
  -- factor > 0, from != to, one canonical unordered pair per food type

grocy_ai_bulk_runs
  id UUID/opaque PK, kind, status, taxonomy_version, input_json,
  preview_hash, idempotency_key UNIQUE, created_by_user_id,
  previewed_at, applied_at, rolled_back_at, failure_class

grocy_ai_bulk_run_items
  run_id, ordinal, operation_type, target_kind, target_id,
  before_json, after_json, before_hash, after_hash,
  confidence, evidence_json, conflict_code, selected, outcome
  UNIQUE(run_id, ordinal)
```

Do not make brand/package size columns in `products`; use managed Grocy product userfields so the normal product save lifecycle and generic API remain authoritative. Keep food type separate from `product_group_id`: product group is a Grocy organizational field, while food type is a stable rule-bearing taxonomy. Do not duplicate the current food type into a free-text userfield.

The module migration stream should run immediately after core Grocy migrations and before any module route performs a schema-dependent read. This requires one small, documented stable adapter hook. That hook must run even when the UI/API feature flag is off so it can synchronize `grocy_ai_runtime_state.rules_enabled` and rebuild affected conversion-cache rows on a flag transition. With the module disabled, routes/UI disappear, suggestions stop, and the resolver ignores food-type rules; approved native Grocy data remains intact. Keep migration implementation under `custom/grocy_AI/`; never edit an already-shipped upstream migration. Every module migration must be tested on both `atech-main` and `atech-release` because their adapters differ.

## Explicit Data Flows

### 1. Single-Product Enrichment and Barcode Handoff

```text
UPC scan/entry
  → Grocy authenticated enrichment endpoint
  → validate/normalize UPC and generate request/trace ID
  → companion v1 request
  → provider adapters + evidence normalization
  → Grocy contract validation, allowlists, field mapping
  → browser displays each candidate and provenance
  → user selects individual fields/image
  → values are staged into existing form/userfield/file controls
  → normal Grocy Save persists native/userfield/image data
  → barcode handoff uses the existing product-barcode flow after product ID exists
```

Keep lookup and persistence separate. The companion response should contain candidate values, source/evidence, confidence, warnings, `contract_version`, and `taxonomy_version`; it should not contain executable mutations. A searched UPC may populate the existing barcode UI, but duplicate detection and persistence stay in Grocy. Re-check barcode uniqueness at save time because search and save are separated by user review.

For a new product, food type cannot be persisted until a product ID exists. Preserve the reviewed selection in browser state and invoke a clearly named, authenticated assignment endpoint only after normal product creation succeeds. If that second request fails, report “product saved; food type not assigned” and allow retry with the same idempotency key. Do not roll back or recreate the successfully saved product from browser code.

### 2. Taxonomy Classification

```text
Grocy reads bounded product snapshots
  → sends opaque item key + normalized descriptive fields + taxonomy version
  → companion returns ranked food-type slugs, confidence, evidence, abstention/warnings
  → Grocy rejects unknown/stale taxonomy slugs
  → planner compares current assignment and creates preview items
  → user filters/selects items
  → executor writes reviewed assignments in one transaction
```

Grocy owns the catalog and version. The companion classifies only against the taxonomy version supplied in the request; it must abstain when evidence is weak or the version is unsupported. Exclude baby food and pet food in the Grocy catalog/input policy as well as in model instructions, so exclusion does not depend on probabilistic output.

### 3. Conversion Rule Resolution

Use deterministic precedence:

```text
explicit native product-specific override
  > reviewed grocy_AI food-type rule
  > native universal conversion (product_id IS NULL)
  > no conversion
```

Universal rules should remain native Grocy conversions. Food-type rules should be stored once in the module table and projected into the existing `quantity_unit_conversions_resolved` / `cache__quantity_unit_conversions_resolved` read contract. That lets existing stock, recipe, shopping-list, and UI consumers keep reading `(product_id, from_qu_id, to_qu_id, factor, path)` without PHP call-site patches.

Implement the projection in one module-owned schema integration migration that recreates the resolver view and the minimum cache-maintenance triggers. Do not patch each call to `cache__quantity_unit_conversions_resolved`. This is the most sensitive upstream seam: the upstream `0225.sql` triggers rebuild the cache after unit/product changes and `0232.sql` defines recursive closure. Maintain a characterization fixture that compares effective conversions before/after the overlay on both branches.

The resolver must reject rather than guess when:

- the same precedence level yields more than one factor for a product/from/to pair;
- inverse factors are not reciprocal within a defined numeric tolerance;
- two paths between the same units produce materially different factors;
- a factor is non-positive, non-finite, or crosses incompatible dimensions;
- a food type or quantity unit is inactive/missing;
- cleanup would remove the only usable conversion for a current product, recipe, or stock aggregation path.

An LLM/provider may suggest a factor and cite package evidence, but rule validation and runtime conversion are deterministic Grocy-side code. Store one logical bidirectional food-type rule, derive its inverse, and treat Grocy's native trigger-created inverse rows as one logical pair during cleanup.

### 4. Bulk Preview, Apply, and Rollback

```text
POST preview request (kind + explicit product/rule IDs + options)
  → permission and maximum-scope checks
  → external classification/enrichment, if needed (no transaction)
  → typed planner reads a consistent snapshot
  → conflict detection + before/after hashes
  → durable immutable preview/run record; no target mutation

POST /bulk-runs/{id}/apply (selected ordinals + idempotency key)
  → BEGIN IMMEDIATE on the one Grocy PDO connection
  → reload and hash every selected target
  → any mismatch/conflict: rollback all target changes
  → typed operations only (taxonomy assign, native conversion upsert/delete, rule upsert)
  → rebuild/validate affected conversion cache inside the same transaction
  → record after-images and applied status
  → COMMIT

POST /bulk-runs/{id}/rollback
  → require applied/not-already-rolled-back status
  → BEGIN IMMEDIATE
  → verify each current target equals recorded after-image
  → any later edit: report conflict and rollback the rollback attempt
  → apply typed compensating operations from before-images
  → record rollback outcome
  → COMMIT
```

At household scale, prefer one short atomic transaction for the selected set over partial chunk commits. Set an application limit (recommended default: 250 items) and require a new preview for a larger or changed scope. SQLite allows only one simultaneous writer, so compute suggestions and render previews before acquiring the write lock. `BEGIN IMMEDIATE` makes writer contention fail at the boundary rather than halfway through apply.

“Rollback-safe” after commit means a recorded compensating operation, not an open database transaction and not Grocy's stock-booking `UndoTransaction`. Store semantic before/after images and operate through typed services so rollback remains valid if surrogate row IDs change. If any affected value changed after apply, do not overwrite it; mark the rollback conflicted and require a new preview.

### 5. Telemetry and Failure Attribution

Use one correlation context across browser → Grocy → companion → provider. Accept a valid W3C `traceparent` or generate one in Grocy; propagate it to the companion, which creates child provider spans. Return the trace ID and a safe `Server-Timing` summary to the browser.

Capture these boundaries with a stable event schema:

| Layer | Measure | Failure classes |
|-------|---------|-----------------|
| Browser/mobile | click-to-response, upload/download duration, aborted request, `navigator.onLine`, response status, trace ID | offline/DNS/connection reset/client abort/browser timeout |
| Grocy ingress | authenticated route, total duration, permission/result, response bytes | auth/permission, validation, PHP exception, client disconnected |
| Grocy → companion | connect, time-to-first-byte, total, HTTP status, response bytes, contract validation | connect timeout, total timeout, TLS/network, companion 4xx/5xx, invalid/oversize response |
| Companion | route total, cache hit, normalization/classification duration | auth, validation, internal exception, circuit open |
| Each provider/image host | provider name, attempt, duration, status, bytes, result count | timeout, rate limit, upstream status, parse, unsafe content |
| Bulk engine | preview/apply/rollback duration and counts by applied/skipped/conflict | stale plan, lock busy, invariant violation, rollback conflict |

Start with structured JSON logs and correlation rather than adding a monitoring platform dependency. Use Guzzle transfer statistics for outbound timing and a small companion middleware/span wrapper for provider calls. Redact API keys, auth/session headers, opaque image tokens, raw response bodies, full URLs/query strings, and product/UPC values by default. Audit records answer “who changed what”; telemetry answers “where was it slow or broken.” Do not merge the two retention/security models.

## Patterns to Follow

### Pattern 1: Anti-Corruption Adapter at the Companion Boundary

**What:** Validate and normalize every companion response into a Grocy-owned DTO before it reaches the UI or planner.

**When:** Every enrichment, classification, image, or diagnostics call.

**Example:**

```php
$raw = $companion->classify($requestDto, $traceContext);
$proposal = ClassificationProposal::fromContractV1($raw);
$proposal->assertTaxonomyVersion($taxonomy->currentVersion());
$proposal->assertKnownFoodTypeSlugs($taxonomy->activeSlugs());
return $proposal;
```

This keeps provider/model drift outside the domain and makes contract fixtures usable against both production branches.

### Pattern 2: Plan Then Compare-and-Apply

**What:** Preview records an immutable before-image and hash; apply succeeds only when the target still matches that image.

**When:** Any bulk categorization or conversion cleanup.

**Example:**

```php
$db->exec('BEGIN IMMEDIATE');
try {
    $plan->assertFresh($repository);          // hashes selected business fields
    $typedOperations->apply($plan->selectedItems());
    $conversionResolver->assertConsistent($plan->affectedProductIds());
    $audit->markApplied($plan);
    $db->exec('COMMIT');
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    throw $error;
}
```

### Pattern 3: Stable Read Contract for Conversion Resolution

**What:** Extend the conversion graph behind the existing resolved/cache relation instead of patching every Grocy consumer.

**When:** Adding food-type-scoped rules.

**Precedence:** product override → food type → universal. The projection must preserve the upstream columns and cache invalidation semantics.

### Pattern 4: Typed Operation Registry

**What:** Each bulk item names a whitelisted semantic operation with validated DTOs.

**When:** Planning, applying, and compensating changes.

```text
AssignFoodType(product_id, old_food_type_id, new_food_type_id)
UpsertFoodTypeConversion(rule_key, old_rule, new_rule)
DeleteRedundantProductConversion(product_id, unit_pair, old_factor)
UpsertUniversalConversion(unit_pair, old_factor, new_factor)
```

Never accept client-supplied SQL, table names, column names, or arbitrary JSON Patch paths.

### Pattern 5: Branch-Neutral Core, Thin Branch Adapters

**What:** Keep services, DTOs, migration SQL, browser assets, contract fixtures, and tests identical; isolate Slim/controller/bootstrap differences in explicit adapters.

**When:** Every feature added to both `atech-main` and stable `atech-release`.

Release only after the same behavioral fixture set, schema upgrade fixture, route/permission smoke tests, and container data-volume test pass on both branches.

## Anti-Patterns to Avoid

### Companion as a Second System of Record

**What:** The companion stores its own copy of products/taxonomy or writes Grocy through an API key.

**Why bad:** Creates split-brain state, expands credential exposure, bypasses the user's reviewed Grocy session, and makes backup/rollback incomplete.

**Instead:** Send bounded snapshots for suggestion and return proposals; persist only in Grocy.

### Generic CRUD for Behavioral Bulk Changes

**What:** Browser loops over `/api/objects/...` updates/deletes.

**Why bad:** No atomicity, stale-plan detection, coherent audit record, paired conversion handling, or safe rollback.

**Instead:** One named plan/apply/rollback API backed by typed services and one transaction.

### Long Transactions Around Provider Calls

**What:** Begin a transaction, call companion/providers, then write.

**Why bad:** SQLite has one simultaneous writer; LAN/provider latency would block unrelated writes and increase `SQLITE_BUSY` failures.

**Instead:** Perform external work during preview, then use a short compare-and-apply transaction.

### Materializing Every Food-Type Rule as User-Maintained Product Overrides

**What:** Expand one food-type rule into dozens of ordinary product conversion rows without provenance.

**Why bad:** Recreates the 101-row maintenance sprawl, makes rule edits incomplete, and obscures precedence/conflicts.

**Instead:** Store the rule once and resolve it through the stable conversion projection. If temporary materialization is required during an intermediate phase, tag every generated row in a module-owned mapping table and make it fully rebuildable; do not treat that as the target architecture.

### Free-Text Taxonomy Keys

**What:** Store provider category labels or a userfield string as the rule join key.

**Why bad:** Renames, spelling changes, localization, and provider drift silently detach conversion policy.

**Instead:** Stable Grocy-owned food-type IDs/slugs; labels are editable display data.

### Database Backup as the Only Rollback Mechanism

**What:** Require restoring the whole SQLite file for every categorization mistake.

**Why bad:** Reverts unrelated household activity after the batch and cannot explain which items changed.

**Instead:** Atomic apply plus an immutable semantic before/after ledger and conflict-aware compensating rollback. Keep normal whole-volume backups as disaster recovery.

### Telemetry Payload Logging

**What:** Log full enrichment JSON, UPCs, image URLs/tokens, or headers to diagnose latency.

**Why bad:** Leaks household data and secrets while adding little timing value.

**Instead:** Stable event names, durations, sizes, status/failure class, provider, and trace ID.

## Security Boundaries

- Browser access stays same-origin through Grocy authentication. Enrichment and single-item reviewed writes require `MASTER_DATA_EDIT`; bulk apply/rollback should require an administrator-level permission in addition to the feature flag.
- The companion key is a dedicated least-privilege service credential, never a Grocy user/API key. Prefer HTTPS or mutually authenticated/private transport because the current LAN HTTP configuration exposes `X-API-Key` to network observers.
- The companion is network-restricted to Grocy and exposes versioned, size-limited endpoints. Grocy validates content type, length, schema, candidate count, strings, numeric ranges, taxonomy version, and timeouts.
- External images remain behind opaque, short-lived, single-purpose handles. Proxy thumbnails as well as selected full images so mobile browsers do not contact arbitrary image hosts. Stream into a bounded buffer and validate declared length, MIME, magic bytes, decoded dimensions, and pixel budget.
- Apply/rollback endpoints require a server-created plan ID, selected ordinals, an idempotency key, a maximum item count, and current-row fingerprints. They never accept raw SQL/URLs.
- Trace headers are untrusted input: validate their syntax, start a new trace when invalid, do not reflect arbitrary `tracestate`, and never use a trace ID as authorization.
- Production errors expose a safe message plus trace ID; detailed exceptions go only to redacted server logs.

## Build-Order Dependencies

1. **Release and observability guardrails**
   - Normalize branch documentation, add dual-branch route/contract/container checks, add correlation IDs and boundary timing, and verify the current mobile enrichment workflow.
   - This makes later integration failures attributable and protects the stable deployment before schema work.

2. **Versioned enrichment contract and native field staging**
   - Add brand/package-size userfields, product-group and quantity-unit candidate mapping, barcode duplicate preview/handoff, bounded image proxying, and strict companion contract fixtures.
   - Depends on telemetry/contract test foundation; does not depend on taxonomy schema.

3. **Taxonomy foundation and one-product assignment**
   - Add module migrations, stable food-type catalog/version, assignment service/UI, exclusion constraints, and single-item review/apply.
   - Must precede classification batches and food-type conversions.

4. **Deterministic conversion policy and resolver seam**
   - Add food-type rules, precedence/conflict validation, projection into the existing resolved/cache contract, and characterization tests across stock/recipe consumers.
   - Depends on stable taxonomy and is the phase most likely to need deeper schema research.

5. **Generic bulk planner/executor/rollback ledger**
   - Implement immutable previews, typed operation registry, freshness hashes, `BEGIN IMMEDIATE` apply, audit, idempotency, and conflict-aware compensation.
   - Build after single-item taxonomy and conversion services exist so batch code orchestrates proven operations rather than inventing separate write paths.

6. **Bulk classification and conversion cleanup adapters**
   - Classify existing products, preview replacement of redundant product-specific conversions, apply selected changes, verify effective conversion equivalence, and exercise rollback.
   - Depends on taxonomy, resolver, and generic bulk engine.

7. **Stable promotion and upstream synchronization**
   - Upgrade fixtures, merge/rebuild both branches, preserve `GROCY_DATAPATH`, bump the customization cache marker, deploy to stable release, and perform mobile UAT using trace IDs.
   - This is a gate on every prior phase, not a one-time cleanup task.

## Scalability Considerations

| Concern | Household / ~100 products | 10K products | 1M products |
|---------|---------------------------|--------------|--------------|
| Enrichment | Synchronous request with strict timeout and small TTL cache is sufficient | Queue classification jobs; coalesce UPC lookups | Separate workflow service/event transport required |
| Bulk apply | One atomic transaction, default max 250 items | Server-side job with bounded chunks and a run-level state machine; each chunk independently audited | SQLite/single-node architecture is no longer appropriate |
| Conversion closure | Rebuild affected products/rules and verify all paths | Incremental dependency index by unit pair/food type | Dedicated rule engine/materialized projection store |
| Telemetry | Structured logs + trace IDs; short retention | Collector and metrics backend | Full distributed observability and sampling policy |
| Storage/concurrency | One Grocy writer and local persistent volume | SQLite lock contention needs measurement and scheduled batches | Migrate persistence architecture as a separate project |

Do not optimize the household system for hypothetical million-row scale. The important current limit is transaction duration and one-writer contention, not query throughput.

## Confidence and Research Flags

| Area | Confidence | Notes |
|------|------------|-------|
| Grocy persistence and extension boundary | HIGH | Verified against the local fork, `CUSTOMIZATIONS.md`, core services, migrations, routes, and Phase 1 module |
| SQLite apply/rollback model | HIGH | Matches existing explicit transactions and official SQLite transaction/savepoint behavior |
| Conversion resolver integration | HIGH on need/boundary; MEDIUM on final SQL | All consumers use the resolved cache, but recreating upstream recursive closure/cache triggers needs phase-specific fixtures on both branches |
| Taxonomy ownership | HIGH | Stable IDs and Grocy-side ownership are required for deterministic rules and rollback |
| Companion internals | MEDIUM | Contract and deployment context are known; the Python repository was not part of the required local read |
| Telemetry architecture | HIGH | Correlated structured events and W3C context directly address the stated fault-attribution need without requiring a vendor |

**Phase-specific research flags:**

- Conversion policy phase: inspect all `quantity_unit_conversions_resolved` and cache trigger changes on both target branches before final migration SQL; test ambiguous graph paths and numeric tolerance.
- Companion contract phase: inspect `grocy-mcp` provider concurrency, timeout, caching, and authentication code before selecting instrumentation hooks.
- Bulk phase: validate the live inventory's exact duplicate/inverse conversion patterns and references before defining cleanup operations.
- Stable promotion phase: verify the production `atech-release` adapter, Docker overlay, customization version marker, and persistent-volume migration on a copy of live data.

## Sources

- Local authoritative project context: `.planning/PROJECT.md`, `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/INTEGRATIONS.md`, `.planning/codebase/CONCERNS.md`, and `CUSTOMIZATIONS.md` (HIGH confidence).
- Local Grocy v4.6/fork implementation: `custom/grocy_AI/`, `services/StockService.php`, `services/UserfieldsService.php`, `services/DatabaseMigrationService.php`, `migrations/0082.sql`, `migrations/0225.sql`, `migrations/0232.sql`, and `migrations/0233.sql` (HIGH confidence).
- [Grocy v4.6.0 StockService](https://github.com/grocy/grocy/blob/v4.6.0/services/StockService.php) — upstream transaction, conversion, and stock-undo reference (HIGH confidence).
- [Grocy v4.6.0 DatabaseMigrationService](https://github.com/grocy/grocy/blob/v4.6.0/services/DatabaseMigrationService.php) — ordered transactional migration behavior (HIGH confidence).
- [SQLite transaction documentation](https://www.sqlite.org/lang_transaction.html) — explicit transactions, `BEGIN IMMEDIATE`, snapshots, and one simultaneous writer (HIGH confidence).
- [SQLite savepoint documentation](https://www.sqlite.org/lang_savepoint.html) — nested savepoint and rollback semantics (HIGH confidence).
- [SQLite transactional guarantees](https://www.sqlite.org/transactional.html) — atomicity/durability basis for one-transaction apply (HIGH confidence).
- [W3C Trace Context](https://www.w3.org/TR/trace-context/) — standard cross-service request correlation headers (HIGH confidence).
- [OpenTelemetry signals](https://opentelemetry.io/docs/concepts/signals/) and [structured logs guidance](https://opentelemetry.io/docs/concepts/signals/logs/) — separation/correlation of traces, metrics, and logs (HIGH confidence).

---
*Architecture research: 2026-08-12*
