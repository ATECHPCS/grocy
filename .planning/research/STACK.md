# Technology Stack

**Project:** grocy_AI subsequent milestone
**Researched:** 2026-08-12
**Research scope:** Stack additions and existing-stack patterns for mobile-reliable enrichment, structured food classification, reusable conversions, safe bulk changes, and telemetry
**Overall confidence:** HIGH for platform/library choices; MEDIUM for the food-type conversion projection because Grocy has no native food-type conversion scope

## Recommendation in One Sentence

Keep Grocy's PHP 8.5/Slim/Blade/JavaScript/SQLite runtime and the existing Python Starlette/FastMCP companion; add only strict Pydantic contract models, namespaced SQLite rule/audit tables, standards-based timing/correlation, and an isolated Playwright mobile test workspace.

## Recommended Stack

### Core Framework

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Existing Grocy modular monolith | Grocy 4.6 production line; PHP 8.5; Slim 4.15.2 | Authentication, permissions, reviewed UI, product persistence, conversion execution, and bulk-change transaction owner | Keep all durable writes inside Grocy. This preserves upstream behavior and the established product/file/API flows; Python must not write the SQLite file directly. **Confidence: HIGH** (repository evidence). |
| Existing `custom/grocy_AI` module | Existing feature-gated boundary | New routes, controllers, services, migrations, and browser behavior | Put implementation under `custom/grocy_AI/` and `public/custom/grocy_AI/`; keep core changes to feature-gated includes/assets documented in `CUSTOMIZATIONS.md`. **Confidence: HIGH** (repository evidence). |
| Existing Starlette/FastMCP companion | Python >=3.11; retain deployed compatible versions | Provider fan-out, normalization, classification suggestions, image retrieval, and versioned JSON contracts | Starlette already hosts `/v1` alongside FastMCP. Extend that adapter rather than adding FastAPI, Flask, or another service. **Confidence: HIGH** (companion source evidence). |
| Pydantic | **2.13.4**, direct exact runtime pin in `grocy-mcp` | Strict request/response DTOs for enrichment v2, classification evidence, timing metadata, and bulk suggestions | Use `ConfigDict(strict=True, extra='forbid')`, bounded strings/lists, enums, and model validators. Pydantic turns the currently hand-built provider dictionaries into an explicit compatibility boundary and can generate JSON Schema fixtures for PHP contract tests. It is already part of the FastMCP ecosystem transitively, but must be a direct dependency because application code will import it. **Confidence: HIGH** (official docs and current package registry). |

### Database

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Existing PDO SQLite | >=3.40.0 | Taxonomy, canonical conversion rules, materialization provenance, preview plans, and mutation audit | Add normal ordered migrations and namespace every custom object `grocy_ai_*`. SQLite already supports the required foreign keys, constraints, recursive queries, transactions, and built-in JSON functions at the project's minimum version. No PostgreSQL is warranted for a single-household deployment. **Confidence: HIGH**. |
| SQLite text JSON + `CHECK(json_valid(...))` | Built into SQLite >=3.38 | Store immutable plan/before/after documents for change sets | Use canonical JSON **TEXT**, not JSONB. JSON functions are built in at the project's SQLite 3.40 minimum, while SQLite JSONB arrived only in 3.45 and would silently raise the runtime floor. Keep frequently queried state relational; JSON is for immutable audit envelopes and provider evidence. **Confidence: HIGH** (SQLite official docs). |
| Native `quantity_unit_conversions` | Existing Grocy schema and resolver | Compatibility projection consumed by all upstream recipe/stock code | Store universal rules directly as native rows with `product_id IS NULL`. Keep food-type rules canonical in `grocy_ai_conversion_rules`, then materialize only their required per-product projections into the native table with provenance in `grocy_ai_conversion_materializations`. This avoids patching Grocy's large recursive resolver view while giving users one reusable rule to maintain. **Confidence: MEDIUM** (best compatibility pattern from current schema; Grocy lacks a native food-type scope). |

Recommended custom relational objects:

| Object | Role | Key constraints |
|--------|------|-----------------|
| `grocy_ai_food_types` | Stable internal taxonomy | Stable slug, display name, optional parent, active flag; unique slug; do not use provider labels as primary keys. |
| `grocy_ai_product_food_types` | Reviewed product classification | One current food type per product; confidence/source are evidence, not authority; reviewed state and timestamps. |
| `grocy_ai_conversion_rules` | Canonical reusable rules | Scope is `universal` or `food_type`; positive finite factor; unique active rule per scope/from/to; optional provenance/notes. |
| `grocy_ai_conversion_materializations` | Maps a canonical food-type rule to native product-specific rows | Unique rule/product/direction; hash of source inputs; enough provenance to reconcile or safely remove only module-owned rows. |
| `grocy_ai_change_sets` | Preview/apply/rollback audit | UUID, operation kind, status, canonical plan/before/after JSON, plan hash, creator, timestamps; JSON validity checks. |

Do not replace Grocy's resolver view with a fork-only food-type branch. Upstream has repeatedly recreated `quantity_unit_conversions_resolved`; owning a patched copy would create a large, fragile merge surface. Materialization is deliberately an adapter: the custom rule is the source of truth, while native rows are rebuildable outputs.

### Infrastructure and Operations

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Guzzle `on_stats` plus existing timeouts | Existing locked Guzzle 7.15.1 | Measure Grocy-to-companion transfer time and distinguish connect/total failures | Reuse the installed client. Set explicit per-call total and connect budgets, keep redirects disabled when credentials are present, and capture `TransferStats` through `on_stats`. Guzzle documents that `connect_timeout` is supported only by its cURL handler, so the production image should include `ext-curl`; report its absence in the module status instead of changing Grocy's global prerequisites. **Confidence: HIGH**. |
| Shared HTTPX `AsyncClient` with fine-grained `Timeout` and event hooks | Existing HTTPX dependency; retain compatible deployed line | Provider connection reuse, connect/read/pool budgets, request correlation, and provider result metrics | Create the client in Starlette/FastMCP lifespan rather than once per lookup. Use named connect/read/write/pool timeouts, bounded connection limits, and async request/response hooks. Measure each provider coroutine separately with `time.perf_counter()`. **Confidence: HIGH** (HTTPX official docs). |
| W3C Trace Context | Trace Context standard | Correlate browser → Grocy → companion → provider logs | Generate or validate `traceparent` at the Grocy boundary, advance the parent span for the companion call, and forward it from HTTPX. Include the trace/request ID in safe error responses and logs; never include API keys, full query strings, internal provider URLs, or image tokens. This stays compatible with later OpenTelemetry adoption without adding it now. **Confidence: HIGH** (W3C standard). |
| `Server-Timing` response header | W3C Server Timing | Make `grocy`, `companion`, `lookup`, `off`, `searx`, and `image` durations visible on authenticated same-origin requests | It is natively understood by browser performance tooling and requires no JavaScript or telemetry backend. Return only coarse component names and durations; the standard warns that timing details can be sensitive. **Confidence: HIGH** (W3C standard). |
| Structured JSON logs using standard logging | Python `logging`, PHP `error_log`/small module helper | Low-volume operational telemetry in existing Docker logs | Emit one completion event per enrichment/image/bulk request with trace ID, operation, outcome category, HTTP status, duration buckets, provider statuses, timeout layer, and counts. Keep mutation audit in SQLite, but keep request telemetry out of SQLite to avoid adding write contention. Do not add Monolog, structlog, Prometheus, or an OpenTelemetry collector for this milestone. **Confidence: HIGH** for fit; observability backend can be revisited only if log volume/use grows. |

### Testing and Mobile Reliability

| Technology | Version | Purpose | When to Use |
|------------|---------|---------|-------------|
| Playwright Test | **1.62.1**, exact dev pin in an isolated e2e workspace | Chromium and WebKit mobile viewport/touch tests, offline transitions, stalled provider routes, screenshots, traces, and HAR capture | Add under `custom/grocy_AI/tests/e2e/` (or a top-level test-only workspace), not to Grocy's runtime `public/packages`. Cover search, review, apply, save, reload, barcode handoff, expiry, timeout, retry, and duplicate-submit prevention. Playwright device profiles emulate viewport/user-agent/touch; keep one physical-phone LAN acceptance pass because emulation is not a real iPhone radio/Safari stack. **Confidence: HIGH** (official docs and current npm registry). |
| Existing PHP CLI contract harness | Existing custom runner | PHP normalization, authorization/error mapping, plan hashing, stale-plan conflicts, and rollback invariants | Extend it rather than adding PHPUnit during this milestone. Inject transports and database fixtures as it already does. **Confidence: HIGH**. |
| Existing Python `unittest` suite | Python standard library | Pydantic adapters, provider mapping, partial failure, timeout classification, and trace propagation | Continue `IsolatedAsyncioTestCase` and `unittest.mock`; no pytest/respx dependency is necessary for the current suite size. **Confidence: HIGH**. |

### Supporting Libraries and Primitives

| Library / Primitive | Version | Purpose | When to Use |
|---------------------|---------|---------|-------------|
| `ramsey/uuid` | Existing 4.9.3 | Change-set and audit identifiers | Generate opaque server-side IDs. Never accept client-selected database identifiers as authority. |
| Native `random_bytes` / Python `secrets` | Runtime built-ins | Trace/span IDs and opaque one-time handles | Keep security-sensitive randomness in runtime primitives; do not add a token package. |
| Native `XMLHttpRequest` | Existing Grocy browser layer | Abortable mobile requests, explicit UI deadline, and clear timeout/offline states | Add a **module-local** request helper that returns the XHR, sets `timeout`, handles `ontimeout`/`onabort`, and suppresses late responses. Do not globally rewrite `Grocy.Api` unless upstream later provides the capability. |
| Browser Performance API | Browser built-in | End-to-end user-observed duration and Server-Timing inspection | Capture coarse durations only after explicit diagnostics opt-in or in test automation; do not fingerprint devices or collect unrelated navigation history. |
| Open Food Facts API v3.6 adapter | Explicit `/api/v3.6/` endpoint and selected fields | Structured name, brand, normalized package quantity/unit, product type, category/food-group tags, and schema version | Replace new enrichment work against the legacy `/api/v0` payload with an explicit v3.6 adapter. Request only documented fields; reject `product_type=petfood`; map canonical provider tags to internal food-type IDs through a checked-in mapping table. Treat all data as suggestions. **Confidence: HIGH** for API shape, MEDIUM for classification quality because Open Food Facts explicitly disclaims completeness/accuracy. |
| Versioned JSON rule catalog | Project-owned schema version | Seed taxonomy aliases and common universal/food-type conversion rules | Keep deterministic, reviewable data in Git (for example `custom/grocy_AI/data/food-types.v1.json` and `conversion-rules.v1.json`), validate it with Pydantic in Python and contract fixtures in PHP, and import idempotently. Do not embed mutable rules across controller code. **Confidence: HIGH**. |

## Feature-to-Stack Mapping

| Feature | Prescribed implementation stack |
|---------|---------------------------------|
| Mobile-reliable enrichment | Module-local abortable XHR + explicit UI state machine; Guzzle total/connect deadline and `on_stats`; shared HTTPX client with per-provider budgets; trace ID and Server-Timing; Playwright Chromium/WebKit failure-path tests. |
| UPC/barcode handoff | Reuse Grocy's existing barcode/product form and normal Save path. Pass reviewed UPC and suggestions as form state; do not add a second product-write client. Use idempotency/duplicate checks in a named module endpoint where multi-step behavior is needed. |
| Structured product suggestions | Companion `/v2` DTOs in Pydantic, while `/v1` remains backward compatible. Every suggested field carries `value`, `source`, `confidence`, and optional reason/conflict; PHP revalidates IDs against current Grocy master data. |
| Food classification | Internal namespaced taxonomy with stable IDs; Open Food Facts v3.6 `product_type`, `food_groups_tags`, and `categories_tags` as evidence; deterministic alias mapper first; human review before `grocy_ai_product_food_types` changes. |
| Universal conversions | Native `quantity_unit_conversions` rows with `product_id IS NULL`; use Grocy's existing inverse triggers/resolution. |
| Food-type conversions | Canonical `grocy_ai_conversion_rules` plus idempotent materialization into upstream-compatible native product rows. Tag ownership in a provenance table so reconciliation never deletes user-owned conversions. |
| Safe bulk changes | Server-side preview plan stored by UUID; canonical plan hash; explicit apply endpoint; `BEGIN IMMEDIATE`; re-read expected values; fail stale/conflicting rows; one transaction; immutable before/after audit; conditional rollback that refuses to overwrite later edits. |
| Telemetry | W3C trace propagation, `Server-Timing`, Guzzle stats, HTTPX event hooks/per-provider clocks, structured completion logs, and mutation audit records. |

## Contract and Compatibility Rules

1. Keep `/v1/products/enrich/upc/{upc}` stable. Add a negotiated `/v2` response or an explicit versioned endpoint for richer fields; do not silently change v1 shapes.
2. Pydantic models are the companion's provider-to-contract boundary. PHP still validates every scalar, enum, URL, ID, list length, and numeric range before rendering or acting.
3. Provider schemas are not the internal taxonomy. Store provider tag IDs and schema version as evidence, map them through project-owned aliases, and persist only internal food-type IDs after review.
4. Preview output is informational. Apply accepts a server-stored change-set ID, not a client-edited array of SQL-like operations.
5. A plan is stale if any expected product/type/conversion value differs at apply time. Return `409 Conflict` with row-level conflicts and make no changes.
6. Use `BEGIN IMMEDIATE` for the short apply transaction so write contention is discovered before partial work. Perform provider/AI calls before the transaction; never hold the SQLite writer lock during network I/O.
7. At most retry idempotent provider GETs, once, with jitter and only while the overall user deadline has time remaining. Never automatically retry Grocy writes, bulk apply, rollback, or opaque image-token fetches.
8. Bulk rollback is another reviewed plan. Restore a row only if its current value still matches the recorded post-apply value; otherwise report a conflict.
9. Keep test/browser dependencies outside production assets. Production remains Composer + Yarn Classic as upstream expects; Playwright is test-only.

## Timeout Budget Pattern

Use one end-to-end deadline and smaller layer budgets rather than the current single opaque 20-second wait. Exact values should be tuned from LAN measurements, but start with:

| Layer | Initial budget | Failure label |
|-------|----------------|---------------|
| Browser → Grocy request | 15 s overall | `browser_deadline` / `connection_lost` |
| Grocy → companion connect | 2 s | `companion_connect_timeout` |
| Grocy → companion total | 12 s | `companion_total_timeout` |
| Companion structured-provider calls | 2 s connect, 5–6 s read, concurrent | `provider_connect_timeout` / `provider_read_timeout` |
| Optional SearXNG image search | 2 s connect, 5–6 s read | `searx_timeout` |
| Selected image fetch | Separate user action, bounded stream and byte cap | `image_host_timeout` / `image_too_large` / `image_invalid` |

The companion should return useful partial structured results when one provider fails. The PHP layer should not retry the whole companion request, because that multiplies provider calls and makes mobile latency worse.

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Grocy UI/runtime | Existing Blade + plain JS extension | React/Vue/Svelte SPA or native app | Adds a second routing/state/build system and a large upstream integration surface; the requirement is mobile reliability, not a rewrite. |
| Companion web framework | Existing Starlette + FastMCP | Add FastAPI/Flask/Django | Starlette already owns the conventional HTTP routes and middleware. Pydantic can be used directly without another framework. |
| Validation | Pydantic 2 direct dependency in companion; explicit PHP normalizers | JSON Schema framework in both runtimes | A cross-runtime schema engine adds substantial machinery. Generate schema/fixtures from Pydantic, but keep PHP's narrow boundary validator explicit and reviewable. |
| Taxonomy storage | Namespaced relational tables with stable IDs | Product `preset-list` userfield only | Userfield values are text and list labels are unstable identifiers; rules need referential integrity and safe renames. A userfield may be a display bridge, not the canonical model. |
| Food-type conversions | Canonical rules + native materialized rows | Patch `quantity_unit_conversions_resolved` | The resolver is large and has been repeatedly recreated by upstream migrations; owning a forked view is high-conflict. |
| Bulk execution | Synchronous preview/apply transaction | Celery/RQ/Redis/job queue | Household scope is small, writes must be short, and a queue complicates rollback and deployment. Provider work happens before apply. |
| Audit payload | JSON TEXT with `json_valid` | SQLite JSONB | Project minimum is SQLite 3.40; JSONB requires >=3.45 and offers little value for small immutable audit documents. |
| Operational telemetry | Trace headers + Server-Timing + JSON logs | OpenTelemetry SDK/collector, Prometheus, ELK, Sentry | Valuable at larger scale, but disproportionate for one LAN household and two services. Keep field names trace-compatible so later adoption is possible. |
| Provider integration | Direct HTTPX adapter to explicit OFF API v3.6 | Add an Open Food Facts SDK | The existing code already uses HTTPX, only a small field subset is needed, and the v3 API/schema is changing. A narrow owned adapter is easier to pin and test. |
| Unit conversion math | Explicit curated factors with source/provenance | Generic units/density package or LLM-generated factors | Dimensional libraries can convert oz↔g or cup↔ml, but cannot know food-dependent density. LLM guesses are unsafe. Keep universal physical conversions exact and food-type factors curated/reviewed. |
| Python tests | Existing `unittest` | Add pytest, pytest-asyncio, respx | Current suite already has async and mock support. Add dependencies only if suite ergonomics become a demonstrated bottleneck. |

## What Not to Add

- No direct Python access to `data/grocy.db`; all durable writes go through authenticated Grocy module APIs.
- No second database, Redis, broker, worker fleet, or cache service.
- No ORM migration. Use PDO/LessQL and ordered SQL migrations consistent with Grocy.
- No global rewrite of `Grocy.Api` for milestone-specific timeout behavior; keep the request helper module-local.
- No automatic taxonomy/conversion/product writes from provider or model output.
- No live dependency on an LLM for normal classification or conversion resolution. An LLM may propose an unmapped class, but it must not create taxonomy entries or factors automatically.
- No high-cardinality labels or secrets in logs: exclude UPC where unnecessary, API keys, full internal URLs, query strings, image tokens, raw provider bodies, and plan contents.
- No unbounded in-memory image downloads. Use HTTPX streaming and a bounded accumulator; reject by content length when present and by running byte count regardless.
- No broad provider retries. Respect Open Food Facts rate limits and cache only reviewed/normalized evidence where useful.

## Installation

No new PHP runtime package is required. Ensure the deployment image exposes `ext-curl` so Guzzle's separate `connect_timeout` is effective; report capability through module diagnostics.

In the `grocy-mcp` companion, add Pydantic as a direct exact dependency and produce a reproducible lock/constraints artifact as part of that repository's deployment process:

```bash
python -m pip install "pydantic==2.13.4"
```

In a test-only e2e workspace, separate from Grocy's production frontend packages:

```bash
npm install --save-dev "@playwright/test@1.62.1"
npx playwright install chromium webkit
```

Do not add Playwright to assets emitted beneath `public/packages/`.

## Sources

### Primary / Official (HIGH confidence)

- [Guzzle request options: `timeout`, `connect_timeout`, redirects, streaming, and `on_stats`](https://docs.guzzlephp.org/en/stable/request-options.html)
- [HTTPX timeout model](https://www.python-httpx.org/advanced/timeouts/)
- [HTTPX event hooks](https://www.python-httpx.org/advanced/event-hooks/)
- [HTTPX resource limits and connection pooling](https://www.python-httpx.org/advanced/resource-limits/)
- [Pydantic strict mode](https://docs.pydantic.dev/latest/concepts/strict_mode/)
- [Pydantic model configuration, including `extra='forbid'`](https://docs.pydantic.dev/latest/api/config/)
- [Pydantic package release registry](https://pypi.org/project/pydantic/)
- [Playwright device/mobile emulation](https://playwright.dev/docs/emulation)
- [Playwright tracing](https://playwright.dev/docs/trace-viewer-intro)
- [Playwright package release registry](https://www.npmjs.com/package/@playwright/test)
- [SQLite transaction semantics, including `BEGIN IMMEDIATE`](https://www.sqlite.org/lang_transaction.html)
- [SQLite JSON functions and version history](https://www.sqlite.org/json1.html)
- [W3C Trace Context](https://www.w3.org/TR/trace-context/)
- [W3C Server Timing](https://www.w3.org/TR/server-timing/)
- [Starlette middleware patterns](https://www.starlette.io/middleware/)
- [Open Food Facts API overview, current v3.6 status, limits, and data-quality warning](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/)
- [Open Food Facts product schema: `product_type`, normalized quantity, schema version, food groups, and category tags](https://openfoodfacts.github.io/documentation/docs/Product-Opener/schemas/schemas/product/)
- [Open Food Facts API/product schema change log](https://openfoodfacts.github.io/documentation/docs/Product-Opener/api/ref-api-and-product-schema-change-log/)

### Repository Evidence (HIGH confidence)

- `.planning/PROJECT.md`
- `.planning/codebase/STACK.md`
- `.planning/codebase/ARCHITECTURE.md`
- `custom/grocy_AI/src/GrocyAiService.php`
- `custom/grocy_AI/tests/run.php`
- `migrations/0082.sql`, `migrations/0188.sql`, and `migrations/0232.sql`
- Sibling companion `../grocy-mcp/pyproject.toml`, `grocy_mcp/server.py`, `grocy_mcp/enrichment.py`, `grocy_mcp/lookup.py`, and its existing `unittest` suite

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Preserve PHP/Slim/Blade/SQLite and companion boundary | HIGH | Directly evidenced by the deployed code and project constraints. |
| Pydantic for strict companion contracts | HIGH | Current official documentation and registry; narrow addition with immediate contract value. |
| Guzzle/HTTPX timing and trace propagation | HIGH | Both clients expose the required hooks/timeouts; standards are stable and package-free. |
| SQLite audit/change-set model | HIGH | Fits existing transaction ownership; JSON TEXT works at the current minimum SQLite version. |
| Playwright mobile reliability suite | HIGH | Official device/offline/trace support; exact current registry pin. Physical-phone UAT remains required. |
| Open Food Facts v3.6 mapping | MEDIUM | API fields/version are verified, but provider data is volunteer-supplied and v3 remains actively developed. Pin and test the adapter. |
| Food-type conversion materialization | MEDIUM | It is the lowest-conflict way to reuse upstream resolution, but phase planning must define reconciliation, inverse-row ownership, and UI visibility precisely. |

