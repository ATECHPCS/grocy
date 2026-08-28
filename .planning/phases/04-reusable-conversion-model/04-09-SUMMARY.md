---
phase: 04-reusable-conversion-model
plan: "09"
subsystem: product-conversion-provenance
tags: [javascript, blade, css, playwright, read-only, provenance, native-save]
provides:
  - read-only product conversion provenance beside the native product conversion table
  - closed presentation contract with factor redaction and revision-guarded rendering
  - browser request-counter evidence that the product UI performs no reusable mutation
affects: [04-06, 04-07, 04-08]
key-files:
  created:
    - public/custom/grocy_AI/conversion-explanations.js
    - .planning/phases/04-reusable-conversion-model/04-09-SUMMARY.md
  modified:
    - views/productform.blade.php
    - public/custom/grocy_AI/grocy-ai.css
    - public/custom/grocy_AI/conversion-explanations.test.js
    - custom/grocy_AI/tests/browser/specs/conversions.spec.js
    - custom/grocy_AI/tests/browser/specs/happy-path.spec.js
    - custom/grocy_AI/tests/browser/fixtures/productform.html
    - custom/grocy_AI/tests/browser/support/server.mjs
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/module-version.json
key-decisions:
  - "Only a `product_native` status may carry a factor; the browser redacts the factor a second time so a tampered inactive/unavailable/blocked response can never present a usable number."
  - "Blocker codes are mapped to a closed correction-category sentence and are never rendered raw."
  - "Rendering is guarded by both the product/form revision and a monotonic request sequence, so a late or out-of-order response cannot repaint a superseded status."
  - "The product status region contains no control other than its details disclosure, so provenance can never become an activation or cleanup authority."
requirements-completed: [CONV-04, CONV-05]
---

# Phase 04 Plan 09: Product Conversion Provenance Summary

**The product editor now explains which conversion source wins, how uncertain it is, and what blocks it — without gaining any authority to write, activate, or project a conversion.**

## Accomplishments

### Task 1 — accessible, revision-safe product conversion provenance

- Added `public/custom/grocy_AI/conversion-explanations.js` as a UMD module matching the Phase 4 `quantityunitconversionform.js` pattern, exporting `describeProductConversionStatus`, `createProductStatusController`, `renderProductStatus`, and `attachProductStatus`.
- `describeProductConversionStatus` validates the Plan 04-05 DTO as one all-or-nothing boundary: exact 14-member key set, closed `status` / `winner_source` / `dimension` / `source_status` enums, bounded and character-restricted text, a decimal-only factor, and a bounded blocker list. Any drift becomes the single bounded recovery state with empty details.
- Presentation states follow the 04-UI-SPEC product-area contract: `Product override` + `Exact`, `Approximate profile: {profile} · {source} {version}` + `Approximate` with the inactive-gate note, the inactive-gate copy for a universal rule, `No estimate is available until this product has an explicit food classification.`, `No estimate is available for this food type.` with its contract body, and `Blocked`.
- Status is text plus a supplementary `aria-hidden` icon — never colour alone. Blockers render with `role="alert"`; every other state uses `role="status"` with `aria-live="polite"`.
- Rendering inserts text nodes and fixed class names only; no API value can produce markup, and the details region stays collapsed inside a native `<details>` disclosure with a 44px `Show conversion details` control.
- Added the `#grocy-ai-product-conversion-status` region to `views/productform.blade.php` between the Product specific QU conversions heading and its native table, gated on `GROCY_FEATURE_FLAG_GROCY_AI` and edit mode, carrying the product id, inspected unit pair, and a form revision token.
- Added `.grocy-ai-conversion-*` styles using only the established module vocabulary: 8-point spacing, 16px/14px/20px type at weights 400/500, `overflow-wrap: anywhere`, tabular numerals, a 2px `var(--primary)` focus outline, 44px disclosure target, a `.night-mode` variant, and a full-width narrow-viewport rule.
- Bumped `module-version.json` and the single `$grocyAiAssetVersion` literal to `2.5.0` together, and raised the native asset-token contract from three to four custom product-form assets.

### Task 2 — browser proof that the product UI stays read-only

- Extended `support/server.mjs` with authoritative request counters for the product status read, product-scoped native POST/PUT, reusable-universal native POST/PUT, activation, projection, and cache families, plus a default-deny counter for any other conversion-shaped `/api/` path.
- The fixture server rejects a generic reusable-universal write rather than simulating success, so no browser fixture implies the bypass works; Plan 04-08 still owns the server-side rejection proof.
- Added a test-only `POST /__fixture/activate-ruleset` control outside `/api` and proved that product status reads after activation still make zero mutation, activation, projection, or cache calls.
- Extended `fixtures/productform.html` with the product conversion section, an existing product conversion row, and native package/measured-density save controls that exercise only `objects/quantity_unit_conversions`.
- Added six `@conv04` specs (12 runs across Chromium and WebKit): zero-mutation status reads including post-activation, inactive-profile source/version with no usable factor, explicit disclosure of the native full-precision factor with a 44px keyboard-operable control, blocked/unavailable states non-actionable and overflow-free at 320px with no raw blocker code, normal product-scoped package and density saves reaching only native routes with the existing row untouched, and a failed status read that preserves the native table and normal save while showing bounded recovery copy.
- Updated the `@smoke` harness contract for the fourth phase-owned asset.

## Verification

- `node --test public/custom/grocy_AI/conversion-explanations.test.js` — 21 passed (13 added this plan; they were RED before the module existed).
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` — 26 passed.
- `npx playwright test` (full matrix) — 164 passed, 10 failed. All 10 failures are the pre-existing baseline described below; the count of unique failing specs is unchanged from before this plan.
- `php custom/grocy_AI/tests/run.php` — all 113 checks passed on PHP 8.5.10 with the real Blade autoloader present.
- `node --check public/custom/grocy_AI/conversion-explanations.js`, `node --check custom/grocy_AI/tests/browser/support/server.mjs`, `node --check custom/grocy_AI/tests/browser/specs/conversions.spec.js`, `php -l views/productform.blade.php` — all clean.
- `git diff --check` — clean.

## Pre-existing baseline (not caused by this plan)

Five browser specs fail on both engines and already failed at the end of Phase 3 (`ccade631`), before any Phase 4 code:

| Spec | Assertion |
|---|---|
| `happy-path.spec.js` `@mob01` | document has no phone-width overflow |
| `responsive-a11y.spec.js` `@mob08` 320px | `document.scrollWidth - clientWidth <= 0` (observed 16) |
| `responsive-a11y.spec.js` `@enr05…` 320px | responsive integrated review layout |
| `barcode-handoff.spec.js` `@enr02 @enr03 @enr09` | transient barcode staging |
| `contract-review.spec.js` `@enr05 @enr06 @enr09` | seven-family final diff |

Root cause of the 320px overflow is deterministic, not environmental: the fixture's `.row { margin: 0 -8px; }` on `#grocy-ai-product-enrichment` extends 8px past the 16px-padded body on each side, producing exactly the observed 16px. This contradicts the Phase 1 and Phase 2 mobile completion records and needs its own quick task; it is out of Plan 04-09's scope.

## Deviations from the plan

- The plan routed 04-06 as the next slice, but `04-06-PLAN.md` declares `depends_on: [04-09]`, reads `04-09-SUMMARY.md` in its context block, and the roadmap places 04-09 in Wave 6 ahead of 04-06 in Wave 7. `HANDOFF.json` and `.continue-here.md` were stale; 04-09 was executed first and those files are now corrected.
- The 04-UI-SPEC copywriting table has no entry for a failed product-status read or for a blocked product-area correction category. Two closed string families were added: `The conversion status could not be loaded. Try again. Nothing was changed.` and the fixed `Correction needed` sentences. Both are module-owned constants, and no blocker code, SQL, exception text, URL, or household value can reach the DOM.
- The inspected unit pair is fixed at `cup → g` and is visible in the heading. It is the pair the seeded Phase 4 profiles cover; a product-derived pair would need a Grocy-unit-to-catalog-key mapping that no plan has specified.
- Phase 4 strings live in the module's JavaScript `COPY` constant rather than Grocy localization, matching the Plan 04-04 precedent in `public/viewjs/quantityunitconversionform.js`. The Blade heading and disclosure label do use `$__t`.

## Follow-ups for later plans

- `custom/grocy_AI/portable-files.txt` does not yet list `public/custom/grocy_AI/conversion-explanations.js`. Plan 04-08 owns that file and must add it, or the SHA-pinned parity checker will not compare the new portable asset.
- `custom/grocy_AI/README.md` and `CUSTOMIZATIONS.md` still describe three custom product-form assets; Plan 04-08 owns those updates.

## Decision and next step

The product conversion area is inspection-only and its provenance is now legible at the decision point. Plan 04-06 may reuse `describeProductConversionStatus` and the `conversion-explanations` copy for the resolved-conversions table, but the resolved table needs its own bounded `resolved-provenance` read; it must not repurpose the product status endpoint.
