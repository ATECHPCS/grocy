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

Public module name: `grocy_AI`  
Internal PHP namespace: `GrocyAI`

The small upstream integration surface is:

- `config-dist.php`: feature flag and companion-service settings.
- `routes.php`: conditional custom route registration.
- `views/productform.blade.php`: conditional product-enrichment panel and assets.
- `version.json` at image build time: customization marker that invalidates Grocy's persisted route/view cache.

The module implementation and contract are documented in [`custom/grocy_AI/README.md`](custom/grocy_AI/README.md).

The production container is built with `Dockerfile.atech`. It pins the matching LinuxServer Grocy 4.6 runtime and overlays only the integration surface listed above.

### Phase 1 portable and stable adapter boundary

- Main commit `968b03dbddd00d422817a1533b18850f71f2b120` and stable portable commit `2e35a36e5b3da0a6badf72a3dfcb8d11d6e4b936` define the current portable baseline. The existing seven paths remain unchanged and match `atech-main` byte-for-byte: the module/diagnostic versions, diagnostic and service classes, native contract tests, module documentation, browser behavior, and module CSS.
- The stable adapter commit changes only `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`, `views/productform.blade.php`, `custom/grocy_AI/version.json`, and this file. The controller retains `Grocy\Controllers\BaseApiController`, routes retain class-based `JsonMiddleware::class`, and the product form retains the stable Save lifecycle.
- `Customization` is `ATECHPCS-grocy_AI-5` so the unchanged `Dockerfile.atech` overlay invalidates persisted route/view and asset caches after the live Bootstrap focus, feedback-visibility, and camera focus-timing refinement.

## Unused Grocy features

Chores and batteries remain in upstream source code, but their fork defaults are off. Keep these settings in the deployment configuration so the intent is explicit:

```php
Setting('FEATURE_FLAG_CHORES', false);
Setting('FEATURE_FLAG_BATTERIES', false);
```

Keeping the upstream implementations intact avoids unnecessary merge conflicts and allows either feature to be restored without a database migration.
