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

Expected response:

```json
{
  "found": true,
  "upc": "012345678905",
  "product": {
    "name": "Example product",
    "brand": "Example brand",
    "size": "12 oz"
  },
  "images": [
    {
      "url": "https://images.example/product-front.png",
      "download_token": "short-lived-opaque-handle",
      "source": "openfoodfacts",
      "score": 100,
      "match_confidence": 1
    }
  ],
  "sources": ["openfoodfacts", "searxng"],
  "warnings": [],
  "outcome": "success",
  "diagnostics": {
    "schema_version": 1,
    "contract_version": "1",
    "companion_version": "0.1.0",
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "outcome": "success",
    "stages": [],
    "overall_duration_ms": 25
  }
}
```

Only `http` and `https` image URLs are returned to the browser. Image downloads use short-lived opaque handles, so the browser cannot turn either server into an arbitrary URL fetcher. The UI renders values as text and applies nothing without a button click; the chosen values are persisted only through Grocy's normal Save action.

## Diagnostic and privacy contract

`custom/grocy_AI/module-version.json` is the portable source for the module version and diagnostic contract version. Grocy validates or replaces inbound W3C v00 `traceparent`, creates a fresh parent ID, and forwards only that rebuilt header to the companion. `tracestate` is ignored, and owned trace headers end at the companion rather than reaching external providers.

Grocy rebuilds diagnostics field-by-field. The v1 browser envelope contains only schema/version values, the trace ID, a finite outcome, allowlisted stages, and bounded or nullable millisecond durations. The supplementary `Server-Timing` header contains only allowlisted metric names and durations. Diagnostics and status never contain GTINs, product or inventory values, service URLs, request/response headers, credentials, cookies, payload bodies, image handles, or raw exception text.

Enrichment and diagnostics remain authenticated GET/read operations. They do not write database rows, files, product data, barcodes, stock, or inventory state. Suggested names and selected images remain previews until the user invokes Grocy's existing Save workflow.

Run the standalone module checks with:

```sh
php custom/grocy_AI/tests/run.php
```

## Next phase

Add an optional create-product barcode handoff and broader structured field mappings after the review-before-save workflow is verified on desktop and mobile.
