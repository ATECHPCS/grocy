# Phase 02 Environment Preflight

## Resolved preflight

status: PASS
rechecked_at_utc: 2026-08-13T21:06:00Z
contract_version: 2

### Product destinations

brand_target: products.brand:text-single-line
brand_target_count: 1
brand_target_drift_count: 0
package_size_target: none
package_size_target_count: 0
food_type_target: none
food_type_target_count: 0

The active brand destination is the single exact deployed product userfield. Phase 2 creates no package-size or food-type destination. Nutrition Facts, allergen, dietary, and medical members remain rejection-only and deferred.

### Canonical GTIN audit

canonical_valid_gtin_rows: 3
canonical_collision_groups: 0
canonical_aggregate_sha256: f275b9ade00718b8c19009b1131fed483c35d69a0bd8f54b392353993fb8b3c4

The read-only production query used the future canonical predicate: only numeric, checksum-valid GTIN-8/12/13/14 values receive a left-padded 14-character key; every other barcode receives `NULL`. The artifact records aggregate counts and their hash only. No barcode, product identity, or row output was retained.

### Secure media bounds

redirect_limit: 2
https_downgrade_allowed: false
dimension_range: 32-4096
pixel_limit: 16000000
byte_range: 2000-3000000
allowed_media_types: image/jpeg,image/png,image/webp
media_fixture_cases: 4
media_fixture_failures: 0
media_fixture_bounds_sha256: 26907b92c2dcf5de48061a319399ef2864361b1f4b8aee426dc1a935634264bb

Deterministic in-memory JPEG, PNG, and WebP fixtures, including the minimum dimension and a maximum-bound image below 16 megapixels, fit the locked byte, signature, decoded-dimension, and MIME envelope. Incompatibility would block execution; these limits were not widened.

### Companion dependency set

python_version: 3.12.13
httpx_version: 0.28.1
starlette_version: 1.6.0
uvicorn_version: 0.52.1
dependency_distribution_count: 69
dependency_constraints_sha256: 53c2a4b530e9802d0d0f5587875db0ae72320652dd4627925b06eca2edbb2019

The complete normalized installed-distribution set from the running companion is recorded in `grocy-mcp/constraints-phase2.txt`. Its sorted SHA-256 matches the deployed set exactly. No package installation or dependency resolution occurred in this plan.

### Privacy and authority boundary

The preflight used aggregate, query-only database inspection and read-only container metadata. It records no product or userfield values, barcode strings, URLs, handles, credentials, headers, or payloads. Search, preview, and this preflight retain zero persistence authority; Grocy's normal Save remains the sole durable product mutation path.
