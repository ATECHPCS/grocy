<!-- GSD:project-start source:PROJECT.md -->
## Project

**grocy_AI**

`grocy_AI` is the ATECHPCS-maintained extension boundary for a local Grocy household inventory system. It keeps Grocy's proven PHP/SQLite core and upstream compatibility while adding reviewable AI- and search-assisted workflows for product creation, real package imagery, food classification, quantity conversions, and inventory maintenance.

The project serves a private household deployment on the LAN, primarily through Grocy's responsive web interface and mobile usage. Fork-specific behavior remains segregated under `custom/grocy_AI/` and `public/custom/grocy_AI/`, with only small, documented hooks in upstream files.

**Core Value:** Adding and maintaining real household food inventory must be fast, accurate, and dependable from a phone without surrendering control of the data to automatic guesses.

### Constraints

- **Upstream compatibility**: Keep ATECHPCS implementation under `custom/grocy_AI/` and `public/custom/grocy_AI/`; minimize and document unavoidable changes to upstream files.
- **Deployment stability**: Production remains pinned to a tested stable Grocy release branch and must preserve `/etc/komodo/grocy` data across image rebuilds.
- **Data safety**: Bulk changes require a dry-run preview, bounded scope, conflict reporting, and an auditable result even when the user elects not to make a database backup.
- **Human control**: External metadata and image search results are suggestions; explicit user action is required before normal Grocy persistence.
- **Security**: Secrets remain in deployment configuration, never in Git/build URLs/logs; external image fetching uses allowlisted formats, bounded sizes, and server-issued opaque handles.
- **Performance**: Mobile and LAN workflows should degrade clearly when companion providers or image hosts are slow instead of presenting unexplained hangs or connection drops.
- **Compatibility**: Continue using Grocy's established PHP, Blade, JavaScript, REST, file-upload, permissions, and SQLite migration patterns.
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP 8.5 - Server-side application, routing, controllers, services, middleware, migrations, and Blade views live in `app.php`, `routes.php`, `controllers/`, `services/`, `middleware/`, `migrations/`, and `views/`. Composer constrains PHP to `8.5.*` in `composer.json`; runtime startup requires at least 8.5.0 in `helpers/PrerequisiteChecker.php`.
- JavaScript (browser ECMAScript; no transpilation target declared) - Page behavior and reusable UI helpers are plain scripts in `public/js/`, `public/viewjs/`, and `public/custom/grocy_AI/product-enrichment.js`.
- SQLite SQL 3.40+ dialect - The schema is evolved through numbered files in `migrations/`; SQLite 3.40.0 is the minimum checked by `helpers/PrerequisiteChecker.php`.
- Blade/HTML - Server-rendered page templates and components use `.blade.php` files under `views/`, rendered through `helpers/SlimBladeView.php`.
- CSS - Application styles are stored in `public/css/` and custom module styles in `public/custom/grocy_AI/grocy-ai.css`.
- Bash and Windows Batch - Release updating and dependency/localization workflows are in `update.sh` and `.devtools/*.bat`.
- JSON/OpenAPI - API metadata and application versioning are defined in `grocy.openapi.json` and `version.json`.
- gettext PO/POT - Runtime translations and source catalogs are under `localization/`, with Transifex mapping in `.tx/config`.
## Runtime
- PHP 8.5.x runs the web application through `public/index.php`; `composer.json` restricts the supported minor line to 8.5 and `helpers/PrerequisiteChecker.php` enforces 8.5.0 or newer.
- SQLite 3.40.0 or newer is embedded through PHP PDO and checked at startup in `helpers/PrerequisiteChecker.php`; no standalone database server is required.
- Required PHP extensions are `fileinfo`, `pdo_sqlite`, `gd`, `ctype`, `intl`, `zlib`, `mbstring`, `filter`, `iconv`, `tokenizer`, and `json`, as enumerated in `helpers/PrerequisiteChecker.php`.
- The optional LDAP authentication mode additionally needs PHP LDAP functions used by `middleware/LdapAuthMiddleware.php`; this extension is conditional and is not in the baseline prerequisite list in `helpers/PrerequisiteChecker.php`.
- Production serves a responsive/PWA-style browser frontend to a recent Firefox, Chrome, or Edge, per `README.md`; browser camera barcode scanning in `public/viewjs/components/camerabarcodescanner.js` requires HTTPS.
- The application release represented by this checkout is Grocy 4.6.0, recorded in `version.json`.
- Composer 2-compatible dependency management - the exact Composer executable version is not pinned; `composer.lock` records plugin API 2.9.0 and is present.
- Composer installs runtime libraries into the non-default `packages/` directory via `composer.json`; `public/index.php` refuses startup when `packages/autoload.php` is missing through `helpers/PrerequisiteChecker.php`.
- Yarn Classic lockfile format v1 - the exact Yarn executable version is not pinned; `yarn.lock` is present.
- Yarn installs production-only packages directly into `public/packages/`, with install scripts and optional dependencies disabled by `.yarnrc`; there is no npm lockfile.
## Frameworks
- Slim 4.15.2 - HTTP application, route groups, PSR-7 request/response handling, and middleware composition are configured in `app.php` and `routes.php`; version is locked in `composer.lock`.
- webman/blade 1.5.7 - Blade template rendering for `views/`, adapted by `helpers/SlimBladeView.php`; version is locked in `composer.lock`.
- PHP-DI 7.1.1 - Dependency injection container passed to Slim and controllers in `app.php`; version is locked in `composer.lock`.
- LessQL `dev-master-fork` - Lightweight relational mapper over PDO SQLite used through `services/DatabaseService.php`; source fork is declared in `composer.json` and locked in `composer.lock`.
- Bootstrap 4.6.2 and jQuery 3.7.1 - Core browser UI and DOM/AJAX layer loaded by `views/layout/default.blade.php`; versions are locked in `yarn.lock`.
- DataTables 1.13.11 - Tabular master-data and report UI loaded on demand by Blade views such as `views/products.blade.php`; version is locked in `yarn.lock`.
- No general-purpose test runner or assertion framework is declared in `composer.json` or `package.json`.
- The `grocy_AI` module uses a standalone PHP contract test script at `custom/grocy_AI/tests/run.php`, run with `php custom/grocy_AI/tests/run.php` as documented in `custom/grocy_AI/README.md`.
- PHPUnit references in `composer.lock` belong to dependencies' development metadata and are not installed as this project's test framework because `composer.json` has no `require-dev` section.
- No bundler or transpiler is configured; Yarn places distributable dependency assets in `public/packages/`, and `views/layout/default.blade.php` links those files directly.
- Composer PSR-4 autoloading maps `Grocy\Services\`, `Grocy\Controllers\`, `Grocy\Middleware\`, and `Grocy\Helpers\` to `services/`, `controllers/`, `middleware/`, and `helpers/` in `composer.json`.
- Frontend packages are selected per view with `require_frontend_packages()` in `helpers/extensions.php` and emitted conditionally by `views/layout/default.blade.php`.
- Windows dependency maintenance uses Composer and Yarn from `.devtools/install_dependencies.bat` and `.devtools/update_dependencies.bat`.
- Release archives are assembled by `.devtools/create_release_package.bat`; in-place Linux updates and backups are performed by `update.sh`.
- Swagger UI 5.32.10 renders the checked-in OpenAPI 3.1 specification from `grocy.openapi.json` via `views/openapiui.blade.php`; the package version is locked in `yarn.lock`.
## Key Dependencies
- `slim/slim` 4.15.2, `slim/psr7` 1.8.0, and `slim/http` 1.4.0 - Core HTTP stack in `app.php` and `routes.php`, locked by `composer.lock`.
- `webman/blade` 1.5.7 and Illuminate 12.64 components - Server-side rendering of `views/`, locked by `composer.lock`.
- `morris/lessql` `dev-master-fork` - Main database abstraction used by `services/DatabaseService.php`, sourced from the VCS repository declared in `composer.json`.
- `php-di/php-di` 7.1.1 - Runtime container for Slim, controllers, middleware, views, and URL management in `app.php`.
- `guzzlehttp/guzzle` 7.15.1 - Outbound HTTP for barcode lookup, label-printer webhooks, remote product images, and `grocy_AI` companion calls in `plugins/OpenFoodFactsBarcodeLookupPlugin.php`, `helpers/WebhookRunner.php`, `services/StockService.php`, and `custom/grocy_AI/src/GrocyAiService.php`.
- `jquery` 3.7.1 and `bootstrap` 4.6.2 - Base browser interaction and styling loaded in `views/layout/default.blade.php`.
- `ezyang/htmlpurifier` 4.19.0 - Sanitizes HTML-bearing API fields and caches serializer data under the Grocy data path in `controllers/Api/BaseApiController.php`.
- `gettext/gettext` `dev-4.x-fork` - Runtime localization service used from `services/LocalizationService.php` with catalogs in `localization/`.
- `gumlet/php-image-resize` 2.1.3 - Generates resized local image variants in `services/FilesService.php`.
- `eluceo/ical` 2.17.0 - Produces the calendar feed in `controllers/Api/CalendarApiController.php`.
- `mike42/escpos-php` 4.0 - Connects to network or device-file ESC/POS printers in `services/PrintService.php`.
- `interficieis/php-barcode` 2.0.2 and `bwip-js` 4.11.2 - Server/browser barcode generation used by label and management views; dependencies are declared in `composer.json` and `package.json`.
- `@zxing/library` 0.21.3 - Offline, client-side camera barcode decoding in `public/viewjs/components/camerabarcodescanner.js`.
- `ramsey/uuid` 4.9.3 - UUID generation available to application services from the dependency declared in `composer.json`.
- `erusev/parsedown` 1.8.0 - Markdown rendering used for application content such as changelog display, locked in `composer.lock`.
- `chart.js` 2.9.4, FullCalendar 3.10.5, Moment 2.30.1, and Summernote 0.9.1 - Reporting, calendar, date/time, and rich-text UI loaded selectively by `views/layout/default.blade.php`, with versions in `yarn.lock`.
## Configuration
- Copy `config-dist.php` to the required runtime file `data/config.php` and keep the data directory writable, as described in `README.md` and enforced by `helpers/PrerequisiteChecker.php`.
- Configuration precedence is: constants already defined by `data/config.php`; otherwise a matching `GROCY_DATAPATH/settingoverrides/<SETTING>.txt`; otherwise `GROCY_<SETTING>` from the process environment; otherwise the default in `config-dist.php`, implemented by `Setting()` in `helpers/extensions.php`.
- `GROCY_DATAPATH` separately selects the data directory before application startup in `public/index.php`; relative values resolve beneath `public/` and the default points to `data/`.
- Core URL/deployment controls are `GROCY_BASE_URL`, `GROCY_BASE_PATH`, `GROCY_DISABLE_URL_REWRITING`, `GROCY_MODE`, and `GROCY_DATAPATH`, defined or consumed in `config-dist.php`, `public/index.php`, and `app.php`.
- Conditional integration configuration includes `GROCY_AUTH_CLASS`, `GROCY_REVERSE_PROXY_AUTH_HEADER`, `GROCY_REVERSE_PROXY_AUTH_USE_ENV`, `GROCY_LDAP_*`, `GROCY_LABEL_PRINTER_*`, `GROCY_TPRINTER_*`, `GROCY_STOCK_BARCODE_LOOKUP_PLUGIN`, and `GROCY_AI_*`, with defaults in `config-dist.php`.
- No `.env` file or dotenv loader is present; use `data/config.php`, process environment variables, or embedded-mode override files as implemented by `public/index.php` and `helpers/extensions.php`.
- Runtime secrets and writable state belong under the ignored `data/` tree: `data/.gitignore` ignores everything except metadata directories/files, while `data/config.php` is required but deliberately untracked.
- Backend dependencies and autoload rules are defined by `composer.json` and locked in `composer.lock`; install output goes to ignored `packages/`.
- Frontend dependencies are defined by `package.json` and locked in `yarn.lock`; `.yarnrc` places production assets in ignored `public/packages/`.
- The application has no Webpack, Vite, Rollup, Parcel, TypeScript, Babel, ESLint, or CSS preprocessor configuration in the repository.
- View and Slim route caches are created beneath `GROCY_DATAPATH/viewcache` in `app.php`; the cache is invalidated when `version.json`, `GROCY_BASE_URL`, or `GROCY_BASE_PATH` changes.
- The API contract is a checked-in OpenAPI 3.1 document at `grocy.openapi.json`; Swagger UI loads it through routes defined in `routes.php`.
## Platform Requirements
- Install PHP 8.5.x with the extensions enumerated in `helpers/PrerequisiteChecker.php`, SQLite 3.40+, Composer, and Yarn Classic-compatible tooling; setup commands are represented in `.devtools/install_dependencies.bat`.
- Run `composer install` to create `packages/autoload.php` and `yarn install` to populate `public/packages/`, matching `composer.json`, `.yarnrc`, and `README.md`.
- Create writable `data/config.php` from `config-dist.php` and point the web server document root at `public/`, per `README.md`.
- Use URL rewriting to `public/index.php` or set `GROCY_DISABLE_URL_REWRITING`; sample Apache rules are in `public/.htaccess`, while nginx guidance is in `README.md`.
- Optional update tooling in `update.sh` requires Bash, `tar`, `wget`, and `unzip`; Windows release development additionally uses the commands referenced by `.devtools/*.bat`.
- Deploy as a self-hosted PHP web application with the web root at `public/` and persistent writable state under `GROCY_DATAPATH`, as specified in `README.md` and `public/index.php`.
- A conventional Apache or nginx/PHP runtime is supported through `public/.htaccess` and the nginx rewrite guidance in `README.md`; this repository does not include a container image or compose manifest.
- `README.md` points operators to the external LinuxServer Grocy image for Docker deployments, but hosting remains operator-managed and no hosting vendor is required by application code.
- Preserve the complete data path across upgrades because the SQLite database, uploaded files, configuration, caches, and backups live below it as implemented in `services/DatabaseService.php`, `services/FilesService.php`, and `update.sh`.
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Naming Patterns
- Name PHP class files after the class in PascalCase, such as `services/StockService.php`, `controllers/Api/BaseApiController.php`, and `custom/grocy_AI/src/GrocyAiService.php`.
- Name page-level Blade templates and their browser scripts in lowercase, with matching stems where practical: `views/productform.blade.php` and `public/viewjs/productform.js`.
- Name reusable browser components by feature under `public/viewjs/components/`, for example `public/viewjs/components/productpicker.js` and `public/viewjs/components/userfieldsform.js`.
- Keep fork-specific implementation inside a named module boundary under `custom/`; the `grocy_AI` module uses `custom/grocy_AI/src/`, `custom/grocy_AI/tests/`, and `public/custom/grocy_AI/` as documented in `CUSTOMIZATIONS.md`.
- Use kebab-case for standalone custom browser assets, as in `public/custom/grocy_AI/product-enrichment.js` and `public/custom/grocy_AI/grocy-ai.css`.
- Use PascalCase for public and private application methods in controllers and services, matching `GetInstance()` in `services/BaseService.php`, `GetCurrentStock()` in `services/StockService.php`, and `EnrichByUpc()` in `custom/grocy_AI/src/GrocyAiService.php`.
- Preserve local exceptions to method casing when extending an existing file; `helpers/ConfigurationValidator.php` uses camelCase for `validateConfig()` and its private check methods.
- Use PascalCase for Grocy global JavaScript functions and namespace methods, such as `Grocy.Api.Get()` in `public/js/grocy.js` and `Grocy.Components.ProductPicker.GetValue()` in `public/viewjs/components/productpicker.js`.
- Use lower camelCase for functions scoped inside an IIFE, as shown by `renderResult()`, `showError()`, and `useSelectedImage()` in `public/custom/grocy_AI/product-enrichment.js`.
- Use lower camelCase for PHP locals and parameters, such as `$serviceUrl`, `$contentType`, and `$imageCandidates` in `custom/grocy_AI/src/GrocyAiService.php`.
- Use PascalCase for established PHP object properties in the upstream application, such as `$AppContainer`, `$View`, and `$DB` in `controllers/BaseController.php`; the isolated module follows that pattern with `$Transport` in `custom/grocy_AI/src/GrocyAiService.php`.
- Use lower camelCase for JavaScript locals, as in `productPictureInput`, `originalButtonContent`, and `imageGrid` in `public/custom/grocy_AI/product-enrichment.js`.
- Use SCREAMING_SNAKE_CASE for configuration and feature constants, such as `GROCY_FEATURE_FLAG_GROCY_AI` referenced by `custom/grocy_AI/src/GrocyAiService.php` and configured through `config-dist.php`.
- Use PascalCase class names with a responsibility suffix: `*Controller` in `controllers/`, `*ApiController` in `controllers/Api/`, `*Service` in `services/`, and `*Middleware` in `middleware/`.
- Use PSR-4 namespaces matching `composer.json`: `Grocy\Services` maps to `services/`, `Grocy\Controllers` to `controllers/`, `Grocy\Middleware` to `middleware/`, and `Grocy\Helpers` to `helpers/`.
- Keep the fork module namespace distinct as `GrocyAI`, even though its files are loaded explicitly from `custom/grocy_AI/routes.php` rather than registered in `composer.json`.
- Use descriptive SPL exception classes to communicate failure categories, as in `InvalidArgumentException`, `LogicException`, and `RuntimeException` in `custom/grocy_AI/src/GrocyAiService.php`.
## Code Style
- Indent PHP and JavaScript blocks with tabs. Representative files include `controllers/BaseController.php`, `services/FilesService.php`, `public/js/grocy.js`, and `public/custom/grocy_AI/product-enrichment.js`.
- Put opening braces on the next line for classes, methods, functions, closures, and control structures, following `controllers/Api/BaseApiController.php` and `custom/grocy_AI/tests/run.php`.
- Put one blank line between logical blocks and between methods, as in `custom/grocy_AI/src/GrocyAiService.php`.
- Use trailing commas only where already required or established; PHP arrays in `custom/grocy_AI/src/GrocyAiService.php` generally omit a trailing comma on the final element.
- Prefer single-quoted strings in PHP and JavaScript unless interpolation or embedded quoting makes double quotes useful; examples appear in `custom/grocy_AI/src/GrocyAiService.php` and `public/js/grocy.js`.
- Terminate JavaScript statements with semicolons and wrap isolated custom browser behavior in an IIFE with `'use strict'`, as in `public/custom/grocy_AI/product-enrichment.js`.
- Use LF endings for shell scripts according to `.gitattributes`; no repository-wide editor settings are defined beyond the PHP server root in `.vscode/settings.json`.
- No automated formatter or linter is configured: `composer.json` contains no development tools or scripts, and `package.json` contains dependencies only.
- No `.editorconfig`, ESLint, Prettier, PHP-CS-Fixer, PHPStan, or Psalm configuration is present at the repository root. Preserve the style of the target file and validate changed PHP with `php -l`, especially under `custom/grocy_AI/src/`.
- Do not introduce formatting-only churn in upstream files. Fork changes are intentionally isolated by `CUSTOMIZATIONS.md` to keep upstream merges practical.
## Import Organization
- PHP resolution uses the PSR-4 mappings in `composer.json`; application code imports `Grocy\...` classes instead of relative file paths.
- `custom/grocy_AI/routes.php` manually loads the `GrocyAI` classes with `require_once`; keep any new module class loading inside that module bootstrap unless `composer.json` is deliberately extended.
- Browser code has no module loader or bundler. Use existing globals such as `Grocy`, `U`, `__t`, and jQuery from `public/js/grocy.js` and page scripts under `public/viewjs/`.
- Blade templates reference public assets through the URL helper exposed by `controllers/BaseController.php`; follow the existing asset inclusion in `views/productform.blade.php`.
## Error Handling
- Validate at service boundaries and throw a specific exception before performing external work. `custom/grocy_AI/src/GrocyAiService.php` uses `InvalidArgumentException` for caller input, `LogicException` for configuration, and `RuntimeException` for companion-service failures.
- Preserve the originating exception as the previous exception when translating infrastructure failures, as `custom/grocy_AI/src/GrocyAiService.php` does for JSON and HTTP transport failures.
- In API controllers, perform permission checks before the guarded operation, then translate known exception categories into explicit HTTP statuses with `GenericErrorResponse()`, following `custom/grocy_AI/src/GrocyAiApiController.php` and `controllers/Api/BaseApiController.php`.
- Use `ApiResponse()` for JSON bodies and `EmptyApiResponse()` for successful operations without content, as provided by `controllers/Api/BaseApiController.php`.
- For frontend API calls, restore UI state in both success and failure callbacks and show a user-safe message; `public/custom/grocy_AI/product-enrichment.js` implements this around `Grocy.Api.Get()`.
- Treat malformed server error payloads as expected: parse inside `try/catch` and retain a generic fallback, matching `public/custom/grocy_AI/product-enrichment.js`.
- Use narrow empty catches only when failure is intentionally non-fatal and the fallback is obvious; `controllers/BaseController.php` tolerates a database-not-initialized state during view setup.
## Logging
- Route user-visible browser failures through `Grocy.FrontendHelpers.ShowGenericError()` in `public/js/grocy.js` or a feature-owned error element such as `public/custom/grocy_AI/product-enrichment.js`.
- Use `console.error()` for diagnostic XHR details after providing appropriate UI feedback; examples are `public/js/grocy.js` and `public/viewjs/stockoverview.js`.
- In CLI tests only, write assertion failures to `STDERR`, the summary to `STDOUT`, and exit nonzero on failure, following `custom/grocy_AI/tests/run.php`.
- Never log configured secrets. `custom/grocy_AI/tests/run.php` explicitly verifies that `GetStatus()` from `custom/grocy_AI/src/GrocyAiService.php` does not expose the API key.
## Comments
- Explain security intent or non-obvious operational constraints, not line-by-line mechanics. The redirect restriction comment in `custom/grocy_AI/src/GrocyAiService.php` is the preferred pattern.
- Document intentionally swallowed errors when the reason is not obvious, as in the database initialization catch in `controllers/BaseController.php` and the JSON fallback catch in `public/custom/grocy_AI/product-enrichment.js`.
- Record fork boundaries and every necessary upstream-file edit in `CUSTOMIZATIONS.md`; keep module behavior and configuration documented in `custom/grocy_AI/README.md`.
- Preserve existing TODO markers only when they describe a concrete limitation, such as the locale direction handling note in `controllers/BaseController.php`.
- JSDoc/TSDoc is not an established convention in browser code under `public/js/` or `public/viewjs/`; prefer clear function and variable names.
- PHPDoc is sparse and used for cases where native signatures do not fully express the contract, such as authentication helpers in `middleware/AuthMiddleware.php` and the `Grocycode` type in `helpers/Grocycode.php`.
- Prefer native PHP parameter and return types for new isolated code, as used throughout `custom/grocy_AI/src/GrocyAiService.php`, instead of adding redundant docblocks.
## Function Design
- Use PSR request/response signatures for controller actions, as in `custom/grocy_AI/src/GrocyAiApiController.php` and controllers under `controllers/Api/`.
- Add scalar and return types in new module code where the contract is stable, following `custom/grocy_AI/src/GrocyAiService.php`; preserve untyped signatures when modifying legacy methods in `services/StockService.php` unless the full call graph is verified.
- Use optional constructor callables as explicit seams around external I/O, matching the transport injection in `custom/grocy_AI/src/GrocyAiService.php`.
- Prefer associative arrays at existing HTTP and database boundaries; document and normalize their shape immediately, as `NormalizeResponse()` does in `custom/grocy_AI/src/GrocyAiService.php`.
- Return PSR-7 `Response` objects from typed custom API actions in `custom/grocy_AI/src/GrocyAiApiController.php`.
- Return normalized arrays from service methods that supply API data, following `custom/grocy_AI/src/GrocyAiService.php`.
- Use `null`, empty strings, or empty arrays only when they are part of the local contract; validate uncertain external values before returning them, as in `ScalarString()` and `StringList()` in `custom/grocy_AI/src/GrocyAiService.php`.
## Module Design
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## System Overview
```text
```
## Component Responsibilities
| Component | Responsibility | File |
|-----------|----------------|------|
| Front controller | Resolve the writable data path, check runtime prerequisites, and bootstrap the application | `public/index.php` |
| Application bootstrap | Load dependencies and settings, configure PHP-DI/Slim, middleware, error handling, route caching, and request execution | `app.php` |
| Route registry | Map HTML and REST endpoints and conditionally attach the ATECHPCS extension routes | `routes.php` |
| Page controllers | Query presentation data and render Blade templates | `controllers/StockController.php`, `controllers/RecipesController.php`, `controllers/SystemController.php` |
| API controllers | Enforce permissions, parse/clean request bodies, invoke services, and serialize responses | `controllers/Api/StockApiController.php`, `controllers/Api/GenericEntityApiController.php`, `controllers/Api/BaseApiController.php` |
| Domain services | Implement stock, recipes, chores, tasks, users, files, printing, and calendar behavior | `services/StockService.php`, `services/RecipesService.php`, `services/FilesService.php` |
| Persistence gateway | Own singleton PDO/SQLite and LessQL connections | `services/DatabaseService.php` |
| Schema manager | Execute ordered SQL/PHP migrations and record applied migration IDs | `services/DatabaseMigrationService.php`, `migrations/` |
| Authentication pipeline | Select API-key/session/proxy/LDAP identity and publish request-scoped user constants | `middleware/AuthMiddleware.php`, `middleware/DefaultAuthMiddleware.php` |
| Server-rendered UI | Compose layout, page markup, reusable components, and per-page asset requirements | `views/layout/default.blade.php`, `views/components/`, `views/productform.blade.php` |
| Browser application layer | Provide the shared REST client and page-specific event/workflow code | `public/js/grocy.js`, `public/viewjs/productform.js` |
| Extension boundary | Provide feature-flagged UPC enrichment without owning normal product persistence | `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiService.php`, `public/custom/grocy_AI/product-enrichment.js` |
## Pattern Overview
- Keep one Slim 4 process and one route table as the application boundary; register endpoints in `routes.php` or a feature-gated module route file such as `custom/grocy_AI/routes.php`.
- Use Blade for the initial document and per-view JavaScript for interactions; `views/layout/default.blade.php` automatically loads `public/viewjs/<viewName>.js`.
- Put multi-step domain writes and invariants in `services/`; use `services/StockService.php` as the reference for stock transactions and correlated stock-log records.
- Treat SQLite views and triggers as part of the domain model, not merely storage details; examples live in `migrations/0200.sql`, `migrations/0226.sql`, and `migrations/0255.sql`.
- Keep ATECHPCS behavior isolated under `custom/` and `public/custom/`; the permitted core integration points are documented in `CUSTOMIZATIONS.md`.
## Layers
- Purpose: Establish the runtime data path, configuration constants, dependency container, route registry, and middleware pipeline.
- Location: `public/index.php`, `app.php`, `routes.php`
- Contains: Front-controller setup, `Setting(...)` configuration loading, PHP-DI container entries, route groups, and the Slim run call.
- Depends on: `helpers/PrerequisiteChecker.php`, `config-dist.php`, `packages/autoload.php`, `middleware/`
- Used by: Every HTTP request entering through `public/index.php`.
- Purpose: Resolve authentication and locale globally and normalize API CORS/JSON behavior.
- Location: `middleware/`
- Contains: The `AuthMiddleware` template plus default, session, API-key, reverse-proxy, and LDAP strategies; locale, CORS, and JSON middleware.
- Depends on: `services/SessionService.php`, `services/ApiKeyService.php`, `services/UsersService.php`, `helpers/UrlManager.php`
- Used by: The Slim pipeline configured in `app.php` and the `/api` group configured in `routes.php`.
- Purpose: Translate route inputs into rendered pages or REST responses.
- Location: `controllers/`, `controllers/Api/`
- Contains: Page controllers extending `controllers/BaseController.php` and API controllers extending `controllers/Api/BaseApiController.php`.
- Depends on: `services/`, the LessQL connection exposed by `services/DatabaseService.php`, and permission checks in `controllers/Users/User.php`.
- Used by: Route handlers declared in `routes.php` and `custom/grocy_AI/routes.php`.
- Purpose: Centralize workflows that span entities, transactions, derived values, external calls, or file operations.
- Location: `services/`
- Contains: Stock booking, recipe fulfillment, task/chore state changes, users and sessions, localization, migrations, and file storage.
- Depends on: `services/BaseService.php`, `services/DatabaseService.php`, `helpers/`, and optional external clients.
- Used by: Both page and API controllers in `controllers/` and `controllers/Api/`.
- Purpose: Render responsive HTML and attach shared/component/page-specific browser behavior.
- Location: `views/`, `public/js/`, `public/viewjs/`, `public/css/`
- Contains: Blade pages/components/layout, the global `Grocy` JavaScript namespace, API helpers, and one script per rendered view.
- Depends on: View data populated by `controllers/BaseController.php`, frontend packages requested via `helpers/extensions.php`, and URL generation from `helpers/UrlManager.php`.
- Used by: Page controllers through `helpers/SlimBladeView.php`.
- Purpose: Store user/domain data and expose derived domain projections.
- Location: `services/DatabaseService.php`, `services/DatabaseMigrationService.php`, `migrations/`, runtime `data/grocy.db`
- Contains: PDO SQLite setup, LessQL access, ordered migration scripts, tables, views, triggers, indexes, and migration bookkeeping.
- Depends on: SQLite 3.40+ checked by `helpers/PrerequisiteChecker.php`.
- Used by: `controllers/BaseController.php`, `services/BaseService.php`, and all database-aware controllers/services.
- Purpose: Add locally owned features and pluggable external behavior without expanding the core domain surface.
- Location: `custom/grocy_AI/`, `public/custom/grocy_AI/`, `plugins/`, runtime `data/plugins/`
- Contains: A feature-gated companion-service adapter, product enrichment UI, built-in barcode lookup plugins, and user-installed barcode plugins.
- Depends on: The core controller/service contracts in `controllers/Api/BaseApiController.php`, `helpers/BaseBarcodeLookupPlugin.php`, and `services/StockService.php`.
- Used by: Conditional route registration in `routes.php`, conditional page assets in `views/productform.blade.php`, and plugin loading in `services/StockService.php`.
## Data Flow
### Primary Request Path
### Browser Write Path
### Database Migration Flow
### grocy_AI Enrichment Flow
- Keep durable state in SQLite and `data/storage`; database/file access is implemented by `services/DatabaseService.php` and `services/FilesService.php`.
- Keep identity and locale as request-scoped `GROCY_*` constants established by `middleware/AuthMiddleware.php` and `middleware/LocaleMiddleware.php`.
- Keep server service connections as process-local singleton instances through `services/BaseService.php`; `services/LocalizationService.php` maintains one instance per locale.
- Keep browser state in the global `Grocy` namespace and DOM/page script variables initialized by `views/layout/default.blade.php` and `public/js/grocy.js`.
## Key Abstractions
- Purpose: Give all controllers the DI container, view renderer, LessQL database, localization functions, URL generation, feature flags, permissions, and user settings.
- Examples: `controllers/BaseController.php`, `controllers/Api/BaseApiController.php`
- Pattern: Inheritance with `Render`/`RenderPage` for HTML and `ApiResponse`/`GenericErrorResponse` for JSON.
- Purpose: Reuse domain and infrastructure services with a shared database connection.
- Examples: `services/BaseService.php`, `services/StockService.php`, `services/UsersService.php`
- Pattern: Static `GetInstance()` keyed by called class; use this pattern for core services that extend `BaseService`.
- Purpose: Represent tables/views and rows without dedicated model classes.
- Examples: `services/DatabaseService.php`, `controllers/Api/GenericEntityApiController.php`, `controllers/StockController.php`
- Pattern: Dynamic `$DB->entity()` results and `$DB->entity($id)` rows; preserve entity exposure allowlists from `grocy.openapi.json`.
- Purpose: Gate navigation, pages, routes, and subfeatures with configuration-derived constants.
- Examples: `config-dist.php`, `helpers/extensions.php`, `routes.php`, `views/layout/default.blade.php`
- Pattern: Define settings through `Setting(...)`, read `GROCY_FEATURE_FLAG_*`, and expose those constants to browser code through `controllers/BaseController.php`.
- Purpose: Separate server-provided markup/data from browser event handling while retaining a convention-based link.
- Examples: `views/productform.blade.php` + `public/viewjs/productform.js`, `views/stockoverview.blade.php` + `public/viewjs/stockoverview.js`
- Pattern: Pass the Blade view name from the controller and create a same-basename script under `public/viewjs/`.
- Purpose: Normalize third-party barcode results into product fields and validate required references.
- Examples: `helpers/BaseBarcodeLookupPlugin.php`, `plugins/OpenFoodFactsBarcodeLookupPlugin.php`, `services/StockService.php`
- Pattern: Subclass `BaseBarcodeLookupPlugin`, implement `ExecuteLookup`, and load from built-in `plugins/` or runtime `data/plugins/`.
- Purpose: Keep fork-specific code reviewable and upstream-merge friendly.
- Examples: `CUSTOMIZATIONS.md`, `custom/grocy_AI/`, `public/custom/grocy_AI/`
- Pattern: Put implementation under the custom namespace and restrict core edits to documented feature-flagged hooks.
## Entry Points
- Location: `public/index.php`
- Triggers: Any web request routed through the `public/` document root.
- Responsibilities: Resolve embedded/normal data paths, validate prerequisites, and require `app.php`.
- Location: `app.php`
- Triggers: Required by `public/index.php` after prerequisite validation.
- Responsibilities: Load dependencies/configuration, initialize container/routes/middleware/error handling, and run Slim.
- Location: `routes.php`
- Triggers: Required by `app.php` during application initialization.
- Responsibilities: Register page routes, REST routes, API middleware, OPTIONS handling, and the optional `custom/grocy_AI/routes.php` module.
- Location: `controllers/SystemController.php`
- Triggers: `GET /`, including redirects caused by a version/base-URL hash change in `app.php`.
- Responsibilities: Migrate the SQLite schema, optionally populate demo data, and redirect to the configured entry page.
- Location: `routes.php`, `controllers/Api/`
- Triggers: Requests under `/api`, including calls from `public/js/grocy.js`.
- Responsibilities: Authenticate, authorize, validate, execute generic or domain-specific operations, and return JSON/files.
- Location: `custom/grocy_AI/tests/run.php`
- Triggers: Direct `php custom/grocy_AI/tests/run.php` invocation.
- Responsibilities: Exercise the isolated companion adapter contract without booting the full web application.
- Location: `update.sh`
- Triggers: Direct shell invocation in a deployed release tree.
- Responsibilities: Back up runtime `data/`, replace application files with the latest release, and retain the data directory.
## Architectural Constraints
- **Threading:** Treat each PHP HTTP request as synchronous; blocking SQLite, filesystem, HTTP companion, printer, and webhook work happens in-process through `services/`, `custom/grocy_AI/src/GrocyAiService.php`, and `helpers/WebhookRunner.php`.
- **Global state:** Configuration, authentication, user, locale, feature flags, and frontend-package requirements use `GROCY_*` constants or globals in `helpers/extensions.php`, `middleware/AuthMiddleware.php`, and `views/layout/default.blade.php`.
- **Service state:** Core services and database handles are static singletons in `services/BaseService.php` and `services/DatabaseService.php`; do not assume constructor injection or isolated instances in core service tests.
- **Schema source:** Add all durable schema changes as the next ordered file in `migrations/`; do not edit runtime `data/grocy.db` as source (`services/DatabaseMigrationService.php`).
- **Migration trigger:** Schema migration runs on the root route, and application version/base URL changes force a redirect there (`app.php`, `controllers/SystemController.php`).
- **No domain model layer:** Database rows are dynamic LessQL rows and projections defined by migrations; field and exposure contracts also live in `grocy.openapi.json` (`services/DatabaseService.php`, `controllers/Api/GenericEntityApiController.php`).
- **Document root:** Point the web server at `public/`; application source and runtime SQLite/configuration stay outside the served root (`README.md`, `public/index.php`).
- **Circular imports:** No PHP namespace circular-import chain is required; shared construction flows through `services/DatabaseService.php`, `services/BaseService.php`, and PHP-DI in `app.php`.
- **Fork boundary:** Put ATECHPCS-specific implementation under `custom/`/`public/custom/` and record every unavoidable core hook in `CUSTOMIZATIONS.md`.
## Anti-Patterns
### Adding Domain Writes to Page Controllers
### Treating Generic CRUD as a Universal Domain API
### Expanding Fork Logic Across Core Directories
## Error Handling
- Throw service-level exceptions for invalid domain operations, as in `services/StockService.php`; catch expected exceptions in `controllers/Api/StockApiController.php` and return `GenericErrorResponse`.
- Map typed integration exceptions to 400/502/503 in `custom/grocy_AI/src/GrocyAiApiController.php`.
- Throw Slim HTTP exceptions for permission/missing-resource failures from `controllers/Users/PermissionMissingException.php` and `controllers/Api/FilesApiController.php`.
- Render `views/errors/403.blade.php`, `views/errors/404.blade.php`, or `views/errors/500.blade.php` for non-API failures through `controllers/ExceptionController.php`.
- Keep rollback/commit pairs around explicit multi-step SQL operations in `services/DatabaseMigrationService.php`, `services/RecipesService.php`, `services/ChoresService.php`, and `services/StockService.php`.
## Cross-Cutting Concerns
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
