# External Integrations

**Analysis Date:** 2026-08-12

## APIs & External Services

**Product data and image discovery:**
- Open Food Facts - Default barcode lookup provider for product names and image URLs in `plugins/OpenFoodFactsBarcodeLookupPlugin.php`.
  - SDK/Client: Guzzle 7.15.1 through `GuzzleHttp\Client`, declared in `composer.json` and locked in `composer.lock`.
  - Endpoint: `GET https://world.openfoodfacts.org/api/v2/product/{barcode}` with a field projection, assembled in `plugins/OpenFoodFactsBarcodeLookupPlugin.php`.
  - Auth: None; the client sends a fixed Grocy identification `User-Agent` from `plugins/OpenFoodFactsBarcodeLookupPlugin.php`.
  - Selection: `GROCY_STOCK_BARCODE_LOOKUP_PLUGIN` chooses the implementation and defaults to `OpenFoodFactsBarcodeLookupPlugin` in `config-dist.php`; set it empty to disable external lookup.
  - Image follow-up: Returned HTTP(S) product images are downloaded server-side by Guzzle and saved into local product-picture storage by `services/StockService.php`.

**grocy_AI companion service:**
- Configurable companion REST service - Supplies review-before-save UPC metadata plus package-image candidates for the ATECHPCS extension in `custom/grocy_AI/`.
  - SDK/Client: Guzzle 7.15.1 in `custom/grocy_AI/src/GrocyAiService.php`.
  - Outgoing endpoints: `GET {GROCY_AI_SERVICE_URL}/v1/products/enrich/upc/{upc}` and `GET {GROCY_AI_SERVICE_URL}/v1/products/images/{token}`, implemented in `custom/grocy_AI/src/GrocyAiService.php` and documented in `custom/grocy_AI/README.md`.
  - Auth: Optional `X-API-Key` header populated from `GROCY_AI_SERVICE_API_KEY` in `custom/grocy_AI/src/GrocyAiService.php`.
  - Configuration: `GROCY_FEATURE_FLAG_GROCY_AI`, `GROCY_AI_SERVICE_URL`, `GROCY_AI_SERVICE_API_KEY`, and `GROCY_AI_REQUEST_TIMEOUT_SECONDS`, with setting definitions in `config-dist.php` and generated-name documentation in `custom/grocy_AI/README.md`.
  - Safety boundary: Service and candidate URLs must use HTTP(S), redirects are disabled so the API key is not forwarded, image downloads use opaque tokens, and returned images are size/MIME/signature checked in `custom/grocy_AI/src/GrocyAiService.php`.
  - Data behavior: The module only fills the existing form after explicit user actions; persistence occurs through the standard product save/upload flow in `public/custom/grocy_AI/product-enrichment.js` and `views/productform.blade.php`.
  - Downstream providers: The companion may aggregate Open Food Facts and a local SearXNG instance, but those services are outside this repository and are described only as companion behavior in `custom/grocy_AI/README.md`.

**Label-printing webhook:**
- Operator-configured HTTP endpoint - Grocy POSTs product, stock-entry, recipe, chore, or battery label data to `GROCY_LABEL_PRINTER_WEBHOOK`, defined in `config-dist.php`.
  - SDK/Client: Guzzle server-side in `helpers/WebhookRunner.php`, or jQuery AJAX client-side in `public/js/grocy.js` when `GROCY_LABEL_PRINTER_RUN_SERVER` is false.
  - Auth: No dedicated auth setting; any endpoint-specific values must be placed by the operator in `GROCY_LABEL_PRINTER_PARAMS`, defined in `config-dist.php`.
  - Payload: JSON or form fields according to `GROCY_LABEL_PRINTER_HOOK_JSON`, with extra fields from `GROCY_LABEL_PRINTER_PARAMS`, implemented in `helpers/WebhookRunner.php` and `public/js/grocy.js`.
  - Timeout/error behavior: Server-side calls use a two-second Guzzle timeout and log failures to stderr in `helpers/WebhookRunner.php`; browser-side failures display a UI error from `public/js/grocy.js`.
  - Producers: Server-side calls originate from `controllers/Api/StockApiController.php`, `controllers/Api/RecipesApiController.php`, `controllers/Api/ChoresApiController.php`, `controllers/Api/BatteriesApiController.php`, and `services/StockService.php`.

**Release and demo resources:**
- Grocy release service - `update.sh` downloads the latest release archive from `https://releases.grocy.info/latest`, after creating a local backup and before replacing application files.
  - SDK/Client: `wget`, `unzip`, `tar`, and Bash in `update.sh`.
  - Auth: None in `update.sh`.
- Grocy demo resources - Demo/prerelease seed generation downloads example images/documents from `releases.grocy.info` in `services/DemoDataGeneratorService.php`.
  - SDK/Client: PHP stream functions in `services/DemoDataGeneratorService.php`.
  - Auth: None in `services/DemoDataGeneratorService.php`.

**Localization workflow:**
- Transifex - Development-time push/pull of PO catalogs configured in `.tx/config` and invoked by `.devtools/transifex_upload.bat`, `.devtools/transifex_download.bat`, and `.devtools/transifex_check_for_new_languages.bat`.
  - SDK/Client: External `tx` CLI invoked by `.devtools/*.bat`.
  - Auth: Managed outside the repository by Transifex CLI configuration; no token file is tracked by this repository, as evidenced by `.tx/config` containing only host/resource mappings.

**Browser device APIs:**
- Camera barcode scanning - ZXing processes the browser camera stream locally in `public/viewjs/components/camerabarcodescanner.js`; no scanning image or barcode is sent to an external service.
  - SDK/Client: `@zxing/library` 0.21.3 declared in `package.json` and loaded by `views/layout/default.blade.php`.
  - Auth: Browser camera permission; HTTPS is required according to `README.md`.

**Thermal printers:**
- ESC/POS printer - Direct connection to a TCP network printer or a local device file from `services/PrintService.php`.
  - SDK/Client: `mike42/escpos-php` 4.0 from `composer.json` and `composer.lock`.
  - Connection: `GROCY_TPRINTER_IS_NETWORK_PRINTER`, `GROCY_TPRINTER_IP`, `GROCY_TPRINTER_PORT`, and `GROCY_TPRINTER_CONNECTOR` from `config-dist.php`.
  - Auth: None implemented in `services/PrintService.php`; access relies on network or operating-system device permissions.

## Data Storage

**Databases:**
- SQLite 3.40+ local file database - The normal database is `GROCY_DATAPATH/grocy.db`; demo/prerelease mode uses locale-suffixed files, as implemented in `services/DatabaseService.php`.
  - Connection: No connection-string environment variable; `GROCY_DATAPATH` selects the containing directory in `public/index.php`.
  - Client: PHP `PDO\Sqlite` plus the LessQL `dev-master-fork` wrapper in `services/DatabaseService.php`.
  - Schema management: Numbered SQL/PHP migrations under `migrations/` are executed by `services/DatabaseMigrationService.php` during application migration flow.
  - Stored identity state: Users, password hashes, API keys, and sessions are stored in the same SQLite database by `services/UsersService.php`, `services/ApiKeyService.php`, and `services/SessionService.php`.

**File Storage:**
- Local filesystem only - User uploads and generated/downscaled pictures live under `GROCY_DATAPATH/storage/<group>/`, implemented by `services/FilesService.php` and exposed through `controllers/Api/FilesApiController.php`.
- Runtime configuration is `GROCY_DATAPATH/config.php`; the default path is `data/config.php`, required by `helpers/PrerequisiteChecker.php` and ignored by `data/.gitignore`.
- Update backups are local `.tgz` archives under `data/backups/`, created and aged out after 60 days by `update.sh`.
- No S3, object-storage, CDN, or network-filesystem SDK is declared in `composer.json` or used by `services/FilesService.php`.

**Caching:**
- Local filesystem cache only - Blade compiled views, HTMLPurifier serializer cache, and Slim route cache live under `GROCY_DATAPATH/viewcache`, configured in `app.php`, `helpers/SlimBladeView.php`, and `controllers/Api/BaseApiController.php`.
- Cache invalidation is keyed by `version.json`, `GROCY_BASE_URL`, and `GROCY_BASE_PATH` in `app.php`; no Redis, Memcached, or remote cache integration is present in `composer.json`.
- Resized image variants are cached beside their originals under local storage by `services/FilesService.php`.

## Authentication & Identity

**Auth Provider:**
- Custom database-backed authentication is the default through `Grocy\Middleware\DefaultAuthMiddleware`, selected by `GROCY_AUTH_CLASS` in `config-dist.php`.
  - Implementation: Username/password login uses Argon2id hashes in `middleware/DefaultAuthMiddleware.php` and `services/UsersService.php`; successful login creates a database-backed `grocy_session` cookie via `services/SessionService.php`.
  - API authentication: Clients send a user API key in the `GROCY-API-KEY` header, defined in `app.php`, checked by `middleware/ApiKeyAuthMiddleware.php`, and documented as the OpenAPI security scheme in `grocy.openapi.json`.
  - Query fallback: `middleware/ApiKeyAuthMiddleware.php` also accepts `GROCY-API-KEY` as a query parameter but explicitly marks that path as not recommended.
  - Calendar sharing: A special-purpose API key is accepted as the `secret` query parameter on `/api/calendar/ical`, implemented by `middleware/ApiKeyAuthMiddleware.php`, `services/ApiKeyService.php`, and `controllers/Api/CalendarApiController.php`.
- LDAP is an optional provider through `Grocy\Middleware\LdapAuthMiddleware`, implemented in `middleware/LdapAuthMiddleware.php`.
  - Implementation: Service-account bind locates the user, user bind verifies the password, and first login creates a local Grocy user in `middleware/LdapAuthMiddleware.php`.
  - Configuration: `GROCY_LDAP_ADDRESS`, `GROCY_LDAP_BASE_DN`, `GROCY_LDAP_BIND_DN`, `GROCY_LDAP_BIND_PW`, `GROCY_LDAP_USER_FILTER`, and `GROCY_LDAP_UID_ATTR` are defined in `config-dist.php`.
- Trusted reverse-proxy identity is an optional provider through `Grocy\Middleware\ReverseProxyAuthMiddleware`, implemented in `middleware/ReverseProxyAuthMiddleware.php`.
  - Implementation: A configured request header or server environment key supplies the username; missing local users are provisioned automatically by `middleware/ReverseProxyAuthMiddleware.php`.
  - Configuration: `GROCY_REVERSE_PROXY_AUTH_HEADER` and `GROCY_REVERSE_PROXY_AUTH_USE_ENV` are defined in `config-dist.php`.
- Authentication can be disabled with `GROCY_DISABLE_AUTH`, and is automatically bypassed in development/demo/prerelease or embedded mode by `middleware/AuthMiddleware.php`; keep production deployments in authenticated mode.

## Monitoring & Observability

**Error Tracking:**
- None detected - `controllers/ExceptionController.php` renders JSON or HTML error responses, and `composer.json` has no Sentry, Rollbar, Bugsnag, OpenTelemetry, or similar dependency.

**Logs:**
- Label webhook execution/failures are written to PHP stderr by `helpers/WebhookRunner.php`.
- Optional development SQL logging appends queries to `GROCY_DATAPATH/sql.log` only when the file already exists, implemented in `services/DatabaseService.php`.
- Slim error middleware is configured with the custom handler in `app.php`; no structured logging backend or external log shipper is configured in the repository.
- System/runtime diagnostics are returned by authenticated `/api/system/info`, implemented in `controllers/Api/SystemApiController.php` and `services/ApplicationService.php`.

## CI/CD & Deployment

**Hosting:**
- Self-hosted PHP application - Operators point the web root to `public/` and preserve a writable data directory, as specified in `README.md` and bootstrapped by `public/index.php`.
- Apache rewrite support is provided in `public/.htaccess`; nginx rewrite guidance is documented in `README.md`.
- `README.md` links to the external LinuxServer Grocy container image, but this checkout contains no `Dockerfile` or compose manifest.
- In-place release updates are supported by `update.sh`, which backs up the current tree, downloads the latest release, and preserves `data/`.

**CI Pipeline:**
- None detected - `.github/` contains issue/contribution/security templates and publication images but no `.github/workflows/` pipeline.
- Release packaging is a manual Windows workflow in `.devtools/create_release_package.bat`; dependency refresh is manual through `.devtools/update_dependencies.bat`.
- The fork tracks GitHub remotes and branch conventions documented in `CUSTOMIZATIONS.md`, but no repository automation deploys the application.

## Environment Configuration

**Required env vars:**
- None are universally required; the required runtime configuration file is `GROCY_DATAPATH/config.php`, checked in `helpers/PrerequisiteChecker.php`, and defaults to `data/config.php` through `public/index.php`.
- `GROCY_DATAPATH` is optional but critical when persistent state is mounted outside `data/`; it is read before startup in `public/index.php`.
- `GROCY_BASE_URL`, `GROCY_BASE_PATH`, `GROCY_MODE`, and `GROCY_DISABLE_URL_REWRITING` are conditional deployment overrides defined in `config-dist.php`.
- `GROCY_AI_SERVICE_URL` is required only when `GROCY_FEATURE_FLAG_GROCY_AI=true`; `GROCY_AI_SERVICE_API_KEY` is optional but security-sensitive, and `GROCY_AI_REQUEST_TIMEOUT_SECONDS` tunes the client in `custom/grocy_AI/src/GrocyAiService.php`.
- `GROCY_LDAP_ADDRESS`, `GROCY_LDAP_BASE_DN`, `GROCY_LDAP_BIND_DN`, `GROCY_LDAP_BIND_PW`, `GROCY_LDAP_USER_FILTER`, and `GROCY_LDAP_UID_ATTR` are required only for `middleware/LdapAuthMiddleware.php`, with definitions in `config-dist.php`.
- `GROCY_REVERSE_PROXY_AUTH_HEADER` and `GROCY_REVERSE_PROXY_AUTH_USE_ENV` control `middleware/ReverseProxyAuthMiddleware.php` when that class is selected.
- `GROCY_LABEL_PRINTER_WEBHOOK`, `GROCY_LABEL_PRINTER_RUN_SERVER`, `GROCY_LABEL_PRINTER_PARAMS`, and `GROCY_LABEL_PRINTER_HOOK_JSON` are required only when the label-printer feature is enabled in `config-dist.php`.
- `GROCY_TPRINTER_IS_NETWORK_PRINTER`, `GROCY_TPRINTER_IP`, `GROCY_TPRINTER_PORT`, and `GROCY_TPRINTER_CONNECTOR` configure optional ESC/POS output in `services/PrintService.php`.

**Secrets location:**
- Put sensitive values in the untracked runtime file `data/config.php` or inject them as process environment variables; configuration lookup is implemented by `Setting()` in `helpers/extensions.php`, and `data/.gitignore` excludes runtime files.
- Embedded installations may use per-setting files under `GROCY_DATAPATH/settingoverrides/`, consumed by `helpers/extensions.php`; treat any secret-bearing override as deployment data, not source.
- No `.env` file or dotenv facility is present, so do not add checked-in environment files as part of this repository's configuration pattern.
- Grocy user API keys and sessions are persisted in the local SQLite database by `services/ApiKeyService.php` and `services/SessionService.php`; protect the entire `GROCY_DATAPATH` as sensitive data.

## Webhooks & Callbacks

**Incoming:**
- No dedicated incoming webhook/callback receiver is present in `routes.php` or `custom/grocy_AI/routes.php`.
- The authenticated REST API under `/api` is the general machine-integration surface, registered in `routes.php`, described by `grocy.openapi.json`, and authenticated by `middleware/ApiKeyAuthMiddleware.php`.
- The shareable iCalendar feed at `GET /api/calendar/ical?secret=...` is a pull integration rather than a webhook, implemented in `controllers/Api/CalendarApiController.php`.
- The optional `grocy_AI` browser-facing endpoints `GET /api/grocy-ai/status`, `GET /api/grocy-ai/products/enrich/upc/{upc}`, and `GET /api/grocy-ai/images/{token}` are registered in `custom/grocy_AI/routes.php`; they proxy outbound companion calls rather than accept callbacks.

**Outgoing:**
- Label-printer POST webhook to `GROCY_LABEL_PRINTER_WEBHOOK`, sent server-side by `helpers/WebhookRunner.php` or client-side by `Grocy.FrontendHelpers.RunWebhook` in `public/js/grocy.js`.
- Open Food Facts barcode lookup uses outbound GET requests from `plugins/OpenFoodFactsBarcodeLookupPlugin.php`; returned image URLs may cause a second outbound GET from `services/StockService.php`.
- `grocy_AI` sends outbound GET requests to the configured companion service for UPC enrichment and tokenized image retrieval from `custom/grocy_AI/src/GrocyAiService.php`.
- No retry queue, signature scheme, delivery ledger, or asynchronous job system is implemented for outbound calls in `helpers/WebhookRunner.php` or `custom/grocy_AI/src/GrocyAiService.php`; calls execute synchronously within the initiating request.

---

*Integration audit: 2026-08-12*
