# Phase 2: Enrichment Contract, Barcode Handoff & Secure Media - Context

**Gathered:** 2026-08-13
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase turns the deployed name/image preview into a strictly validated, versioned product-enrichment contract. It lets users compare current product values with sourced suggestions, safely hand a scanned barcode into Grocy's existing product/barcode workflow without duplicates, review one final selected diff, and demand-load real package images through short-lived same-origin handles.

Search, preview, cancellation, timeout, and media failure remain zero-write. A barcode or selected field persists only through Grocy's normal Save path. Phase 1 physical-phone timing evidence was explicitly skipped and remains an acknowledged incomplete prerequisite; it is not treated as a Phase 2 acceptance result.

</domain>

<decisions>
## Implementation Decisions

### Suggestion Review and Selection
- **D-01:** Present each supported field as a phone-friendly side-by-side comparison of its current value and suggested value. Show the suggestion's source, confidence band, reason, and freshness directly with that field rather than in a detached summary.
- **D-02:** High-confidence suggestions may be preselected so product creation stays fast, but every preselection remains visible and reversible before the final diff and normal Save.
- **D-03:** A suggestion qualifies for high-confidence preselection only when a validated structured provider supplies that field directly for the canonical barcode. Search-derived, inferred, transformed, or otherwise indirect suggestions remain unselected even if they carry a high numeric score.
- **D-04:** Preselection applies only when the current product field is blank. A suggestion that would replace any existing non-empty value always requires an explicit user selection.
- **D-05:** The final diff must distinguish automatic preselection from explicit user selection without weakening the rule that normal Grocy Save is the only persistence action.

### Carried-Forward Safety Contract
- **D-06:** Preserve Phase 1's bounded request lifecycle, stale/duplicate suppression, named recovery states, usable normal Save controls, privacy-safe diagnostics, and zero enrichment writes.
- **D-07:** Keep feature code within `custom/grocy_AI/` and `public/custom/grocy_AI/`; minimize and document core hooks and maintain portable parity with the stable branch.
- **D-08:** Prefer real structured-source front-package imagery. Search-discovered imagery is an explicitly unverified fallback, never equivalent evidence.

### the agent's Discretion
- Choose the exact compact control (checkbox, toggle, or select button) used to represent a selected suggestion, provided selection state is accessible, touch-friendly, and clear in the final diff.
- Choose labels and visual treatment for confidence bands, source, reason, freshness, and automatic-preselection badges within the existing Bootstrap 4/Roboto/Font Awesome design system.
- Resolve barcode-owner routing, canonical-equivalent presentation, stale-current-value handling, and secure-media implementation details according to ENR-01 through ENR-09 and established Grocy patterns; the user did not request further discussion of those areas.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase Contract
- `.planning/ROADMAP.md` — Phase 2 goal, dependency, MVP boundary, UI hint, and observable success criteria.
- `.planning/REQUIREMENTS.md` — Authoritative ENR-01 through ENR-09 requirements and the out-of-scope boundary for nutrition, allergen, dietary, or medical recommendations.
- `.planning/PROJECT.md` — Core value, review-before-save rule, companion topology, real-package-image preference, and upstream/deployment constraints.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-CONTEXT.md` — Carried-forward mobile lifecycle, privacy, diagnostic, zero-write, and normal-Save decisions.
- `.planning/phases/01-safety-baseline-mobile-diagnostics/01-PHONE-ACCEPTANCE.md` — Explicitly skipped physical evidence and the resulting incomplete Phase 1 release gate; do not reinterpret it as PASS.

### Existing Implementation and Integration
- `views/productform.blade.php` — Product form fields, existing barcode section, normal Save controls, enrichment markup, and phase-owned asset hooks.
- `public/custom/grocy_AI/product-enrichment.js` — Existing request state machine, name staging, image candidate rendering, secure-image attachment, and camera handoff.
- `public/custom/grocy_AI/grocy-ai.css` — Existing responsive visual boundary for the enrichment card.
- `custom/grocy_AI/src/GrocyAiService.php` — Companion transport, GTIN normalization, response validation, deadline, and image retrieval boundary.
- `custom/grocy_AI/src/GrocyAiApiController.php` — Permission enforcement and API response/error mapping.
- `custom/grocy_AI/README.md` — Deployed module contract, routes, secure media behavior, parity, and release procedure.
- `.planning/codebase/ARCHITECTURE.md` — Grocy request/write flow, view-script pairing, extension boundary, LessQL/service patterns, and migration constraints.
- `.planning/codebase/INTEGRATIONS.md` — Existing Grocy, companion-provider, barcode, file, and deployment integration seams.
- `.planning/codebase/STACK.md` — Current PHP/Blade/JavaScript/SQLite stack and version constraints.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `public/custom/grocy_AI/product-enrichment.js`: already owns cancellable searches, current-intent enforcement, product-name staging, candidate image selection, and secure same-origin download-to-file behavior.
- `views/productform.blade.php`: already exposes current product fields, existing product barcodes, camera input, user fields, and both normal Save actions needed for side-by-side comparisons and final staging.
- `custom/grocy_AI/src/GrocyAiService.php`: provides a strict PHP validation boundary in front of the companion and an injectable transport seam for deterministic contract tests.
- Grocy's existing barcode pages and product-form flow: provide the authoritative persistence path for duplicate-safe barcode staging rather than a new module-owned write endpoint.

### Established Patterns
- External data is untrusted until normalized and allowlisted at the PHP boundary; browser rendering uses safe text insertion and closed enums.
- The enrichment operation is read-only. Selected values and downloaded image files are staged in the existing form and persist only through normal Save.
- Browser behavior uses an IIFE and explicit state restoration; UI remains Bootstrap 4 and responsive without a new frontend framework.
- Portable module files must remain byte-identical across maintained branches, while stable-only adapters and cache markers are committed separately.

### Integration Points
- Extend the companion `/v1/products/enrich/upc/{upc}` response and Grocy service validator with versioned field-level suggestion/provenance data.
- Extend the feature-gated product form and `product-enrichment.js` with side-by-side review, selection state, canonical barcode ownership results, and final diff staging.
- Reuse existing product/barcode and file-upload Save flows; do not introduce enrichment persistence endpoints.
- Extend secure media validation and token handling without exposing external image URLs or opaque handles as durable product data.

</code_context>

<specifics>
## Specific Ideas

- The user prioritizes speed during product creation: direct structured-source suggestions for blank fields should arrive preselected, while existing data is protected from automatic replacement.
- Provenance should be understandable at the field where the decision is made, not hidden in a global diagnostics panel.

</specifics>

<deferred>
## Deferred Ideas

- **Nutrition Facts enrichment:** On a valid barcode during product creation, stage factual nutrition fields for review and allow them to persist only through normal Save. This is a new suggestion family outside ENR-01 through ENR-09. It must not be interpreted as nutrition, allergen, dietary, or medical recommendations, and requires its own requirements, source/schema validation, unit/serving normalization, provenance, and UI contract before implementation.

</deferred>

---

*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Context gathered: 2026-08-13*
