# grocy_AI

`grocy_AI` is the isolated ATECHPCS extension boundary for AI-assisted and search-assisted Grocy features.

## Phase 1

Phase 1 provides:

- a feature-flagged module bootstrap;
- authenticated status and UPC-enrichment API routes;
- a product-form search panel with explicit, review-before-save controls;
- previews of metadata and real package-image candidates;
- user-controlled “apply suggested name” and “use as product picture” actions;
- no automatic database, product, image, or stock writes.

The companion service may combine structured sources such as Open Food Facts with exact-product image discovery through the local SearXNG instance. Search results are candidates for human review, never proof of an exact UPC match by themselves.

## Configuration

Set these in `config.php` or with the corresponding environment variables:

```php
Setting('FEATURE_FLAG_GROCY_AI', true);
Setting('AI_SERVICE_URL', 'http://grocy-mcp:8000');
Setting('AI_SERVICE_API_KEY', 'replace-with-a-secret');
Setting('AI_REQUEST_TIMEOUT_SECONDS', 20); // Legacy setting; enrichment is hard-capped below.
```

The generated constants/environment names are:

- `GROCY_FEATURE_FLAG_GROCY_AI`
- `GROCY_AI_SERVICE_URL`
- `GROCY_AI_SERVICE_API_KEY`
- `GROCY_AI_REQUEST_TIMEOUT_SECONDS`

Do not commit the API key. The status route only reports whether one is configured.

Product enrichment always uses a 12-second total request limit and a 2-second connect limit, even when the legacy `AI_REQUEST_TIMEOUT_SECONDS` setting is larger. Redirects are disabled so neither the API key nor owned trace context can be forwarded to another host. No automatic retry is performed.

## Grocy routes

- `GET /api/grocy-ai/status`
- `GET /api/grocy-ai/barcodes/resolve/{barcode}`
- `GET /api/grocy-ai/products/enrich/upc/{upc}`
- `GET /api/grocy-ai/images/{variant}/{selection-token}` where `variant` is exactly `thumbnail` or `full`

All routes use Grocy authentication. Barcode ownership resolution, UPC enrichment, and selected-image retrieval also require the `MASTER_DATA_EDIT` permission.

## Barcode ownership handoff

`GrocyAiGtin` is the single predicate owner for both PHP and generated SQLite expressions. It accepts only checksum-valid digit strings whose exact lengths are GTIN-8, GTIN-12, GTIN-13, or GTIN-14. The exact scanned string remains the display value; ownership comparisons use its left-zero-padded 14-character canonical key. Unsupported lengths, text, and checksum-invalid numeric-looking values return no canonical key and remain ordinary arbitrary Grocy barcodes outside this enrichment boundary.

The authenticated barcode route is read-only and checks `MASTER_DATA_EDIT` before any ownership lookup. It searches locally before provider enrichment, returns only a bounded database-owned product ID for an existing owner, and never accepts an owner ID or destination from browser or companion input. An existing owner suppresses provider work. An unused valid GTIN may be staged once in transient browser state as `Ready to add on Save`, where it remains removable and disappears on invalidation, cancellation, stale results, or navigation.

Migration `0256.php` audits with `GrocyAiGtin::CanonicalSqlExpression('barcode')` and creates `ix_product_barcodes_canonical_gtin` inside a transaction only when the canonical collision count is zero. It never deletes, rewrites, or reassigns stored barcodes. Because the expression returns `NULL` for unsupported text and checksum-invalid numeric-looking values, those ordinary Grocy barcodes remain outside canonical uniqueness while the existing exact-text index continues to apply.

Grocy's normal product Save remains the only persistence gesture. After the existing product/userfield/picture path has a trusted product ID, one narrow continuation re-resolves the staged barcode owner and posts at most one `{product_id, barcode, amount: 1}` row. Current-product ownership is idempotent success; another owner blocks and exposes only the trusted owner route. A partial attachment failure retains the created product ID and exact barcode in transient state, and `Retry barcode attachment` repeats only ownership resolution plus at most one barcode insert attempt—never product creation or update. Nutrition Facts, allergen, dietary, and medical data remain rejected and deferred.

## Companion-service contract

Grocy calls:

```text
GET {GROCY_AI_SERVICE_URL}/v1/products/enrich/upc/{upc}
Accept: application/json
X-API-Key: {GROCY_AI_SERVICE_API_KEY}   # only when configured
traceparent: 00-{trace-id}-{owned-parent-id}-{flags}
```

Expected contract-v2 response:

```json
{
  "contract_version": 2,
  "outcome": "found",
  "barcode": {
    "scanned_gtin": "012345678905",
    "canonical_gtin": "00012345678905",
    "equivalents_checked": ["012345678905", "00012345678905"],
    "status": "unused",
    "owner_product_id": null
  },
  "suggestions": [
    {
      "id": "name:openfoodfacts:0",
      "field": "name",
      "value": "Example product",
      "display_value": "Example product",
      "source": {"id": "openfoodfacts", "label": "Open Food Facts"},
      "confidence_band": "high",
      "reason_code": "canonical_structured_match",
      "evidence_kind": "structured_direct",
      "retrieved_at": "2026-08-13T12:00:00Z",
      "source_updated_at": null,
      "target": null
    }
  ],
  "media": [
    {
      "id": "image:openfoodfacts:front",
      "kind": "front_package",
      "thumbnail_handle": "opaque-thumbnail-capability",
      "full_handle": "distinct-opaque-full-capability",
      "source": {"id": "openfoodfacts", "label": "Open Food Facts"},
      "confidence_band": "high",
      "reason_code": "canonical_structured_front_image",
      "evidence_kind": "structured_direct",
      "retrieved_at": "2026-08-13T12:00:00Z"
    }
  ],
  "warnings": [],
  "diagnostics": {"trace_id": "4bf92f3577b34da6a3ce929d0e0e4736"}
}
```

Grocy preserves the raw response until a duplicate-aware lexical walk has rejected repeated member names at every object depth. It then validates exact version, members, enums, types, IDs, timestamps, targets, provenance, and unique IDs as one all-or-nothing boundary. Any malformed, unknown, duplicate, URL-bearing, nutrition, allergen, dietary, or medical content becomes the single redacted `contract_invalid` recovery state; no partial suggestion survives.

Contract v2 contains no external image URL or provider dictionary. Secure media is represented only by distinct short-lived variant-bound opaque handles and authenticated same-origin routes. Exact structured-source front-package imagery is ordered first. SearXNG candidates appear only in the separate `Unverified search alternatives` group with `unverified` confidence and `search` evidence; they are never equivalent to structured evidence or preselected. The browser makes zero image request until `Load thumbnail` or `Select image` is activated.

The companion revalidates URL syntax, DNS answers, and the actual connected peer at every hop, refuses mixed or non-global addresses, disables environment proxies and automatic redirects, allows at most two manually checked redirects, and forbids HTTPS downgrade. It enforces a 2-second connect deadline, 12-second total deadline, 2,000–3,000,000 streamed bytes, and exact matching JPEG/PNG/WebP MIME and magic. Handles live for 900 seconds in a maximum 512-entry LRU store.

Grocy independently checks the closed variant and token, byte count, exact MIME, magic, decoded width and height of 32–4096 pixels, and a maximum 16,000,000 pixels before returning fixed-name bytes with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. Thumbnail object URLs and the selected full `File` remain transient module state. Only `Stage selected changes` assigns that `File` to the native picture input, and only Grocy's unchanged normal Save can persist it. Candidate failure preserves every other suggestion, manual form value, selection, and Save control; stale, replaced, cancelled, and navigation state abort requests and revoke obsolete object URLs.

The review renders name, brand, package size, product group, quantity unit, food type, and product image as independent current/suggested decisions with field-local provenance. Only a blank native destination backed by high-confidence direct structured canonical evidence starts selected. Mapped, inferred, search, missing-target, inactive-target, whitespace, and non-empty cases require an explicit decision or remain disabled.

The only Phase 2 brand destination is the revalidated `products.brand` single-line product userfield. Package size and food type remain visible evidence with no destination; this module does not create userfields or a Phase 3 taxonomy surrogate. Product-group and quantity-unit suggestions must name an active local option. Nutrition Facts, allergen, dietary, and medical members remain rejected and deferred.

Selection state, captured current values, and the selected-only final diff are transient. The browser re-reads each native control before opening the diff and again before staging. A changed value is marked `Needs review`, removed from the diff, and cannot stage until the user explicitly selects it against the new current value. `Stage selected changes` dispatches only local native input/change behavior for selected live rows and makes no API request. An unused checksum-valid barcode can join that transient review state; its only write boundary is the normal-Save continuation described above. Grocy's unchanged normal Save buttons are the sole persistence authority.

## Diagnostic and privacy contract

`custom/grocy_AI/module-version.json` is the portable source for the module version and diagnostic contract version. Grocy validates or replaces inbound W3C v00 `traceparent`, creates a fresh parent ID, and forwards only that rebuilt header to the companion. `tracestate` is ignored, and owned trace headers end at the companion rather than reaching external providers.

The product-form JavaScript and CSS query token is the grocy_AI `module_version`, not Grocy's core release version. Bump `module-version.json` whenever either custom asset changes and update the one `grocyAiAssetVersion` literal in `views/productform.blade.php` to the same value. The native contract suite enforces that both asset URLs use that matching module token and remain independent from core `$version`, preventing a stable browser cache from serving older custom bytes across deployments.

Grocy validates contract-v2 diagnostics as the single owned trace ID. The browser still creates its closed local diagnostic report without copying raw response fields. Diagnostics and status never contain GTINs, product or inventory values, service URLs, request/response headers, credentials, cookies, payload bodies, image handles, or raw exception text.

Enrichment and diagnostics remain authenticated GET/read operations. Search, review, selection, final-diff display, staleness handling, and local form staging do not write database rows, files, product data, barcodes, categories, conversions, stock, or inventory state. Unselected controls receive no value or dirty event. Suggested fields and image evidence remain review state until the user invokes Grocy's existing Save workflow.

Run the standalone module checks with:

```sh
php custom/grocy_AI/tests/run.php
```

## Taxonomy v1 validation

Run the fixture-only contract test with:

```sh
php custom/grocy_AI/tests/run.php taxonomy-validation
```

Run the actual configured Grocy database report from the deployment checkout/container with its configured absolute data path:

```sh
GROCY_DATAPATH=/path/to/grocy-data php custom/grocy_AI/bin/validate-inventory-taxonomy.php
```

The production command refuses a missing or relative data path and does not bootstrap, migrate, assign, or otherwise write the database. It emits one JSON object containing only redacted aggregate counts and the frozen/preserved boundary; retain that output as the maintainer validation record.

The report evaluates local products and local module evidence only. It makes no provider request and does not create assignments or taxonomy leaves, edit products, or change stock, history, recipes, locations, prices, or handling data. Its output is intentionally limited to taxonomy version and aggregate mapped, Unclassified, excluded, conflicting, and low-confidence counts; it never includes household product names, barcodes, provider URLs, or raw evidence. On an authenticated product-edit enrichment request, the module reconciles only the server-validated Phase 2 `food_type` suggestion into its own evidence snapshot; the browser never supplies the provider category, confidence, or reason. An active Grocy Product group with a closed local mapping (for example, `Seafood` to `Meat & seafood`) is also high-confidence local evidence and takes precedence over provider food-type evidence. It is read-only: neither source assigns a taxonomy leaf automatically or changes `products.product_group_id`; the user must explicitly assign or leave the food type Unclassified.

Frozen and preserved are handling/location concerns, not taxonomy identities. This report records that boundary but does not decide or apply classifications. Phase 6 owns any later bulk preview, approval, apply, recovery, or inventory cleanup workflow.

When `packages/autoload.php` is present, the native suite also compiles the complete product form and renders the custom asset-version fixture with Grocy's installed Blade engine. To point the same regression at an exact external Composer runtime, set `GROCY_BLADE_AUTOLOAD` to that runtime's `autoload.php`.

## Reusable conversion model

Grocy keeps owning every durable conversion. The module owns only reusable *candidates* and the
evidence that would let them become real, and it separates the two with one write authority.

### Ownership

- `grocy_ai_conversion_rule_revisions` owns every reusable universal and profile candidate: its
  source, source version, precise factor, revision hash, and `inactive`/`active` lifecycle. Bootstrap
  seeds each candidate `inactive`; nothing else may seed one active.
- `grocy_ai_conversion_activation_evidence` is the immutable ledger. One row records the exact
  Plan 01 main and stable revisions, the characterization checksum, the selected adapter, the cache
  key schema, the query-plan checksum, the pinned migration hashes and cache objects, and a checksum
  over every protected-consumer proof.
- Native `quantity_unit_conversions` keeps ownership of normal product-scoped conversions plus the
  universal rows the activation transaction creates. Nothing else writes universal rows.

### The one activation transaction

`GrocyAiConversionService::ActivateVerifiedRuleset()` is the only operation allowed to transition a
revision active or to produce a reusable cache effect. In one transaction it re-reads
`.planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md`, requires the supplied bundle
to equal that document in every field, validates every candidate revision hash and the whole rule
graph, records the evidence row, activates the named revisions, and only then calls the selected
adapter. Missing, stale, altered, failed, or unequal evidence returns `inactive` with one bounded
blocker and leaves module records, native rows, and cache snapshots untouched.

The document currently records **no selected projection**, so activation fails closed with
`selected_projection_absent` on every real call. That is the intended production state: a projection
becomes possible only when a named candidate has been exercised against current immutable
dual-branch evidence and the document records it.

### Native trigger and cache behavior

The one supported adapter, `universal_native_rows_v1`, writes universal `quantity_unit_conversions`
rows for activated mass and volume rules whose units Grocy already has, and stops there. The module
issues no cache SQL. Everything downstream is Grocy's own characterized behavior:

- `quantity_unit_conversions_INS` derives the inverse row, so five gate-created rows become ten
  native universal rows.
- The same trigger rebuilds `cache__quantity_unit_conversions_resolved` from
  `quantity_unit_conversions_resolved`. That cache is product-scoped: a universal rule appears as
  resolved rows for each product, never as a `product_id IS NULL` cache row.
- Cache rows are keyed by `(product_id, from_qu_id, to_qu_id)` through
  `ix_cache__quantity_unit_conversions_resolved_performance1` on both maintained branches.

### The generic native boundary stays fail-closed

Before and after any activation, every generic reusable-universal `quantity_unit_conversions`
POST/PUT is rejected by `GenericEntityApiController` before native trigger or cache work, with a
bounded `conversion_write_blocked:<reason>` error. A request that exactly restates an already
projected rule is rejected as `reusable_scope_inactive`; a tampered factor is rejected as
`factor_tolerance`. A generic PUT aimed at a gate-created universal row is rejected the same way.
Valid product-scoped package/count and measured-density requests keep their normal Grocy Save
behavior, and existing product conversion rows are never replaced or removed.

### Cleanup boundary

Activation adds evidence and rows. It never drops a table, trigger, or index, never removes a
superseded native row, and never reconciles duplicate or redundant product overrides. Coverage
diagnostics only *count* redundant overrides. All conversion cleanup is Phase 6 work and requires a
scrubbed production-shaped snapshot.

### Stable-only differences

The Phase 4 module files in `custom/grocy_AI/portable-files.txt` are byte-portable and must be
mirrored to the stable branch unchanged. Only the documented adapters differ on stable: the
controller namespace and base class, route registration syntax, the Blade view hooks and their
`$grocyAiAssetVersion` literals, `custom/grocy_AI/version.json`, and the Docker overlay. The
conversion release gate proves the immutable dual-branch evidence directly from git, so it runs
identically whether both maintained branches live in one checkout or two.

## Deterministic release gates

Run the main-repository contracts from `/Users/ian/Documents/Repos/grocy` on `atech-main`:

```sh
php custom/grocy_AI/tests/run.php
bash custom/grocy_AI/tests/release-gate.sh taxonomy
bash custom/grocy_AI/tests/release-gate.sh conversions
npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04
npm --prefix custom/grocy_AI/tests/browser test -- --grep '@mob01|@mob02|@mob03|@mob04|@mob05|@mob06|@mob07|@mob08'
npm --prefix custom/grocy_AI/tests/browser run test:release
python3 .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py --self-test
bash -n custom/grocy_AI/tests/check-portable-parity.sh
```

Run the companion contract in its separate repository and working directory:

```sh
cd /Users/ian/Documents/Repos/grocy-mcp
.venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics
```

`release-gate.sh conversions` proves the Phase 4 portable manifest, the immutable main/stable
revisions and their byte-equal characterized migrations, the cache/trigger adapter contract, the
eight protected-consumer proofs, the evidence-ledger and single-activation-statement contract, and
the full conversion suite. It resolves the stable side from `GROCY_AI_STABLE_REPO` when a separate
stable checkout exists and otherwise from the same repository, and honours `GROCY_AI_PHP` when the
required PHP is not the default `php`.

The browser release script runs the complete Chromium/WebKit matrix with Playwright retries disabled. It uses only the loopback fixture and deterministic route envelopes; it must not contact the household deployment or external providers.

The production timing file is intentionally empty until the physical-phone pass. After capturing the closed redacted records described in `01-PHONE-ACCEPTANCE.md`, enforce the locked p95 and exact timeout policy from the main repository:

```sh
python3 .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py
```

## SHA-pinned portable parity

The main, stable, and companion repositories have separate working directories and separate commits:

- main Grocy: `/Users/ian/Documents/Repos/grocy`, branch `atech-main`;
- stable Grocy: `/Users/ian/Documents/Repos/grocy-atech-release`, branch `atech-release`;
- companion: `/Users/ian/Documents/Repos/grocy-mcp`, its own repository and commit history.

Commit portable mirroring separately from stable framework adaptation. Record the resulting stable commit as a full 40-hex SHA, then compare without changing the main working tree:

```sh
custom/grocy_AI/tests/check-portable-parity.sh --stable-sha <recorded-40-hex-stable-commit>
```

The checker accepts no implicit or moving ref, reads stable blobs only through the supplied commit, and compares exactly the paths in `custom/grocy_AI/portable-files.txt`. It reports the controller, routes, product-form hook, stable cache marker, and stable customization record as documented Plan 01-09 adapters rather than byte-portable files. A missing or mismatched portable path exits nonzero.

## Stable deployment smoke

Perform this smoke only after the portable and adapter commits exist in `/Users/ian/Documents/Repos/grocy-atech-release` and the deploy record names the exact adapter commit and immutable image digest.

1. Confirm the stable working directory is on `atech-release`, its `HEAD` equals the recorded full SHA, and the main working directory remains on `atech-main`. Do not use the parity process to change either working directory.
2. Run `php custom/grocy_AI/tests/run.php` in the stable repository. Run the full companion suite in the companion repository. Run the SHA-pinned parity command from the main repository.
3. Confirm `custom/grocy_AI/version.json` was incremented for the route/view adaptation and is still copied by `Dockerfile.atech` over the deployed root `version.json`. This stable cache marker is independent from portable `module-version.json` and is required to invalidate persisted route/view caches.
4. Build and deploy only the recorded stable adapter commit. Record the immutable image digest and confirm the persistent `/etc/komodo/grocy` mount is unchanged before and after restart.
5. In an authenticated phone browser, load the product form and confirm the enrichment card renders above Picture, both ordinary Save actions remain enabled, and the status, enrichment, and selected-image GET routes respond through the deployed stable application.
6. Run one valid search/review flow and one each of offline, timeout, companion failure, provider failure, partial-image failure, Cancel, Retry, rotation, background/foreground, and Back. Confirm no automatic retry, stale result, hidden product/barcode/stock/file write, stuck spinner, modal, or disabled Search remains.
7. Select an existing name/image suggestion, verify that nothing is durable before normal Save, then exercise Save and reload through the explicit restoration spine in `01-PHONE-ACCEPTANCE.md`.
8. Copy the redacted diagnostic report and confirm it contains only versions, trace/outcome/stage enums, bounded timings, and deadline state. Never capture product/GTIN values, URLs, queries, headers, cookies, credentials, payloads, inventory data, image handles, or raw exceptions.
9. Complete the physical scenario checklist and timing JSONL. The stable release gate is green only when the full deterministic suites, SHA-pinned parity, stable smoke, privacy review, and production timing checker all pass.

## Next phase

Attach the reviewed transient barcode through Grocy's normal Save boundary and enforce canonical uniqueness only after the migration and rollback gates pass.
