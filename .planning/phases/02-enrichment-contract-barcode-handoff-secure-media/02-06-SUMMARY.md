---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "06"
subsystem: secure-media-testing
tags: [python, php, playwright, ssrf, streaming, opaque-handles, zero-write]

requires:
  - phase: 02-05
    provides: normal-Save-only barcode attachment and the existing closed review contract
provides:
  - deterministic RED matrix for peer-bound SSRF, redirects, deadlines, streamed bytes, content, handles, and auth ordering
  - independent Grocy decoded dimension and 16-megapixel RED gate
  - same-origin browser RED for explicit thumbnail/full demand-load, transient File state, failure preservation, and zero writes
affects: [02-07-secure-media-implementation, stable-parity, deployment-security-verification]

tech-stack:
  added: []
  patterns: [injected offline network seams, exact named RED markers, loopback same-origin media fixture]

key-files:
  created:
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_secure_media.py
    - custom/grocy_AI/tests/browser/specs/secure-media.spec.js
  modified:
    - /Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py
    - custom/grocy_AI/tests/run.php
    - custom/grocy_AI/tests/browser/fixtures/productform.html
    - custom/grocy_AI/tests/browser/support/server.mjs
    - .planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-ENVIRONMENT-PREFLIGHT.md

key-decisions:
  - "Keep Plan 02-06 test-only: secure-media production behavior remains owned by Plan 02-07."
  - "Specify one injected SecureMediaService seam around resolver, actual peer, clock, token source, and streamed transport so every network case is deterministic and offline."
  - "Bind browser media tests to closed same-origin variant routes while asserting that native picture staging and durable writes remain untouched until explicit final staging and normal Save."

patterns-established:
  - "Named RED discipline: syntax, import, discovery, and fixture startup must pass before one standalone EXPECTED_RED marker is accepted."
  - "Three-tier media proof: companion validates network/stream/capability policy, Grocy independently validates decoded bytes, and the browser proves explicit transient interaction."

requirements-completed: [ENR-07, ENR-08, ENR-09]

duration: 11 min
completed: 2026-08-13
---

# Phase 02 Plan 06: Secure Media RED Specification Summary

**Offline adversarial RED suites now lock peer-bound media fetching, independent decoded-image validation, and explicit same-origin transient selection before any production secure-media implementation.**

## Performance

- **Duration:** 11 min
- **Started:** 2026-08-13T22:41:46Z
- **Completed:** 2026-08-13T22:52:23Z
- **Tasks:** 2
- **Files modified:** 7 across Grocy and `grocy-mcp`

## Accomplishments

- Added a deterministic companion matrix covering unsafe schemes/authority parts/ports, blocked and mixed IPv4/IPv6 answers, actual-peer mismatch, two-hop redirect inclusivity, third-hop/loop/downgrade rejection, deadlines, Content-Length, streamed overflow, byte/MIME/magic bounds, variant-bound handles, TTL/capacity, and auth-before-work.
- Added Grocy's independent decoded-image RED for malformed, zero, below/above-dimension, exact 16MP, and over-16MP PNG fixtures without changing production validation.
- Added a real-asset Playwright slice that specifies structured-front ordering, separated unverified alternatives, zero pre-action media requests, distinct thumbnail/full actions, reducer-only `File` state, final staging, candidate-local failure, expiry, stale/cancel behavior, object-URL lifecycle, privacy, and zero durable writes.
- Revalidated the four-case redacted media corpus against the unchanged R-02/R-03 bounds; no URL, handle, payload, fixture bytes, or widened limit entered the preflight artifact.

## Task Commits

Each task was committed atomically in its owning repository:

1. **Task 1: Create exact companion and Grocy media boundary tests**
   - `grocy-mcp@7574321` — companion secure-media and producer-contract RED suites
   - `grocy-mcp@f6e684f` — corrected the structured candidate discriminator to locked `front_package`
   - `grocy@cd4ded08` — independent Grocy decoded-dimension RED and preflight recheck
2. **Task 2: Create the explicit same-origin media happy-path RED**
   - `grocy@5e25025f` — loopback fixture support, request counters, and browser secure-media RED suite

**Summary commit:** `d559b248`

## Files Created/Modified

- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_secure_media.py` — injected resolver/peer/clock/token/stream adversarial specification with exact named RED methods.
- `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py` — structured-front-first, unverified-search, distinct-handle, and no-origin contract RED.
- `custom/grocy_AI/tests/run.php` — exact Grocy decoded dimension/pixel-bound RED case.
- `custom/grocy_AI/tests/browser/specs/secure-media.spec.js` — explicit same-origin happy path plus denial, expiry, wrong-variant, stale, cancellation, privacy, and zero-write cases.
- `custom/grocy_AI/tests/browser/fixtures/productform.html` — real production-asset fixture copy and exact media/fetch/object-URL/write counters.
- `custom/grocy_AI/tests/browser/support/server.mjs` — deny-by-default loopback media capabilities, private/no-store bytes, and aggregate request counters.
- `.planning/phases/02-enrichment-contract-barcode-handoff-secure-media/02-ENVIRONMENT-PREFLIGHT.md` — aggregate-only media corpus recheck record.

## Decisions Made

- The companion test contract expects `SecureMediaService` to receive resolver, streaming transport, clock, and token source explicitly. This makes DNS, peer identity, redirects, deadlines, handle expiry, and byte delivery fully deterministic with no live network.
- URL policy rejects any non-HTTP(S) URL, userinfo, fragment, or nonstandard port; any non-global/private/link-local/loopback/multicast/unspecified/reserved/metadata address; and any mixed DNS answer. A successful resolution is insufficient unless the actual connected peer belongs to the approved set.
- Two redirects are accepted only when every hop is freshly parsed, resolved, peer-bound, and remains HTTPS when the origin is HTTPS. A third redirect, loop, or downgrade fails closed.
- The streamed body admits exactly 2,000 through 3,000,000 bytes and aborts on the first excess byte. Only matching JPEG/PNG/WebP MIME and magic are accepted; Grocy separately owns 32–4096 dimensions and at most 16MP.
- Thumbnail and full handles are distinct, opaque, candidate/variant-bound, retained for exactly 900 seconds in a maximum 512-entry store, and rejected before resolver/fetch work when unknown, expired, or used for the wrong variant.
- The browser contract uses only `/api/grocy-ai/images/{variant}/{token}` within the current origin. Thumbnail load and full selection are separate user actions; the selected full `File` remains reducer-only until `Stage selected changes`, and durable upload remains behind normal Grocy Save.
- Nutrition Facts, allergen, dietary, and medical suggestion families remain rejected/deferred. Phase 1 physical-phone evidence remains untouched and `SKIPPED — NOT ACCEPTED`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Test contract bug] Aligned structured media kind with the locked contract**

- **Found during:** Task 2 contract-to-browser review
- **Issue:** The initial companion assertion used `structured_front`, while Phase 2 research locks the structured candidate discriminator as `front_package`.
- **Fix:** Changed the producer-contract expectation to `front_package`; search alternatives remain separately classified and explicitly unverified.
- **Files modified:** `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py`
- **Verification:** Python syntax/import passes and `EXPECTED_RED: media.contract_handles` is accepted only for the missing producer behavior.
- **Committed in:** `grocy-mcp@f6e684f`

**2. [Rule 3 - Blocking test runtime] Ran Playwright with scoped loopback permission**

- **Found during:** Task 2 and overall verification
- **Issue:** The sandbox denied the deterministic fixture server's `127.0.0.1:4173` bind with `EPERM`.
- **Fix:** Reran only the repository-owned Playwright command with scoped localhost permission. No live Grocy, companion, provider, DNS, or external image host was contacted.
- **Files modified:** None.
- **Verification:** The wrapper accepted exactly one `EXPECTED_RED: media.same_origin_happy_path` after the fixture server and Chromium launched successfully.
- **Committed in:** Not applicable (runtime permission only).

**3. [Rule 1 - State serialization] Corrected SDK progress and metric placement**

- **Found during:** Plan close-out
- **Issue:** `state.update-progress` reported 15 of 24 plans and 63%, but serialized `progress.percent: 0`; `state.record-metric` appended the Phase 02 P06 row to Quick Tasks instead of Performance Metrics.
- **Fix:** Set the frontmatter percentage to 63 and moved the metric row into the Performance Metrics table while keeping STATE below 150 lines.
- **Files modified:** `.planning/STATE.md`
- **Verification:** STATE frontmatter/body report 15 of 24 plans (63%), Plan 7 of 14 is next, the P06 metric appears once under Performance Metrics, and STATE is 140 lines.
- **Committed in:** Plan tracking commit.

---

**Total deviations:** 3 auto-fixed (2 state/test contract bugs, 1 blocking runtime issue).
**Impact on plan:** The fixes preserve the locked contract, deterministic test infrastructure, and accurate planning state. No production secure-media behavior, dependency, persistence route, or security limit was added or widened.

## Issues Encountered

None beyond the auto-fixed contract discriminator, deterministic loopback permission, and state serialization issues above.

## Authentication Gates

None. Authentication behavior is specified with local middleware/fakes; no external credential was requested or used.

## Known Stubs

- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py` does not yet exist. This is the intentional companion RED owned by Plan 02-07; peer binding, streaming, handles, redirects, and auth-before-work remain unimplemented.
- `custom/grocy_AI/src/GrocyAiService.php` still validates byte count, MIME, and magic without decoded dimensions/pixels. `EXPECTED_RED: media.pixel_limit` intentionally remains RED for Plan 02-07.
- `public/custom/grocy_AI/product-enrichment.js` still renders media through the inactive `mediaReviewRow` placeholder and does not demand-load same-origin variants. `EXPECTED_RED: media.same_origin_happy_path` intentionally remains RED for Plan 02-07.

These stubs are intentional specification targets and do not mark the secure-media feature as implemented by this plan.

## Threat Model Verification

- **T-02-06-01:** Public resolution alone cannot authorize a connection; the RED matrix requires every hop's actual peer to belong to its freshly approved address set.
- **T-02-06-02:** Exact connect/total deadlines, pre-body Content-Length refusal, first-excess-byte abort, byte/MIME/magic gates, decoded dimensions, and pixel caps all have direct deterministic cases.
- **T-02-06-03:** Unknown/expired/wrong-variant handles and missing authorization must fail before resolver or transport counters change.
- **T-02-06-04:** Thumbnail/full activity leaves the native picture input untouched until explicit final staging, and all preview/failure/stale/cancel paths retain zero durable writes.

No production endpoint, auth path, file-access behavior, or schema boundary was introduced. The new HTTP paths exist only inside the deny-by-default loopback browser fixture.

## User Setup Required

None - no package, dependency, environment variable, deployment setting, or external service configuration was added.

## Next Phase Readiness

- Plan 02-07 has an executable three-tier acceptance target for the complete companion-to-Grocy-to-browser secure-media slice.
- Implementation must make the named REDs green without weakening two redirects, HTTPS downgrade refusal, public peer binding, 2s/12s deadlines, 2KB–3MB bytes, JPEG/PNG/WebP, 32–4096 dimensions, 16MP, 900-second TTL, 512 handles, same-origin privacy, or zero-write rules.
- Nutrition Facts remains deferred, and Phase 1 physical-phone evidence remains `SKIPPED — NOT ACCEPTED`.

## Self-Check: PASSED

- All seven plan files exist; `test_secure_media.py` and `secure-media.spec.js` are newly created.
- Grocy commits `cd4ded08` and `5e25025f`, plus companion commits `7574321` and `f6e684f`, exist with no tracked-file deletion.
- Companion syntax/import/discovery passes; 19 unaffected companion tests pass; all 105 native Grocy checks pass; PHP lint, JavaScript syntax, Playwright discovery, and `git diff --check` pass.
- `media.peer_binding`, `media.stream_overflow`, `media.pixel_limit`, `media.contract_handles`, `media.auth_before_work`, and `media.same_origin_happy_path` each emit exactly one accepted standalone RED marker after infrastructure gates.
- The browser discovery gate lists four new secure-media cases for both Chromium-mobile and WebKit-mobile; the named Chromium-mobile happy path reaches only its intentional missing-UI assertion.
- Both Grocy and `grocy-mcp` working trees are clean after their task commits.
- STATE reports 15 of 24 plans (63%) with Plan 7 next, ROADMAP reports Phase 2 at 6 of 14, and ENR-07/ENR-08/ENR-09 are marked complete by the plan tracker.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
