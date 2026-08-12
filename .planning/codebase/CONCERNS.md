# Codebase Concerns

**Analysis Date:** 2026-08-12

## Tech Debt

**Dual-track upstream maintenance:**
- Issue: The same customization is maintained against two materially different upstream APIs. `atech-main` is two commits ahead of `upstream/master`, while `atech-release` is five customization commits ahead of `upstream/release`; the branches differ across 191 files because upstream development moved controllers, routing, middleware construction, and other framework surfaces.
- Files: `CUSTOMIZATIONS.md`, `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `routes.php`, `Dockerfile.atech`
- Impact: A customization change is not safely transferable by blindly merging or cherry-picking all files. The service, browser client, styles, and tests are identical between the branches, but the route/controller adapters are branch-specific. A missed port can leave development validation green while production remains stale.
- Fix approach: Treat `custom/grocy_AI/src/GrocyAiService.php`, `public/custom/grocy_AI/product-enrichment.js`, `public/custom/grocy_AI/grocy-ai.css`, and `custom/grocy_AI/tests/run.php` as the portable core. Maintain explicit adapter patches for `custom/grocy_AI/routes.php` and `custom/grocy_AI/src/GrocyAiApiController.php`, and verify both ATECHPCS branches for every feature change.

**Customization isolation still has upstream touch points:**
- Issue: The custom module lives under `custom/grocy_AI/`, but enabling it requires edits to three upstream-owned application files and a production-specific version overlay.
- Files: `config-dist.php`, `routes.php`, `views/productform.blade.php`, `custom/grocy_AI/version.json`, `Dockerfile.atech`
- Impact: Upstream changes to configuration order, route composition, the product form, or view/route cache invalidation can silently disable or break the module during an upgrade.
- Fix approach: Keep the integration surface limited to these documented files, review their upstream diffs before every rebase/merge, and run a route/render smoke test after resolving any conflict.

**Branch documentation is inconsistent:**
- Issue: The `atech-main` copy says `atech-main` is deployable and omits the stable production branch, container overlay, and cache marker. The `atech-release` copy documents `atech-release` as deployable and `atech-main` as compatibility testing.
- Files: `CUSTOMIZATIONS.md`, `Dockerfile.atech`, `custom/grocy_AI/version.json`
- Impact: An operator following the development branch documentation can build from the wrong branch; `Dockerfile.atech` and the customization version marker do not exist on `atech-main`.
- Fix approach: Make the branch matrix identical on both branches and name one production source of truth. State that production images are built only from `atech-release` unless the container strategy is deliberately ported.

**Large stock-domain modules concentrate change risk:**
- Issue: Stock behavior is concentrated in `services/StockService.php` (1,825 lines), `controllers/Api/StockApiController.php` (929 lines), and several 500-800 line browser modules.
- Files: `services/StockService.php`, `controllers/Api/StockApiController.php`, `public/viewjs/purchase.js`, `public/viewjs/consume.js`, `public/viewjs/inventory.js`
- Impact: Unrelated stock workflows share mutable services and controllers, making regression scope broad and merge conflicts harder to isolate.
- Fix approach: Extract cohesive operations behind narrow services only when touching the relevant behavior, and add characterization tests before moving logic.

**No enforced code-quality pipeline:**
- Issue: The repository has no committed CI workflow, formatter enforcement, linter command, coverage configuration, or general application test runner. The only executable test suite is the custom PHP harness.
- Files: `.github/`, `composer.json`, `package.json`, `custom/grocy_AI/tests/run.php`
- Impact: Syntax, style, dependency, and regression checks depend on manual discipline, especially across the two customization branches.
- Fix approach: Add CI that runs PHP syntax checks, the grocy_AI harness, JavaScript syntax checks, dependency installation, and branch-specific build smoke tests.

## Known Bugs

**The bundled updater removes fork customizations:**
- Symptoms: Running the upstream update helper deletes everything except `data/` and `update.sh`, then installs the latest upstream release. This removes `custom/grocy_AI/`, the product-form integration, custom routes, and container-specific version marker.
- Files: `update.sh`, `custom/grocy_AI/`, `routes.php`, `views/productform.blade.php`, `custom/grocy_AI/version.json`
- Trigger: Run `update.sh` in an ATECHPCS source-style deployment.
- Workaround: Do not use `update.sh` for this fork. Upgrade by merging `upstream/release` into `atech-release`, rebuilding `Dockerfile.atech`, and retaining the external data volume.

**Height-only image resizing takes the wrong branch:**
- Symptoms: A request with only `best_fit_height` calls `resizeToBestFit` with a null width instead of `resizeToHeight` because the first condition checks `$bestFitHeight` twice.
- Files: `services/FilesService.php`, `controllers/Api/FilesApiController.php`
- Trigger: Request `/api/files/...?...&best_fit_height=<number>` without `best_fit_width`.
- Workaround: Use width-only or provide both dimensions until the condition tests `$bestFitWidth` as its second operand.

**grocy_AI route documentation has a stale authorization statement:**
- Symptoms: The README lists three routes but says “Both routes” use authentication and mentions the edit permission only for enrichment. The image route also enforces `MASTER_DATA_EDIT`; the status route does not.
- Files: `custom/grocy_AI/README.md`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`
- Trigger: Use the README to build an API client or authorization test matrix.
- Workaround: Read controller permission checks as authoritative; document status, enrichment, and image-fetch permissions separately.

## Security Considerations

**Companion API key may cross the LAN in plaintext:**
- Risk: `GROCY_AI_SERVICE_URL` accepts both HTTP and HTTPS, and the stable-branch deployment guidance uses HTTP. The optional `X-API-Key` is therefore readable by any party able to observe that network path.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/README.md`, `config-dist.php`
- Current mitigation: Redirects are disabled, the service URL is configuration-controlled, and the key is never returned by the status endpoint.
- Recommendations: Prefer HTTPS or a mutually authenticated private transport. Use a dedicated least-privilege key for grocy_AI rather than reusing a general companion-service inbound key, and rotate it independently.

**External previews disclose browser network activity:**
- Risk: Candidate images are loaded directly from companion-supplied external URLs, exposing the user's IP address and request timing to each image host. HTTP candidates can also be blocked as mixed content when Grocy is served over HTTPS.
- Files: `public/custom/grocy_AI/product-enrichment.js`, `custom/grocy_AI/src/GrocyAiService.php`
- Current mitigation: Only HTTP(S) URLs pass normalization, links use `noopener noreferrer`, images set `referrerPolicy = 'no-referrer'`, and values are inserted with `textContent`.
- Recommendations: Proxy thumbnails through the tokenized Grocy image endpoint or through the companion service, require HTTPS candidates, and avoid contacting third-party hosts until the user requests a preview.

**Response size limits are enforced after buffering:**
- Risk: Guzzle reads the full enrichment JSON or image response into memory before the 3 MB image limit is checked. A slow or compromised companion can consume PHP worker memory and bandwidth with an oversized or never-ending response.
- Files: `custom/grocy_AI/src/GrocyAiService.php`
- Current mitigation: Connect and total timeouts are capped, redirects are disabled, supported image MIME types and magic bytes are checked, and stored output is capped by the normal Grocy save flow.
- Recommendations: Stream image bodies to a bounded temporary stream, reject excessive `Content-Length`, impose a small JSON-body limit, and configure server/proxy response limits.

**Application session cookies lack explicit hardening flags:**
- Risk: The session cookie is set without explicit `Secure`, `HttpOnly`, or `SameSite` attributes. Script access or non-HTTPS transport can expose a long-lived session key.
- Files: `middleware/AuthMiddleware.php`, `services/SessionService.php`
- Current mitigation: Server-side session expiry is checked and passwords use Argon2id.
- Recommendations: Set `HttpOnly`, `Secure` when HTTPS is used, and an appropriate `SameSite` policy; keep the deployment behind authenticated HTTPS even on a private network.

**Production error responses expose implementation details:**
- Risk: Error middleware is configured with display details enabled. Unhandled API failures return stack traces, source paths, and line numbers.
- Files: `app.php`, `controllers/ExceptionController.php`
- Current mitigation: grocy_AI converts expected validation, configuration, and upstream failures into generic 400/502/503 responses.
- Recommendations: Disable detailed errors outside development and log full exceptions server-side with request correlation IDs.

**API exposure is intentionally broad:**
- Risk: API keys can be supplied through query parameters, CORS allows every origin, and default API keys do not expire. Query credentials can leak through logs, browser history, referrers, and copied URLs.
- Files: `middleware/ApiKeyAuthMiddleware.php`, `middleware/CorsMiddleware.php`, `services/ApiKeyService.php`
- Current mitigation: Every non-demo/non-embedded request passes global authentication, and grocy_AI mutation-adjacent operations require `MASTER_DATA_EDIT`.
- Recommendations: Use header-only keys, scope allowed origins, add key expiry/rotation, and do not expose the instance directly to the public internet as stated in `.github/SECURITY.md`.

**Authenticated file uploads have no explicit quota:**
- Risk: Uploads stream to disk but do not enforce a per-file size or content-type limit at the API boundary. An authenticated user can exhaust the data volume.
- Files: `controllers/Api/FilesApiController.php`, `services/FilesService.php`, `routes.php`
- Current mitigation: File groups and filenames are validated, and files are created exclusively to avoid overwrite.
- Recommendations: Enforce group-specific MIME/size limits, application permissions, and filesystem quotas; monitor free space on the persisted data volume.

## Performance Bottlenecks

**Synchronous enrichment occupies a PHP worker:**
- Problem: Each UPC lookup blocks until the companion responds, for up to the configured 1-60 second timeout. There are no retries, circuit breaker, local cache, or request coalescing.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `public/custom/grocy_AI/product-enrichment.js`, `config-dist.php`
- Cause: Enrichment is a direct synchronous Guzzle request made during the Grocy API request.
- Improvement path: Keep the timeout low, add companion health telemetry and a short circuit breaker, and cache successful UPC responses with a bounded TTL if lookup volume grows.

**Image selection buffers the file twice:**
- Problem: The server converts the complete upstream image body to a PHP string, then the browser receives it as a Blob and copies it into a `File` through `DataTransfer`.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `public/custom/grocy_AI/product-enrichment.js`
- Cause: Safety validation and form attachment operate on fully materialized payloads.
- Improvement path: Stream through a bounded server-side validator where possible and retain the 3 MB cap; do not raise the cap without measuring PHP and browser memory on mobile devices.

**Candidate previews fan out to external hosts:**
- Problem: Rendering one result can start up to six external image requests, while the server normalizes up to twenty candidates.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `public/custom/grocy_AI/product-enrichment.js`
- Cause: The UI eagerly assigns `img.src` for the first six candidates; native lazy loading is advisory and does not guarantee zero fetches.
- Improvement path: Serve bounded thumbnails from one trusted origin, paginate candidates, or load images only when their cards enter the viewport.

**SQLite and shared file storage cap concurrency:**
- Problem: All application data, sessions, API-key last-used updates, and files share one writable data volume; write-heavy concurrent use can contend on SQLite and disk.
- Files: `services/DatabaseService.php`, `services/SessionService.php`, `services/ApiKeyService.php`, `services/FilesService.php`
- Cause: The application is designed as a single-instance household system backed by SQLite.
- Improvement path: Keep one application writer, use fast local persistent storage, back up the entire data volume consistently, and do not scale by starting independent replicas against the same SQLite file.

## Fragile Areas

**Branch-specific routing adapter:**
- Files: `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `routes.php`
- Why fragile: Stable uses the pre-refactor base controller namespace and class-name middleware registration; development uses `Grocy\Controllers\Api\BaseApiController` plus constructed CORS/JSON middleware. These differences are required by their respective upstream branches.
- Safe modification: Port core behavior first, then adapt imports and middleware to each branch independently. Confirm all three routes authenticate and the two data/image routes enforce `MASTER_DATA_EDIT`.
- Test coverage: The service harness does not boot Slim, inspect the route table, or exercise middleware and permissions.

**Product-form DOM coupling:**
- Files: `views/productform.blade.php`, `public/custom/grocy_AI/product-enrichment.js`, `public/viewjs/productform.js`
- Why fragile: The custom client depends on upstream IDs including `name`, `product-picture`, and `product-picture-label`, plus Grocy globals `U` and `Grocy.Api`. Product-form markup is already one of the largest templates at more than 1,000 lines.
- Safe modification: Preserve the upstream form's IDs and save lifecycle, test create and edit modes, and verify desktop/mobile behavior whenever upstream changes the product form or file upload flow.
- Test coverage: No DOM, browser, accessibility, or mobile test exercises apply-name or `DataTransfer` image attachment.

**Route/view cache invalidation depends on a production-only marker:**
- Files: `app.php`, `version.json`, `custom/grocy_AI/version.json`, `Dockerfile.atech`
- Why fragile: Slim's route cache is keyed from `version.json`. The production image deliberately overlays a custom marker; missing that copy can leave a persisted route/view cache unaware of customization changes.
- Safe modification: Bump `custom/grocy_AI/version.json` whenever route or view integration changes, verify the Docker copy step, and confirm `/api/grocy-ai/status` after deployment.
- Test coverage: No automated image test verifies marker installation or cache invalidation.

**Destructive source updater remains executable:**
- Files: `update.sh`, `CUSTOMIZATIONS.md`, `Dockerfile.atech`
- Why fragile: The generic upstream updater is valid for stock Grocy but incompatible with the fork overlay and has no fork-awareness guard.
- Safe modification: Mark the script unsupported in fork operations or add an ATECHPCS marker check that aborts with release-branch upgrade instructions.
- Test coverage: No release test asserts that customization files survive the supported upgrade path.

## Scaling Limits

**Single-node Grocy runtime:**
- Current capacity: Intended for household-scale use on one PHP/SQLite instance; no numeric throughput target is defined.
- Limit: SQLite write locking, local file storage, in-process caches, and session/API-key writes make active-active replicas unsafe without redesign.
- Scaling path: Scale the PHP worker pool only within one node and one local data volume, measure lock contention, and preserve a single writer. Treat database replacement as a separate architectural project.

**Companion-service dependency:**
- Current capacity: One synchronous companion call per search and one per selected image, with at most six previews rendered and twenty candidates normalized.
- Limit: Companion latency, rate limits, or downtime consume Grocy workers and make enrichment unavailable, although core Grocy remains usable.
- Scaling path: Add rate limiting per user, caching by normalized UPC, bounded concurrency, health checks, and graceful backoff around `custom/grocy_AI/src/GrocyAiService.php`.

## Dependencies at Risk

**Moving VCS dependencies and disabled platform checks:**
- Risk: Composer depends on `dev-4.x-fork` and `dev-master-fork`, Yarn installs a GitHub `master-fork`, and Composer disables its platform check. Lockfiles reduce ordinary drift, but regeneration can resolve moving branches and deployment can start with an unsupported platform.
- Impact: Re-locking or rebuilding can introduce unreviewed changes; missing PHP extension/version compatibility may fail at runtime instead of install time.
- Migration plan: Pin immutable commit references or tagged fork releases, keep `composer.lock` and `yarn.lock` reviewed, enable platform validation in CI, and run `helpers/PrerequisiteChecker.php` in image smoke tests.
- Files: `composer.json`, `composer.lock`, `package.json`, `yarn.lock`, `helpers/PrerequisiteChecker.php`

**Legacy browser stack:**
- Risk: Core UI packages include Bootstrap 4, Chart.js 2, DataTables 1, Moment, and other older major lines that are expensive to upgrade together.
- Impact: Security and browser-compatibility fixes may require coordinated template and plugin changes across the large view/client surface.
- Migration plan: Upgrade one tightly related package family at a time, preserve lockfiles, and add browser smoke coverage before changing Bootstrap, jQuery, DataTables, or charting packages.
- Files: `package.json`, `yarn.lock`, `views/layout/default.blade.php`, `public/js/grocy.js`

**Mutable production base-image tag:**
- Risk: `Dockerfile.atech` pins a versioned LinuxServer tag but not an image digest; a tag can resolve to different bytes across rebuilds.
- Impact: Production rebuilds are not fully reproducible, and upstream base-image changes can alter PHP/extensions or runtime behavior without a repository diff.
- Migration plan: Record the tested digest for each release, automate scheduled rebuild/security scanning, and intentionally update both tag and digest after verification.
- Files: `Dockerfile.atech`

## Missing Critical Features

**Automated dual-branch release gate:**
- Problem: Nothing verifies that a grocy_AI core change is present and adapted on both `atech-main` and `atech-release`, or that production is built from the stable branch.
- Blocks: Reliable promotion, repeatable upstream merges, and detection of branch drift before deployment.
- Files: `CUSTOMIZATIONS.md`, `.github/`, `Dockerfile.atech`, `custom/grocy_AI/tests/run.php`

**Custom OpenAPI contract:**
- Problem: The three grocy_AI routes are registered in PHP but absent from `grocy.openapi.json`.
- Blocks: Generated clients, Swagger discovery, schema validation, and automated authorization/response conformance tests for the extension.
- Files: `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `grocy.openapi.json`

**Operational health and observability:**
- Problem: Status reports only local configuration booleans; there is no companion reachability check, latency/error metric, structured log, request ID, or container health check.
- Blocks: Fast diagnosis of companion outages and objective timeout/capacity tuning.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `Dockerfile.atech`

**Documented rollback and backup validation:**
- Problem: The container overlay preserves external data, but the repository does not define a tested database backup/restore and image rollback procedure for the custom production branch.
- Blocks: Confident recovery from a bad upstream migration or broken custom image.
- Files: `Dockerfile.atech`, `app.php`, `services/DatabaseMigrationService.php`, `update.sh`, `CUSTOMIZATIONS.md`

## Test Coverage Gaps

**grocy_AI HTTP and authorization behavior:**
- What's not tested: Slim route registration, global authentication, `MASTER_DATA_EDIT`, response status mapping, headers, CORS differences between branches, and binary streaming.
- Files: `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `middleware/AuthMiddleware.php`
- Risk: Namespace or middleware changes can make a route unreachable, unauthenticated, or incorrectly authorized while all 21 service checks still pass.
- Priority: High

**Browser review-before-save workflow:**
- What's not tested: Rendering, error recovery, XSS-safe DOM insertion, apply-name events, image selection via `DataTransfer`, save integration, accessibility, and mobile/browser compatibility.
- Files: `public/custom/grocy_AI/product-enrichment.js`, `views/productform.blade.php`, `public/viewjs/productform.js`
- Risk: Upstream form changes or browser API differences can break the visible feature without a server-side failure.
- Priority: High

**Bounded-response and failure-path behavior:**
- What's not tested: Oversized JSON, oversized images without a declared length, slow streams, connection timeouts, DNS failures, malformed content types with parameters, and companion redirect responses.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/tests/run.php`
- Risk: Resource exhaustion and poor user-facing behavior appear only under hostile or degraded network conditions.
- Priority: High

**Stable container and cache marker:**
- What's not tested: Building `Dockerfile.atech`, verifying the LinuxServer layout, copying the custom `version.json`, invalidating an existing route cache, and serving custom assets from the built image.
- Files: `Dockerfile.atech`, `custom/grocy_AI/version.json`, `app.php`, `public/custom/grocy_AI/product-enrichment.js`
- Risk: A syntactically correct change can deploy without routes/assets or can remain hidden behind stale persisted cache.
- Priority: High

**Core application regressions:**
- What's not tested: Stock transactions, file upload/downscaling, authentication cookie behavior, migrations, and the large browser workflows have no committed automated suite in this repository.
- Files: `services/StockService.php`, `controllers/Api/StockApiController.php`, `services/FilesService.php`, `middleware/AuthMiddleware.php`, `migrations/`, `public/viewjs/`
- Risk: Upstream merges and dependency updates can break household data operations without detection before manual use.
- Priority: High

**Branch parity:**
- What's not tested: Portable core files remain identical while adapter differences remain intentional across `atech-main` and `atech-release`.
- Files: `custom/grocy_AI/src/GrocyAiService.php`, `custom/grocy_AI/tests/run.php`, `public/custom/grocy_AI/product-enrichment.js`, `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`
- Risk: Manual cherry-picks can omit a file or overwrite a required stable/development compatibility adaptation.
- Priority: High

---

*Concerns audit: 2026-08-12*
