# 06-07 — surface the 3 passes in bulk-review + browser spec + full-phase acceptance (SUMMARY)

**Status:** complete, GREEN. Native `php8.5 custom/grocy_AI/tests/run.php` → **All 272 checks passed**
(was 249; +23 from the new `categorization` endpoint-surface suite). Browser matrix (chromium-mobile +
webkit-mobile) → **206 passed** incl. the 6 new categorization tests; `npm run test:smoke` → **12 passed**;
`node --test public/custom/grocy_AI/bulk-review.test.js` → **34 passed**. Full-phase DATA-06/07 acceptance
recorded in [06-ACCEPTANCE.md](06-ACCEPTANCE.md).

## Task 1 — the three passes on the bulk-review surface

The existing `/grocyai/bulkreview` page (`GrocyAiBulkController::BulkReview`, `views/grocyai_bulkreview.blade.php`,
`public/custom/grocy_AI/bulk-review.js`) now drives all three Phase 6 passes, reusing the shipped
generate/review/apply/rollback/export controls. Each writing pass is driven by its **own explicit
server-side generator**, never by enumerating `RegisteredOperations()` (which stays pinned to the two
taxonomy ops with `delegate_write = AssignProductTaxonomy`).

- **Plan generation dispatch** — `GrocyAiApiController::GenerateBulkPlan` now accepts a closed 3-value
  `operation_type` selector and routes each to its generator (the request still carries **only**
  `operation_type`; proposed values/operations remain server-derived):
  - `taxonomy_assignment` → `GeneratePlan` (unchanged legacy confident-only pass; pinned counts intact),
  - `product_group_assignment` → `GenerateGroupPlan` (06-04),
  - `classification_review` → `GenerateClassificationPlan` (06-05, conflict-first). `classification_review`
    is a request-only selector; the stored plan header stays `taxonomy_assignment` (byte-identical to 06-05).
- **Conversion audit** — new read-only route `GET /api/grocy-ai/bulk/conversion-audit`
  (`BulkConversionAudit`) returns the 06-06 report (`global_count`, `product_specific_count`,
  `expected_count`, `expected_product_count`, `suspicious[]`, `ok`) from `auditConversions()` — SELECT-only,
  no plan, no apply/rollback. MASTER_DATA_EDIT-gated like the rest of the surface.
- **Client (`bulk-review.js`)** — additive, keeping every pinned contract: `isImage`/`imageValue` accept the
  group image shape `{product_group_id}` alongside `{leaf_slug}`; a confidence-band badge
  (`data-grocy-ai-bulk-band` = conflict / low-confidence / confident) is derived from the server `reason` so
  the conflict-first ordering + bands are visible; three generate controls (`generate`, `generateGroup`,
  `generateClassification`) share one internal generation flow; a read-only `loadConversionAudit` +
  `renderConversionAudit` render the report. The zero-argument `buildGeneratePlanBody`/`generate` contract
  the node test pins (`.length === 0`, called with 0 args) is untouched.
- **Blade** — three generate buttons + a report-only conversion-audit section + a
  `data-conversion-audit-endpoint` attribute; still exactly 2 asset-version tokens and no `<form>`.

## Task 2 — browser coverage

`custom/grocy_AI/tests/browser/specs/categorization.spec.js` (deny-by-default harness; the API mocked per
test with `page.route`, the fixture loading the **real** `/assets/bulk-review.js`). Three tests × 2 engines:

- **Group pass** — generate → items render as `suggest_product_group` proposals (native `product_group_id`)
  → apply (explicit confirm) → rollback (preview then execute); apply/rollback each POST exactly once.
- **Classification pass** — conflicts render **before** low-confidence **before** confident (asserted on the
  ordered band badges); the low-confidence item **retains Unclassified** (proposes None) and is deselected;
  only the confident change is pre-selected/in the apply set.
- **Conversion audit** — renders as a read-only report (`data-...-audit-ok=true`, baseline 62/18/9); no plan
  render, no apply.

New fixture (`fixtures/bulk-review.html`) and asset (`/assets/bulk-review.js`) added to the `server.mjs`
allowlist. Full matrix stays green (206 passed, no regressions).

## Task 3 — full-phase acceptance on the snapshot

Recorded in [06-ACCEPTANCE.md](06-ACCEPTANCE.md), operating on a **temp copy** (snapshot never mutated;
`sha256sum -c` OK). Raw-snapshot reality and the seeded demonstration are kept strictly separate.

- **Raw:** group pass = **0 suggestions** (0 evidence rows — recorded truthfully); classification raw
  confident = **57**; conversion audit = **62 global + 18 expected / 9 products, 0 suspicious**.
- **Seeded (labeled):** group DATA-06 approved-only diff (violations `[]`), ungrouped **224 → 221**,
  confident **57 → 60** after grouping (isolated via low-band seed); group DATA-07 products byte-identical +
  zero-diff rerun (identical checksum). Classification DATA-06 (module store + ledger only, native clean) and
  DATA-07 (products byte-identical + zero-diff rerun). Conversion audit read-only (row-value equality).

### Deviations / findings (honest)

- **Group pass fires a native cache-rebuild trigger.** On the full snapshot, writing
  `products.product_group_id` fires Grocy's `products_default_qu_conversions_UPD` trigger, which rebuilds
  `cache__quantity_unit_conversions_resolved` (224,602 rows, regenerated surrogate ids) with **byte-identical
  logical content**. The 06-04 "products + bulk tables only" claim held only on the trigger-less unit
  fixture. The cache is a Grocy-maintained **derived cache** (not authoritative inventory) and is approved in
  the group DATA-06 diff on that basis, with the id-excluded content proven identical; the sole authoritative
  write is still `products.product_group_id`, fully restored on rollback.
- **Classification rollback is not byte-identical in the module store, by design.**
  `grocy_ai_taxonomy_classifications` records the reversal in-place (new timestamped rows), so it is not
  byte-identical after rollback; logical restoration is proven by the zero-diff **rerun checksum equality**
  (the checksum is derived from every product's current leaf). DATA-07's native guarantee (`products`
  byte-identical) holds exactly.
- **The group op is still NOT in `RegisteredOperations()`** (06-04 decision upheld); the endpoint dispatches
  it via the explicit generator, matching the design fact that the registry is pinned to the two taxonomy ops.

## Files

- **Changed:** `custom/grocy_AI/src/GrocyAiApiController.php` (3-selector `GenerateBulkPlan` dispatch +
  read-only `BulkConversionAudit`); `custom/grocy_AI/routes.php` (conversion-audit route);
  `views/grocyai_bulkreview.blade.php` (three generate controls + report section + endpoint attr);
  `public/custom/grocy_AI/bulk-review.js` (dual image shape, band badges, group/classification generation,
  conversion-audit report); `custom/grocy_AI/tests/run.php` (register the `categorization` suite);
  `custom/grocy_AI/tests/browser/support/server.mjs` (allowlist the new fixture + asset); `MISTAKES.md`.
- **New:** `custom/grocy_AI/tests/categorization.php` (endpoint-surface suite);
  `custom/grocy_AI/tests/browser/specs/categorization.spec.js`;
  `custom/grocy_AI/tests/browser/fixtures/bulk-review.html`;
  `.planning/phases/06-inventory-categorization/06-ACCEPTANCE.md`; this summary.
- **Portable-files.txt:** unchanged — no new production `src/*` or `public/*` asset (the surfaced behavior
  lives in already-portable `bulk-review.js` / the API controller; tests + fixtures are test-only, matching
  the 06-02..06-06 precedent).
