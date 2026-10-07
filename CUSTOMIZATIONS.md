# ATECHPCS Grocy customizations

This fork keeps ATECHPCS-specific behavior isolated so upstream Grocy updates remain practical.

## Branch model

- `master` follows the upstream development branch.
- `release` follows the latest stable upstream release.
- `atech-main` carries the customization against upstream development for early compatibility testing.
- `atech-release` is the deployable ATECHPCS branch and stays based on `release`.
- New custom commits are applied to both ATECHPCS branches. Stable upstream releases are merged into `atech-release` with merge commits so conflicts and local changes remain visible.
- Custom code belongs under `custom/` whenever possible. Every edit outside that directory must be recorded below.

## grocy_AI

Phase 1 adds a disabled-by-default, read-only product enrichment module. On the product form, an authorized user can look up a UPC and review product metadata and real package-image candidates returned by a companion service. It does not write Grocy master data, upload images, or change stock.

Phase 2 keeps search and review read-only while adding a closed suggestion contract, canonical barcode ownership, selected-only field/image staging, and same-origin secure media. Durable changes still occur only through Grocy's normal Save workflow; after Grocy establishes a trusted product ID, the Save continuation may attach only the explicitly staged checksum-valid barcode and upload only the selected staged picture.

Public module name: `grocy_AI`  
Internal PHP namespace: `GrocyAI`

The small upstream integration surface is:

- `config-dist.php`: feature flag and companion-service settings.
- `routes.php`: conditional custom route registration.
- `views/productform.blade.php`: conditional product-enrichment panel and assets.
- `public/viewjs/productform.js`: one post-Save continuation invokes transient barcode attachment only after Grocy establishes a trusted product ID and before redirect.
- `migrations/0256.php`: transactional checksum-valid canonical GTIN uniqueness; collisions block without deleting or reassigning household data.
- `services/StockService.php`: after Grocy's exact barcode lookup misses, resolve checksum-valid GTIN variants through the module's canonical owner lookup. This preserves existing stored barcode spellings while capture approval can link an equivalent scan under the canonical unique index. Non-GTIN barcodes retain the native exact-only behavior.
- `version.json` at image build time: customization marker that invalidates Grocy's persisted route/view cache.

The module implementation and contract are documented in [`custom/grocy_AI/README.md`](custom/grocy_AI/README.md).

The production container is built with `Dockerfile.atech`. It pins the matching LinuxServer Grocy 4.6 runtime and overlays the two new core adapter paths (`public/viewjs/productform.js` and `migrations/0256.php`) at their matching `/app/www/` runtime paths in addition to the Phase 1 integration surface.

### Phase 1 portable and stable adapter boundary

- Main commit `f3df50491dbf10f78a4bc711b04eb145e388a3f3` and stable portable commit `0ac85c5bc2c8441c4fea6cdc2ea712fbbd484a84` define the current portable baseline. The existing seven paths remain unchanged and match `atech-main` byte-for-byte: the module/diagnostic versions, diagnostic and service classes, native contract tests, module documentation, browser behavior, and module CSS.
- The stable adapter commit changes only `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`, `views/productform.blade.php`, `custom/grocy_AI/version.json`, and this file. The controller retains `Grocy\Controllers\BaseApiController`, routes retain class-based `JsonMiddleware::class`, and the product form retains the stable Save lifecycle.
- `Customization` is `ATECHPCS-grocy_AI-7` so the unchanged `Dockerfile.atech` overlay invalidates the persisted compiled view after the module-token assignment was changed to Blade-compatible block syntax. Custom asset URLs remain on grocy_AI module token `1.0.1`.

### Phase 2 portable and stable adapter boundary

- Stable portable commit `c21c4db88457e0da504fc7fde148da4e5d34e0ce` is the direct Phase 2 portable baseline. Its exact 12-path diff remains byte-for-byte identical to the recorded `atech-main` blobs.
- The Phase 2 stable adapter is confined to exactly eight paths: this file, `Dockerfile.atech`, `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/version.json`, `migrations/0256.php`, `public/viewjs/productform.js`, and `views/productform.blade.php`.
- The controller retains `Grocy\Controllers\BaseApiController`; the route group retains class-based `JsonMiddleware::class`. Authorization still runs before owner/provider/media work, and all module routes remain authenticated GETs.
- The product form uses portable asset token `2.4.1`; the stable cache marker advances independently to `ATECHPCS-grocy_AI-9`. The Blade hook stages only explicit selections, and the stable Save continuation preserves the trusted product ID for barcode-only retry after partial failure.
- Migration `0256.php` uses the same generated checksum-valid canonical GTIN predicate as owner lookup. It runs transactionally, blocks on any collision group, and never deletes, rewrites, or reassigns barcode rows.
- `Dockerfile.atech` copies the stable Save continuation and migration to `/app/www/public/viewjs/productform.js` and `/app/www/migrations/0256.php`; the deployed image therefore contains every source adapter represented by this commit.

### Phase 3 taxonomy portable and stable adapter boundary

- The Phase 3 portable commit mirrors the taxonomy schema bootstrap, read-only validation service, isolated tests, and taxonomy panel assets byte-for-byte from `atech-main`.
- The stable adapter changes only this record, `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/version.json`, and `views/productform.blade.php`. It keeps the stable controller namespace and class-based JSON middleware while adding the narrow taxonomy read/assignment endpoints and edit-only panel hook.
- `Customization` is `ATECHPCS-grocy_AI-10`; `Dockerfile.atech` already copies the complete module and custom-asset trees, so it includes the namespaced taxonomy migration bootstrap and panel bytes without a broader image change.

### Phases 4–8 stable release candidate

- The Phase 4–8 module and public assets are mirrored from the development candidate using `custom/grocy_AI/portable-files.txt`; the stable API controller and route bootstrap retain the Grocy 4.6 controller namespace and class-based JSON middleware.
- `controllers/GenericEntityApiController.php` validates native quantity-conversion writes through the module before persistence. `services/StockService.php` lets stock compaction join the purchase-capture transaction without committing or rolling it back on behalf of the caller.
- `controllers/BaseApiController.php` sends JSON errors through its existing PSR response writer, so module error paths work with the stable response type as well as native Grocy requests.
- The product, conversion, resolved-conversion, bulk-review, coverage, and purchase-capture views expose the new workflows. `public/viewjs/quantityunitconversionform.js` and `public/viewjs/quantityunitconversionsresolved.js` supply the native page behavior. The conversion form leaves native Save available when the module feature flag is off.
- `Dockerfile.atech` overlays every edited Grocy core path and the new views while retaining the pinned LinuxServer Grocy 4.6 base. `.dockerignore` excludes local database snapshots and dependency caches from the build context; `.gitignore` excludes those snapshots from commits.
- The stable cache marker advances to `ATECHPCS-grocy_AI-14` to invalidate persisted route and view caches on image replacement. Production data remains on the existing persistent data mount.
- The capture and review Blade views pass a literal `%s` to Grocy's translation formatter for client-side message templates, avoiding a server-side format error before the page renders.
- The native conversion form does the same for its dimension and source message templates.

## Unused Grocy features

Chores and batteries remain in upstream source code, but their fork defaults are off. Keep these settings in the deployment configuration so the intent is explicit:

```php
Setting('FEATURE_FLAG_CHORES', false);
Setting('FEATURE_FLAG_BATTERIES', false);
```

Keeping the upstream implementations intact avoids unnecessary merge conflicts and allows either feature to be restored without a database migration.

## Purchase receipt review

The purchase review view loads isolated `capture-receipts.js` before `capture-review.js`. Receipt edits and allocations remain in extension endpoints. `GET /api/grocy-ai/capture/trips/{tripId}/receipt-readiness` exposes the same server blockers used during commit. The customization marker in `custom/grocy_AI/version.json` advances to `ATECHPCS-grocy_AI-19`; the image copies it into root `version.json` to invalidate persisted Blade and route caches for this UI and route update.

The capture and review views now link an open trip back to `/grocyai/capture?trip=<id>` and load its saved lines through the existing trip GET endpoint. A missing or closed trip does not create a replacement automatically. When no ID is supplied, the capture page selects the signed-in user's latest open trip with saved scans from the namespaced capture tables. The capture asset uses cache version `2.6.2`, and the stable customization marker advances to `ATECHPCS-grocy_AI-21` for the new view bytes.

Receipt persistence is namespaced under `grocy_ai_receipt_*` in the Grocy SQLite data path; private images are under `GROCY_DATAPATH/grocy_ai/receipts/`. This adds no new upstream hook beyond the purchase review view, module routes/controller, and `Dockerfile.atech` overlay already listed above. The receipt release and recovery procedure is in [`docs/PURCHASE-RECEIPT-ACCEPTANCE.md`](docs/PURCHASE-RECEIPT-ACCEPTANCE.md).

The purchase review view also loads `capture-product-research.js` between the receipt editor and `capture-review.js`. It reads the separate versioned research DTO and mounts a card beside each selected unknown line; the existing capture and receipt DTOs and their stock commit gate stay separate. The review asset version is `2.6.3`.

## Capture research worker boundary

The module registers three worker-only capture research routes for bounded job claim, completion, and failure. Grocy's existing API authentication runs before these routes, and the module also checks the server-held `GROCY_AI_RESEARCH_WORKER_KEY` with a timing-safe comparison. The only upstream configuration edit is the empty `AI_RESEARCH_WORKER_KEY` default in `config-dist.php`; deployments provide its secret outside Git. `custom/grocy_AI/version.json` advances to `ATECHPCS-grocy_AI-23` so the persisted route cache includes the new endpoints. Worker leases and raw results do not enter capture browser DTOs.

The namespaced CLI `custom/grocy_AI/bin/capture-research-backfill.php` uses the configured absolute `GROCY_DATAPATH` to open `grocy.db`; `--db=PATH` is a local fixture override. It adds no web route or upstream hook. The customization marker advances to `ATECHPCS-grocy_AI-26` for the release image/cache boundary. Dry runs use SQLite `query_only`; apply uses one immediate transaction and the existing research queue. No core product, barcode, receipt, or stock table is written.

The purchase review also reads `GET /api/grocy-ai/capture/research/options` once per review to show active local groups, the current taxonomy leaves, and active top-level parent products alongside provider suggestions. The route requires stock purchase permission and returns only review choices; approval still validates all references and parent unit compatibility. A selected known scan with an unfinished research draft presents an explicit link to its current product owner and cannot create a duplicate. The capture review asset token advances to `2.6.4` and the customization marker to `ATECHPCS-grocy_AI-27` for the new route and view bytes.

Receipt review now offers same-trip scan candidates from reviewed product names and research drafts, and a manual UPC picker when store receipt descriptions cannot be matched safely. Pairing a receipt line to an unknown scan updates only the namespaced research evidence after the user confirms it. Once a product is approved or linked, the paired scan and receipt-derived unit price are prefilled for an explicit allocation confirmation. No pairing or suggestion creates a product or books stock. The capture review asset token advances to `2.6.5` and the customization marker to `ATECHPCS-grocy_AI-28` for this release.

Paid capture web searches use the namespaced research migration v3 and an authenticated worker reservation route at `POST /api/grocy-ai/capture/research/jobs/{jobId}/web-search/reserve`. The additional upstream configuration default is `AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT=20`. Reservations remain append-only across lease reclaim and provider failures; manual retries keep provider lookup available while limiting paid searches to generations zero and one.

OpenAI UPC fallback review accepts the companion v2 evidence contract while retaining provider-only v1. Validation, citation DTOs, and mobile cards stay in the module service and public assets. The existing purchase review hook advances its asset token to `2.6.7`; the customization marker advances to `ATECHPCS-grocy_AI-29` to invalidate cached routes for the paid reservation endpoint. No additional upstream integration hook is introduced.

The capture and review views show each saved scan's original UPC. Camera reads require an explicit confirm or edit before capture, while typed entry keeps its direct Add action. Both views use asset token `2.6.8`; the customization marker advances to `ATECHPCS-grocy_AI-30` so the production image invalidates cached Blade views.

Any failed camera scan POST, including a lost or malformed response, locks capture until page reload and manual trip review. The page never infers whether the POST saved the scan and offers no immediate retry, avoiding an accidental duplicate purchase quantity. Typed Add retains its existing submission path and is paused while a camera save is in flight.

Receipt scan suggestions ignore one leading six-digit store item code when comparing OCR receipt descriptions with product names. The stored OCR description and product names retain their original text, and the suggestion still requires user pairing and allocation confirmation.

## Capture classification suggestions

The additional upstream configuration default is `AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT=20` in `config-dist.php` (environment override `GROCY_AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT`). It limits append-only UTC-day reservations independently of capture web search. The module registers worker-only `POST /api/grocy-ai/capture/research/classifications/claim`, `POST /api/grocy-ai/capture/research/classifications/{jobId}/reserve`, `POST /api/grocy-ai/capture/research/classifications/{jobId}/complete`, and `POST /api/grocy-ai/capture/research/classifications/{jobId}/fail`; each requires explicit Grocy API credentials and the private worker key. Eligible unclaimed work is exposed as pending in the review DTO. Suggestions and manual choices remain subject to explicit approval and normal persistence checks.

The purchase review asset token advances to `2.6.9`, and `custom/grocy_AI/version.json` advances to `ATECHPCS-grocy_AI-31` so the image overlay invalidates persisted route/Blade caches and phones request the updated classification UI. The capture page retains token `2.6.8` because its assets did not change.


## Capture purchase and stock unit suggestions

The isolated research service adds active local unit evidence and nullable classification unit candidates, saves reviewer unit selections/clears in the existing research draft, and revalidates directed positive global conversions and Generic Parent compatibility before approval. The existing classification call accepts bounded active unit choices and conversion pairs; legacy results without unit fields remain compatible. Multipack or conflicting evidence leaves provisional units blank. No new paid call, conversion creation, catalog write, or stock write occurs from suggestions or draft edits.

The existing purchase review hook in `views/grocyai_capture_review.blade.php` uses asset token `2.6.10` for the unit controls and stale-response guard. The capture page retains `2.6.8`. `custom/grocy_AI/version.json` advances to `ATECHPCS-grocy_AI-32` so the image overlay invalidates persisted route and Blade caches. No additional upstream hook or configuration default is needed.


## Mobile purchase review queue

The existing `views/grocyai_capture_review.blade.php` hook loads isolated `capture-review-queue.js` before `capture-review.js`, alongside the receipt and research components. Review assets advance together to `2.6.11`; `custom/grocy_AI/version.json` advances to `ATECHPCS-grocy_AI-33` for persisted Blade/route cache invalidation by the release image overlay.

The purchase review follow-up keeps server receipt-readiness checks intact while collapsing long trip-wide reason lists in the custom review UI. It corrects the custom research component's failed GET message and adds bounded disclosure styling. The same review hook advances to asset token `2.6.12` and customization marker `ATECHPCS-grocy_AI-34` to invalidate persisted view and browser caches.

The review UI now labels scan deletion explicitly and allows deleting scans that have no receipt allocation history, even when another scan in the trip has a receipt. Scans with allocation history remain protected for audit; exclude them from the purchase instead. The existing audited trip cancellation endpoint is exposed as a twice-confirmed Delete trip action for uncommitted trips. Continue Scanning and Finish Scanning share a spaced mobile action row. Review assets advance to `2.6.13` and the customization marker to `ATECHPCS-grocy_AI-35`.

Below Bootstrap md, the isolated browser assets render one stable scan, receipt-only, multi-scan receipt, or adjustment card at a time with accessible Previous/Next navigation plus optional non-form horizontal swipe. Receipt lines have one authoritative editor; shared allocations link to it. Compact receipt summaries retain totals, image access, and Finish/Reopen controls. Unsaved draft state survives navigation and refresh, and server readiness remains authoritative. Save research draft, receipt saves, Finish receipt, Approve new product, and Commit purchase retain their separate persistence boundaries. No schema or backend write behavior changes and no additional upstream hook is added.
