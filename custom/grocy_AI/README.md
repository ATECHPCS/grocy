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
Setting('AI_REQUEST_TIMEOUT_SECONDS', 20);
```

The generated constants/environment names are:

- `GROCY_FEATURE_FLAG_GROCY_AI`
- `GROCY_AI_SERVICE_URL`
- `GROCY_AI_SERVICE_API_KEY`
- `GROCY_AI_REQUEST_TIMEOUT_SECONDS`

Do not commit the API key. The status route only reports whether one is configured.

For the current LAN deployment, the companion service URL is
`http://10.10.0.156:3061`; use one of the connector's existing inbound
`MCP_API_KEYS` values as `GROCY_AI_SERVICE_API_KEY`.

## Grocy routes

- `GET /api/grocy-ai/status`
- `GET /api/grocy-ai/products/enrich/upc/{upc}`
- `GET /api/grocy-ai/images/{selection-token}`

Both routes use Grocy authentication. UPC enrichment also requires the `MASTER_DATA_EDIT` permission.

## Companion-service contract

Grocy calls:

```text
GET {GROCY_AI_SERVICE_URL}/v1/products/enrich/upc/{upc}
Accept: application/json
X-API-Key: {GROCY_AI_SERVICE_API_KEY}   # only when configured
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
  "warnings": []
}
```

Only `http` and `https` image URLs are returned to the browser. Image downloads use short-lived opaque handles, so the browser cannot turn either server into an arbitrary URL fetcher. The UI renders values as text and applies nothing without a button click; the chosen values are persisted only through Grocy's normal Save action.

Run the standalone module checks with:

```sh
php custom/grocy_AI/tests/run.php
```

## Next phase

Add an optional create-product barcode handoff and broader structured field mappings after the review-before-save workflow is verified on desktop and mobile.
