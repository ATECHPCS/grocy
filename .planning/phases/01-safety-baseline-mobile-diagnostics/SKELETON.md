# Walking Skeleton — grocy_AI

**Phase:** 1
**Generated:** 2026-08-12

## Capability Proven End-to-End

> An authenticated phone user can validate a GTIN, run the existing read-only enrichment request, review the returned name/image preview, and use Grocy's normal product form Save to prove the existing SQLite read/write path without enrichment adding any persistence.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Existing Grocy 4.6 PHP 8.5, Slim 4, Blade, Bootstrap 4, plain JavaScript | Preserves the mature upstream-compatible application and D-01/D-02; no new application scaffold or frontend framework is introduced. |
| Data layer | Existing SQLite/LessQL through Grocy's normal product form Save | Enrichment remains zero-write; the deployed smoke proves one real read and one real write only through the established Save/reload workflow per D-06. |
| Auth | Existing Grocy session/API-key middleware plus `MASTER_DATA_EDIT` permission | Keeps the deployed authentication and authorization boundary unchanged. |
| Companion | Existing `grocy-mcp` `/v1/products/enrich/upc/{upc}` read-only service | The companion remains stateless suggestion/evidence infrastructure and receives no Grocy database authority. |
| Diagnostics | W3C `traceparent` across owned browser→Grocy→companion boundaries, closed allowlist DTO, `Server-Timing`, no provider propagation | Correlates request stages while enforcing D-04 and preventing diagnostic disclosure. |
| Deployment target | Existing DockerKomodo stable image path with persistent `/etc/komodo/grocy:/config` | Preserves the known production topology and data volume; stable adaptation is verified without switching `atech-main`. |
| Directory layout | Portable code in `custom/grocy_AI/` and `public/custom/grocy_AI/`; companion code in `/Users/ian/Documents/Repos/grocy-mcp`; minimal Blade hook in `views/productform.blade.php` | Matches the fork boundary and keeps the two repositories' commit histories separate. |

## Stack Touched in Phase 1

- [x] Existing project scaffold documented; no framework initialization is required.
- [ ] Routing — authenticated `/api/grocy-ai/products/enrich/upc/{upc}` exercised through the rendered product form.
- [ ] Database — deployed smoke loads a designated product and restores one approved field through two normal Save/reload cycles; no direct SQL or enrichment persistence.
- [ ] UI — manual/camera GTIN intent drives the real enrichment route, preview, and existing form controls.
- [ ] Deployment — stable DockerKomodo image smoke records routes, assets, cache marker, persistent-volume continuity, and physical-phone evidence.

## Brownfield Proof Contract

The deterministic browser harness proves the phone interaction, zero-write enrichment invariant, state machine, and normal Save controls without live providers. The final deployed smoke signs in through existing auth, opens a designated test product (real SQLite read), changes an approved reversible field through the ordinary Save action (real SQLite write), reloads to observe it, restores the original value through ordinary Save, and reloads again. Evidence records product ID only; it excludes GTIN, product values, credentials, payloads, cookies, image tokens, and inventory contents per D-04.

## Out of Scope (Deferred to Later Slices)

- Phase 2 structured field review, duplicate-safe barcode handoff, and secure-media contract expansion.
- Phase 3 taxonomy and classification.
- Phase 4 conversion schema/rules/projection.
- Phase 5 bulk persistence, audit, and rollback.
- Any new enrichment write endpoint, schema migration, ORM schema push, frontend framework, observability backend, or automatic retry.

## Subsequent Slice Plan

- Phase 2: Duplicate-safe, review-before-save structured enrichment and hardened media.
- Phase 3: Stable household food taxonomy and one-product categorization.
- Phase 4: Deterministic reusable conversion model.
- Phase 5: Immutable bulk preview/apply/recovery engine.
- Phase 6: Reviewed inventory categorization and conversion cleanup.
- Phase 7: Reproducible upstream/stable promotion and recovery.

