---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "05"
subsystem: barcode-persistence
tags: [php, sqlite, gtin, javascript, playwright, normal-save, idempotency]

requires:
  - phase: 02-04
    provides: shared checksum-valid GTIN predicate, permission-first owner resolution, and transient unused-barcode staging
provides:
  - transactional checksum-valid canonical GTIN uniqueness with collision refusal and no row mutation
  - exactly-once staged-barcode attachment after Grocy establishes a trusted product ID
  - same-product race idempotency, trusted other-owner conflict routing, and barcode-only partial-failure retry
affects: [02-08-stable-parity, 02-10-deployment, barcode-save, schema-migrations]

tech-stack:
  added: []
  patterns: [generated-expression unique index, post-Save promise continuation, final-owner re-resolution, barcode-only partial recovery]

key-files:
  created:
    - migrations/0256.php
  modified:
    - public/custom/grocy_AI/product-enrichment.js
    - public/viewjs/productform.js
    - custom/grocy_AI/tests/barcode-handoff.php
    - custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js
    - custom/grocy_AI/tests/browser/fixtures/productform.html
    - custom/grocy_AI/README.md
    - CUSTOMIZATIONS.md

key-decisions:
  - "Create canonical uniqueness only from GrocyAiGtin::CanonicalSqlExpression('barcode'); refuse nonzero collision groups transactionally without deleting, rewriting, or reassigning any barcode."
  - "Run barcode attachment only after the existing Grocy Save path establishes a trusted product ID; coalesce duplicate continuations and never add an enrichment write endpoint."
  - "Treat current-product ownership as success, route another owner only from the authenticated resolver result, and retain product context for barcode-only retry after partial failure."

patterns-established:
  - "Canonical invariant: lookup, collision audit, and unique expression index all call the same GrocyAiGtin SQL generator."
  - "Partial Save recovery: once product creation succeeds, attachment failure switches the retained product context to edit mode and retry performs owner resolution plus at most one barcode POST only."

requirements-completed: [ENR-04, ENR-06, ENR-09]

duration: 11 min
completed: 2026-08-13
---

# Phase 02 Plan 05: Canonical Barcode Uniqueness and Normal-Save Attachment Summary

**Checksum-valid canonical GTIN uniqueness now closes concurrent insert races while Grocy's normal Save attaches one transient barcode at most once and preserves barcode-only recovery after partial product creation.**

## Performance

- **Duration:** 11 min
- **Started:** 2026-08-13T22:26:02Z
- **Completed:** 2026-08-13T22:37:18Z
- **Tasks:** 2
- **Files modified:** 10

## Accomplishments

- Added migration `0256.php`, which calls the shared `GrocyAiGtin` SQL generator for both its collision audit and unique expression index. The migration creates the index inside an explicit transaction only at zero collisions and never deletes, normalizes, rewrites, or reassigns a stored barcode.
- Preserved arbitrary Grocy barcode behavior: unsupported text, unsupported numeric lengths, and checksum-invalid numeric-looking values return `NULL` from the canonical expression and remain outside canonical uniqueness, while the existing exact-text unique index remains intact.
- Published `window.GrocyAI.GetStagedBarcode`, `AttachStagedBarcode`, and `RetryBarcodeAttachment` as a narrow transient interface. Attachment re-resolves authenticated ownership, coalesces duplicate callbacks, posts one `{product_id, barcode, amount: 1}` row only when unused, and clears successful staging.
- Added one documented `public/viewjs/productform.js` continuation after product/userfield/picture persistence obtains a trusted product ID and before redirect. Normal Save remains the sole product persistence authority; no module write route was added.
- Made insert races deterministic: current-product ownership is idempotent success, another owner clears staging and exposes the trusted owner route, and unresolved failure retains the created product ID plus exact barcode for a barcode-only retry.
- Added isolated exact-migration SQLite coverage plus browser request-sequence coverage for pre-Save zero writes, one Save/one insert, delayed duplicate callbacks, same/other-owner races, and retry with zero repeated product calls.

## Task Commits

Each task was committed atomically:

1. **Task 1: Lock the exact database and normal-Save continuation contract** — `2b7e4423` (test)
2. **Task 2: Add canonical uniqueness and idempotent Save attachment** — `b282dd78` (feat)

## Files Created/Modified

- `migrations/0256.php` — Audits canonical collision groups and creates the checksum-valid unique expression index in a transaction.
- `public/custom/grocy_AI/product-enrichment.js` — Owns staged-barcode retrieval, authenticated owner re-resolution, coalesced insertion, race recovery, conflict UI, and barcode-only retry.
- `public/viewjs/productform.js` — Adds the single normal-Save continuation after a trusted product ID exists and before redirect.
- `custom/grocy_AI/tests/barcode-handoff.php` — Executes the exact migration against temporary SQLite databases, compares PHP/SQL predicates, hashes rows before/after, and checks the core Save-hook source contract.
- `custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js` — Proves exactly-once Save behavior, delayed callback coalescing, same/other-owner races, and product-write-free retry.
- `custom/grocy_AI/tests/browser/fixtures/productform.html` — Adds opt-in deterministic product/barcode Save endpoints while retaining default mutation traps for every pre-Save test.
- `custom/grocy_AI/module-version.json` and `views/productform.blade.php` — Advance the portable asset token to `2.3.0` so deployed browsers cannot reuse pre-continuation JavaScript.
- `custom/grocy_AI/README.md` and `CUSTOMIZATIONS.md` — Document migration semantics, the normal-Save hook, exact retry authority, and the minimized upstream surface.

## Decisions Made

- The migration imports `GrocyAiGtin` and calls `CanonicalSqlExpression('barcode')`; it contains no copied checksum predicate. This keeps lookup, preflight, temporary tests, and schema enforcement aligned.
- A nonzero canonical collision count is a hard failure inside the migration transaction. Household data requires human resolution; the migration has no deletion or reassignment path.
- Attachment state is keyed by the trusted product ID and exact staged barcode. Repeated continuations share one pending promise, and a completed pair returns idempotent success without another insert.
- Insert failure always triggers one final authenticated owner lookup. Same-product ownership succeeds, another product becomes a blocked trusted route, and still-unused ownership exposes barcode-only retry.
- Product attachment failure does not redirect or repeat product creation. The already-created ID remains in `Grocy.EditObjectId`, the form moves to edit context, and only the module retry can reattempt the barcode.
- Nutrition Facts, allergen, dietary, and medical content remain rejected/deferred. Phase 1 physical-phone evidence remains untouched and `SKIPPED — NOT ACCEPTED`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Test harness bug] Counted ownership GETs separately from barcode writes**

- **Found during:** Task 2
- **Issue:** The fixture's broad `barcode` URL classifier counted authenticated owner-resolution GETs as barcode persistence, producing a false second write after a correct one-insert flow.
- **Fix:** Restricted the barcode mutation counter to non-GET requests while preserving owner-route observations in the dedicated sequence counter.
- **Files modified:** `custom/grocy_AI/tests/browser/fixtures/productform.html`
- **Verification:** The one-Save case reports one product POST and one barcode POST; all pre-Save owner checks still report zero writes.
- **Committed in:** `b282dd78`

**2. [Rule 1 - Conflict recovery bug] Restored trusted owner routing after an insert race**

- **Found during:** Task 2
- **Issue:** Final owner re-resolution correctly detected another owner after a failed insert, but the recovered owner route was not rendered and the staged selection remained visible.
- **Fix:** Built the route only from the resolver's positive product ID, cleared the conflicting stage/diff, hid removal, and refreshed the selection count before showing the blocked outcome.
- **Files modified:** `public/custom/grocy_AI/product-enrichment.js`, `custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js`
- **Verification:** The other-owner race routes to `/product/777`, exposes no retry insert, hides staging, and reports zero selected changes.
- **Committed in:** `b282dd78`

**3. [Rule 2 - Missing critical deployment behavior] Advanced the custom asset cache token**

- **Found during:** Task 2
- **Issue:** Changing portable browser behavior without advancing the module token could leave deployed clients running the pre-attachment asset from cache.
- **Fix:** Bumped `module-version.json`, the production Blade asset literal, and deterministic fixture assets to `2.3.0` as required by the module contract.
- **Files modified:** `custom/grocy_AI/module-version.json`, `views/productform.blade.php`, `custom/grocy_AI/tests/browser/fixtures/productform.html`
- **Verification:** All 105 native module checks pass, including asset-token equality and exactly two tokenized production assets.
- **Committed in:** `b282dd78`

**4. [Rule 3 - Blocking test runtime] Ran Playwright with scoped loopback permission**

- **Found during:** Tasks 1, 2, and overall verification
- **Issue:** The sandbox denied the deterministic fixture server's `127.0.0.1:4173` bind with `EPERM`.
- **Fix:** Reran the repository-owned Playwright command with scoped localhost approval. No provider, production application, or external URL was contacted.
- **Files modified:** None.
- **Verification:** All 11 focused Chromium-mobile cases pass.
- **Committed in:** Not applicable (runtime permission only).

---

**Total deviations:** 4 auto-fixed (2 bugs, 1 missing critical deployment behavior, 1 blocking runtime issue).
**Impact on plan:** The fixes made test accounting accurate, completed the specified conflict recovery, and ensured deployed asset freshness. They added no dependency, write route, persistence authority, or product scope.

## Issues Encountered

None beyond the auto-fixed deterministic test-runtime and harness issues above.

## Authentication Gates

None. Existing local test infrastructure and repository permissions were sufficient.

## Known Stubs

- `public/custom/grocy_AI/product-enrichment.js` keeps secure-media selection unavailable in `mediaReviewRow`; this is an intentional pre-existing phase stub owned by the later secure-media plans, not by barcode Save attachment.

## Threat Model Verification

- **T-02-04-01:** The exact shared expression index rejects a checksum-valid canonical equivalent, and insert conflict recovery re-resolves the owner before deciding same-product success or another-owner block.
- **T-02-04-02:** Both successful and blocked exact-migration fixtures hash every pre-existing row before/after. The nonzero collision fixture raises, creates no index, and leaves all rows unchanged.
- **T-02-04-03:** One normal Save creates one product and at most one barcode; repeated callbacks and delayed responses coalesce without another insert.
- **T-02-04-04:** Partial failure retains product ID `501`; retry performs zero product create/update calls and one owner check plus at most one barcode attempt.
- **T-02-04-05:** Writes remain on Grocy's existing authenticated object APIs. The enrichment module adds no POST/PUT/DELETE route.
- **T-02-04-06:** Barcode tests issue no media request, expose no external URL, and activate no secure-media handle.

No security-relevant surface outside the plan's registered migration, existing object API, and documented normal-Save hook was introduced.

## User Setup Required

None - no package, environment variable, userfield, taxonomy, or external service configuration was added.

## Next Phase Readiness

- Plan 02-06 can consume the now-enforced normal-Save barcode boundary without adding another persistence authority.
- Stable parity/adaptation must mirror the portable `2.3.0` assets and add the framework-appropriate migration/Save hook while preserving byte-identical portable files.
- Deployment remains gated on a zero canonical collision audit. Any future drift is a manual data-resolution stop; it is never permission to delete or reassign rows.
- Phase 1 physical-phone evidence remains `SKIPPED — NOT ACCEPTED`; this plan does not alter or satisfy it.

## Self-Check: PASSED

- All 10 changed files exist, including newly created `migrations/0256.php`.
- Task commits `2b7e4423` and `b282dd78` exist in repository history with no tracked-file deletion.
- All 84 exact migration/ownership checks and all 105 native module checks pass; PHP lint, JavaScript syntax, and `git diff --check` pass.
- All 11 focused Chromium-mobile `@enr04|@enr06|@enr09` cases pass, including exactly-once Save, delayed coalescing, same/other-owner races, barcode-only retry, and pre-Save zero writes.
- The redacted execution preflight remains `canonical_collision_groups: 0`; no production row value or mutation was read or recorded by this plan.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
