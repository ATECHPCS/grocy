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
- `GET /api/grocy-ai/products/enrich/upc/{upc}`
- `GET /api/grocy-ai/images/{selection-token}`

All routes use Grocy authentication. UPC enrichment and selected-image retrieval also require the `MASTER_DATA_EDIT` permission.

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
  "media": [],
  "warnings": [],
  "diagnostics": {"trace_id": "4bf92f3577b34da6a3ce929d0e0e4736"}
}
```

Grocy preserves the raw response until a duplicate-aware lexical walk has rejected repeated member names at every object depth. It then validates exact version, members, enums, types, IDs, timestamps, targets, provenance, and unique IDs as one all-or-nothing boundary. Any malformed, unknown, duplicate, URL-bearing, nutrition, allergen, dietary, or medical content becomes the single redacted `contract_invalid` recovery state; no partial suggestion survives.

Contract v2 contains no external image URL or provider dictionary. Secure media is represented only by future short-lived opaque handles and authenticated same-origin routes. The first v2 slice renders a direct structured name suggestion beside the current value and visibly preselects it only when the current name is blank. Selection is transient and reversible; search and review make zero mutation calls, and Grocy's unchanged normal Save remains the sole persistence authority.

## Diagnostic and privacy contract

`custom/grocy_AI/module-version.json` is the portable source for the module version and diagnostic contract version. Grocy validates or replaces inbound W3C v00 `traceparent`, creates a fresh parent ID, and forwards only that rebuilt header to the companion. `tracestate` is ignored, and owned trace headers end at the companion rather than reaching external providers.

The product-form JavaScript and CSS query token is the grocy_AI `module_version`, not Grocy's core release version. Bump `module-version.json` whenever either custom asset changes and update the one `grocyAiAssetVersion` literal in `views/productform.blade.php` to the same value. The native contract suite enforces that both asset URLs use that matching module token and remain independent from core `$version`, preventing a stable browser cache from serving older custom bytes across deployments.

Grocy validates contract-v2 diagnostics as the single owned trace ID. The browser still creates its closed local diagnostic report without copying raw response fields. Diagnostics and status never contain GTINs, product or inventory values, service URLs, request/response headers, credentials, cookies, payload bodies, image handles, or raw exception text.

Enrichment and diagnostics remain authenticated GET/read operations. They do not write database rows, files, product data, barcodes, stock, or inventory state. Suggested names and selected images remain previews until the user invokes Grocy's existing Save workflow.

Run the standalone module checks with:

```sh
php custom/grocy_AI/tests/run.php
```

When `packages/autoload.php` is present, the native suite also compiles the complete product form and renders the custom asset-version fixture with Grocy's installed Blade engine. To point the same regression at an exact external Composer runtime, set `GROCY_BLADE_AUTOLOAD` to that runtime's `autoload.php`.

## Deterministic release gates

Run the main-repository contracts from `/Users/ian/Documents/Repos/grocy` on `atech-main`:

```sh
php custom/grocy_AI/tests/run.php
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

Add an optional create-product barcode handoff and broader structured field mappings after the review-before-save workflow is verified on desktop and mobile.
