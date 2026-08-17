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

## Unused Grocy features

Chores and batteries remain in upstream source code, but their fork defaults are off. Keep these settings in the deployment configuration so the intent is explicit:

```php
Setting('FEATURE_FLAG_CHORES', false);
Setting('FEATURE_FLAG_BATTERIES', false);
```

Keeping the upstream implementations intact avoids unnecessary merge conflicts and allows either feature to be restored without a database migration.
