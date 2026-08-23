# Phase 4: Reusable Conversion Model - Pattern Map

**Mapped:** 2026-08-21  
**Files analyzed:** 16 likely created/modified files  
**Analogs found:** 15 / 16

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `custom/grocy_AI/src/GrocyAiConversionMigration.php` | migration | CRUD | `custom/grocy_AI/src/GrocyAiTaxonomyMigration.php` | exact |
| `custom/grocy_AI/src/GrocyAiConversionService.php` | service | transform | `custom/grocy_AI/src/GrocyAiTaxonomyService.php` | role-match |
| `custom/grocy_AI/src/GrocyAiApiController.php` | controller | request-response | same file's taxonomy endpoints | exact |
| `custom/grocy_AI/src/GrocyAiConversionController.php` | controller | request-response | `controllers/StockController.php` | role-match |
| `custom/grocy_AI/routes.php` | route/config | request-response | same file | exact |
| `custom/grocy_AI/bin/validate-conversion-rules.php` | utility | batch | `custom/grocy_AI/bin/validate-inventory-taxonomy.php` | exact |
| `custom/grocy_AI/tests/conversions.php` | test | transform | `custom/grocy_AI/tests/taxonomy.php` | role-match |
| `custom/grocy_AI/tests/run.php` | test config | batch | same file | exact |
| `custom/grocy_AI/tests/fixtures/conversion-characterization.php` (or equivalent disposable harness) | test | batch | no close analog | none |
| `views/quantityunitconversionform.blade.php` | component/view | request-response | same file | exact |
| `public/viewjs/quantityunitconversionform.js` | component | request-response | same file | exact |
| `views/productform.blade.php` | component/view | request-response | existing Grocy AI taxonomy block in same file | exact |
| `public/viewjs/productform.js` plus `public/custom/grocy_AI/conversion-explanations.js` | component | event-driven | `public/custom/grocy_AI/product-taxonomy.js` | role-match |
| `views/quantityunitconversionsresolved.blade.php` | component/view | request-response | same file | exact |
| `public/viewjs/quantityunitconversionsresolved.js` | component | event-driven | same file | exact |
| `views/grocyai_conversioncoverage.blade.php` and `public/custom/grocy_AI/conversion-coverage.js` | view/component | request-response | resolved-conversions view/script + taxonomy JS | role-match |
| `public/custom/grocy_AI/grocy-ai.css` | config/style | transform | same file's taxonomy styles | exact |

`GrocyAiConversionController.php`, the coverage Blade view, and coverage JS names are inferred implementation paths for the UI-SPEC's authenticated page; keep the final names aligned with the route/view convention selected during planning. The existing view renderer only resolves normal `views/` names, so a custom controller can remain module-owned while rendering a minimally hooked core view.

## Pattern Assignments

### `custom/grocy_AI/src/GrocyAiConversionMigration.php` (migration, CRUD)

**Analog:** `custom/grocy_AI/src/GrocyAiTaxonomyMigration.php`

**Namespace, versioned ledger, and rollback pattern** (lines 3-48):

```php
namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyMigration
{
	public const VERSION = 'v1';

	public static function Bootstrap(PDO $pdo): void
	{
		$startedTransaction = !$pdo->inTransaction();
		if ($startedTransaction)
		{
			$pdo->beginTransaction();
		}

		try
		{
			// Create module-owned ledger/schema, then source-controlled seeds.
			if ($startedTransaction)
			{
				$pdo->commit();
			}
		}
		catch (\Throwable $ex)
		{
			if ($startedTransaction && $pdo->inTransaction())
			{
				$pdo->rollBack();
			}
			throw $ex;
		}
	}
}
```

**Schema/seed convention** (lines 51-105): keep durable objects namespaced `grocy_ai_conversion_*`, make bootstrap idempotent, seed only source-controlled rules/profiles, and use SQLite `CHECK`/foreign-key constraints for closed enums and provenance relationships. Do not alter `quantity_unit_conversions` or protected consumer rows.

### `custom/grocy_AI/src/GrocyAiConversionService.php` (service, transform)

**Analog:** `custom/grocy_AI/src/GrocyAiTaxonomyService.php`

**DB acquisition and module bootstrap** (lines 7-18):

```php
class GrocyAiTaxonomyService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		}
	}
}
```

**Closed validation and bounded DTOs** (lines 93-117 and 304-327): validate scalar inputs before SQL; throw `InvalidArgumentException` for invalid/stale input and `RuntimeException` for unavailable upstream objects; return fixed-key arrays rather than database rows. Phase 4 must expose only source/version, closed status/reason labels, precise factor, precedence/path outcome, and counts—not raw SQL or diagnostics.

**Atomic write pattern** (lines 137-167): for the server-side save/projection boundary, begin one transaction, validate candidate/ruleset before any active projection, commit only the eligible result, and roll back on every `Throwable`. The report/inspection methods must be read-only and instantiate with `$bootstrap = false` if even schema bootstrap would write.

**Explicit-assignment guard:** copy `CurrentLeaf()` at lines 193-199, which joins `grocy_ai_taxonomy_classifications` to a non-null leaf and matches its ruleset version. Do not call `Evidence()`/`ProductGroupEvidence()` (lines 201-268): those are deliberately suggestion evidence and are prohibited as conversion activation input.

### `custom/grocy_AI/src/GrocyAiApiController.php` and `custom/grocy_AI/routes.php` (controller/route, request-response)

**Analog:** `custom/grocy_AI/src/GrocyAiApiController.php` lines 100-164 and `custom/grocy_AI/routes.php` lines 3-25.

**Permission, route-argument validation, and bounded errors:**

```php
public function ProductTaxonomy(Request $request, Response $response, array $args): Response
{
	User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
	$productId = $args['productId'] ?? null;
	if (!is_string($productId) || preg_match('/^[1-9][0-9]{0,9}$/D', $productId) !== 1)
	{
		return $this->GenericErrorResponse($response, 'Invalid product', 400);
	}

	try
	{
		return $this->ApiResponse($response, (new GrocyAiTaxonomyService())->ReadProductTaxonomy((int) $productId));
	}
	catch (\InvalidArgumentException)
	{
		return $this->GenericErrorResponse($response, 'Invalid product', 400);
	}
	catch (\RuntimeException)
	{
		return $this->GenericErrorResponse($response, 'Product unavailable', 404);
	}
}
```

Register conversion validation, product status, resolved provenance, and report-refresh reads inside the existing `/api/grocy-ai` group. Preserve its `CorsMiddleware` and `JsonMiddleware` chain (routes lines 17-25). Keep validation requests read-only; do not introduce a second persistence endpoint.

### `custom/grocy_AI/src/GrocyAiConversionController.php` and coverage view (controller/view, request-response)

**Analog:** `controllers/StockController.php` lines 635-649.

```php
public function QuantityUnitConversionsResolved(Request $request, Response $response, array $args)
{
	$product = null;
	if (isset($request->getQueryParams()['product']))
	{
		$product = $this->DB->products($request->getQueryParams()['product']);
		$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id', $product->id);
	}
	else
	{
		$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id IS NULL');
	}

	return $this->RenderPage($response, 'quantityunitconversionsresolved', [...]);
}
```

For the module controller, extend `Grocy\Controllers\BaseController`, call `User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT)` before rendering, and pass a fixed report DTO to a normal Blade view. The report route and refresh endpoint are inspection-only: no bootstrap/projection/activation in the request path.

### `custom/grocy_AI/bin/validate-conversion-rules.php` (utility, batch)

**Analog:** `custom/grocy_AI/bin/validate-inventory-taxonomy.php` lines 1-36.

```php
$dataPath = getenv('GROCY_DATAPATH');
if (!is_string($dataPath) || $dataPath === '' || $dataPath[0] !== '/')
{
	fwrite(STDERR, "GROCY_DATAPATH must be an absolute configured Grocy data path\n");
	exit(2);
}

$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Bootstrap is deliberately disabled: this maintainer command is read-only.
$report = (new GrocyAiTaxonomyService($pdo, false))->ValidateInventoryTaxonomy();
fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
```

Keep the script `strict_types`, use only a configured absolute Grocy data path, emit a JSON report to stdout, and replace caught exception text with a single stable stderr message/exit code.

### `custom/grocy_AI/tests/conversions.php` and `custom/grocy_AI/tests/run.php` (test, transform/batch)

**Analogs:** `custom/grocy_AI/tests/taxonomy.php` lines 1-80, 120-167; `custom/grocy_AI/tests/run.php` lines 35-50 and 600-620.

**Fixture-first contract pattern:** build an in-memory PDO fixture containing only minimal upstream-shaped tables, call migration bootstrap twice to prove idempotence, snapshot protected tables/cache outputs before tests, and use `expectedRed('EXPECTED_RED: conversion-*', ...)` for an unimplemented/broken contract. Assert exact fixed DTO key order/statuses and that excluded count/package units, evidence-only assignments, and non-eligible candidates never project.

**Test dispatch convention:** `run.php` conditionally requires the implementation/test files with `is_file()` (lines 35-50) and exposes dedicated argv commands before the full suite (lines 600-620). Add `conversions` dispatch plus focused characterization commands without changing existing Phase 1-3 test behavior.

### Native conversion editor: `views/quantityunitconversionform.blade.php` and `public/viewjs/quantityunitconversionform.js` (view/component, request-response)

**Analogs:** view lines 28-132; script lines 1-153.

Insert the validation region after the existing `#qu-conversion-inverse-info` content passed through `additionalHtmlElements` (view lines 100-120), before `components.userfieldsform` and native `#save-quconversion-button` (lines 121-128). Preserve the existing form identifiers and native POST/PUT flow:

```javascript
var jsonData = $('#quconversion-form').serializeJSON();
jsonData.from_qu_id = $("#from_qu_id").val();
Grocy.FrontendHelpers.BeginUiBusy("quconversion-form");

Grocy.Api.Post('objects/quantity_unit_conversions', jsonData, success, failure);
// Edit mode uses Grocy.Api.Put('objects/quantity_unit_conversions/' + Grocy.EditObjectId, ...).
```

Use the current `input-group-qu` change handler (script lines 82-134) to recompute display data and invalidate candidate revisions. Phase-owned code must disable only validation and Save while validation is running; it must not replace `Grocy.Components.UserfieldsForm.Save`, redirect/close-modal handling, localized forward/inverse copy, or core error presentation.

### Product and resolved-conversion UI (views/components, request-response/event-driven)

**Product analog:** `views/productform.blade.php` lines 840-890 and 1150-1205; `public/custom/grocy_AI/product-taxonomy.js` lines 1-147.

Place the read-only reusable-status line in the existing Product specific QU conversions section before its table. Keep its native Add and resolved-view links intact (product view lines 851-869). Follow the taxonomy JS IIFE/root guard and local DOM/XHR pattern:

```javascript
(function ()
{
	'use strict';
	var root = document.getElementById('grocy-ai-product-taxonomy');
	if (!root) return;

	function request(method, body, done, failed)
	{
		var xhr = new XMLHttpRequest();
		xhr.open(method, '/api/grocy-ai/products/' + productId + '/taxonomy');
		xhr.setRequestHeader('Accept', 'application/json');
		// Parse only successful JSON; route all failures to bounded UI copy.
	}
}());
```

**Resolved analog:** `views/quantityunitconversionsresolved.blade.php` lines 1-101 and `public/viewjs/quantityunitconversionsresolved.js` lines 1-27. Extend this existing DataTable with source/status/details columns; retain the current quantity-unit filter and its `colReorder.transpose` use. Build details with text nodes/known DTO fields, retain table rows initially hidden until `DataTable()` is ready, and expand a semantic child region from a real text-labeled button.

### `public/custom/grocy_AI/grocy-ai.css` (style, transform)

**Analog:** taxonomy/card styles at lines 1-44, 365-439, and 442-508.

Use the existing module vocabulary: `16px` body, `20px/500` section headings, `24px/500` card titles, `8px/16px/24px` spacing, `min-height: 44px` controls, `overflow-wrap: anywhere`, a 2px primary focus outline, and explicit `.night-mode` border/background variants. The responsive pattern is full-width controls below 576px and `prefers-reduced-motion` disables non-essential spinner/icon animation. Do not add isolated one-off spacing values or color-only status indicators.

## Shared Patterns

### Module boundary and load order
**Source:** `custom/grocy_AI/routes.php` lines 8-25.  
**Apply to:** all new service/controller classes and module HTTP endpoints.

Use explicit `require_once` entries before route registration, the existing `GrocyAI` namespaces, and the `/api/grocy-ai` group. Update `custom/grocy_AI/portable-files.txt`, module README/CUSTOMIZATIONS documentation, and module asset version together when final implementation adds custom files/hooks.

### Permission and error handling
**Source:** `custom/grocy_AI/src/GrocyAiApiController.php` lines 100-164.  
**Apply to:** validation/status/report APIs and the coverage page.

Enforce `User::PERMISSION_MASTER_DATA_EDIT` before inspecting report/rule data. Catch expected exception categories and return bounded localized messages/status codes; never surface raw diagnostics, exception text, SQL, source URLs, or household data.

### Database safety
**Source:** `custom/grocy_AI/src/GrocyAiTaxonomyMigration.php` lines 11-48 and `GrocyAiTaxonomyService.php` lines 137-164.  
**Apply to:** migration/bootstrap, candidate validation before native Save, and any eventual eligible projection.

Use idempotent module-owned schema, transaction ownership checks, rollback-on-throw, and closed schema/DTO enums. Validate rules before touching an active projection and keep read-only report/CLI paths free of bootstrap writes.

### Native Grocy persistence and browser behavior
**Source:** `public/viewjs/quantityunitconversionform.js` lines 1-80 and 82-153.  
**Apply to:** conversion validation integration.

Keep the native `objects/quantity_unit_conversions` POST/PUT, user-field save, navigation, modal events, and existing client-side form validation. Phase 4 only gates Save on a fresh eligible validation and repeats that validation server-side; it does not add a custom conversion save endpoint.

### UI accessibility and theme
**Source:** `public/custom/grocy_AI/grocy-ai.css` lines 1-44 and 365-508; `views/quantityunitconversionsresolved.blade.php` lines 20-53.  
**Apply to:** all new Phase 4 controls/surfaces.

Use Bootstrap semantics and visible text labels, `role=status`/`aria-live=polite` for progress, `role=alert` for blockers, 44px targets, keyboard-focusable disclosure buttons, mobile filter collapse, focus outlines, night-mode alternatives, and reduced-motion behavior.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `custom/grocy_AI/tests/fixtures/conversion-characterization.php` (or selected dual-branch harness) | test | batch | No existing module test snapshots both maintained branches' cache schema, trigger deltas, and protected consumer matrix. Derive it from the taxonomy in-memory PDO fixture pattern, but keep the explicit dual-checkout/process boundary described in RESEARCH.md. |

## Metadata

**Analog search scope:** `custom/grocy_AI/`, `public/custom/grocy_AI/`, `views/`, `public/viewjs/`, `controllers/`, and conversion migrations.  
**Files scanned:** 20 representative module/native files and migrations.  
**Pattern extraction date:** 2026-08-21
