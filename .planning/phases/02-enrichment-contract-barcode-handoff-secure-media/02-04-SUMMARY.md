---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "04"
subsystem: barcode-ownership
tags: [php, sqlite, gtin, javascript, playwright, zero-write]

requires:
  - phase: 02-03
    provides: transient selected-only review reducer and unchanged normal-Save persistence authority
provides:
  - one checksum-valid GTIN-8/12/13/14 predicate shared by PHP and generated SQLite expressions
  - permission-first local owner resolution that suppresses provider work for existing products
  - trusted owner navigation and removable exactly-once transient staging for unused barcodes
affects: [02-05-barcode-save, 02-08-stable-parity]

tech-stack:
  added: []
  patterns: [string-preserving canonical GTIN key, generated SQLite checksum expression, local-owner-before-provider, reducer-only barcode staging]

key-files:
  created:
    - custom/grocy_AI/src/GrocyAiGtin.php
    - custom/grocy_AI/src/GrocyAiBarcodeService.php
    - custom/grocy_AI/tests/barcode-handoff.php
    - custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js
  modified:
    - custom/grocy_AI/src/GrocyAiApiController.php
    - custom/grocy_AI/routes.php
    - views/productform.blade.php
    - public/custom/grocy_AI/product-enrichment.js
    - public/custom/grocy_AI/grocy-ai.css
    - custom/grocy_AI/portable-files.txt

key-decisions:
  - "Canonicalize only checksum-valid digit strings of exact GTIN-8/12/13/14 lengths; preserve the exact scan separately and leave invalid numeric-looking values outside the enrichment ownership boundary."
  - "Resolve MASTER_DATA_EDIT and local ownership before provider work; construct owner navigation only from the bounded database product ID."
  - "Keep unused barcode staging in reducer state only; Plan 02-05 owns normal-Save attachment and database uniqueness enforcement."

patterns-established:
  - "Single predicate owner: GrocyAiGtin supplies both PHP validation and the exact SQLite CASE/checksum expression."
  - "Trusted handoff: local owner results suppress companion enrichment, while unused scans stage once and remain removable without a mutation request."

requirements-completed: [ENR-02, ENR-03, ENR-09]

duration: 20min
completed: 2026-08-13
---

# Phase 02 Plan 04: Canonical Barcode Ownership and Transient Handoff Summary

**Checksum-valid GTIN ownership now resolves locally before provider enrichment, preserves the exact scanned display, routes only through a server-owned product ID, and stages an unused barcode transiently without writing.**

## Performance

- **Duration:** 20 min
- **Started:** 2026-08-13T22:01:21Z
- **Completed:** 2026-08-13T22:21:17Z
- **Tasks:** 3
- **Files modified:** 17

## Accomplishments

- Added `GrocyAiGtin` as the sole checksum predicate and canonical-key owner for exact GTIN-8, GTIN-12, GTIN-13, and GTIN-14 strings, with a generated SQLite expression that returns `NULL` for text, unsupported lengths, and checksum-invalid numeric-looking values.
- Added permission-first local ownership lookup across exact and leading-zero-equivalent barcodes. Existing ownership returns only bounded local product data and suppresses all companion-provider work.
- Preserved the exact scanned string as the browser display while rendering the bounded equivalent set separately; no barcode is coerced to a number or replaced by the padded comparison key.
- Added server-trusted owner navigation, current-product handling, stale-response invalidation, and phone-safe ownership controls with 44px targets, wrapping, night-mode support, and no horizontal overflow at 320px.
- Added one removable `Ready to add on Save` reducer entry for an unused valid barcode. Lookup, search, remove, Cancel, stale response, lifecycle invalidation, and navigation issue no product, barcode, file, stock, category, or conversion write.
- Rechecked the stable production database through SQLite query-only mode: three checksum-valid rows, zero canonical collision groups, and aggregate hash `eab817087de37b4d5920b194489c5b7f9a0b4d44e9519c08d1aaab7ed53a5b69`. No row value was recorded and no migration or write was performed.

## Task Commits

Each task was committed atomically:

1. **Task 1: Specify canonical ownership, trusted routing, and zero-write handoff** — `08a08ba9` (test)
2. **Task 2: Implement canonical barcode ownership and transient handoff** — `e9825a29` (feat)
3. **Task 3: Register and document the shared GTIN boundary** — `c63c1559` (docs)

## Files Created/Modified

- `custom/grocy_AI/src/GrocyAiGtin.php` — Validates exact GTIN lengths/checksums, returns canonical-14 keys, and generates the equivalent SQLite predicate.
- `custom/grocy_AI/src/GrocyAiBarcodeService.php` — Performs bounded local ownership lookup and permission/provider orchestration through injectable seams.
- `custom/grocy_AI/src/GrocyAiApiController.php` and `custom/grocy_AI/routes.php` — Add the authenticated read-only resolver and place permission/local ownership before companion enrichment.
- `custom/grocy_AI/tests/barcode-handoff.php` — Covers valid/invalid vectors, leading-zero equivalence, owners, collisions, permission order, provider suppression, both maintained schemas, and future unique-expression behavior.
- `custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js` — Covers trusted routing, owner-current behavior, removable unused staging, stale responses, accessibility, phone layout, and zero writes.
- `views/productform.blade.php`, `public/custom/grocy_AI/product-enrichment.js`, and `public/custom/grocy_AI/grocy-ai.css` — Render and manage immutable scan/equivalent/ownership/staging state without a persistence request.
- `custom/grocy_AI/portable-files.txt`, `custom/grocy_AI/module-version.json`, and `custom/grocy_AI/README.md` — Register the shared predicate, advance assets to `2.2.0`, and document the read-only boundary without claiming Plan 02-05 enforcement.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-ENVIRONMENT-PREFLIGHT.md` — Records only the timestamped aggregate production collision-audit result.

## Decisions Made

- Only strict digit strings at exact GTIN-8/12/13/14 lengths with a valid GS1 checksum receive a canonical key. Separator normalization remains available only through the legacy normalization entrypoint; ownership never trims or rewrites the scanned display.
- The PHP predicate and generated SQLite CASE expression live together in `GrocyAiGtin`, preventing application/database drift and excluding arbitrary invalid Grocy barcodes from equivalence matching.
- Both the resolver and enrichment path require `MASTER_DATA_EDIT` before local lookup. An existing owner returns a local contract with empty suggestions/media and never reaches the companion provider.
- Browser/companion IDs cannot choose a navigation target. The link is built only from the positive integer returned by the local owner query.
- Unused state is transient and exactly-once in the reducer. This plan deliberately adds no insert/update route, schema constraint, migration, or alternate Save authority.
- Nutrition Facts, allergen, dietary, and medical content remain rejected/deferred. Phase 1 physical-phone evidence remains untouched and `SKIPPED — NOT ACCEPTED`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Test harness bug] Corrected mutable-counter capture and narrowed the trusted-ID source assertion**

- **Found during:** Task 2
- **Issue:** A PHP arrow closure captured the permission counter by value, and a controller-source assertion rejected every legitimate use of the service-owned `owner_product_id` result rather than only browser/request-derived IDs.
- **Fix:** Replaced the closure with reference capture and narrowed the source checks to reject query/argument ownership IDs while allowing the bounded service result.
- **Files modified:** `custom/grocy_AI/tests/barcode-handoff.php`
- **Verification:** All 58 barcode-handoff checks pass, including permission-first and client-ID rejection cases.
- **Committed in:** `e9825a29`

**2. [Rule 1 - Regression expectation] Included the new transient barcode in the established selected-diff contract**

- **Found during:** Task 2
- **Issue:** Existing contract-review fixtures correctly return an unused valid barcode, so the new locked reducer behavior adds its transient handoff to the selected-only diff and changed the prior three-row expectation.
- **Fix:** Updated the established browser regression to assert the fourth barcode row and explicitly remove the staged barcode in the zero-selection path.
- **Files modified:** `custom/grocy_AI/tests/browser/specs/contract-review.spec.js`
- **Verification:** All seven focused `@enr02|@enr03|@enr09` Chromium-mobile cases pass.
- **Committed in:** `e9825a29`

**3. [Rule 3 - Blocking test runtime] Ran Playwright with scoped loopback permission**

- **Found during:** Tasks 1, 2, and overall verification
- **Issue:** The sandbox denied the deterministic fixture server's `127.0.0.1:4173` bind with `EPERM`.
- **Fix:** Reran the same repository-owned Playwright command with scoped approval; no network provider, production application, or external URL was contacted.
- **Files modified:** None.
- **Verification:** The focused suite completes with seven passing cases.
- **Committed in:** Not applicable (runtime permission only).

**4. [Rule 1 - State serialization] Corrected the SDK-written progress percentage**

- **Found during:** Plan close-out
- **Issue:** `state.update-progress` correctly reported 13 of 24 plans and rendered the 54% body progress bar, but serialized `progress.percent: 0` in STATE frontmatter.
- **Fix:** Corrected the frontmatter percentage to the SDK-reported 54%, keeping all tracking representations consistent.
- **Files modified:** `.planning/STATE.md`
- **Verification:** STATE frontmatter/body report 13 of 24 plans (54%), ROADMAP reports Phase 2 at 4 of 14 summaries, and requirements ENR-02/ENR-03/ENR-09 are complete.
- **Committed in:** Plan metadata commit.

---

**Total deviations:** 4 auto-fixed (3 bugs, 1 blocking runtime issue).
**Impact on plan:** The fixes aligned the harness with the specified ownership contract and enabled deterministic browser execution; no architecture, dependency, persistence authority, or product scope changed.

## Issues Encountered

- A direct remote address exhausted SSH authentication attempts during the query-only audit. The configured deployment alias completed the same read-only container/SQLite path successfully; no credentials, barcode values, or row contents were printed or stored.

## Authentication Gates

None. Existing deployment access and Grocy permission boundaries were sufficient.

## Known Stubs

- The transient unused barcode deliberately has no insert/update operation. Plan 02-05 owns the exactly-once normal-Save attachment and canonical uniqueness migration/rollback gates.
- Secure-media handles remain inactive in this slice and retain their later phase ownership; no barcode flow resolves a media URL or stages a file.

## Threat Model Verification

- **T-02-03-01:** Strict string-only GTIN vectors and matching PHP/SQLite checksum results prove no numeric coercion; leading zeroes remain visible in the scan and canonicalize only for comparison.
- **T-02-03-02:** Permission counters remain zero-work when unauthorized, injection fixtures cannot control owner routing, and navigation uses only the local database result.
- **T-02-03-03:** Owner results suppress provider calls, collision fixtures fail closed, and the production aggregate audit reports zero canonical collision groups. No uniqueness migration is claimed or applied here.
- **T-02-03-04:** Runtime errors remain closed/redacted, diagnostics do not receive raw barcode or owner data, and the preflight artifact contains only aggregate count/hash evidence.
- **T-02-03-05:** The new route is GET/read-only, unused staging stays in reducer memory, and mutation counters remain zero for search, lookup, remove, Cancel, stale results, and navigation.
- **T-02-03-06:** No media activation, external URL, provider-owned navigation, or file request was introduced by barcode flows.

No security-relevant surface outside the plan's registered authenticated read-only route and local database lookup was introduced. No schema or durable file-access boundary changed.

## User Setup Required

None - no package, environment variable, database migration, userfield, taxonomy, or service configuration was added.

## Next Phase Readiness

- Plan 02-05 can consume the single reducer-owned staged barcode through Grocy's existing Save authority and add uniqueness only after its explicit migration and rollback gates pass.
- Stable adaptation can reuse the portable `GrocyAiGtin` predicate and the aggregate zero-collision evidence while implementing framework-specific routing/view hooks separately.
- Arbitrary Grocy barcodes remain unaffected because invalid/unsupported values receive no canonical key and no ownership handoff.
- Phase 1 physical-phone evidence remains `SKIPPED — NOT ACCEPTED`; this plan makes no acceptance claim for it.

## Self-Check: PASSED

- All 17 changed files exist, including the four newly created predicate/service/contract files.
- Task commits `08a08ba9`, `e9825a29`, and `c63c1559` are present in repository history with no tracked-file deletion.
- All 58 barcode-handoff checks and all 105 native module checks pass; PHP lint, JavaScript syntax, portable-manifest lookup, executable parity help, and `git diff --check` pass.
- Seven focused Chromium-mobile cases pass, including exact scan display, trusted owner navigation, 320px removable staging, stale invalidation, selected-diff integration, accessibility, and zero-write counters.
- STATE frontmatter and body report 13 of 24 plans complete (54%), ROADMAP reports Phase 2 at 4 of 14, and ENR-02/ENR-03/ENR-09 are checked complete.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
