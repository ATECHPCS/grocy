# Phase 01: Safety Baseline & Mobile Diagnostics - Pattern Map

**Mapped:** 2026-08-12
**Primary branch:** `atech-main`
**Stable adaptation branch:** `atech-release`
**Repositories searched:** `ATECHPCS/grocy`, `/Users/ian/Documents/Repos/grocy-mcp`
**Files classified:** 38 implementation, test, evidence, and stable-adaptation targets
**Strong analog families:** 5

## Scope Guardrails

- Enrichment, retry, cancel, diagnostics, and image selection remain read/preview operations. The only durable write path is the existing Grocy product form Save flow in `public/viewjs/productform.js:81-111`.
- Do not add product, barcode, stock, conversion, upload, or save requests to the enrichment state machine. Selecting an image may populate `#product-picture`; upload still occurs only from normal Save (`public/viewjs/productform.js:1-47`, `96-111`).
- Portable implementation belongs on `atech-main` under `custom/grocy_AI/` and `public/custom/grocy_AI/`. Mirror those portable files to `atech-release`, then adapt only the stable branch's controller/route framework differences.
- Keep the portable diagnostic/module version separate from the stable deployment cache marker. The stable marker is copied over Grocy's root `version.json` at image build time and must still be bumped when route/view integration changes.
- Browser, Grocy, and companion may share validated trace context. Provider calls are timed but must not receive `traceparent`, `tracestate`, Grocy credentials, or diagnostic payloads.

## File Classification

Legend: **exact** = same role and flow; **role-match** = same role but missing part of the new contract; **partial** = useful local convention only; **none** = use the phase research/UI contract.

| New/Modified File | Role | Data Flow | Closest Existing Analog | Match |
|---|---|---|---|---|
| `custom/grocy_AI/module-version.json` | config | file-I/O | `atech-release:custom/grocy_AI/version.json` | role-match |
| `custom/grocy_AI/src/GrocyAiDiagnostic.php` | utility / DTO | transform | `custom/grocy_AI/src/GrocyAiService.php:176-262` | role-match |
| `custom/grocy_AI/src/GrocyAiService.php` | service | request-response | same file, especially `:12-15`, `:30-78`, `:139-214` | exact |
| `custom/grocy_AI/src/GrocyAiApiController.php` | controller | request-response | same file `:18-70` | exact |
| `custom/grocy_AI/routes.php` | route / config | request-response | same file `:1-16` | exact |
| `custom/grocy_AI/tests/run.php` | test | request-response + transform | same file `:14-115` | exact |
| `public/custom/grocy_AI/product-enrichment.js` | component / state machine | event-driven + request-response | same file `:1-42`, `:80-134`, `:226-270` | exact |
| `public/custom/grocy_AI/grocy-ai.css` | component styling | transform | same file `:1-43` | exact |
| `views/productform.blade.php` | component / upstream hook | request-response | same file `:5-13`, `:922-975` | exact |
| `custom/grocy_AI/README.md` | documentation | file-I/O | same file and `CUSTOMIZATIONS.md:12-25` | exact |
| `custom/grocy_AI/tests/browser/package.json` | test config | batch | root `package.json:1-40` | partial |
| `custom/grocy_AI/tests/browser/package-lock.json` | generated config | batch | no in-repository lockfile analog | none |
| `custom/grocy_AI/tests/browser/playwright.config.js` | test config | batch | no Playwright analog | none |
| `custom/grocy_AI/tests/browser/fixtures/productform.html` | test fixture | event-driven | `views/productform.blade.php:922-975` + `public/viewjs/productform.js:81-111` | role-match |
| `custom/grocy_AI/tests/browser/support/server.mjs` | test utility | request-response + file-I/O | no Node server analog | none |
| `custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js` | test | event-driven | `custom/grocy_AI/tests/run.php:41-44` | role-match |
| `custom/grocy_AI/tests/browser/specs/states.spec.js` | test | request-response | `custom/grocy_AI/tests/run.php:46-107` | role-match |
| `custom/grocy_AI/tests/browser/specs/concurrency.spec.js` | test | event-driven | no browser concurrency analog | none |
| `custom/grocy_AI/tests/browser/specs/diagnostics.spec.js` | test | transform | `custom/grocy_AI/tests/run.php:86-88` | partial |
| `custom/grocy_AI/tests/browser/specs/preservation.spec.js` | test | event-driven + zero-write | `public/viewjs/productform.js:81-111` | role-match |
| `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js` | test | event-driven | no automated browser analog | none |
| `.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md` | test evidence | batch | no close analog | none |
| `.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/phone-timings.jsonl` | test evidence | streaming | no close analog | none |
| `.planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py` (inferred name) | test utility | batch + transform | `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py` | partial |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/diagnostics.py` | utility / typed DTO | transform | `grocy_mcp/enrichment.py:21-39`, `:58-68`, `:145-165` | role-match |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py` | service / orchestrator | request-response | same file `:58-165` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/lookup.py` | provider service | pub-sub / request-response | same file `:101-216` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/images.py` | provider service | request-response | same file `:202-252`, `:380-415` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py` | controller / provider | request-response | same file `:1016-1029`, `:2158-2208` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py` | test | request-response | same file `:9-141` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/tests/test_http_api.py` | test | request-response | same file `:17-177` | exact |
| `/Users/ian/Documents/Repos/grocy-mcp/tests/test_diagnostics.py` | test | transform + request-response | `tests/test_enrichment.py` + `tests/test_http_api.py` | role-match |
| `[atech-release] custom/grocy_AI/src/GrocyAiApiController.php` | controller adaptation | request-response | stable same file `:1-72` | exact |
| `[atech-release] custom/grocy_AI/routes.php` | route adaptation | request-response | stable same file `:1-15` | exact |
| `[atech-release] custom/grocy_AI/version.json` | deployment cache marker | file-I/O | stable same file `:1-5` | exact |
| `[atech-release] Dockerfile.atech` | deployment config | file-I/O / batch | stable same file `:1-13` | exact |
| `[atech-release] routes.php` | integration hook / verification | request-response | stable same file `:247-250` | exact |
| `[atech-release] CUSTOMIZATIONS.md` | documentation | file-I/O | stable same file `:5-30` | exact |

The inferred checker filename is deliberately called out: the contracts require a checked-in nearest-rank checker but do not lock its path. The planner may rename it, provided it remains beside the redacted evidence and is invoked by the phase gate.

## Pattern Assignments

### 1. Browser state machine, cancellable XHR, camera handoff, and Save preservation

**Apply to:**

- `public/custom/grocy_AI/product-enrichment.js`
- `views/productform.blade.php`
- `public/custom/grocy_AI/grocy-ai.css`
- browser fixture and all browser specs

**Primary analog:** `public/custom/grocy_AI/product-enrichment.js`

Copy the module boundary and safe text-rendering style (`:1-27`):

```javascript
(function ()
{
	'use strict';

	var root = document.getElementById('grocy-ai-product-enrichment');
	if (!root)
	{
		return;
	}

	function textElement(tag, className, value)
	{
		var element = document.createElement(tag);
		// ...
		element.textContent = value;
		return element;
	}
```

Copy the direct-XHR ownership and explicit control restoration from the selected-image request (`:88-134`), not the non-cancellable `Grocy.Api.Get` call currently used by search (`:240-259`):

```javascript
var xhr = new XMLHttpRequest();
xhr.open('GET', U('/api/grocy-ai/images/' + encodeURIComponent(candidate.download_token)), true);
xhr.responseType = 'blob';
xhr.onload = function ()
{
	button.disabled = false;
	button.textContent = 'Use as product picture';
	// Validate before attaching to the existing form input.
};
xhr.onerror = function ()
{
	button.disabled = false;
	button.textContent = 'Use as product picture';
};
xhr.send();
```

For enrichment, extend this local XHR pattern with `timeout = 15000`, `ontimeout`, `onabort`, one monotonic intent token, normalized-GTIN guards in every callback, and a returned/stored XHR so Cancel/input changes/page lifecycle can abort it. Invalidate the token **before** calling `abort()` so the abort callback cannot overwrite a replacement request. Do not modify shared `Grocy.Api.Get`; it creates a private XHR and returns nothing (`public/js/grocy.js:2-37`).

Copy the existing camera event boundary from `public/viewjs/components/camerabarcodescanner.js:73-89`:

```javascript
Grocy.Components.CameraBarcodeScanner.StopScanning();
$(document).trigger("Grocy.BarcodeScanned", [result.getText(), Grocy.Components.CameraBarcodeScanner.CurrentTarget]);
$(".modal").last().modal("hide");
```

The scanner's Bootbox `onHide` always stops the camera (`public/viewjs/components/camerabarcodescanner.js:154-179`). The enrichment module should subscribe to `Grocy.BarcodeScanned` for its target and feed the decoded string through the exact same pure validator and intent path as manual entry.

Preserve the current image-selection behavior (`product-enrichment.js:103-121`): populate the existing `File`/`DataTransfer`, dispatch `change`, and do not upload. Normal Save owns upload and persistence:

```javascript
var file = new File([xhr.response], fileName, { type: xhr.response.type });
var transfer = new DataTransfer();
transfer.items.add(file);
productPictureInput.files = transfer.files;
productPictureInput.dispatchEvent(new Event('change', { bubbles: true }));
```

**Product form hook:** retain the feature-gated asset pushes (`views/productform.blade.php:5-13`) and the single card immediately above Picture (`:922-975`). Extend its existing IDs/markup rather than adding a second card or form. The fixture must include representative `#name`, `#product-picture`, `.save-product-button`, and both Save controls, but it must not implement a fake persistence path.

**CSS convention:** extend `public/custom/grocy_AI/grocy-ai.css`; keep the existing `.grocy-ai-*` prefix and Bootstrap semantic classes. Replace current one-off gaps only where changed by the UI contract's 4/8/16/24/32/48/64px tokens. Add `<576px` touch rules, 44px targets, wrapping/no-overflow, reduced-motion, and night-mode equivalents only when Bootstrap/Grocy inheritance is insufficient.

### 2. Grocy controller/service/diagnostic boundary

**Apply to:**

- `custom/grocy_AI/src/GrocyAiDiagnostic.php`
- `custom/grocy_AI/src/GrocyAiService.php`
- `custom/grocy_AI/src/GrocyAiApiController.php`
- `custom/grocy_AI/routes.php`
- `custom/grocy_AI/module-version.json`
- `custom/grocy_AI/tests/run.php`

**Primary analog:** `custom/grocy_AI/src/GrocyAiService.php`

Preserve constructor-injected transport as the deterministic seam (`:8-15`):

```php
class GrocyAiService
{
	private $Transport;

	public function __construct(?callable $transport = null)
	{
		$this->Transport = $transport;
	}
```

Evolve the callable signature to receive the request URL, safe headers, and an options/budget structure (or equivalent explicit arguments) so tests can assert total timeout `12`, connect timeout `2`, trace propagation, and transfer timing without loading Guzzle. Keep the production request protections from `:152-173`:

```php
$response = $client->request('GET', $url, [
	'headers' => $headers,
	'http_errors' => false,
	'timeout' => $this->GetTimeout(),
	'connect_timeout' => min(5, $this->GetTimeout()),
	'allow_redirects' => false
]);
```

Change the concrete values to the locked 12s/2s budgets and add `on_stats`; extract only bounded milliseconds/status enums from transfer stats. Never serialize the request URI, headers, API key, raw exception, response body, or image token into diagnostics.

Use the service's existing allowlist normalization style (`:176-214`) for the closed response/diagnostic DTO: inspect types, copy named fields, cap list sizes, and discard everything else. `GrocyAiDiagnostic.php` should own strict v00 trace parsing/generation, finite outcome/stage/error enums, non-negative bounded millisecond coercion, and final redaction. It should not know about product persistence or the database.

**Controller boundary:** preserve permission-first execution and typed exception mapping (`GrocyAiApiController.php:18-38`):

```php
User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

try
{
	return $this->ApiResponse($response, (new GrocyAiService())->EnrichByUpc($args['upc']));
}
catch (\InvalidArgumentException $ex)
{
	return $this->GenericErrorResponse($response, $ex->getMessage(), 400);
}
catch (\LogicException $ex)
{
	return $this->GenericErrorResponse($response, $ex->getMessage(), 503);
}
catch (\RuntimeException $ex)
{
	return $this->GenericErrorResponse($response, $ex->getMessage(), 502);
}
```

For Phase 1, map those categories to a finite safe envelope instead of returning raw exception messages. Read the inbound `traceparent`, validate/replace it in the diagnostic helper, and pass only the validated owned-boundary header to the companion. Preserve `MASTER_DATA_EDIT` for enrichment and selected-image routes.

**Routes:** follow `custom/grocy_AI/routes.php:8-16`: explicitly `require_once` every custom class and keep endpoints within the existing `/api/grocy-ai` group and middleware chain. Do not add write verbs.

**Version source:** model portable `module-version.json` as a tiny committed JSON manifest, but do not copy the stable marker's Grocy release fields as diagnostic semantics. Give the module and diagnostic contract their own explicit versions and load them through one helper with a safe fallback.

### 3. Native PHP contract testing

**Apply to:** `custom/grocy_AI/tests/run.php`

**Analog:** same file.

Reuse the zero-dependency counter/assertion style (`:14-39`):

```php
$failures = 0;
$tests = 0;

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

Reuse closure capture for transport assertions (`:46-78`):

```php
$captured = [];
$service = new GrocyAiService(function (string $url, array $headers, int $timeout) use (&$captured): array
{
	$captured = compact('url', 'headers', 'timeout');
	return ['status' => 200, 'body' => json_encode(/* fixture */)];
});
```

Extend this harness with shared valid/invalid GTIN vectors, checksum cases, strict trace accept/replace tests, 12s/2s option assertions, transfer-timing normalization, every finite error/outcome enum, and forbidden privacy canaries. Keep the existing final aggregate exit (`:109-115`) so one command runs all contract checks.

### 4. Companion provider outcomes, timing, and HTTP boundary

**Apply to:**

- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/diagnostics.py`
- `grocy_mcp/enrichment.py`, `lookup.py`, `images.py`, `server.py`
- `tests/test_enrichment.py`, `test_http_api.py`, `test_diagnostics.py`

**Primary analog:** `grocy_mcp/enrichment.py` for orchestration and `grocy_mcp/lookup.py` for concurrent provider calls.

Keep provider orchestration in the companion, not the Starlette controller. Current `enrich_upc` normalizes first, invokes lookup, optionally invokes images, and builds one stable response (`enrichment.py:58-68`, `:93-108`, `:145-165`). Preserve that boundary, but replace ambiguous empty/error dicts with typed finite provider results carrying `status`, `error_code`, `cache`, and `duration_ms`.

Current concurrent metadata calls are the closest analog (`lookup.py:168-185`):

```python
async with httpx.AsyncClient(
    timeout=timeout, headers={"User-Agent": USER_AGENT}, follow_redirects=True
) as client:
    federation_names, off = await asyncio.gather(
        _lookup_federation(client, barcode),
        _lookup_off(client, barcode),
    )
```

Do not preserve the current information-losing behavior where `_lookup_federation` and `_lookup_off` both return empty values for non-200, malformed JSON, and transport failure (`lookup.py:101-138`). The new result type must distinguish at least success, not-found, timeout, provider-error, and malformed-response so aggregation cannot mislabel provider failure as not-found.

Use one outer `asyncio.timeout(...)` in `enrichment.py`; configure `httpx.Timeout` with the locked connect/read budgets in provider helpers. Time stages with an injected/default monotonic clock and clamp/round at DTO construction. Do not add provider durations together to derive wall-clock time when providers run concurrently.

The Starlette boundary should retain its small safe mapping (`server.py:2162-2176`):

```python
try:
    result = await enrich_upc(request.path_params.get("upc", ""))
except ValueError as exc:
    return JSONResponse({"error_message": str(exc)}, status_code=400)
except Exception:
    return JSONResponse(
        {"error_message": "Product enrichment failed"}, status_code=502
    )
return JSONResponse(result)
```

Validate/replace owned trace context at this boundary, include the closed diagnostic DTO in successful/finite failure envelopes, and never forward the trace headers to federation, Open Food Facts, SearXNG, or image hosts. Preserve `APIKeyMiddleware` (`server.py:1016-1029`) and its generic 401 response; no diagnostic output may contain the configured inbound key/header.

Reuse the existing package version rather than inventing a second companion version source:

```python
# grocy_mcp/__init__.py:1-3
"""Grocy MCP server package."""

__version__ = "0.1.0"
```

**Testing convention:** use `unittest.IsolatedAsyncioTestCase`, `AsyncMock`, and `patch` as in `tests/test_enrichment.py:3-18`, and Starlette `TestClient` with an in-memory route/middleware assembly as in `tests/test_http_api.py:17-38`. Assert calls and exact envelopes, not implementation logs. Add a fake-clock seam for duration/deadline tests and assert provider requests receive no `traceparent`/`tracestate`.

### 5. Playwright harness and physical evidence

**Apply to:** all files below `custom/grocy_AI/tests/browser/` and the phase acceptance/evidence files.

There is no existing Playwright, Node static-server, mobile-emulation, JSONL evidence, or percentile-checker analog in either repository. Use the approved `01-UI-SPEC.md` and `01-RESEARCH.md` contracts as source of truth, while borrowing only these local conventions:

- Isolate browser dependencies in `custom/grocy_AI/tests/browser/package.json`; do not add Playwright to Grocy's production `package.json`. Set `private: true` like root `package.json:1-4`. Generate `package-lock.json` with npm; do not hand-edit it.
- The fixture must load the real `public/custom/grocy_AI/product-enrichment.js` and `grocy-ai.css`, and mirror only the existing product form hook/controls needed to prove preservation.
- The Node server should use built-in modules, bind to loopback, resolve files against explicit allowlisted roots, reject traversal, and expose deterministic startup/shutdown to Playwright.
- Configure exactly `chromium-mobile` and `webkit-mobile`; run functional cases at 390px and layout/a11y checks at 320, 375, 390, and 768px.
- Use `page.route` for success, malformed, delayed, abort, not-found, provider-error, and partial-image envelopes; use Playwright Clock for the exact 15s deadline and context offline mode for the offline path. Do not use live providers in the deterministic suite.
- Every state/concurrency test asserts request counts and current rendered state. Preservation tests also register counters for product/barcode/stock/file/save endpoints and assert zero calls.
- Diagnostics tests seed forbidden canaries into raw responses and assert absence from DOM, clipboard/fallback textarea, console, and copied JSON.

`01-PHONE-ACCEPTANCE.md` is the human procedure and sign-off; `evidence/phone-timings.jsonl` contains one redacted sample per line. The checker must use deterministic nearest-rank p50/p95, enforce the locked thresholds without silently re-baselining, reject forbidden product/GTIN fields, and exit nonzero on invalid schema, insufficient sample counts, or threshold failure.

## Stable `atech-release` Adaptation Map

Portable files should be transferred semantically from `atech-main`; do not replace the stable branch with main-branch framework code wholesale.

| Concern | Stable Analog | Required Adaptation |
|---|---|---|
| Controller base class | `atech-release:custom/grocy_AI/src/GrocyAiApiController.php:5-11` | Keep `Grocy\Controllers\BaseApiController`; main uses `Grocy\Controllers\Api\BaseApiController`. Port method bodies/contracts only. |
| Module middleware | `atech-release:custom/grocy_AI/routes.php:3-15` | Keep class-based `JsonMiddleware::class`; do not copy main's container-based `CorsMiddleware`/`JsonMiddleware` construction. Add the diagnostic helper `require_once` in stable syntax. |
| Root integration hook | `atech-release:routes.php:247-250` | Preserve conditional `require_once`; verify cached routes expose the existing GET endpoints after deployment. |
| Portable assets | `custom/grocy_AI/src/GrocyAiService.php`, tests, public JS/CSS, module manifest | Require content parity unless a documented stable framework incompatibility exists. Browser behavior and diagnostic contract must not fork. |
| Product form hook | stable `views/productform.blade.php` corresponding grocy_AI block | Reapply the approved markup within the stable form's existing structure; verify card remains above Picture and Save controls remain untouched. |
| Cache marker | `atech-release:custom/grocy_AI/version.json:1-5` | Increment `Customization` when route/view integration changes. Do not use the portable module version as a substitute. |
| Image overlay | `atech-release:Dockerfile.atech:4-10` | Keep the pinned stable base and existing narrow COPY surface. It already recursively copies `custom/grocy_AI`, then copies the stable marker over `/app/www/version.json`. |
| Documentation | `atech-release:CUSTOMIZATIONS.md:5-30` | Record parity/adaptation and the cache-marker bump; retain the two-branch policy. |

Stable cache behavior is concrete: `app.php:59-71` hashes root `version.json` with base URL/path, empties `data/viewcache`, resets opcache, and redirects through migration when the hash changes; route cache is stored at `app.php:118`. The Docker overlay must therefore continue copying the stable custom marker to `/app/www/version.json` (`Dockerfile.atech:6-10`).

## Shared Patterns

### Authentication and authorization

- Browser calls stay same-origin under `/api/grocy-ai`.
- Grocy controller calls `User::CheckPermission(...PERMISSION_MASTER_DATA_EDIT)` before enrichment/image work (`GrocyAiApiController.php:18-24`, `:40-46`).
- Companion preserves `APIKeyMiddleware`; `/health` may remain open and unauthorized requests return only `{"error":"unauthorized"}` (`server.py:1016-1029`).

### Validation

- Normalize without numeric coercion so leading zeroes survive.
- Apply the same GTIN-8/12/13/14 length and GS1 modulo-10 checksum vectors in JavaScript, PHP, and Python.
- Validate trace context at each owned trust boundary; replace missing/invalid/zero IDs with cryptographically secure IDs. Never echo invalid raw input.

### Error handling

- Browser renders only the finite UI-SPEC states and exact localized copy; raw server messages never become user-visible.
- Grocy translates typed service failures into finite safe response enums.
- Companion differentiates provider outcomes internally but keeps unexpected public errors generic (`server.py:2166-2174`).
- Partial metadata with unavailable images is a success variant, not a total failure.

### Privacy

- Construct diagnostics from a closed allowlist twice: once at server DTO construction and again in the browser copy serializer.
- Never include GTIN/product values, URLs/query strings, tokens, payloads, cookies, authorization headers, user identity, raw exceptions, or arbitrary headers.
- Logging/console output must be tested with forbidden canaries; do not rely only on copied-report assertions.

### Timing and lifecycle

- Browser overall deadline: exactly 15s; feedback within 250ms.
- Grocy-to-companion: 12s total, 2s connect.
- Provider: 2s connect, 5-6s read, and no individual path beyond 10s.
- No automatic retry. Retry is a new explicit trace/request. Cancel/edit/navigation/background invalidates current intent before abort.

### Zero-write preservation

The enrichment module may update preview DOM, copy diagnostics, fill the normal name input after explicit Apply, or attach a selected `File` to the normal picture input. It must never call the normal Save handlers or persistence endpoints. Existing Save serializes the form and performs product creation/update and optional upload (`public/viewjs/productform.js:81-111`); leave this code untouched.

## No Close Analog Found

| File/Area | Why | Planner Direction |
|---|---|---|
| `GrocyAiDiagnostic.php` closed DTO/trace logic | No Grocy diagnostic DTO or trace parser exists | Follow the phase research's strict W3C v00 and closed-enum contract; copy only service normalization style. |
| Companion finite provider result types/timings | Existing providers collapse several failures to empty dict/list and have no timing DTO | Introduce the smallest typed result helper in `diagnostics.py`; update providers before aggregation. |
| Playwright config/specs/server | No browser automation exists | Treat Wave 0 as new isolated test infrastructure after the required human package checkpoint. |
| Browser concurrency tests | No cancellable/stale-response automated analog exists | Implement route-held A/B resolution, duplicate counting, lifecycle cancellation, and zero-write assertions from UI-SPEC. |
| Physical JSONL and percentile checker | No performance-evidence tooling exists | Define a redacted schema and deterministic nearest-rank checker; do not derive a new threshold policy. |

## Metadata

**Analog search scope:** Grocy `custom/grocy_AI/`, `public/custom/grocy_AI/`, `views/productform.blade.php`, shared browser/camera/save helpers, `app.php`, `CUSTOMIZATIONS.md`, `version.json`, and `atech-release` equivalents; companion `grocy_mcp/{enrichment,lookup,images,server}.py`, package version, and focused tests.

**Strong analog families:** browser module/XHR/form integration; Grocy service/controller/transport; native PHP tests; companion async provider/Starlette/unittest; stable branch overlay/cache marker.

**Pattern extraction date:** 2026-08-12
