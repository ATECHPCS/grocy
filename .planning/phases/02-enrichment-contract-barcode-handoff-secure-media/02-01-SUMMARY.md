---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "01"
subsystem: testing
tags: [contract-v2, duplicate-json-keys, playwright, companion-constraints, secure-media-preflight]

requires:
  - phase: 01-safety-baseline-mobile-diagnostics
    provides: bounded zero-write enrichment lifecycle and privacy-safe diagnostics
provides:
  - Exact-marker RED harness for Python, PHP, and browser contract-v2 gaps
  - Raw duplicate-key fixtures preserved until Grocy's future duplicate-aware decoder
  - Redacted production destination, canonical-collision, media-bound, and dependency preflight
affects: [02-02-contract-implementation, 02-04-barcode-ownership, 02-07-secure-media]

tech-stack:
  added: []
  patterns: [exact standalone RED markers, raw JSON fixture strings, aggregate-only production preflight, exact deployed dependency constraints]

key-files:
  created:
    - custom/grocy_AI/tests/assert-expected-red.sh
    - custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json
    - custom/grocy_AI/tests/browser/specs/contract-review.spec.js
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py
    - /Users/ian/Documents/Repos/grocy-mcp/constraints-phase2.txt
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-ENVIRONMENT-PREFLIGHT.md
  modified:
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/browser/fixtures/productform.html

key-decisions:
  - "Keep adversarial contract documents as raw JSON strings until the Grocy duplicate-aware boundary examines every object scope."
  - "Accept intentional RED only when exit code 1 and exactly one named standalone marker are present; syntax, import, discovery, fixture, auth, browser-launch, and dependency failures remain blocking."
  - "Pin later HTTP and secure-media work to the exact 69-distribution deployed companion set and block on runtime drift."

patterns-established:
  - "Fail-closed RED gate: syntax/discovery checks run independently before the wrapper accepts one exact missing-behavior marker."
  - "Privacy-safe preflight: persist only closed target names/types, aggregate counts/hashes, bounds, and package versions."

requirements-completed: [ENR-01, ENR-05, ENR-09]

duration: 13min
completed: 2026-08-13
---

# Phase 02 Plan 01: Contract RED and Environment Preflight Summary

**Exact-marker contract-v2 RED tests now preserve duplicate JSON members, specify the first zero-write name-review slice, and pin live destination, collision, media, and dependency facts without household data.**

## Performance

- **Duration:** 13 min
- **Started:** 2026-08-13T20:58:33Z
- **Completed:** 2026-08-13T21:11:37Z
- **Tasks:** 2
- **Files modified:** 8

## Accomplishments

- Added a fail-closed shell gate that accepts only assertion exit code 1 plus one exact standalone marker and rejects infrastructure, syntax, import, discovery, fixture, authentication, browser-launch, and dependency failures.
- Added literal raw contract-v2 documents covering valid name provenance, repeated top-level members, repeated nested members, escaped-equivalent nested members, version/type/member drift, raw media URLs, and deferred nutrition members.
- Specified the first direct structured name-review producer/browser path with complete provenance, blank-field preselection, and zero mutation counters, failing only at the four required missing-behavior markers.
- Reconfirmed one exact brand destination, no package-size or food-type destination, three checksum-valid GTIN rows, and zero canonical collision groups using aggregate-only output.
- Captured all 69 deployed companion distributions, verified Python 3.12.13 and the HTTPX/Starlette/Uvicorn anchors, and matched the committed sorted constraints SHA-256 to the running container.
- Confirmed deterministic JPEG, PNG, and WebP fixtures fit the locked two-redirect, 2KB–3MB, 32–4096 dimension, and 16MP envelope without widening it.

## Task Commits

Each task was committed atomically in each repository it modified:

1. **Task 1: Create the failing contract-v2 name-review slice and raw duplicate-key matrix**
   - `e317a23a` — Grocy test harness, raw fixtures, browser fixture/spec
   - `15755dc` — Companion producer contract RED test
2. **Task 2: Reconfirm resolved deployment facts without mutation**
   - `96cfc49d` — Grocy redacted environment preflight
   - `538c766` — Companion exact deployed constraints

## Files Created/Modified

- `custom/grocy_AI/tests/assert-expected-red.sh` — Exact named-RED assertion gate with optional absolute working directory.
- `custom/grocy_AI/tests/fixtures/enrichment-v2-cases.json` — Raw valid and adversarial v2 documents whose duplicate members survive fixture loading.
- `custom/grocy_AI/tests/run.php` — Focused duplicate-key cases targeting `GrocyAiContract::DecodeAndValidateRaw`.
- `custom/grocy_AI/tests/browser/fixtures/productform.html` — Phase 2 review copy and fixed closed labels for the isolated form fixture.
- `custom/grocy_AI/tests/browser/specs/contract-review.spec.js` — Mobile name comparison, provenance, preselection, and zero-write RED slice.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py` — Discoverable producer-v2 name-review RED test.
- `/Users/ian/Documents/Repos/grocy-mcp/constraints-phase2.txt` — Complete normalized deployed distribution constraints.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-ENVIRONMENT-PREFLIGHT.md` — Redacted R-01 through R-07 execution facts.

## Decisions Made

- Raw object members remain bytes/strings until a per-object duplicate-aware lexical walk has compared decoded member names; ordinary JSON decoding cannot be the first parser.
- Expected-RED acceptance is narrower than generic nonzero acceptance: every runner must first pass syntax/import/discovery, then fail with exactly one named marker and exit code 1.
- Later contract, barcode, and secure-media implementation must use the revalidated closed targets, bounds, canonical predicate, and exact companion set. Drift blocks implementation rather than creating fields, deleting data, widening limits, or floating dependencies.
- Nutrition Facts remains deferred and rejection-only. Phase 1 physical-phone evidence remains `SKIPPED — NOT ACCEPTED` and was not modified or reinterpreted.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Runtime probe bug] Removed an accidental empty SQLite file created by a wrong read-only probe path**

- **Found during:** Task 2 (Reconfirm resolved deployment facts without mutation)
- **Issue:** Opening `/config/grocy.db` with PDO created a zero-byte file beside the real `/config/data/grocy.db` before any query ran.
- **Fix:** Verified the stray path was exactly zero bytes, removed only that file, confirmed it no longer exists, and confirmed the real database remained present at 8,249,344 bytes before continuing with `PRAGMA query_only=ON` against the correct path.
- **Files modified:** Runtime stray `/config/grocy.db` removed; no repository or household database file modified.
- **Verification:** Final container check returned `no_stray` and `real_database_present`; aggregate queries then passed from `/config/data/grocy.db`.
- **Committed in:** No runtime artifact was committed; Task 2 evidence is recorded in `96cfc49d`.

**2. [Rule 1 - State serialization] Corrected the progress percentage written by the state SDK**

- **Found during:** Plan close-out
- **Issue:** `state.update-progress` reported 42% and updated the body progress bar, but serialized `progress.percent: 0` in STATE frontmatter.
- **Fix:** Corrected the frontmatter percentage to match 10 of 24 completed milestone plans and the SDK's reported result.
- **Files modified:** `.planning/STATE.md`
- **Verification:** STATE frontmatter, body progress bar, completed-plan count, and ROADMAP's Phase 2 plan count now agree.
- **Committed in:** Plan metadata commit.

---

**Total deviations:** 2 auto-fixed (2 bugs).
**Impact on plan:** The accidental zero-byte file was fully removed before evidence capture and metadata was made internally consistent. The real database and all household rows remained untouched; the final preflight used query-only access.

## Issues Encountered

- The sandbox denied the Playwright fixture server's loopback bind with `EPERM`. The wrapper correctly rejected that infrastructure failure; the exact suite was rerun with approved local-server permission and reached only the named contract-v2 RED marker.

## Authentication Gates

None. The established host SSH identity was already configured; no credential value was printed or persisted.

## Known Stubs

None. Fixture `null` target, owner, and source-update fields are intentional contract values, not UI/data-source stubs. Production behavior remains deliberately absent and named RED for Plan 02-02.

## Threat Model Verification

- **T-02-01-01:** Literal raw strings retain top-level, nested, and escaped-equivalent duplicate names until the future Grocy lexical/object walk; both duplicate cases reach only their exact markers.
- **T-02-01-02:** Preflight evidence contains only closed target names/types, aggregate counts/hashes, bounds, and versions; no product/userfield values, barcode strings, URLs, handles, credentials, headers, or payloads were recorded.
- **T-02-01-03:** The committed 69-line constraints artifact has SHA-256 `53c2a4b530e9802d0d0f5587875db0ae72320652dd4627925b06eca2edbb2019`, identical to the running companion distribution set; no install occurred.

No unplanned network endpoint, authentication path, schema change, durable file-access pattern, or new trust boundary was introduced.

## User Setup Required

None - no package installation or external configuration was added.

## Next Phase Readiness

- Plan 02-02 can implement the closed producer/consumer and first authenticated name-review row directly against four exact RED markers.
- Later barcode work is cleared by zero production canonical collision groups, while invalid numeric-looking barcodes remain outside the future canonical key.
- Later secure-media work is pinned to the exact deployed constraints and locked bounds; any drift remains fail-closed.
- Nutrition Facts and Phase 1 physical-phone acceptance remain explicitly outside this plan's completion claims.

## Self-Check: PASSED

- All eight created/modified plan files exist in their owning repositories.
- Grocy commits `e317a23a` and `96cfc49d` exist.
- Companion commits `15755dc` and `538c766` exist.
- PHP lint, Python compile/import/discovery, Playwright list, all four exact expected-RED gates, preflight anchors, privacy canaries, and constraints hash verification passed.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
