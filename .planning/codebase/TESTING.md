# Testing Patterns

**Analysis Date:** 2026-08-12

## Test Framework

**Runner:**
- Native PHP CLI on the PHP 8.5 platform declared in `composer.json`.
- The only detected automated suite is the standalone module harness `custom/grocy_AI/tests/run.php`; PHPUnit, Pest, Jest, Vitest, Mocha, Cypress, and Playwright are not configured in `composer.json` or `package.json`.
- Config: Not detected; `custom/grocy_AI/tests/run.php` defines its own counters, assertion helpers, fixtures, and process exit behavior.

**Assertion Library:**
- Custom `check()` and `expectException()` helpers in `custom/grocy_AI/tests/run.php`.
- Assertions use strict comparisons, explicit array checks, and exception-class checks in `custom/grocy_AI/tests/run.php`.

**Run Commands:**
```bash
php custom/grocy_AI/tests/run.php  # Run all detected automated checks
php -l custom/grocy_AI/src/GrocyAiService.php  # Syntax-check a changed PHP source file
# Watch mode and coverage commands are not configured in composer.json or package.json
```

The module README repeats the authoritative suite command in `custom/grocy_AI/README.md`. A successful current run reports 21 checks and exits with status 0 from `custom/grocy_AI/tests/run.php`.

## Test File Organization

**Location:**
- Tests are colocated with the custom module in `custom/grocy_AI/tests/`, adjacent to implementation in `custom/grocy_AI/src/`.
- No repository-level `tests/`, `test/`, or `spec/` suite is present outside `custom/grocy_AI/tests/run.php`.
- Keep future `grocy_AI` service checks under `custom/grocy_AI/tests/` so the fork remains isolated as required by `CUSTOMIZATIONS.md`.

**Naming:**
- The current suite uses a single executable-style file named `run.php` at `custom/grocy_AI/tests/run.php`.
- No `*Test.php`, `*.test.js`, or `*.spec.js` naming convention is established by the repository.
- If the native harness remains in use, add focused test files under `custom/grocy_AI/tests/` and require them from `custom/grocy_AI/tests/run.php`; do not imply PHPUnit discovery without adding it to `composer.json`.

**Structure:**
```text
custom/grocy_AI/
├── src/
│   ├── GrocyAiApiController.php
│   └── GrocyAiService.php
├── tests/
│   └── run.php
└── README.md
```

The implementation/test pairing is `custom/grocy_AI/src/GrocyAiService.php` with `custom/grocy_AI/tests/run.php`. `custom/grocy_AI/src/GrocyAiApiController.php` and browser behavior in `public/custom/grocy_AI/product-enrichment.js` currently have no automated tests.

## Test Structure

**Suite Organization:**
```php
// Pattern from custom/grocy_AI/tests/run.php
$captured = [];
$service = new GrocyAiService(function (string $url, array $headers, int $timeout) use (&$captured): array
{
	$captured = compact('url', 'headers', 'timeout');
	return [
		'status' => 200,
		'body' => json_encode(['found' => true, 'images' => []], JSON_THROW_ON_ERROR)
	];
});

$result = $service->EnrichByUpc('012345678905');
check($captured['timeout'] === 17, 'The configured timeout is used');
check($result['upc'] === '012345678905', 'The requested UPC is authoritative');
```

**Patterns:**
- Setup constants before requiring the service under test, as `custom/grocy_AI/tests/run.php` does for feature, service URL, API-key, and timeout configuration.
- Require the concrete implementation directly with a path relative to `custom/grocy_AI/tests/run.php`; the custom namespace is not registered in `composer.json`.
- Use descriptive behavior messages for every `check()` call in `custom/grocy_AI/tests/run.php`; these messages become failure diagnostics on `STDERR`.
- Exercise success, malformed-input, malformed-upstream-data, content validation, secret non-disclosure, and upstream-failure paths in the same deterministic process, as covered by `custom/grocy_AI/tests/run.php`.
- Accumulate failures rather than stopping at the first failed assertion, then exit 1 after the suite summary in `custom/grocy_AI/tests/run.php`.
- Teardown is not used because `custom/grocy_AI/tests/run.php` mutates only process-local constants and variables and does not access the database or filesystem.

## Mocking

**Framework:** Callable fakes; no mocking library is installed in `composer.json`.

**Patterns:**
```php
// Pattern from custom/grocy_AI/tests/run.php and the constructor seam in
// custom/grocy_AI/src/GrocyAiService.php
$failedService = new GrocyAiService(fn(): array => [
	'status' => 500,
	'body' => '{}'
]);

expectException(
	fn() => $failedService->EnrichByUpc('012345678905'),
	RuntimeException::class,
	'Companion HTTP errors are rejected'
);
```

**What to Mock:**
- Replace outbound companion HTTP transport through the optional callable accepted by `custom/grocy_AI/src/GrocyAiService.php`.
- Return minimal response-shaped arrays containing `status`, `body`, and, for image cases, `content_type`, matching the seam used in `custom/grocy_AI/tests/run.php`.
- Capture URL, headers, and timeout arguments when verifying request construction, as demonstrated in `custom/grocy_AI/tests/run.php`.
- Simulate invalid JSON, non-2xx status codes, malformed collections, unsupported MIME types, and invalid image bodies without making network calls, following `custom/grocy_AI/tests/run.php`.

**What NOT to Mock:**
- Do not mock pure normalization and validation methods such as `NormalizeUpc()`, `NormalizeResponse()`, or image-signature logic in `custom/grocy_AI/src/GrocyAiService.php`; call them through the public service behavior.
- Do not make live companion-service requests from `custom/grocy_AI/tests/run.php`; the suite is deterministic and has no environment dependency.
- Do not fake Grocy internals when a true controller or database integration test is intended. No such harness exists for `custom/grocy_AI/src/GrocyAiApiController.php`, so adding one requires an explicit application bootstrap rather than expanding the current unit-style fake.

## Fixtures and Factories

**Test Data:**
```php
// Inline fixture style from custom/grocy_AI/tests/run.php
'product' => [
	'name' => 'Test Product',
	'brand' => 'Test Brand',
	'size' => '12 oz'
],
'images' => [
	['url' => 'https://images.example/front.png', 'source' => 'openfoodfacts'],
	['url' => 'javascript:alert(1)', 'source' => 'unsafe']
]
```

**Location:**
- Fixtures are inline in `custom/grocy_AI/tests/run.php`; no fixture directory, factories, snapshots, database seed specifically for tests, or recorded HTTP responses are present.
- Use invented domains and synthetic binary bodies in `custom/grocy_AI/tests/run.php`; do not depend on external URLs or production data.
- Construct binary image samples from magic bytes plus padding, as `custom/grocy_AI/tests/run.php` does for PNG validation, so tests remain small and self-contained.

## Coverage

**Requirements:** None enforced. `composer.json` has no coverage tool or development dependency, `package.json` has no test scripts, and `.github/` contains no CI workflow.

**View Coverage:**
```bash
# Not configured; there is no coverage command in composer.json or package.json
```

- The current harness counts checks, not executed lines or branches, in `custom/grocy_AI/tests/run.php`.
- Treat the 21-check success message from `custom/grocy_AI/tests/run.php` as assertion count only, not a coverage percentage.
- If coverage becomes required, add a declared tool and command to `composer.json` before documenting a threshold; no repository convention currently selects PHPUnit/PCOV/Xdebug.

## Test Types

**Unit Tests:**
- `custom/grocy_AI/tests/run.php` provides unit-style coverage of `custom/grocy_AI/src/GrocyAiService.php` through direct calls and injected transport fakes.
- Covered behaviors include UPC normalization, request construction, response normalization, URL filtering, token handling, image MIME/signature/size validation, configuration-safe status output, invalid JSON, and upstream HTTP failures in `custom/grocy_AI/tests/run.php`.
- Add new pure service behavior to this deterministic suite before wiring it through `custom/grocy_AI/src/GrocyAiApiController.php`.

**Integration Tests:**
- Not used. There is no automated bootstrap covering Slim routes in `custom/grocy_AI/routes.php`, permission checks in `custom/grocy_AI/src/GrocyAiApiController.php`, middleware, real Guzzle behavior, or SQLite operations under `services/`.
- The direct `require_once` in `custom/grocy_AI/tests/run.php` avoids Composer/application bootstrapping, so it must not be described as an HTTP integration test.

**E2E Tests:**
- Not used. No Cypress, Playwright, Selenium, browser harness, or corresponding dependency appears in `package.json`.
- User interactions in `public/custom/grocy_AI/product-enrichment.js`—search, applying a suggested name, downloading a candidate image, and attaching a file—have no automated browser coverage.
- Blade asset inclusion and feature-flag rendering in `views/productform.blade.php` have no automated rendering tests.

## Common Patterns

**Async Testing:**
```php
// Not applicable to the current PHP harness. Convert external I/O to an
// immediate callable result, as done in custom/grocy_AI/tests/run.php.
$service = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => '{"found":false}'
]);
```

- Browser callbacks in `public/custom/grocy_AI/product-enrichment.js` are currently untested; the repository has no JavaScript test runtime in `package.json`.
- Keep native service tests synchronous and deterministic through the transport seam in `custom/grocy_AI/src/GrocyAiService.php`.

**Error Testing:**
```php
// Pattern from custom/grocy_AI/tests/run.php
function expectException(callable $callback, string $exceptionClass, string $message): void
{
	try
	{
		$callback();
		check(false, $message);
	}
	catch (Throwable $ex)
	{
		check($ex instanceof $exceptionClass, $message . ' (received ' . get_class($ex) . ')');
	}
}
```

- Assert the exception category, not only that an exception occurred, using `expectException()` in `custom/grocy_AI/tests/run.php`.
- Give each negative case a behavior-specific message so failures identify the broken contract in `custom/grocy_AI/tests/run.php`.
- Test malformed external structures as well as thrown transport failures because `custom/grocy_AI/src/GrocyAiService.php` accepts untrusted response data.
- API status mapping remains uncovered; tests for the 400/502/503 branches in `custom/grocy_AI/src/GrocyAiApiController.php` require a PSR-7/controller harness.

---

*Testing analysis: 2026-08-12*
