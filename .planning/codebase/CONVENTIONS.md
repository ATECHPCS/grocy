# Coding Conventions

**Analysis Date:** 2026-08-12

## Naming Patterns

**Files:**
- Name PHP class files after the class in PascalCase, such as `services/StockService.php`, `controllers/Api/BaseApiController.php`, and `custom/grocy_AI/src/GrocyAiService.php`.
- Name page-level Blade templates and their browser scripts in lowercase, with matching stems where practical: `views/productform.blade.php` and `public/viewjs/productform.js`.
- Name reusable browser components by feature under `public/viewjs/components/`, for example `public/viewjs/components/productpicker.js` and `public/viewjs/components/userfieldsform.js`.
- Keep fork-specific implementation inside a named module boundary under `custom/`; the `grocy_AI` module uses `custom/grocy_AI/src/`, `custom/grocy_AI/tests/`, and `public/custom/grocy_AI/` as documented in `CUSTOMIZATIONS.md`.
- Use kebab-case for standalone custom browser assets, as in `public/custom/grocy_AI/product-enrichment.js` and `public/custom/grocy_AI/grocy-ai.css`.

**Functions:**
- Use PascalCase for public and private application methods in controllers and services, matching `GetInstance()` in `services/BaseService.php`, `GetCurrentStock()` in `services/StockService.php`, and `EnrichByUpc()` in `custom/grocy_AI/src/GrocyAiService.php`.
- Preserve local exceptions to method casing when extending an existing file; `helpers/ConfigurationValidator.php` uses camelCase for `validateConfig()` and its private check methods.
- Use PascalCase for Grocy global JavaScript functions and namespace methods, such as `Grocy.Api.Get()` in `public/js/grocy.js` and `Grocy.Components.ProductPicker.GetValue()` in `public/viewjs/components/productpicker.js`.
- Use lower camelCase for functions scoped inside an IIFE, as shown by `renderResult()`, `showError()`, and `useSelectedImage()` in `public/custom/grocy_AI/product-enrichment.js`.

**Variables:**
- Use lower camelCase for PHP locals and parameters, such as `$serviceUrl`, `$contentType`, and `$imageCandidates` in `custom/grocy_AI/src/GrocyAiService.php`.
- Use PascalCase for established PHP object properties in the upstream application, such as `$AppContainer`, `$View`, and `$DB` in `controllers/BaseController.php`; the isolated module follows that pattern with `$Transport` in `custom/grocy_AI/src/GrocyAiService.php`.
- Use lower camelCase for JavaScript locals, as in `productPictureInput`, `originalButtonContent`, and `imageGrid` in `public/custom/grocy_AI/product-enrichment.js`.
- Use SCREAMING_SNAKE_CASE for configuration and feature constants, such as `GROCY_FEATURE_FLAG_GROCY_AI` referenced by `custom/grocy_AI/src/GrocyAiService.php` and configured through `config-dist.php`.

**Types:**
- Use PascalCase class names with a responsibility suffix: `*Controller` in `controllers/`, `*ApiController` in `controllers/Api/`, `*Service` in `services/`, and `*Middleware` in `middleware/`.
- Use PSR-4 namespaces matching `composer.json`: `Grocy\Services` maps to `services/`, `Grocy\Controllers` to `controllers/`, `Grocy\Middleware` to `middleware/`, and `Grocy\Helpers` to `helpers/`.
- Keep the fork module namespace distinct as `GrocyAI`, even though its files are loaded explicitly from `custom/grocy_AI/routes.php` rather than registered in `composer.json`.
- Use descriptive SPL exception classes to communicate failure categories, as in `InvalidArgumentException`, `LogicException`, and `RuntimeException` in `custom/grocy_AI/src/GrocyAiService.php`.

## Code Style

**Formatting:**
- Indent PHP and JavaScript blocks with tabs. Representative files include `controllers/BaseController.php`, `services/FilesService.php`, `public/js/grocy.js`, and `public/custom/grocy_AI/product-enrichment.js`.
- Put opening braces on the next line for classes, methods, functions, closures, and control structures, following `controllers/Api/BaseApiController.php` and `custom/grocy_AI/tests/run.php`.
- Put one blank line between logical blocks and between methods, as in `custom/grocy_AI/src/GrocyAiService.php`.
- Use trailing commas only where already required or established; PHP arrays in `custom/grocy_AI/src/GrocyAiService.php` generally omit a trailing comma on the final element.
- Prefer single-quoted strings in PHP and JavaScript unless interpolation or embedded quoting makes double quotes useful; examples appear in `custom/grocy_AI/src/GrocyAiService.php` and `public/js/grocy.js`.
- Terminate JavaScript statements with semicolons and wrap isolated custom browser behavior in an IIFE with `'use strict'`, as in `public/custom/grocy_AI/product-enrichment.js`.
- Use LF endings for shell scripts according to `.gitattributes`; no repository-wide editor settings are defined beyond the PHP server root in `.vscode/settings.json`.

**Linting:**
- No automated formatter or linter is configured: `composer.json` contains no development tools or scripts, and `package.json` contains dependencies only.
- No `.editorconfig`, ESLint, Prettier, PHP-CS-Fixer, PHPStan, or Psalm configuration is present at the repository root. Preserve the style of the target file and validate changed PHP with `php -l`, especially under `custom/grocy_AI/src/`.
- Do not introduce formatting-only churn in upstream files. Fork changes are intentionally isolated by `CUSTOMIZATIONS.md` to keep upstream merges practical.

## Import Organization

**Order:**
1. Begin PHP files with `<?php`, then declare the namespace; examples are `controllers/BaseController.php` and `custom/grocy_AI/src/GrocyAiApiController.php`.
2. Add one contiguous `use` block after the namespace, generally ordered by namespace/package and class name, as in `controllers/StockController.php` and `custom/grocy_AI/src/GrocyAiApiController.php`.
3. Alias long PSR interface names at import time (`ResponseInterface as Response`, `ServerRequestInterface as Request`) and use the short names in signatures, following `controllers/Api/FilesApiController.php`.
4. Place the class or trait immediately after imports; standalone route/bootstrap files may place `require_once` calls after imports, as in `custom/grocy_AI/routes.php`.

**Path Aliases:**
- PHP resolution uses the PSR-4 mappings in `composer.json`; application code imports `Grocy\...` classes instead of relative file paths.
- `custom/grocy_AI/routes.php` manually loads the `GrocyAI` classes with `require_once`; keep any new module class loading inside that module bootstrap unless `composer.json` is deliberately extended.
- Browser code has no module loader or bundler. Use existing globals such as `Grocy`, `U`, `__t`, and jQuery from `public/js/grocy.js` and page scripts under `public/viewjs/`.
- Blade templates reference public assets through the URL helper exposed by `controllers/BaseController.php`; follow the existing asset inclusion in `views/productform.blade.php`.

## Error Handling

**Patterns:**
- Validate at service boundaries and throw a specific exception before performing external work. `custom/grocy_AI/src/GrocyAiService.php` uses `InvalidArgumentException` for caller input, `LogicException` for configuration, and `RuntimeException` for companion-service failures.
- Preserve the originating exception as the previous exception when translating infrastructure failures, as `custom/grocy_AI/src/GrocyAiService.php` does for JSON and HTTP transport failures.
- In API controllers, perform permission checks before the guarded operation, then translate known exception categories into explicit HTTP statuses with `GenericErrorResponse()`, following `custom/grocy_AI/src/GrocyAiApiController.php` and `controllers/Api/BaseApiController.php`.
- Use `ApiResponse()` for JSON bodies and `EmptyApiResponse()` for successful operations without content, as provided by `controllers/Api/BaseApiController.php`.
- For frontend API calls, restore UI state in both success and failure callbacks and show a user-safe message; `public/custom/grocy_AI/product-enrichment.js` implements this around `Grocy.Api.Get()`.
- Treat malformed server error payloads as expected: parse inside `try/catch` and retain a generic fallback, matching `public/custom/grocy_AI/product-enrichment.js`.
- Use narrow empty catches only when failure is intentionally non-fatal and the fallback is obvious; `controllers/BaseController.php` tolerates a database-not-initialized state during view setup.

## Logging

**Framework:** Browser `console` plus HTTP error responses; no server-side logging framework is configured in `composer.json`.

**Patterns:**
- Route user-visible browser failures through `Grocy.FrontendHelpers.ShowGenericError()` in `public/js/grocy.js` or a feature-owned error element such as `public/custom/grocy_AI/product-enrichment.js`.
- Use `console.error()` for diagnostic XHR details after providing appropriate UI feedback; examples are `public/js/grocy.js` and `public/viewjs/stockoverview.js`.
- In CLI tests only, write assertion failures to `STDERR`, the summary to `STDOUT`, and exit nonzero on failure, following `custom/grocy_AI/tests/run.php`.
- Never log configured secrets. `custom/grocy_AI/tests/run.php` explicitly verifies that `GetStatus()` from `custom/grocy_AI/src/GrocyAiService.php` does not expose the API key.

## Comments

**When to Comment:**
- Explain security intent or non-obvious operational constraints, not line-by-line mechanics. The redirect restriction comment in `custom/grocy_AI/src/GrocyAiService.php` is the preferred pattern.
- Document intentionally swallowed errors when the reason is not obvious, as in the database initialization catch in `controllers/BaseController.php` and the JSON fallback catch in `public/custom/grocy_AI/product-enrichment.js`.
- Record fork boundaries and every necessary upstream-file edit in `CUSTOMIZATIONS.md`; keep module behavior and configuration documented in `custom/grocy_AI/README.md`.
- Preserve existing TODO markers only when they describe a concrete limitation, such as the locale direction handling note in `controllers/BaseController.php`.

**JSDoc/TSDoc:**
- JSDoc/TSDoc is not an established convention in browser code under `public/js/` or `public/viewjs/`; prefer clear function and variable names.
- PHPDoc is sparse and used for cases where native signatures do not fully express the contract, such as authentication helpers in `middleware/AuthMiddleware.php` and the `Grocycode` type in `helpers/Grocycode.php`.
- Prefer native PHP parameter and return types for new isolated code, as used throughout `custom/grocy_AI/src/GrocyAiService.php`, instead of adding redundant docblocks.

## Function Design

**Size:** Keep validation and normalization in small private helpers when building isolated functionality. `custom/grocy_AI/src/GrocyAiService.php` separates request execution, response normalization, scalar cleanup, token validation, signature checks, and configuration access. Existing upstream services such as `services/StockService.php` contain large domain methods; avoid enlarging them when a focused service can own the behavior.

**Parameters:**
- Use PSR request/response signatures for controller actions, as in `custom/grocy_AI/src/GrocyAiApiController.php` and controllers under `controllers/Api/`.
- Add scalar and return types in new module code where the contract is stable, following `custom/grocy_AI/src/GrocyAiService.php`; preserve untyped signatures when modifying legacy methods in `services/StockService.php` unless the full call graph is verified.
- Use optional constructor callables as explicit seams around external I/O, matching the transport injection in `custom/grocy_AI/src/GrocyAiService.php`.
- Prefer associative arrays at existing HTTP and database boundaries; document and normalize their shape immediately, as `NormalizeResponse()` does in `custom/grocy_AI/src/GrocyAiService.php`.

**Return Values:**
- Return PSR-7 `Response` objects from typed custom API actions in `custom/grocy_AI/src/GrocyAiApiController.php`.
- Return normalized arrays from service methods that supply API data, following `custom/grocy_AI/src/GrocyAiService.php`.
- Use `null`, empty strings, or empty arrays only when they are part of the local contract; validate uncertain external values before returning them, as in `ScalarString()` and `StringList()` in `custom/grocy_AI/src/GrocyAiService.php`.

## Module Design

**Exports:** PHP modules expose classes through namespaces and Composer autoloading configured in `composer.json`; `custom/grocy_AI/routes.php` is the explicit bootstrap for the fork module. Browser features attach shared behavior to the `Grocy` global in `public/js/grocy.js`, while isolated page behavior remains private inside the IIFE in `public/custom/grocy_AI/product-enrichment.js`.

**Barrel Files:** Barrel files are not used. Import concrete PHP classes directly, and load custom module classes/assets through `custom/grocy_AI/routes.php` and `views/productform.blade.php`.

---

*Convention analysis: 2026-08-12*
