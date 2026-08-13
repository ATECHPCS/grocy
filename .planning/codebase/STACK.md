# Technology Stack

**Analysis Date:** 2026-08-12

## Languages

**Primary:**
- PHP 8.5 - Server-side application, routing, controllers, services, middleware, migrations, and Blade views live in `app.php`, `routes.php`, `controllers/`, `services/`, `middleware/`, `migrations/`, and `views/`. Composer constrains PHP to `8.5.*` in `composer.json`; runtime startup requires at least 8.5.0 in `helpers/PrerequisiteChecker.php`.
- JavaScript (browser ECMAScript; no transpilation target declared) - Page behavior and reusable UI helpers are plain scripts in `public/js/`, `public/viewjs/`, and `public/custom/grocy_AI/product-enrichment.js`.

**Secondary:**
- SQLite SQL 3.40+ dialect - The schema is evolved through numbered files in `migrations/`; SQLite 3.40.0 is the minimum checked by `helpers/PrerequisiteChecker.php`.
- Blade/HTML - Server-rendered page templates and components use `.blade.php` files under `views/`, rendered through `helpers/SlimBladeView.php`.
- CSS - Application styles are stored in `public/css/` and custom module styles in `public/custom/grocy_AI/grocy-ai.css`.
- Bash and Windows Batch - Release updating and dependency/localization workflows are in `update.sh` and `.devtools/*.bat`.
- JSON/OpenAPI - API metadata and application versioning are defined in `grocy.openapi.json` and `version.json`.
- gettext PO/POT - Runtime translations and source catalogs are under `localization/`, with Transifex mapping in `.tx/config`.

## Runtime

**Environment:**
- PHP 8.5.x runs the web application through `public/index.php`; `composer.json` restricts the supported minor line to 8.5 and `helpers/PrerequisiteChecker.php` enforces 8.5.0 or newer.
- SQLite 3.40.0 or newer is embedded through PHP PDO and checked at startup in `helpers/PrerequisiteChecker.php`; no standalone database server is required.
- Required PHP extensions are `fileinfo`, `pdo_sqlite`, `gd`, `ctype`, `intl`, `zlib`, `mbstring`, `filter`, `iconv`, `tokenizer`, and `json`, as enumerated in `helpers/PrerequisiteChecker.php`.
- The optional LDAP authentication mode additionally needs PHP LDAP functions used by `middleware/LdapAuthMiddleware.php`; this extension is conditional and is not in the baseline prerequisite list in `helpers/PrerequisiteChecker.php`.
- Production serves a responsive/PWA-style browser frontend to a recent Firefox, Chrome, or Edge, per `README.md`; browser camera barcode scanning in `public/viewjs/components/camerabarcodescanner.js` requires HTTPS.
- The application release represented by this checkout is Grocy 4.6.0, recorded in `version.json`.

**Package Manager:**
- Composer 2-compatible dependency management - the exact Composer executable version is not pinned; `composer.lock` records plugin API 2.9.0 and is present.
- Composer installs runtime libraries into the non-default `packages/` directory via `composer.json`; `public/index.php` refuses startup when `packages/autoload.php` is missing through `helpers/PrerequisiteChecker.php`.
- Yarn Classic lockfile format v1 - the exact Yarn executable version is not pinned; `yarn.lock` is present.
- Yarn installs production-only packages directly into `public/packages/`, with install scripts and optional dependencies disabled by `.yarnrc`; there is no npm lockfile.
- npm manages only the isolated browser-test workspace under `custom/grocy_AI/tests/browser/`; its generated `package-lock.json` pins test dependencies without changing the root Yarn production dependency tree.

## Frameworks

**Core:**
- Slim 4.15.2 - HTTP application, route groups, PSR-7 request/response handling, and middleware composition are configured in `app.php` and `routes.php`; version is locked in `composer.lock`.
- webman/blade 1.5.7 - Blade template rendering for `views/`, adapted by `helpers/SlimBladeView.php`; version is locked in `composer.lock`.
- PHP-DI 7.1.1 - Dependency injection container passed to Slim and controllers in `app.php`; version is locked in `composer.lock`.
- LessQL `dev-master-fork` - Lightweight relational mapper over PDO SQLite used through `services/DatabaseService.php`; source fork is declared in `composer.json` and locked in `composer.lock`.
- Bootstrap 4.6.2 and jQuery 3.7.1 - Core browser UI and DOM/AJAX layer loaded by `views/layout/default.blade.php`; versions are locked in `yarn.lock`.
- DataTables 1.13.11 - Tabular master-data and report UI loaded on demand by Blade views such as `views/products.blade.php`; version is locked in `yarn.lock`.

**Testing:**
- `@playwright/test` 1.62.1 is pinned in the private `custom/grocy_AI/tests/browser/` workspace for deterministic Chromium/WebKit mobile coverage; it is not a production dependency.
- The `grocy_AI` module uses a standalone PHP contract test script at `custom/grocy_AI/tests/run.php`, run with `php custom/grocy_AI/tests/run.php` as documented in `custom/grocy_AI/README.md`.
- PHPUnit references in `composer.lock` belong to dependencies' development metadata and are not installed as this project's test framework because `composer.json` has no `require-dev` section.

**Build/Dev:**
- No bundler or transpiler is configured; Yarn places distributable dependency assets in `public/packages/`, and `views/layout/default.blade.php` links those files directly.
- Composer PSR-4 autoloading maps `Grocy\Services\`, `Grocy\Controllers\`, `Grocy\Middleware\`, and `Grocy\Helpers\` to `services/`, `controllers/`, `middleware/`, and `helpers/` in `composer.json`.
- Frontend packages are selected per view with `require_frontend_packages()` in `helpers/extensions.php` and emitted conditionally by `views/layout/default.blade.php`.
- Windows dependency maintenance uses Composer and Yarn from `.devtools/install_dependencies.bat` and `.devtools/update_dependencies.bat`.
- Release archives are assembled by `.devtools/create_release_package.bat`; in-place Linux updates and backups are performed by `update.sh`.
- Swagger UI 5.32.10 renders the checked-in OpenAPI 3.1 specification from `grocy.openapi.json` via `views/openapiui.blade.php`; the package version is locked in `yarn.lock`.

## Key Dependencies

**Critical:**
- `slim/slim` 4.15.2, `slim/psr7` 1.8.0, and `slim/http` 1.4.0 - Core HTTP stack in `app.php` and `routes.php`, locked by `composer.lock`.
- `webman/blade` 1.5.7 and Illuminate 12.64 components - Server-side rendering of `views/`, locked by `composer.lock`.
- `morris/lessql` `dev-master-fork` - Main database abstraction used by `services/DatabaseService.php`, sourced from the VCS repository declared in `composer.json`.
- `php-di/php-di` 7.1.1 - Runtime container for Slim, controllers, middleware, views, and URL management in `app.php`.
- `guzzlehttp/guzzle` 7.15.1 - Outbound HTTP for barcode lookup, label-printer webhooks, remote product images, and `grocy_AI` companion calls in `plugins/OpenFoodFactsBarcodeLookupPlugin.php`, `helpers/WebhookRunner.php`, `services/StockService.php`, and `custom/grocy_AI/src/GrocyAiService.php`.
- `jquery` 3.7.1 and `bootstrap` 4.6.2 - Base browser interaction and styling loaded in `views/layout/default.blade.php`.

**Infrastructure:**
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

**Environment:**
- Copy `config-dist.php` to the required runtime file `data/config.php` and keep the data directory writable, as described in `README.md` and enforced by `helpers/PrerequisiteChecker.php`.
- Configuration precedence is: constants already defined by `data/config.php`; otherwise a matching `GROCY_DATAPATH/settingoverrides/<SETTING>.txt`; otherwise `GROCY_<SETTING>` from the process environment; otherwise the default in `config-dist.php`, implemented by `Setting()` in `helpers/extensions.php`.
- `GROCY_DATAPATH` separately selects the data directory before application startup in `public/index.php`; relative values resolve beneath `public/` and the default points to `data/`.
- Core URL/deployment controls are `GROCY_BASE_URL`, `GROCY_BASE_PATH`, `GROCY_DISABLE_URL_REWRITING`, `GROCY_MODE`, and `GROCY_DATAPATH`, defined or consumed in `config-dist.php`, `public/index.php`, and `app.php`.
- Conditional integration configuration includes `GROCY_AUTH_CLASS`, `GROCY_REVERSE_PROXY_AUTH_HEADER`, `GROCY_REVERSE_PROXY_AUTH_USE_ENV`, `GROCY_LDAP_*`, `GROCY_LABEL_PRINTER_*`, `GROCY_TPRINTER_*`, `GROCY_STOCK_BARCODE_LOOKUP_PLUGIN`, and `GROCY_AI_*`, with defaults in `config-dist.php`.
- No `.env` file or dotenv loader is present; use `data/config.php`, process environment variables, or embedded-mode override files as implemented by `public/index.php` and `helpers/extensions.php`.
- Runtime secrets and writable state belong under the ignored `data/` tree: `data/.gitignore` ignores everything except metadata directories/files, while `data/config.php` is required but deliberately untracked.

**Build:**
- Backend dependencies and autoload rules are defined by `composer.json` and locked in `composer.lock`; install output goes to ignored `packages/`.
- Frontend dependencies are defined by `package.json` and locked in `yarn.lock`; `.yarnrc` places production assets in ignored `public/packages/`.
- The application has no Webpack, Vite, Rollup, Parcel, TypeScript, Babel, ESLint, or CSS preprocessor configuration in the repository.
- View and Slim route caches are created beneath `GROCY_DATAPATH/viewcache` in `app.php`; the cache is invalidated when `version.json`, `GROCY_BASE_URL`, or `GROCY_BASE_PATH` changes.
- The API contract is a checked-in OpenAPI 3.1 document at `grocy.openapi.json`; Swagger UI loads it through routes defined in `routes.php`.

## Platform Requirements

**Development:**
- Install PHP 8.5.x with the extensions enumerated in `helpers/PrerequisiteChecker.php`, SQLite 3.40+, Composer, and Yarn Classic-compatible tooling; setup commands are represented in `.devtools/install_dependencies.bat`.
- Run `composer install` to create `packages/autoload.php` and `yarn install` to populate `public/packages/`, matching `composer.json`, `.yarnrc`, and `README.md`.
- Create writable `data/config.php` from `config-dist.php` and point the web server document root at `public/`, per `README.md`.
- Use URL rewriting to `public/index.php` or set `GROCY_DISABLE_URL_REWRITING`; sample Apache rules are in `public/.htaccess`, while nginx guidance is in `README.md`.
- Optional update tooling in `update.sh` requires Bash, `tar`, `wget`, and `unzip`; Windows release development additionally uses the commands referenced by `.devtools/*.bat`.

**Production:**
- Deploy as a self-hosted PHP web application with the web root at `public/` and persistent writable state under `GROCY_DATAPATH`, as specified in `README.md` and `public/index.php`.
- A conventional Apache or nginx/PHP runtime is supported through `public/.htaccess` and the nginx rewrite guidance in `README.md`; this repository does not include a container image or compose manifest.
- `README.md` points operators to the external LinuxServer Grocy image for Docker deployments, but hosting remains operator-managed and no hosting vendor is required by application code.
- Preserve the complete data path across upgrades because the SQLite database, uploaded files, configuration, caches, and backups live below it as implemented in `services/DatabaseService.php`, `services/FilesService.php`, and `update.sh`.

---

*Stack analysis: 2026-08-12*
