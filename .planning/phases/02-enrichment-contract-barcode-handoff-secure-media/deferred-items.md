# Deferred Items

## Plan 02-07

- The broad Chromium-mobile suite still contains 15 Phase 1 fixture cases that send the retired pre-contract-v2 enrichment payload or expect the retired `Use as product picture` action. They fail closed as `contract_invalid` under the already-shipped Phase 2 contract. This is outside the secure-media implementation scope; current contract-v2 ENR-07/08/09 slices pass in Chromium-mobile and WebKit-mobile. Migrate or archive those legacy fixture cases in the test-maintenance plan without restoring permissive legacy normalization.

## Plan 02-09

- The full companion suite passes but emits Starlette's existing `httpx`/`TestClient` deprecation warning from `tests/test_http_api.py`. Dependency migration is outside this no-churn acceptance plan and no package change is authorized without separate package-legitimacy review.
