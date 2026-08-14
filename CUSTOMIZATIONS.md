# ATECHPCS Grocy customizations

This fork keeps ATECHPCS-specific behavior isolated so upstream Grocy updates remain practical.

## Branch model

- `master` follows `grocy/grocy`.
- `atech-main` is the deployable ATECHPCS branch.
- Upstream releases are merged into `atech-main` with merge commits so conflicts and local changes remain visible.
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
- `public/viewjs/productform.js`: one post-Save continuation invokes the transient barcode attachment only after Grocy establishes a trusted product ID and before redirect.
- `migrations/0256.php`: transactional checksum-valid canonical GTIN uniqueness; collisions block without deleting or reassigning household data.

The stable release mirrors the portable module bytes first, then carries one separately reviewable eight-path framework adapter commit. Stable retains its `Grocy\Controllers\BaseApiController` namespace and class-based `JsonMiddleware::class`; the other adapter paths are the feature-gated product-form hook, narrow normal-Save continuation, exact migration, cache marker, customization record, and the Docker overlay that installs both new core adapter files at their runtime paths.

The module implementation and contract are documented in [`custom/grocy_AI/README.md`](custom/grocy_AI/README.md).

## Unused Grocy features

Chores and batteries remain in upstream source code, but their fork defaults are off. Keep these settings in the deployment configuration so the intent is explicit:

```php
Setting('FEATURE_FLAG_CHORES', false);
Setting('FEATURE_FLAG_BATTERIES', false);
```

Keeping the upstream implementations intact avoids unnecessary merge conflicts and allows either feature to be restored without a database migration.
