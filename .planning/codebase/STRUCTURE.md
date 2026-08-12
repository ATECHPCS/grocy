# Codebase Structure

**Analysis Date:** 2026-08-12

## Directory Layout

```text
grocy/
├── public/                    # Web document root, shared browser assets, per-view JS
│   ├── index.php              # Front controller
│   ├── css/                   # Core stylesheets
│   ├── js/                    # Shared Grocy browser runtime/helpers
│   ├── viewjs/                # One browser script per Blade page
│   │   └── components/        # Browser behavior for reusable view components
│   ├── custom/grocy_AI/       # Public assets for the ATECHPCS module
│   ├── img/                   # Brand/application images
│   └── uisounds/              # Browser notification sounds
├── controllers/               # Slim page controllers
│   ├── Api/                   # JSON/file API controllers
│   └── Users/                 # User permission model and exception
├── services/                  # Domain and infrastructure services
├── middleware/                # Authentication, locale, CORS, JSON middleware
├── helpers/                   # Rendering, URL, config, barcode, webhook helpers
├── views/                     # Blade pages
│   ├── layout/                # Shared page shell
│   ├── components/            # Reusable Blade fragments
│   └── errors/                # HTTP error pages
├── migrations/                # Ordered SQLite SQL/PHP migrations
├── localization/              # POT templates and locale-specific PO catalogs
├── plugins/                   # Built-in barcode lookup plugins
├── custom/grocy_AI/           # Fork-specific PHP module, docs, and checks
├── data/                      # Writable runtime config, SQLite, cache, files, plugins
├── docs/                      # Focused feature documentation
├── changelog/                 # Numbered release notes
├── .devtools/                 # Windows release/dependency/localization tooling
├── .github/                   # Contribution/security templates and publication assets
├── .planning/codebase/        # Generated GSD codebase reference
├── app.php                    # Application bootstrap
├── routes.php                 # Page/API route registry
├── config-dist.php            # Versioned configuration defaults
├── grocy.openapi.json         # REST contract and entity/file allowlists
├── composer.json              # PHP dependencies and PSR-4 mappings
├── package.json               # Browser dependencies
├── CUSTOMIZATIONS.md          # ATECHPCS fork boundary and core hooks
└── update.sh                  # Release-tree backup/update utility
```

## Directory Purposes

**`public/`:**
- Purpose: Serve the complete HTTP-visible surface; configure the web server document root here, as required by `README.md`.
- Contains: `public/index.php`, CSS, images, shared JS, page JS, UI sounds, and fork module assets.
- Key files: `public/index.php`, `public/js/grocy.js`, `public/css/grocy.css`, `public/.htaccess`

**`public/viewjs/`:**
- Purpose: Hold page-specific browser logic automatically selected by the Blade view name in `views/layout/default.blade.php`.
- Contains: Scripts named after page templates plus component scripts under `public/viewjs/components/`.
- Key files: `public/viewjs/productform.js`, `public/viewjs/stockoverview.js`, `public/viewjs/components/productpicker.js`

**`controllers/`:**
- Purpose: Implement server-rendered Slim handlers and shared controller rendering behavior.
- Contains: Domain-oriented `*Controller.php` classes, `controllers/BaseController.php`, and the `controllers/GrocycodeTrait.php` reusable response trait.
- Key files: `controllers/BaseController.php`, `controllers/SystemController.php`, `controllers/StockController.php`, `controllers/GenericEntityController.php`

**`controllers/Api/`:**
- Purpose: Implement the REST and file-serving handler layer declared under `/api` in `routes.php`.
- Contains: `*ApiController.php` domain controllers and `controllers/Api/BaseApiController.php` response/body/filter helpers.
- Key files: `controllers/Api/BaseApiController.php`, `controllers/Api/GenericEntityApiController.php`, `controllers/Api/StockApiController.php`, `controllers/Api/FilesApiController.php`

**`controllers/Users/`:**
- Purpose: Define user permission constants/checks and the permission-specific HTTP exception.
- Contains: `controllers/Users/User.php`, `controllers/Users/PermissionMissingException.php`
- Key files: `controllers/Users/User.php`, `controllers/Users/PermissionMissingException.php`

**`services/`:**
- Purpose: Own domain workflows and reusable infrastructure behavior.
- Contains: `*Service.php` classes for stock, recipes, chores, tasks, batteries, users, sessions, files, localization, printing, calendar, database, and migration behavior.
- Key files: `services/BaseService.php`, `services/DatabaseService.php`, `services/DatabaseMigrationService.php`, `services/StockService.php`

**`middleware/`:**
- Purpose: Implement Slim request/response cross-cutting behavior.
- Contains: Base/auth middleware, authentication strategies, locale selection, JSON response headers, and CORS handling.
- Key files: `middleware/AuthMiddleware.php`, `middleware/DefaultAuthMiddleware.php`, `middleware/ApiKeyAuthMiddleware.php`, `middleware/LocaleMiddleware.php`

**`helpers/`:**
- Purpose: Provide non-domain infrastructure utilities and globally autoloaded helper functions.
- Contains: Blade adapter, URL construction, prerequisites/config validation, barcode plugin contract, webhook client, Grocycode utility, and `helpers/extensions.php`.
- Key files: `helpers/extensions.php`, `helpers/SlimBladeView.php`, `helpers/PrerequisiteChecker.php`, `helpers/BaseBarcodeLookupPlugin.php`

**`views/`:**
- Purpose: Hold server-rendered Blade page templates.
- Contains: Flat page templates such as `views/productform.blade.php`, reusable `views/components/`, shared `views/layout/`, and `views/errors/`.
- Key files: `views/layout/default.blade.php`, `views/productform.blade.php`, `views/stockoverview.blade.php`

**`views/components/`:**
- Purpose: Encapsulate reusable picker, card, date/number input, and user-field markup.
- Contains: Component templates whose browser counterparts live under `public/viewjs/components/` when interaction code is required.
- Key files: `views/components/productpicker.blade.php`, `views/components/productcard.blade.php`, `views/components/userfieldsform.blade.php`

**`migrations/`:**
- Purpose: Define the canonical SQLite schema and all ordered schema/data transitions used by `services/DatabaseMigrationService.php`.
- Contains: Zero-padded `NNNN.sql` and occasional `NNNN.php` files; `migrations/8888.php` is the always-run invariant migration.
- Key files: `migrations/0001.sql`, `migrations/0200.sql`, `migrations/0226.sql`, `migrations/0255.sql`, `migrations/8888.php`

**`localization/`:**
- Purpose: Supply gettext source templates and compiled-at-runtime locale catalogs used by `services/LocalizationService.php`.
- Contains: Root `*.pot` templates and one directory per locale containing corresponding `*.po` resources.
- Key files: `localization/strings.pot`, `localization/en/strings.po`, `localization/de/strings.po`

**`plugins/`:**
- Purpose: Provide built-in barcode lookup implementations loaded by `services/StockService.php`.
- Contains: Concrete classes extending `helpers/BaseBarcodeLookupPlugin.php`.
- Key files: `plugins/OpenFoodFactsBarcodeLookupPlugin.php`, `plugins/DemoBarcodeLookupPlugin.php`

**`custom/grocy_AI/`:**
- Purpose: Isolate the ATECHPCS companion-service module from upstream Grocy code.
- Contains: Feature route registration, service/controller implementation, module documentation, and a standalone contract check.
- Key files: `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/tests/run.php`

**`public/custom/grocy_AI/`:**
- Purpose: Serve browser assets owned by the ATECHPCS module.
- Contains: Product enrichment JavaScript and CSS loaded conditionally by `views/productform.blade.php`.
- Key files: `public/custom/grocy_AI/product-enrichment.js`, `public/custom/grocy_AI/grocy-ai.css`

**`data/`:**
- Purpose: Act as the writable deployment data root selected by `public/index.php` and excluded from source control except for protective placeholder files.
- Contains: Deployment `data/config.php`, `data/grocy.db`, `data/viewcache/`, `data/storage/`, `data/plugins/`, backups, logs, and embedded setting overrides as created/configured at runtime.
- Key files: `data/.gitignore`, `data/.htaccess`, `data/plugins/.gitignore`

**`docs/`:**
- Purpose: Document focused user/developer feature contracts outside the main `README.md`.
- Contains: Markdown documentation for Grocycode and label printing.
- Key files: `docs/grocycode.md`, `docs/label-printing.md`

**`changelog/`:**
- Purpose: Feed release history returned by `services/ApplicationService.php`.
- Contains: Numbered Markdown files named with release number, version, and date.
- Key files: `changelog/__TEMPLATE.md`, files matching `changelog/NNNN_vX.Y.Z_YYYY-MM-DD.md`

**`.devtools/`:**
- Purpose: Provide maintainer-only dependency, release, Transifex, and demo-data utilities.
- Contains: Windows batch scripts and `data_generation_scripts/` fixtures/utilities.
- Key files: `.devtools/install_dependencies.bat`, `.devtools/create_release_package.bat`, `.devtools/data_generation_scripts/9999_big_stock.php`

**`.github/`:**
- Purpose: Define repository contribution/security metadata and publication screenshots.
- Contains: Contribution guidelines, issue/PR templates, security policy, and `publication_assets/`.
- Key files: `.github/CONTRIBUTING.md`, `.github/SECURITY.md`, `.github/PULL_REQUEST_TEMPLATE.md`

**`.planning/codebase/`:**
- Purpose: Store generated, implementation-oriented GSD maps for planning and execution.
- Contains: Architecture, structure, stack, integration, conventions, testing, and concerns documents written by the mapping workflow.
- Key files: `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/STRUCTURE.md`

## Key File Locations

**Entry Points:**
- `public/index.php`: HTTP front controller and data-path selection.
- `app.php`: Slim/PHP-DI bootstrap and middleware composition.
- `routes.php`: Core page and API routes.
- `custom/grocy_AI/routes.php`: Feature-gated ATECHPCS API routes.
- `update.sh`: Direct deployment update entry point.
- `custom/grocy_AI/tests/run.php`: Direct module contract-check entry point.

**Configuration:**
- `config-dist.php`: Versioned defaults for application, auth, feature, integration, and user settings.
- `data/config.php`: Deployment-specific configuration expected by `helpers/PrerequisiteChecker.php`; keep it runtime-only under `data/`.
- `composer.json`: PHP version/dependencies, `packages/` vendor directory, and PSR-4 mappings for `Grocy\Services`, `Grocy\Controllers`, `Grocy\Middleware`, and `Grocy\Helpers`.
- `package.json`: Browser package dependency manifest installed into the public package surface used by `views/layout/default.blade.php`.
- `grocy.openapi.json`: REST documentation plus exposed-entity and file-group allowlists consumed by API controllers.
- `.yarnrc`: Yarn installation behavior for frontend packages.
- `.tx/config`: Transifex resource configuration for `localization/`.
- `.vscode/settings.json`: Repository editor settings.

**Core Logic:**
- `services/StockService.php`: Stock booking, consumption, inventory, transfers, barcode lookup, undo, and merge workflows.
- `services/RecipesService.php`: Recipe fulfillment, shopping-list, consumption, and copy workflows.
- `services/ChoresService.php`: Chore scheduling, tracking, undo, assignment, and merge workflows.
- `services/TasksService.php`: Task current-state, completion, and undo workflows.
- `services/DatabaseService.php`: SQLite/LessQL connection and raw statement gateway.
- `controllers/Api/GenericEntityApiController.php`: Generic allowlisted entity CRUD and userfield endpoints.

**Presentation:**
- `views/layout/default.blade.php`: Shared responsive application shell and global browser bootstrap.
- `views/<view>.blade.php`: Server-rendered page markup, for example `views/productform.blade.php`.
- `public/viewjs/<view>.js`: Matching page behavior, for example `public/viewjs/productform.js`.
- `public/js/grocy.js`: Shared REST client, translations, validation, and frontend helpers.
- `public/css/grocy.css`: Shared application styling.

**Data and Schema:**
- `migrations/`: Ordered source of truth for SQLite tables, views, triggers, indexes, and seed/default data.
- `services/DatabaseMigrationService.php`: Migration discovery/execution policy.
- `data/grocy.db`: Runtime SQLite file path in production mode, selected by `services/DatabaseService.php`.
- `data/storage/`: Runtime upload and derived-image location managed by `services/FilesService.php`.

**Testing:**
- `custom/grocy_AI/tests/run.php`: Standalone assertions for the custom module service/contract.
- `public/viewjs/barcodescannertesting.js`: Interactive browser barcode-scanner diagnostic paired with `views/barcodescannertesting.blade.php`.
- `public/viewjs/quantityunitpluraltesting.js`: Interactive plural-form diagnostic paired with `views/quantityunitpluraltesting.blade.php`.

**Documentation:**
- `README.md`: Installation, platform, runtime, API, feature, and extension guidance.
- `CUSTOMIZATIONS.md`: ATECHPCS branch policy and customization surface.
- `custom/grocy_AI/README.md`: Module configuration, endpoints, companion contract, and check command.
- `docs/`: Focused feature documents.

## Naming Conventions

**Files:**
- Use PascalCase plus role suffix for PHP classes: `controllers/StockController.php`, `controllers/Api/StockApiController.php`, `services/StockService.php`, `middleware/LocaleMiddleware.php`.
- Match controller domain names across page controller, API controller, and service when all three exist: `controllers/RecipesController.php`, `controllers/Api/RecipesApiController.php`, `services/RecipesService.php`.
- Use lowercase functional page names for Blade templates: `views/stockoverview.blade.php`, `views/productform.blade.php`.
- Match each page template basename with its browser script basename: `views/productform.blade.php` and `public/viewjs/productform.js`.
- Use lowercase component basenames in both component trees: `views/components/productpicker.blade.php` and `public/viewjs/components/productpicker.js`.
- Use zero-padded four-digit migration numbers: `migrations/0255.sql`; reserve `migrations/8888.php` for always-run checks and the service-defined `9999` ID for emergency migration behavior in `services/DatabaseMigrationService.php`.
- Use `NNNN_vVERSION_YYYY-MM-DD.md` release-note names under `changelog/`, following parsing in `services/ApplicationService.php` and the `changelog/__TEMPLATE.md` template.
- Use lowercase hyphenated filenames for standalone custom web assets: `public/custom/grocy_AI/product-enrichment.js`, `public/custom/grocy_AI/grocy-ai.css`.

**Directories:**
- Use top-level plural lowercase layer/domain directories: `controllers/`, `services/`, `middleware/`, `helpers/`, `views/`, `plugins/`.
- Use capitalized `Api/` and `Users/` subdirectories to match PHP namespaces under `controllers/`.
- Use the public module name exactly as `grocy_AI` in `custom/grocy_AI/` and `public/custom/grocy_AI/`; use the PHP namespace `GrocyAI` inside `custom/grocy_AI/src/` as documented in `CUSTOMIZATIONS.md`.
- Use locale codes as directory names under `localization/`, such as `localization/en_GB/`, `localization/pt_BR/`, and `localization/zh_CN/`.

## Where to Add New Code

**New Core Feature:**
- Primary domain behavior: `services/<Feature>Service.php`, extending `services/BaseService.php` when it needs the shared database/singleton pattern.
- Page handler: `controllers/<Feature>Controller.php`, extending `controllers/BaseController.php`.
- API handler: `controllers/Api/<Feature>ApiController.php`, extending `controllers/Api/BaseApiController.php`.
- Routes: `routes.php`, grouped with the matching page or API domain block.
- Page markup: `views/<feature>.blade.php`, extending `views/layout/default.blade.php`.
- Browser behavior: `public/viewjs/<feature>.js`, with the exact Blade view basename.
- Shared styling: `public/css/grocy.css`; feature-owned fork styling belongs under `public/custom/<module>/`.
- Tests: Follow the only dedicated executable-test location pattern at `custom/grocy_AI/tests/` for isolated modules; do not place diagnostics in `public/viewjs/*testing.js` unless they are user-facing diagnostic pages.

**New REST Operation:**
- Route: Add the named endpoint to the matching `/api` block in `routes.php`.
- Controller method: Add permission/input/response translation to the corresponding file in `controllers/Api/`.
- Domain behavior: Add multi-entity or invariant-bearing operations to the matching file in `services/`.
- Contract: Update `grocy.openapi.json` alongside the endpoint.

**New Simple Master-Data Entity:**
- Schema: Add a numbered migration under `migrations/`.
- Generic API exposure: Update the relevant exposed-entity schemas in `grocy.openapi.json`, consumed by `controllers/Api/GenericEntityApiController.php`.
- UI: Add page controller methods under `controllers/`, Blade templates under `views/`, scripts under `public/viewjs/`, and routes in `routes.php`.
- User fields: Extend the entity mapping through `services/UserfieldsService.php` and related migration/OpenAPI definitions when custom fields apply.

**New Database Change:**
- Implementation: Add the next zero-padded SQL or PHP file in `migrations/`; never modify `data/grocy.db` as source.
- Transaction-sensitive PHP: Follow `services/DatabaseMigrationService.php` execution semantics and use an SQL migration when the change can be expressed entirely in SQLite.
- Derived read model: Define/update views or triggers in the migration, following `migrations/0200.sql`, `migrations/0226.sql`, and `migrations/0255.sql`.

**New Blade Component:**
- Markup: `views/components/<component>.blade.php`.
- Browser behavior: `public/viewjs/components/<component>.js` when required.
- Inclusion/assets: Use Blade `@include`, `@push('componentScripts')`, or frontend package registration via `helpers/extensions.php`, following existing files in `views/components/`.

**New ATECHPCS Custom Module:**
- PHP implementation: `custom/<module>/src/` with its own namespace.
- Routes/bootstrap: `custom/<module>/routes.php`, included conditionally from `routes.php` behind a `GROCY_FEATURE_FLAG_*` setting in `config-dist.php`.
- Browser assets: `public/custom/<module>/`.
- Documentation/checks: `custom/<module>/README.md` and `custom/<module>/tests/`.
- Core hook record: `CUSTOMIZATIONS.md`; keep the hook list narrow and explicit.

**New Barcode Lookup Provider:**
- Built-in implementation: `plugins/<Provider>BarcodeLookupPlugin.php` extending `helpers/BaseBarcodeLookupPlugin.php`.
- Deployment-local implementation: runtime `data/plugins/<Provider>BarcodeLookupPlugin.php` without committing it to the repository.
- Configuration: Select the class through `STOCK_BARCODE_LOOKUP_PLUGIN` in deployment `data/config.php`, using `plugins/OpenFoodFactsBarcodeLookupPlugin.php` as the concrete example.

**Utilities:**
- Shared PHP function: `helpers/extensions.php` only when a global helper is consistent with the existing Composer `autoload.files` model in `composer.json`.
- Stateful/reusable infrastructure: Add a class under `helpers/` or `services/` according to whether it owns domain/database behavior; compare `helpers/WebhookRunner.php` with `services/FilesService.php`.
- Shared browser helper: `public/js/grocy.js` or a focused `public/js/grocy_<concern>.js` loaded by `views/layout/default.blade.php`.

**Localization:**
- Source string templates: Root `localization/*.pot` resources used by `services/LocalizationService.php`.
- Locale translation: Matching `localization/<locale>/*.po` files.
- New UI text: Use the `$__t`/`$__n` helpers in `views/` and `services/LocalizationService.php`; custom module strings remain literal only where the module intentionally does not participate in core gettext catalogs, as in `views/productform.blade.php`.

## Special Directories

**`data/`:**
- Purpose: Writable runtime boundary for configuration, SQLite, cache, storage, plugins, logs, and backups.
- Generated: Yes, except committed `data/.gitignore`, `data/.htaccess`, and `data/plugins/.gitignore` guards.
- Committed: Runtime contents no; guard files yes, according to `data/.gitignore` and repository tracking.

**`packages/`:**
- Purpose: Composer vendor directory and frontend package surface referenced by `app.php`, `helpers/PrerequisiteChecker.php`, and `views/layout/default.blade.php`.
- Generated: Yes, from `composer.lock` and `yarn.lock`/`package.json`.
- Committed: No, excluded by `.gitignore`.

**`public/packages/`:**
- Purpose: Publicly served frontend dependency location expected by `views/layout/default.blade.php`.
- Generated: Yes, by dependency installation/release packaging defined around `package.json`, `.yarnrc`, and `.devtools/install_dependencies.bat`.
- Committed: No, excluded by `.gitignore`.

**`migrations/`:**
- Purpose: Versioned SQLite schema/data history executed by `services/DatabaseMigrationService.php`.
- Generated: No; each file is authored source.
- Committed: Yes.

**`localization/`:**
- Purpose: Versioned gettext templates and translations managed with `.tx/config` and `.devtools/transifex_*.bat`.
- Generated: Partly synchronized/generated by localization tooling, but treated as repository source.
- Committed: Yes.

**`public/viewjs/`:**
- Purpose: Convention-loaded browser behavior paired with `views/` templates.
- Generated: No; files are authored source.
- Committed: Yes.

**`custom/` and `public/custom/`:**
- Purpose: ATECHPCS-owned extension code and public assets, governed by `CUSTOMIZATIONS.md`.
- Generated: No; files are authored source.
- Committed: Yes.

**`.planning/codebase/`:**
- Purpose: Generated GSD reference documents used by planning/execution workflows.
- Generated: Yes, by the codebase mapping workflow.
- Committed: Managed by the GSD orchestrator, not by the mapper agent.

**`changelog/`:**
- Purpose: Machine-read release-note inputs exposed by `services/ApplicationService.php`.
- Generated: No; release notes follow `changelog/__TEMPLATE.md`.
- Committed: Yes.

---

*Structure analysis: 2026-08-12*
