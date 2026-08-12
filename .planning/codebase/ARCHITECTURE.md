<!-- refreshed: 2026-08-12 -->
# Architecture

**Analysis Date:** 2026-08-12

## System Overview

```text
┌─────────────────────────────────────────────────────────────┐
│            HTTP / Browser Presentation Layer                  │
├──────────────────┬──────────────────┬───────────────────────┤
│ Slim page routes │ Slim JSON API    │ Blade + page JS       │
│ `routes.php`     │ `controllers/Api/`│ `views/`, `public/`   │
└────────┬─────────┴────────┬─────────┴──────────────────────┘
         │                  │
         ▼                  ▼
┌────────────────────────────────────────────────────────────┐
│        Controllers, middleware, and domain services           │
│ `controllers/`, `middleware/`, `services/`, `helpers/`       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│ SQLite + LessQL rows/views/triggers; runtime file storage       │
│ `services/DatabaseService.php`, `migrations/`, `data/`        │
└──────────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────────┐
│ Optional HTTP services, barcode plugins, printers, webhooks    │
│ `custom/grocy_AI/`, `plugins/`, `helpers/WebhookRunner.php`   │
└────────────────────────────────────────────────────────────┘
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

**Overall:** Server-rendered modular monolith with MVC-style controllers, singleton service objects, a REST-driven browser layer, and SQLite-backed data access (`app.php`, `controllers/`, `services/`, `views/`, `public/viewjs/`).

**Key Characteristics:**
- Keep one Slim 4 process and one route table as the application boundary; register endpoints in `routes.php` or a feature-gated module route file such as `custom/grocy_AI/routes.php`.
- Use Blade for the initial document and per-view JavaScript for interactions; `views/layout/default.blade.php` automatically loads `public/viewjs/<viewName>.js`.
- Put multi-step domain writes and invariants in `services/`; use `services/StockService.php` as the reference for stock transactions and correlated stock-log records.
- Treat SQLite views and triggers as part of the domain model, not merely storage details; examples live in `migrations/0200.sql`, `migrations/0226.sql`, and `migrations/0255.sql`.
- Keep ATECHPCS behavior isolated under `custom/` and `public/custom/`; the permitted core integration points are documented in `CUSTOMIZATIONS.md`.

## Layers

**Bootstrap and Routing:**
- Purpose: Establish the runtime data path, configuration constants, dependency container, route registry, and middleware pipeline.
- Location: `public/index.php`, `app.php`, `routes.php`
- Contains: Front-controller setup, `Setting(...)` configuration loading, PHP-DI container entries, route groups, and the Slim run call.
- Depends on: `helpers/PrerequisiteChecker.php`, `config-dist.php`, `packages/autoload.php`, `middleware/`
- Used by: Every HTTP request entering through `public/index.php`.

**Middleware and Identity:**
- Purpose: Resolve authentication and locale globally and normalize API CORS/JSON behavior.
- Location: `middleware/`
- Contains: The `AuthMiddleware` template plus default, session, API-key, reverse-proxy, and LDAP strategies; locale, CORS, and JSON middleware.
- Depends on: `services/SessionService.php`, `services/ApiKeyService.php`, `services/UsersService.php`, `helpers/UrlManager.php`
- Used by: The Slim pipeline configured in `app.php` and the `/api` group configured in `routes.php`.

**HTTP Controllers:**
- Purpose: Translate route inputs into rendered pages or REST responses.
- Location: `controllers/`, `controllers/Api/`
- Contains: Page controllers extending `controllers/BaseController.php` and API controllers extending `controllers/Api/BaseApiController.php`.
- Depends on: `services/`, the LessQL connection exposed by `services/DatabaseService.php`, and permission checks in `controllers/Users/User.php`.
- Used by: Route handlers declared in `routes.php` and `custom/grocy_AI/routes.php`.

**Domain Services:**
- Purpose: Centralize workflows that span entities, transactions, derived values, external calls, or file operations.
- Location: `services/`
- Contains: Stock booking, recipe fulfillment, task/chore state changes, users and sessions, localization, migrations, and file storage.
- Depends on: `services/BaseService.php`, `services/DatabaseService.php`, `helpers/`, and optional external clients.
- Used by: Both page and API controllers in `controllers/` and `controllers/Api/`.

**Presentation:**
- Purpose: Render responsive HTML and attach shared/component/page-specific browser behavior.
- Location: `views/`, `public/js/`, `public/viewjs/`, `public/css/`
- Contains: Blade pages/components/layout, the global `Grocy` JavaScript namespace, API helpers, and one script per rendered view.
- Depends on: View data populated by `controllers/BaseController.php`, frontend packages requested via `helpers/extensions.php`, and URL generation from `helpers/UrlManager.php`.
- Used by: Page controllers through `helpers/SlimBladeView.php`.

**Persistence:**
- Purpose: Store user/domain data and expose derived domain projections.
- Location: `services/DatabaseService.php`, `services/DatabaseMigrationService.php`, `migrations/`, runtime `data/grocy.db`
- Contains: PDO SQLite setup, LessQL access, ordered migration scripts, tables, views, triggers, indexes, and migration bookkeeping.
- Depends on: SQLite 3.40+ checked by `helpers/PrerequisiteChecker.php`.
- Used by: `controllers/BaseController.php`, `services/BaseService.php`, and all database-aware controllers/services.

**Optional Extensions and Integrations:**
- Purpose: Add locally owned features and pluggable external behavior without expanding the core domain surface.
- Location: `custom/grocy_AI/`, `public/custom/grocy_AI/`, `plugins/`, runtime `data/plugins/`
- Contains: A feature-gated companion-service adapter, product enrichment UI, built-in barcode lookup plugins, and user-installed barcode plugins.
- Depends on: The core controller/service contracts in `controllers/Api/BaseApiController.php`, `helpers/BaseBarcodeLookupPlugin.php`, and `services/StockService.php`.
- Used by: Conditional route registration in `routes.php`, conditional page assets in `views/productform.blade.php`, and plugin loading in `services/StockService.php`.

## Data Flow

### Primary Request Path

1. The web server sends a request to the front controller, which resolves `GROCY_DATAPATH`, verifies PHP/SQLite/config/dependencies, and loads the application (`public/index.php:3`, `public/index.php:37`).
2. The bootstrap loads runtime settings, creates Slim/PHP-DI services, registers routes and middleware, and runs the application (`app.php:10`, `app.php:79`, `app.php:99`, `app.php:108`, `app.php:121`).
3. Middleware resolves the route, authenticates the user, establishes `GROCY_USER_ID`/`GROCY_AUTHENTICATED`, and selects the locale (`middleware/AuthMiddleware.php:13`, `middleware/LocaleMiddleware.php:12`).
4. A page route such as `/product/{productId}` invokes a page controller (`routes.php:62`, `controllers/StockController.php:198`).
5. The controller reads LessQL entities and service projections, then calls `RenderPage` (`controllers/StockController.php:204`, `controllers/BaseController.php:95`).
6. The Blade renderer composes the page with the shared layout and loads `public/viewjs/productform.js` from the `viewName` convention (`helpers/SlimBladeView.php:20`, `views/layout/default.blade.php:688`, `views/layout/default.blade.php:772`).

### Browser Write Path

1. A page script serializes a form and calls `Grocy.Api.Post`, `Put`, or a domain endpoint (`public/viewjs/productform.js:81`, `public/viewjs/productform.js:105`, `public/js/grocy.js:39`).
2. The `/api` route group applies JSON/CORS handling and dispatches to an API controller (`routes.php:151`, `routes.php:167`).
3. The API controller checks permissions and purifies JSON request values before using LessQL generic CRUD or a domain service (`controllers/Api/GenericEntityApiController.php:14`, `controllers/Api/BaseApiController.php:157`).
4. LessQL persists a row directly for exposed generic entities, while domain endpoints delegate multi-entity behavior to services (`controllers/Api/GenericEntityApiController.php:53`, `controllers/Api/StockApiController.php:151`).
5. The API base writes JSON or a 204 response; the browser callback updates or redirects the page (`controllers/Api/BaseApiController.php:18`, `controllers/Api/BaseApiController.php:29`, `public/viewjs/productform.js:38`).

### Database Migration Flow

1. The root route invokes `DatabaseMigrationService::MigrateDatabase` before redirecting to the configured feature entry page (`controllers/SystemController.php:27`).
2. Migration files from `migrations/` are sorted by filename, executed once, and recorded in the `migrations` table (`services/DatabaseMigrationService.php:13`).
3. SQL migrations run inside explicit PDO transactions, while `migrations/8888.php` executes on every migration pass for runtime invariants (`services/DatabaseMigrationService.php:60`, `migrations/8888.php`).

### grocy_AI Enrichment Flow

1. `routes.php` includes the module routes only when `GROCY_FEATURE_FLAG_GROCY_AI` is enabled (`routes.php:270`, `custom/grocy_AI/routes.php:11`).
2. The product form loads module CSS/JS and exposes the enrichment panel only behind the same flag (`views/productform.blade.php:5`, `views/productform.blade.php:922`).
3. The browser calls the Grocy enrichment endpoint; the controller checks `MASTER_DATA_EDIT` and invokes the companion adapter (`public/custom/grocy_AI/product-enrichment.js:226`, `custom/grocy_AI/src/GrocyAiApiController.php:18`).
4. The adapter normalizes the UPC, calls the configured companion `/v1` endpoint, validates the response, and returns safe candidate data (`custom/grocy_AI/src/GrocyAiService.php:30`, `custom/grocy_AI/src/GrocyAiService.php:176`).
5. A user-selected name or proxied image is attached to the existing product form and persists only through the normal product save/upload flow (`public/custom/grocy_AI/product-enrichment.js:55`, `public/custom/grocy_AI/product-enrichment.js:80`, `public/viewjs/productform.js:81`).

**State Management:**
- Keep durable state in SQLite and `data/storage`; database/file access is implemented by `services/DatabaseService.php` and `services/FilesService.php`.
- Keep identity and locale as request-scoped `GROCY_*` constants established by `middleware/AuthMiddleware.php` and `middleware/LocaleMiddleware.php`.
- Keep server service connections as process-local singleton instances through `services/BaseService.php`; `services/LocalizationService.php` maintains one instance per locale.
- Keep browser state in the global `Grocy` namespace and DOM/page script variables initialized by `views/layout/default.blade.php` and `public/js/grocy.js`.

## Key Abstractions

**Base Controller:**
- Purpose: Give all controllers the DI container, view renderer, LessQL database, localization functions, URL generation, feature flags, permissions, and user settings.
- Examples: `controllers/BaseController.php`, `controllers/Api/BaseApiController.php`
- Pattern: Inheritance with `Render`/`RenderPage` for HTML and `ApiResponse`/`GenericErrorResponse` for JSON.

**Singleton Service:**
- Purpose: Reuse domain and infrastructure services with a shared database connection.
- Examples: `services/BaseService.php`, `services/StockService.php`, `services/UsersService.php`
- Pattern: Static `GetInstance()` keyed by called class; use this pattern for core services that extend `BaseService`.

**LessQL Entity/Result:**
- Purpose: Represent tables/views and rows without dedicated model classes.
- Examples: `services/DatabaseService.php`, `controllers/Api/GenericEntityApiController.php`, `controllers/StockController.php`
- Pattern: Dynamic `$DB->entity()` results and `$DB->entity($id)` rows; preserve entity exposure allowlists from `grocy.openapi.json`.

**Feature Flag:**
- Purpose: Gate navigation, pages, routes, and subfeatures with configuration-derived constants.
- Examples: `config-dist.php`, `helpers/extensions.php`, `routes.php`, `views/layout/default.blade.php`
- Pattern: Define settings through `Setting(...)`, read `GROCY_FEATURE_FLAG_*`, and expose those constants to browser code through `controllers/BaseController.php`.

**View/Page-Script Pair:**
- Purpose: Separate server-provided markup/data from browser event handling while retaining a convention-based link.
- Examples: `views/productform.blade.php` + `public/viewjs/productform.js`, `views/stockoverview.blade.php` + `public/viewjs/stockoverview.js`
- Pattern: Pass the Blade view name from the controller and create a same-basename script under `public/viewjs/`.

**Barcode Lookup Plugin:**
- Purpose: Normalize third-party barcode results into product fields and validate required references.
- Examples: `helpers/BaseBarcodeLookupPlugin.php`, `plugins/OpenFoodFactsBarcodeLookupPlugin.php`, `services/StockService.php`
- Pattern: Subclass `BaseBarcodeLookupPlugin`, implement `ExecuteLookup`, and load from built-in `plugins/` or runtime `data/plugins/`.

**Custom Module Boundary:**
- Purpose: Keep fork-specific code reviewable and upstream-merge friendly.
- Examples: `CUSTOMIZATIONS.md`, `custom/grocy_AI/`, `public/custom/grocy_AI/`
- Pattern: Put implementation under the custom namespace and restrict core edits to documented feature-flagged hooks.

## Entry Points

**Web Application:**
- Location: `public/index.php`
- Triggers: Any web request routed through the `public/` document root.
- Responsibilities: Resolve embedded/normal data paths, validate prerequisites, and require `app.php`.

**Slim Bootstrap:**
- Location: `app.php`
- Triggers: Required by `public/index.php` after prerequisite validation.
- Responsibilities: Load dependencies/configuration, initialize container/routes/middleware/error handling, and run Slim.

**Route Registry:**
- Location: `routes.php`
- Triggers: Required by `app.php` during application initialization.
- Responsibilities: Register page routes, REST routes, API middleware, OPTIONS handling, and the optional `custom/grocy_AI/routes.php` module.

**Root/Migration Route:**
- Location: `controllers/SystemController.php`
- Triggers: `GET /`, including redirects caused by a version/base-URL hash change in `app.php`.
- Responsibilities: Migrate the SQLite schema, optionally populate demo data, and redirect to the configured entry page.

**REST API:**
- Location: `routes.php`, `controllers/Api/`
- Triggers: Requests under `/api`, including calls from `public/js/grocy.js`.
- Responsibilities: Authenticate, authorize, validate, execute generic or domain-specific operations, and return JSON/files.

**Standalone grocy_AI Checks:**
- Location: `custom/grocy_AI/tests/run.php`
- Triggers: Direct `php custom/grocy_AI/tests/run.php` invocation.
- Responsibilities: Exercise the isolated companion adapter contract without booting the full web application.

**Maintenance Update Script:**
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

**What happens:** Page controllers already query LessQL directly for render data, while generic REST CRUD writes directly through `controllers/Api/GenericEntityApiController.php`; placing multi-table workflows there would bypass stock/recipe invariants in `services/StockService.php` and `services/RecipesService.php`.
**Why it's wrong:** Cross-entity updates, logging, user settings, webhooks, and transactions would become split across `controllers/` and `services/`, making API and browser behavior diverge.
**Do this instead:** Keep page controllers read/render oriented, enforce permissions in `controllers/Api/`, and implement multi-step writes in the matching service under `services/`.

### Treating Generic CRUD as a Universal Domain API

**What happens:** `/api/objects/{entity}` can persist exposed rows directly through `controllers/Api/GenericEntityApiController.php`, but stock bookings, recipe consumption, chore executions, and task completion have dedicated controllers and services in `controllers/Api/` and `services/`.
**Why it's wrong:** Direct row changes can skip transaction logs, derived-state updates, permission nuances, and database/service invariants defined in `services/StockService.php` and `migrations/`.
**Do this instead:** Use generic CRUD only for simple exposed master data; add a named route/controller/service flow for behavioral operations, following `controllers/Api/StockApiController.php` and `services/StockService.php`.

### Expanding Fork Logic Across Core Directories

**What happens:** The `grocy_AI` module uses three documented core hooks and keeps implementation under `custom/grocy_AI/` and `public/custom/grocy_AI/`; scattering companion behavior through core controllers/services would enlarge the upstream merge surface recorded in `CUSTOMIZATIONS.md`.
**Why it's wrong:** Upstream merges become conflict-prone and the extension can no longer be disabled as one cohesive unit.
**Do this instead:** Add fork features within `custom/`, attach them through a feature-gated `custom/<module>/routes.php`, and limit/record core view/config/route hooks in `CUSTOMIZATIONS.md`.

## Error Handling

**Strategy:** Convert expected API failures into status-specific JSON at the API-controller boundary and let the global Slim error handler render uncaught API or HTML errors (`controllers/Api/BaseApiController.php`, `controllers/ExceptionController.php`).

**Patterns:**
- Throw service-level exceptions for invalid domain operations, as in `services/StockService.php`; catch expected exceptions in `controllers/Api/StockApiController.php` and return `GenericErrorResponse`.
- Map typed integration exceptions to 400/502/503 in `custom/grocy_AI/src/GrocyAiApiController.php`.
- Throw Slim HTTP exceptions for permission/missing-resource failures from `controllers/Users/PermissionMissingException.php` and `controllers/Api/FilesApiController.php`.
- Render `views/errors/403.blade.php`, `views/errors/404.blade.php`, or `views/errors/500.blade.php` for non-API failures through `controllers/ExceptionController.php`.
- Keep rollback/commit pairs around explicit multi-step SQL operations in `services/DatabaseMigrationService.php`, `services/RecipesService.php`, `services/ChoresService.php`, and `services/StockService.php`.

## Cross-Cutting Concerns

**Logging:** Development SQL logging appends to runtime `data/sql.log` through `services/DatabaseService.php`; webhook diagnostics write to stderr through `helpers/WebhookRunner.php`; uncaught exception details are controlled by `app.php` and `controllers/ExceptionController.php`.

**Validation:** Validate runtime prerequisites/configuration in `helpers/PrerequisiteChecker.php` and `helpers/ConfigurationValidator.php`; purify JSON scalar values in `controllers/Api/BaseApiController.php`; validate entity/file allowlists against `grocy.openapi.json` in `controllers/Api/GenericEntityApiController.php` and `controllers/Api/FilesApiController.php`.

**Authentication:** Select the authentication strategy through `GROCY_AUTH_CLASS` in `config-dist.php`; implement request authentication in `middleware/AuthMiddleware.php` subclasses and resource authorization through `controllers/Users/User.php`.

**Localization:** Resolve the locale in `middleware/LocaleMiddleware.php`, load gettext catalogs in `services/LocalizationService.php`, and expose PHP/JavaScript translation helpers from `controllers/BaseController.php` and `views/layout/default.blade.php`.

**Configuration:** Define defaults with `Setting(...)` in `config-dist.php`; allow deployment values from runtime `data/config.php`, `GROCY_*` environment variables, or embedded-mode override files via `helpers/extensions.php`.

**Files:** Store uploads and derived thumbnails below runtime `data/storage` through `services/FilesService.php`; serve only OpenAPI-allowlisted groups through `controllers/Api/FilesApiController.php`.

---

*Architecture analysis: 2026-08-12*
