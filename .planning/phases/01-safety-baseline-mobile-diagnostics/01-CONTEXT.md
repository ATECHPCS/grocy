# Phase 1: Safety Baseline & Mobile Diagnostics - Context

**Gathered:** 2026-08-12
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase hardens the already-deployed `grocy_AI` enrichment path for dependable phone use and actionable local diagnostics. It delivers local GTIN validation, bounded/cancellable requests, deterministic failure and recovery states, stale/duplicate suppression, privacy-safe cross-component timing, degraded-provider continuity, automated mobile-browser coverage, and a recorded physical-phone acceptance baseline.

It does not add the Phase 2 structured-field/barcode handoff contract, Phase 3 taxonomy, Phase 4 conversion rules, Phase 5 bulk writes, or redesign Grocy's product form. Search and diagnostics remain zero-write operations; normal Grocy Save remains the only persistence path.

</domain>

<decisions>
## Implementation Decisions

### Locked Product and Interaction Contract
- **D-01:** Use the approved Phase 1 UI contract without reopening its visual, copy, state-machine, timing, accessibility, diagnostics, or degraded-path decisions.
- **D-02:** Preserve Grocy's Bootstrap 4/Roboto/Font Awesome design system and the current enrichment-card placement; do not introduce a frontend framework or a second persistence flow.
- **D-03:** Keep the 15-second browser deadline and initial internal timing budgets as the release baseline. Any re-baseline must come from a recorded physical-phone run with device/browser/network metadata and p50/p95 evidence.
- **D-04:** Diagnostics are request-scoped, collapsed by default, locally generated, redacted by allowlist, and never persisted as inventory data. They must exclude GTIN values/history, product data, credentials, headers, cookies, payloads, inventory contents, and image tokens.
- **D-05:** Do not automatically retry after reconnect or failure. The user receives a named state and explicitly chooses Retry or continues editing manually.
- **D-06:** Existing form values, image selections, and normal product Save controls remain usable across cancel, timeout, offline, provider error, partial results, and diagnostics actions.

### User Direction From Discussion
- **D-07:** The user does not want additional focus or product-level deliberation on client selection, diagnostic breadth, release-threshold policy, or network-scenario selection. These are planning/execution details to resolve pragmatically within the approved requirements and UI contract.
- **D-08:** Phase planning should proceed without blocking on a preferred phone model, browser brand, VPN path, or custom performance-policy choice. Use available household devices and the actual LAN topology to produce the required evidence.

### the agent's Discretion
- Choose the smallest test matrix that covers the supported responsive browser paths, current household phone, required viewport widths, and the UI-SPEC degraded scenarios.
- Decide the internal trace propagation and structured-log mechanism, provided the report contract, privacy exclusions, and browser/Grocy/companion/provider stage visibility are preserved.
- Decide how timing thresholds are encoded in automated checks and release output, provided the approved baseline is enforced and evidence is readable.
- Decide whether nearby same-request Grocy timings are included to localize failures; do not expand this phase into general application-wide monitoring.
- Choose deterministic provider fakes, network shaping, and live LAN smoke-test balance for automation. Live external providers must not make the core test suite flaky.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract
- `.planning/ROADMAP.md` — Phase 1 goal, boundary, dependency, and five observable success criteria.
- `.planning/REQUIREMENTS.md` — Authoritative `MOB-01` through `MOB-08` requirements and out-of-scope boundaries.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-UI-SPEC.md` — Approved visual, interaction, copywriting, timing, diagnostics, accessibility, degraded-path, and mobile-verification contract.
- `.planning/PROJECT.md` — Core value, human-control rule, upstream-compatibility constraint, deployment topology, and security/performance boundaries.

### Research and Codebase Guidance
- `.planning/research/SUMMARY.md` — Project research synthesis, recommended Phase 1 architecture, tooling, risks, and ordering.
- `.planning/codebase/TESTING.md` — Existing PHP harness, missing HTTP/browser coverage, and established deterministic test conventions.
- `.planning/codebase/CONVENTIONS.md` — PHP/JavaScript style, module isolation, error-handling, logging, and file-placement conventions.
- `.planning/codebase/CONCERNS.md` — Synchronous latency, observability gaps, DOM coupling, route/cache deployment risks, and dual-branch test gaps.

### Current Implementation Baseline
- `views/productform.blade.php` — Existing feature-gated enrichment-card placement and normal product form lifecycle.
- `public/custom/grocy_AI/product-enrichment.js` — Current search, preview, apply-name, and image-selection behavior to harden.
- `public/custom/grocy_AI/grocy-ai.css` — Current phase-owned visual styling boundary.
- `custom/grocy_AI/src/GrocyAiService.php` — Synchronous companion transport, normalization, timeout, and secure-image proxy behavior.
- `custom/grocy_AI/src/GrocyAiApiController.php` — Authentication/permission and HTTP error-mapping boundary.
- `custom/grocy_AI/tests/run.php` — Current standalone contract-test harness.
- `custom/grocy_AI/README.md` — Module contract, configuration, routes, and review-before-save behavior.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Grocy.Api` in `public/js/grocy.js`: existing same-origin API conventions, although Phase 1 may need a cancellable request seam rather than the current fire-and-forget helper.
- `Grocy.FrontendHelpers` and Bootstrap alerts/buttons/forms: established feedback, busy-state, validation, and responsive UI primitives.
- `public/viewjs/components/camerabarcodescanner.js`: existing camera permission, scan-event, and constraint patterns for barcode entry.
- Transport injection in `custom/grocy_AI/src/GrocyAiService.php`: deterministic seam for timeout, malformed response, and failure-path PHP checks.
- `custom/grocy_AI/tests/run.php`: current no-framework PHP test runner and failure-reporting style.

### Established Patterns
- Keep new behavior isolated under `custom/grocy_AI/` and `public/custom/grocy_AI/`; document and minimize any upstream core hook.
- Validate untrusted data at the PHP service boundary and translate expected exception categories in the API controller.
- Use browser IIFEs, lower-camel local functions, tabs, explicit UI restoration on success/failure, and safe text insertion.
- Preserve the upstream product form IDs and normal Save/file-upload lifecycle.
- Bump the custom production version marker when route or view integration changes so persisted route/view caches invalidate.

### Integration Points
- Browser interaction: `public/custom/grocy_AI/product-enrichment.js` and the feature-gated markup in `views/productform.blade.php`.
- Grocy HTTP boundary: `custom/grocy_AI/routes.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, and `custom/grocy_AI/src/GrocyAiService.php`.
- Companion timing/trace boundary: versioned endpoints in the separate `ATECHPCS/grocy-mcp` repository and deployment at `10.10.0.156:3061`.
- Stable deployment parity: portable module files on `atech-main`, adapted stable files on `atech-release`, and `Dockerfile.atech`/custom version marker checks.

</code_context>

<specifics>
## Specific Ideas

- Treat the existing deployed name/image workflow as the brownfield baseline, not as new Phase 1 scope.
- The user explicitly prefers forward progress over additional discussion of device matrices or monitoring-policy nuances.
- Optimize for practical diagnosis of the originally reported phone/LAN connection errors without turning Phase 1 into a general observability platform.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 01-safety-baseline-mobile-diagnostics*
*Context gathered: 2026-08-12*
