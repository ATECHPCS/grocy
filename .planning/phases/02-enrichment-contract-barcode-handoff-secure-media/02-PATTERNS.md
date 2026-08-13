# Phase 2: Enrichment Contract, Barcode Handoff & Secure Media - Pattern Map

**Mapped:** 2026-08-13
**Files analyzed:** 28 new/modified file groups across main, stable, and companion checkouts
**Analogs found:** 26 / 28 (2 security/invariant units have only partial analogs)

## Scope Guardrails

- Preserve a strict versioned contract and reject an entire malformed response; do not extend the current permissive “drop a bad member and continue” normalization.
- Preserve the exact scanned barcode as a string, derive a canonical 14-digit comparison key only after GS1 validation, and resolve ownership before provider enrichment.
- Stage an unused barcode only in transient product-form state. Attach it exactly once in the continuation of Grocy's normal Save; search, preview, diff, media, cancellation, and failure paths remain write-free.
- Show current and suggested values side by side. Automatic selection is allowed only for a blank current value backed by direct structured evidence for the canonical barcode. Replacement of a non-empty value always requires an explicit selection.
- Re-read native controls before final diff and again before staging. A changed current value invalidates automatic selection and requires explicit review.
- Demand-load thumbnails and full media through authenticated same-origin Grocy routes. The browser contract and DOM must contain no external image URL; handles stay transient and short-lived.
- Keep Nutrition Facts, allergen, dietary, and medical fields out of the contract and UI. Phase 1 physical-phone timing evidence remains incomplete and must not be reclassified by Phase 2 tests.

## Repository Topology Corrections

The implementation paths in the live checkouts override illustrative paths in research:

| Concern | Use this existing path | Do not create/relocate to |
|---|---|---|
| Browser module | `public/custom/grocy_AI/product-enrichment.js` | `public/custom/grocy_AI/js/product-enrichment.js` |
| Browser styles | `public/custom/grocy_AI/grocy-ai.css` | `public/custom/grocy_AI/css/product-enrichment.css` |
| Browser tests | `custom/grocy_AI/tests/browser/` | repository-root `browser-tests/` |
| Companion package | `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/` | `/Users/ian/Documents/Repos/grocy-mcp/src/grocy_mcp/` |
| Stable mirror | `/Users/ian/Documents/Repos/grocy-atech-release/` | edits made by switching the main worktree |

`custom/grocy_AI/README.md:141-155` defines three separate working directories and a SHA-pinned parity process. Seven current portable files are byte-identical between main and stable; framework adapters remain separately committed.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `custom/grocy_AI/src/GrocyAiContract.php` | utility / contract validator | transform | `custom/grocy_AI/src/GrocyAiDiagnostic.php` | role-match; new all-or-nothing semantics |
| `custom/grocy_AI/src/GrocyAiBarcodeService.php` | service | request-response / read-only lookup | `custom/grocy_AI/src/GrocyAiService.php`; `services/StockService.php:824-842` | role/data-flow composite |
| `custom/grocy_AI/src/GrocyAiService.php` | service | request-response / bounded file I/O | itself | exact extension |
| `custom/grocy_AI/src/GrocyAiApiController.php` | controller | request-response / streaming bytes | itself | exact extension |
| `custom/grocy_AI/routes.php` | route/config | request-response | itself | exact extension |
| `public/custom/grocy_AI/product-enrichment.js` | component/controller | event-driven / request-response / transient file I/O | itself | exact extension |
| `public/custom/grocy_AI/grocy-ai.css` | component styling | responsive transform | itself | exact extension |
| `views/productform.blade.php` | component/view | request-response render | existing enrichment card in same file | exact extension |
| `public/viewjs/productform.js` | controller | CRUD continuation / file I/O | existing create/update + picture continuation in same file | exact extension |
| `migrations/0256.sql` (next ordered migration) | migration | batch / database invariant | `migrations/0128.sql`; `migrations/0103.sql` | role-match; canonical expression is new |
| `custom/grocy_AI/tests/run.php` | test harness | transform / mocked request-response | itself | exact extension |
| `custom/grocy_AI/tests/fixtures/*.json` | test fixtures | file I/O / transform | inline `companionBody()` fixtures in `tests/run.php` | role-match |
| `custom/grocy_AI/tests/barcode-handoff.php` | test harness | batch / temporary SQLite | `custom/grocy_AI/tests/run.php` | role-match |
| `custom/grocy_AI/tests/browser/fixtures/productform.html` | browser fixture | event-driven / route-counter simulation | itself | exact extension |
| `custom/grocy_AI/tests/browser/support/server.mjs` | test server | request-response / file I/O | itself | exact extension |
| `custom/grocy_AI/tests/browser/specs/{contract-review,barcode-handoff,secure-media,zero-write}.spec.js` | tests | event-driven / request-response / CRUD observation | `happy-path.spec.js`, `concurrency.spec.js`, `responsive-a11y.spec.js` | role/data-flow match |
| `custom/grocy_AI/README.md` | config/docs | release/parity | itself | exact extension |
| `custom/grocy_AI/portable-files.txt` | config/manifest | batch parity | itself | exact extension |
| `custom/grocy_AI/module-version.json` | config | cache/version handoff | itself | exact extension |
| `CUSTOMIZATIONS.md` | config/docs | release/parity | itself | exact extension |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py` | utility / contract producer | transform | `grocy_mcp/enrichment.py` | role-match |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py` | service/utility | streaming / bounded file I/O / SSRF validation | `grocy_mcp/images.py:458-509`; `grocy_mcp/server.py:2183-2244` | partial; new per-hop policy |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py` | service | request-response / provider aggregation | itself | exact extension |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py` | controller/route/provider | request-response / streaming bytes | itself, especially `2163-2263` | exact extension |
| `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py` | test | transform / mocked provider I/O | `tests/test_enrichment.py` | role-match |
| `/Users/ian/Documents/Repos/grocy-mcp/tests/test_secure_media.py` | test | streaming / mocked network I/O | `tests/test_http_api.py`; `tests/test_images.py` | role/data-flow match |
| Matching portable paths in `/Users/ian/Documents/Repos/grocy-atech-release/` | mirrored source | batch parity | same relative paths in main | exact byte-parity requirement |
| Stable-only controller/routes/view/save/migration/cache/customization adapters | adapters/config | request-response / release | stable Phase 1 adapters | role-match with framework differences |

## Pattern Assignments

### `custom/grocy_AI/src/GrocyAiContract.php` (utility, transform)

**Analog:** `custom/grocy_AI/src/GrocyAiDiagnostic.php`

**Closed constants pattern** (`GrocyAiDiagnostic.php:7-20`):

```php
public const OUTCOMES = ['success', 'partial_image', 'not_found', 'timeout', 'provider_error'];
public const STAGE_NAMES = ['grocy_connect', 'grocy_companion', 'federation', 'open_food_facts', 'image_search', 'image_fetch'];
private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/D';
private const MAX_OVERALL_DURATION_MS = 12000;
```

**Validation style** (`GrocyAiDiagnostic.php:180-202`):

```php
if (!is_array($value)
	|| !in_array($value['name'] ?? null, self::STAGE_NAMES, true)
	|| !in_array($value['status'] ?? null, self::STAGE_STATUSES, true)
	|| !in_array($value['cache'] ?? null, self::CACHE_STATUSES, true))
{
	return null;
}
```

Copy the closed constants, native types, tabs, next-line braces, and finite allowlists. Change the semantic outcome: the Phase 2 contract validator must throw a typed `contract_invalid` failure for unknown/missing keys, invalid enums/types/timestamps/targets, duplicate IDs, raw media URLs, or deferred nutrition members. It must not copy the `return null`/continue behavior.

**Service integration point:** replace `GrocyAiService.php:232-283` permissive `NormalizeResponse()` with delegation to this contract after JSON decode. Preserve the requested/scanned GTIN as authoritative input and do not accept a numeric barcode.

---

### `custom/grocy_AI/src/GrocyAiBarcodeService.php` (service, read-only lookup)

**Analogs:** `custom/grocy_AI/src/GrocyAiService.php:12-15,138-160`; `services/StockService.php:824-842`

**Injectable seam and GS1 string validation:**

```php
public function __construct(?callable $transport = null)
{
	$this->Transport = $transport;
}

public static function NormalizeUpc(string $barcode): string
{
	$upc = str_replace([' ', '-'], '', trim($barcode));
	// Existing length and check-digit validation follows.
}
```

**Parameterized owner lookup style:**

```php
$potentialProduct = $this->DB->product_barcodes()
	->where('barcode = :1 COLLATE NOCASE', $barcode)
	->fetch();
```

Use an optional lookup callable for deterministic tests, or a narrowly injected LessQL/PDO query boundary. Expose pure helpers for validated scan string and `str_pad($gtin, 14, '0', STR_PAD_LEFT)`. Query all stored numeric 8/12/13/14 barcodes by the exact same canonical expression used by the migration. Return only a trusted owner product ID/name and bounded equivalence display values. Perform no inserts.

---

### `custom/grocy_AI/src/GrocyAiService.php` (service, bounded companion/media I/O)

**Analog:** itself

**Imports and constructor** (`1-15`):

```php
namespace GrocyAI\Services;

use GuzzleHttp\Client;
use JsonException;

class GrocyAiService
{
	private $Transport;

	public function __construct(?callable $transport = null)
```

**Bounded request policy** (`209-229`):

```php
return [
	'timeout' => 12.0,
	'connect_timeout' => 2.0,
	// Never forward the optional API key or owned trace to a redirected host.
	'allow_redirects' => false,
	'on_stats' => static function ($stats) use (&$timing): void
```

Keep the injected transport, HTTP status translation, `JSON_THROW_ON_ERROR`, and no-redirect companion boundary. Delegate v2 payload validation to `GrocyAiContract`. Extend media retrieval with a closed `thumbnail|full` variant and validate token, byte length, exact MIME, signature, decoded dimensions, and total pixels before returning bytes. `FetchImage()` at `90-136` is the direct analog; add `getimagesizefromstring` after signature checks.

---

### `custom/grocy_AI/src/GrocyAiApiController.php` and `custom/grocy_AI/routes.php`

**Analogs:** the same files

**Permission and typed error mapping** (`GrocyAiApiController.php:20-66`):

```php
User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

try
{
	$result = (new GrocyAiService())->EnrichByUpc($args['upc'], $traceContext);
	return $this->DiagnosticResponse($response, $result);
}
catch (GrocyAiServiceException $ex)
{
	return $this->DiagnosticResponse(/* finite envelope/status */);
}
```

**Private/no-store media response** (`69-99`):

```php
return $response
	->withHeader('Cache-Control', 'private, no-store')
	->withHeader('Content-Type', $image['content_type'])
	->withHeader('Content-Disposition', 'inline; filename="grocy-ai-product.' . $extension . '"')
	->withHeader('X-Content-Type-Options', 'nosniff');
```

Resolve canonical ownership before calling providers. An owner-found result must suppress duplicate-creation suggestions and provide only a server-trusted route target. Keep every module route GET/read-only; normal object/file APIs remain the only write paths.

**Manual class loading and route group** (`routes.php:8-17`):

```php
require_once __DIR__ . '/src/GrocyAiDiagnostic.php';
require_once __DIR__ . '/src/GrocyAiService.php';
require_once __DIR__ . '/src/GrocyAiApiController.php';

$app->group('/api/grocy-ai', function (RouteCollectorProxy $group)
```

Add new contract/barcode `require_once` entries before dependent classes. Retain main/stable route middleware differences: main uses `CorsMiddleware` plus constructed `JsonMiddleware`; stable uses `->add(JsonMiddleware::class)`. The stable controller imports `Grocy\Controllers\BaseApiController`, while main imports `Grocy\Controllers\Api\BaseApiController`.

---

### `public/custom/grocy_AI/product-enrichment.js` (event-driven review controller)

**Analog:** itself

**Module shell and text-safe rendering** (`1-9,38-46`):

```javascript
(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root) return;

	function textElement(tag, className, value)
	{
		var element = document.createElement(tag);
		element.textContent = value;
		return element;
	}
```

**Current-intent ownership** (`398-415,658-703`):

```javascript
function isCurrent(request)
{
	return activeRequest === request
		&& request.sequence === requestSequence
		&& normalizeGtin(upcInput.value) === request.gtin;
}

if (activeRequest)
{
	if (activeRequest.gtin === validation.gtin) return;
	invalidateActiveRequest('replaced');
}
```

Keep the IIFE, explicit state restoration, 15-second browser deadline, coalescing, abort, sequence+GTIN stale guard, and named recovery states. Add one module state object containing validated immutable response data, current-value snapshots, selections, selection origin, staged barcode, media blobs/object URLs, and final-diff visibility. A new intent/cancel/lifecycle invalidation clears every Phase 2 transient and revokes object URLs.

**Native staging analog** (`465-514`): construct a `File` with `File` + `DataTransfer`, then dispatch a bubbling `change`. Phase 2 must move assignment to `productPictureInput.files` out of image selection and into `Stage selected changes`; image selection alone keeps the `File` only in module state.

**Do not copy:** `531-592` currently accepts `candidate.url`, builds external links, and assigns external `img.src`. Replace it with fixed same-origin demand-load controls and blob URLs. Remove `safeCandidateUrl()` entirely.

**Native dirty semantics:** `public/js/grocy.js:498-501` marks controls and forms dirty on bubbling `keyup paste change click`. Stage only selected live rows and dispatch the native event appropriate to each control. `public/viewjs/components/userfieldsform.js:15-25,70-71` saves only `.userfield-input.is-dirty`, so userfield staging must trigger change/dirty behavior; unselected rows must receive no event.

---

### `views/productform.blade.php` and `public/custom/grocy_AI/grocy-ai.css`

**Analogs:** the current feature-gated card and styles

**Feature-gated assets** (`productform.blade.php:5-16`):

```blade
@if(GROCY_FEATURE_FLAG_GROCY_AI)
@php
$grocyAiAssetVersion = '1.0.1';
@endphp
@push('pageStyles')
<link rel="stylesheet" href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
```

**Placement and native controls:** extend the existing card at `925-1041`, which already sits immediately above Picture (`1043-1086`). Reuse `#name`, `#product_group_id` (`371-383`), `#qu_id_stock` (`385-403`), managed product userfields, and `#product-picture` (`1052-1065`). Keep both normal Save buttons unchanged at `692-696`.

Server-render fixed structural shells and localized strings/data attributes; render provider values only through JS `textContent`. Use native Bootstrap custom checkboxes with stable `id`/`for`/ARIA links.

**Responsive style tokens** (`grocy-ai.css:21-30,118-145,153-174`):

```css
.grocy-ai-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 16px;
}

.grocy-ai-actions .btn {
	min-height: 44px;
}
```

Extend this file with 16px row gaps, 24px section gaps, a two-column `grid-template-columns: repeat(2, minmax(0, 1fr))` comparison/diff grid that remains side by side at 320px, 8px phone cell padding, wrapping via `overflow-wrap: anywhere`, trust/selection non-color cues, night-mode overrides, and reduced-motion behavior. Replace current image-source ellipsis (`147-151`) because decision-critical provenance must wrap.

---

### `public/viewjs/productform.js` (normal-Save continuation)

**Analog:** existing create/update/picture/userfield continuation

**Existing continuation spine** (`1-78,81-140`):

```javascript
function saveProductPicture(result, location, jsonData)
{
	var productId = Grocy.EditObjectId || result.created_object_id;
	Grocy.EditObjectId = productId;

	Grocy.Components.UserfieldsForm.Save(() =>
	{
		// Picture upload, then redirect.
	});
}

Grocy.Api.Post('objects/products', jsonData,
	(result) => saveProductPicture(result, location, jsonData),
	/* error */);
```

Insert a narrow `GrocyAI` continuation after a product ID exists and before redirect. It should: obtain the transient staged scan from the custom module; re-resolve ownership; POST one `objects/product_barcodes` row only when unused; treat same-product ownership as idempotent success; and surface other-owner/insert-race failure without repeating `objects/products`.

**Barcode write analog:** `public/viewjs/productbarcodeform.js:15-38` builds JSON and POSTs `objects/product_barcodes`, but it writes immediately. Copy only the payload/API/error pattern into the normal-Save continuation; never call/click this modal from preview.

Keep a created product ID after a partial barcode failure and provide a barcode-only retry. Do not allow retry to re-enter the product create POST.

---

### `migrations/0256.sql` (canonical uniqueness invariant)

**Analog:** `migrations/0128.sql:17-19`

```sql
CREATE UNIQUE INDEX ix_product_barcodes ON product_barcodes (
	barcode
);
```

The existing index proves the ordered SQL/index convention but is exact-text only. The Phase 2 migration must add a unique expression index over supported numeric lengths using the same canonical-14 expression as server lookup. Unlike `0128.sql:1-15`, it must not delete duplicates. A read-only preflight groups by the expression and blocks with an actionable human-resolution report if collisions exist.

Use `0256.sql` because both main and stable currently end at `0255.sql`. Mirror the migration to stable as a framework adapter/schema change, and test the exact SQL against a temporary database before deployment.

---

### PHP and browser test files

**PHP harness analog:** `custom/grocy_AI/tests/run.php:20-52,255-300,377-383`

```php
function check(bool $condition, string $message): void
{
	global $failures, $tests;
	$tests++;
	if (!$condition)
	{
		$failures++;
		fwrite(STDERR, "FAIL: {$message}\n");
	}
}
```

Extend manual `require_once` loading for the contract/barcode units. Use injected callables and table-driven adversarial fixtures. For `barcode-handoff.php`, create an isolated temporary SQLite database, apply the exact migration expression, assert equivalent conflicts and arbitrary non-GTIN coexistence, and clean up the temporary file. Never point tests at runtime `data/grocy.db`.

**Browser route-counter fixture** (`fixtures/productform.html:123-135,206-220,268-275`):

```javascript
window.__fixtureCounters = {
	apiCalls: [], enrichment: 0, product: 0, barcode: 0,
	stock: 0, file: 0, save: 0, objectUrlsCreated: 0, objectUrlsRevoked: 0
};

['Post', 'Put', 'Delete', 'UploadFile', 'DeleteFile'].forEach(function (methodName)
{
	window.Grocy.Api[methodName] = function (apiFunction)
	{
		classifyApiCall(methodName.toUpperCase(), '/api/' + apiFunction);
		throw new Error('Persistence is intentionally unavailable in the browser fixture');
	};
});
```

Extend the fixture with native current fields/options/userfields, staged barcode state, safe same-origin media endpoints, a controllable normal-Save path, and counters for category/conversion/userfield mutations. Preserve the default trap for all pre-Save tests.

**Playwright analogs:**

- `happy-path.spec.js:57-152` records network requests, preserves manual values, asserts Save controls stay enabled, and checks zero mutation counters.
- `concurrency.spec.js:34-109` holds requests to prove duplicate coalescing and stale-result suppression.
- `responsive-a11y.spec.js:39-85` loops 320/375/390/768 widths and asserts 44px targets/no overflow.

New specs should keep these patterns and add requirement tags `@enr01` through `@enr09`, final-diff focus/ARIA checks, blank-only preselection, explicit replacement, current-value staleness, owner routing, exactly-one barcode insert after normal Save, partial-save barcode-only retry, same-origin-only DOM URLs, demand-load request counts, handle expiry, and object-URL revocation.

---

### Companion `enrichment_contract.py` and `enrichment.py`

**Analog:** `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py`

**Read-only producer boundary** (`1-7,59-70`):

```python
"""Read-only product enrichment contract for the grocy_AI Grocy module.
Nothing in this module reads or writes Grocy itself.
"""

async def enrich_upc(barcode: str, *, image_limit: int = 6, ...) -> dict[str, Any]:
    upc = normalize_upc(barcode)
```

**Structured-first ordering analog** (`155-165,241-259`): exact Open Food Facts image is appended first; SearXNG candidates are appended later with an explicit review-only warning. Replace floating `match_confidence` trust decisions with closed provenance fields. Force search evidence to `confidence_band: unverified`, `evidence_kind: search`, and never mark it auto-selectable.

Build the complete v2 envelope in `enrichment_contract.py`; `enrichment.py` supplies normalized facts/evidence to it. Keep provider aggregation under the current 10.5-second outer deadline and no Grocy writes. Exclude all deferred nutrition-related keys.

---

### Companion `secure_media.py` and `server.py`

**Analogs:** `grocy_mcp/server.py:58-74,2183-2244`; `grocy_mcp/images.py:458-509`

**Opaque bounded handle store** (`server.py:58-63,2183-2212`):

```python
_IMAGE_SELECTION_TTL_SECONDS = 15 * 60
_IMAGE_SELECTION_LIMIT = 512
_image_selections: OrderedDict[str, tuple[float, dict[str, Any]]] = OrderedDict()

token = secrets.token_urlsafe(24)
_image_selections[token] = (now + _IMAGE_SELECTION_TTL_SECONDS, {...})
```

**Validated no-store response** (`2215-2244`): check expiry, fetch server-held target data, reject unsupported raster signatures, and return `Cache-Control: private, no-store` plus `nosniff`.

Move URL state and fetch policy into `secure_media.py`. Extend handles to bind candidate ID and closed `thumbnail|full` variant. Parse HTTP/HTTPS URLs structurally, forbid userinfo/fragments/disallowed ports, resolve and reject private/loopback/link-local/multicast/unspecified/reserved/metadata IPs, and revalidate every bounded redirect with no HTTPS downgrade. Stream with independent timeouts and abort at the byte cap. Current `images.py:476-503` uses `follow_redirects=True` and `resp.content`; treat that as the anti-pattern to replace, not the implementation to copy.

Keep `server.py` as the authenticated Starlette adapter (`2163-2180,2247-2263`) and return only opaque handles/provenance in enrichment JSON—no `url` or `thumbnail_url` fields.

---

### Companion tests

**Analogs:** `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py`; `tests/test_http_api.py`

Use `unittest.IsolatedAsyncioTestCase`, `AsyncMock`, `patch`, and `subTest` as in `test_enrichment.py:11-30`. Preserve structured-first assertions from `32-93`, but assert closed evidence/provenance and unverified search rather than numeric score trust.

Use a minimal Starlette `TestClient` with API-key middleware as in `test_http_api.py:17-38`. Preserve auth-before-work (`40-48`), opaque-handle inspection (`81-109`), validated raster response (`111-129`), unknown/expired handle (`131-138`), and active-content rejection (`140-155`). Add URL/IP/redirect/stream/MIME/signature/dimension/wrong-variant/replay adversarial cases without network access.

---

### Release, manifest, and stable parity files

**Analogs:** `custom/grocy_AI/README.md:94-104,141-169`; `portable-files.txt`; `module-version.json`; `CUSTOMIZATIONS.md:19-25`

- Bump `module-version.json` whenever portable JS/CSS changes and keep the Blade asset literal identical; `tests/run.php:54-59` enforces this.
- Add new portable PHP classes and deterministic fixtures/tests that must be byte-identical to `portable-files.txt`. Do not add framework-specific controller/routes/migration/cache-marker files to the byte-portable list.
- Mirror all portable paths byte-for-byte into `/Users/ian/Documents/Repos/grocy-atech-release/` in a separate commit.
- Adapt controller import and route middleware separately for stable. Record the new product-form Save hook, migration, controller/routes, stable cache marker, and every unavoidable upstream edit in stable `CUSTOMIZATIONS.md`.
- Update README contract examples to v2 and remove the current statement that browser responses contain HTTP/HTTPS image URLs (`README.md:94`). Document demand-loaded variants, no raw URLs, canonical ownership, normal-Save-only barcode attachment, and partial-save retry.

## Shared Patterns

### Authentication and response boundaries

**Source:** `custom/grocy_AI/src/GrocyAiApiController.php:20-99`

Apply `MASTER_DATA_EDIT` before owner lookup, enrichment, and media redemption. Use `ApiResponse()` for JSON, finite diagnostic envelopes for integration failures, and generic media errors. Never expose raw exception/provider/URL/token data.

### External-data handling

**Sources:** `GrocyAiDiagnostic.php:7-20,180-202`; `product-enrichment.js:38-46`

Use closed allowlists at the PHP trust boundary and `textContent`/fixed DOM templates in the browser. For the Phase 2 DTO, malformed external data rejects the whole envelope.

### Zero-write review and normal Save

**Sources:** `product-enrichment.js:398-415,658-703`; `productform.js:1-140`; browser fixture counters at `productform.html:206-220`

Search/review/media routes remain GET-only. Selection lives in module state. Final confirmation stages native controls but performs no API call. Product, userfield, image upload, and exactly-one barcode insert occur only after a normal Save button is pressed.

### Portable/stable split

**Source:** `custom/grocy_AI/README.md:141-163`

Portable module files are byte-identical; framework adapters are separate. Do not switch either checkout during parity checks. Keep the stable controller namespace/middleware differences intact.

## No Exact Analog Found

| File | Role | Data Flow | Reason / Planner Direction |
|---|---|---|---|
| `migrations/0256.sql` canonical expression index and collision preflight | migration | batch / invariant | Existing migrations provide index syntax but none enforce canonical GTIN equivalence without deleting collisions. Use the exact research expression and require a human checkpoint for any preflight result. |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py` | security service | streaming / SSRF-safe file I/O | Current fetch follows redirects and buffers whole bodies. Use the Phase 2 research policy as authoritative; current code is only an adapter/handle/error-shape analog. |

## Planner Checkpoints

1. Inventory deployed product userfields on main/stable before locking brand/package-size targets. Do not create fields as a search side effect.
2. Keep food type visible but non-stageable when no active local destination exists; do not pull Phase 3 taxonomy ownership into this phase.
3. Run the canonical collision audit before applying the migration. Any row returned blocks deployment for human resolution; never delete/reassign automatically.
4. Lock media redirect/dimension/pixel bounds from fixtures or retain the conservative research defaults.
5. Record the deployed companion dependency set before changing HTTP streaming/redirect behavior.
6. Keep Phase 1 physical timing/phone acceptance explicitly incomplete in Phase 2 evidence.

## Metadata

**Analog search scope:** `custom/grocy_AI/`, `public/custom/grocy_AI/`, `views/productform.blade.php`, `public/viewjs/`, `public/js/grocy.js`, `services/`, `migrations/`, `/Users/ian/Documents/Repos/grocy-atech-release/`, `/Users/ian/Documents/Repos/grocy-mcp/`

**Strong analogs stopped at:** current module PHP/JS/view/style/test spine; Grocy product Save/barcode/userfield paths; companion enrichment/handle/media/test spine; stable adapter/parity split.

**Pattern extraction date:** 2026-08-13
