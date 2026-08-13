---
phase: 02-enrichment-contract-barcode-handoff-secure-media
plan: "07"
subsystem: secure-media
tags: [python, php, javascript, ssrf, opaque-capabilities, playwright, zero-write]

requires:
  - phase: 02-06
    provides: deterministic RED gates for peer-bound fetching, Grocy image validation, and transient browser media
provides:
  - peer-bound companion media capabilities with 900-second TTL, 512-handle LRU, two redirects, and streamed content bounds
  - closed structured-front-first media contract with unverified search alternatives and no external URL disclosure
  - independently validated authenticated Grocy variant proxy with byte, MIME, magic, dimension, and pixel limits
  - explicit same-origin thumbnail/full demand loading with reducer-only File state and normal-Save staging
affects: [02-08-stable-parity, deployment-security-verification, secure-media-operations]

tech-stack:
  added: []
  patterns: [approved-IP Host/SNI transport, variant-bound capability redemption, two-layer raster validation, lifecycle-owned object URLs]

key-files:
  created:
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py
  modified:
    - /Users/ian/Documents/Repos/grocy-mcp/Dockerfile
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment_contract.py
    - /Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py
    - custom/grocy_AI/src/GrocyAiContract.php
    - custom/grocy_AI/src/GrocyAiService.php
    - custom/grocy_AI/src/GrocyAiApiController.php
    - public/custom/grocy_AI/product-enrichment.js
    - views/productform.blade.php

key-decisions:
  - "Bind production HTTPX connections to one freshly approved public IP while preserving the original Host header and TLS SNI, then verify the actual peer before redirects or body bytes."
  - "Treat structured front-package imagery and unverified search alternatives as distinct closed contract variants in both PHP and JavaScript; search can never become equivalent evidence."
  - "Keep thumbnail blob URLs and the selected full File inside lifecycle-owned browser state until explicit final staging; normal Grocy Save remains the only persistence authority."

patterns-established:
  - "Capability redemption: authenticate first, validate variant/token, then resolve and peer-bind every hop within one total deadline."
  - "Media lifecycle: abort in-flight fetches and revoke object URLs on replacement, cancellation, input changes, orientation, backgrounding, and navigation."

requirements-completed: [ENR-07, ENR-08, ENR-09]

duration: 18 min
completed: 2026-08-13
---

# Phase 02 Plan 07: Secure Media Implementation Summary

**Peer-bound opaque companion capabilities now deliver independently revalidated same-origin product imagery through an explicit transient review flow that cannot write before normal Save.**

## Performance

- **Duration:** 18 min
- **Started:** 2026-08-13T22:57:31Z
- **Completed:** 2026-08-13T23:15:32Z
- **Tasks:** 2
- **Files modified:** 16 implementation/test files across Grocy and `grocy-mcp`, plus one deferred-items record

## Accomplishments

- Implemented a companion capability store with distinct thumbnail/full handles, exact 900-second expiry, 512-entry LRU capacity, syntax validation at issuance, and generic fail-closed redemption.
- Added per-hop public-address resolution, approved-IP connection binding, actual-peer verification before bytes, two-redirect inclusivity, downgrade/loop refusal, 2-second connect and 12-second total deadlines, and exact 2KB–3MB JPEG/PNG/WebP streaming gates.
- Pinned the companion image to Python 3.12.13 and made its build install and verify the recorded 69-distribution constraints hash plus HTTPX, Starlette, and Uvicorn anchors.
- Emitted exact structured front-package media before separately classified unverified search alternatives without returning an external URL or source domain.
- Added Grocy's second byte/MIME/magic/32–4096/16MP validation layer and fixed private/no-store/nosniff variant response.
- Added explicit thumbnail and full actions, same-origin blob rendering, candidate-local errors, reducer-only `File` selection, DataTransfer staging only at `Stage selected changes`, and lifecycle abort/revocation.

## Task Commits

Each task was committed atomically in its owning repository:

1. **Task 1: Implement reproducible peer-bound companion media** — `grocy-mcp@9b18af9`
2. **Task 2: Implement independent Grocy validation and explicit transient UI** — `grocy@0b6e208d`

## Files Created/Modified

- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py` — capability store, URL/IP policy, approved-IP HTTPX transport, peer checks, redirect handling, streamed content validation, and authenticated route test seam.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/enrichment.py` and `enrichment_contract.py` — structured-front-first opaque media DTO production.
- `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/server.py` — authenticated closed `{variant}/{token}` production route.
- `/Users/ian/Documents/Repos/grocy-mcp/Dockerfile` — exact Python/distribution hash and four R-07 anchor enforcement.
- `custom/grocy_AI/src/GrocyAiContract.php` — distinct-handle and structured-before-search contract validation.
- `custom/grocy_AI/src/GrocyAiService.php` — exact companion variant request plus independent byte, MIME, magic, decoded-dimension, and pixel validation.
- `custom/grocy_AI/src/GrocyAiApiController.php` and `custom/grocy_AI/routes.php` — permission-checked same-origin private media response.
- `public/custom/grocy_AI/product-enrichment.js` — explicit demand loading, transient File selection, candidate-local recovery, and object-URL lifecycle.
- `views/productform.blade.php` and `public/custom/grocy_AI/grocy-ai.css` — ordered media groups, exact copy, responsive controls, and night-mode-safe styling.
- `custom/grocy_AI/module-version.json` and `custom/grocy_AI/README.md` — asset cache bump to 2.4.0 and exact secure-media operational contract.

## Decisions Made

- The default transport connects to a freshly approved address rather than re-resolving the hostname inside HTTPX. Original Host and TLS SNI are preserved, and the returned network stream's peer must still belong to the approved set before headers are acted on or bytes are iterated.
- Each candidate receives two independent capabilities. Variant confusion, unknown/expired tokens, invalid auth, unsafe URL/address, peer mismatch, excess redirects, downgrade, timeout, and content failure all return generic closed errors.
- The browser never renders handles or origins into attributes/text/diagnostics. Handles exist only in frozen module state and in the authenticated same-origin request path required to redeem them.
- Nutrition Facts, allergens, dietary data, and medical suggestions remain rejected/deferred. Phase 1 physical evidence remains untouched and `SKIPPED — NOT ACCEPTED`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking stale test] Updated the prior companion baseline to the implemented v2 media contract**

- **Found during:** Task 1 GREEN verification
- **Issue:** `tests/test_enrichment.py` still asserted `media == []` for the same structured-plus-search producer input that Plan 02-06's new locked RED asserted must emit two opaque candidates.
- **Fix:** Replaced only that obsolete assertion with structured-front-first and unverified-search assertions; retained the existing no-URL checks.
- **Files modified:** `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py`
- **Verification:** All 29 companion secure-media, contract, enrichment, and HTTP API tests pass together.
- **Committed in:** `grocy-mcp@9b18af9`

**2. [Rule 3 - Blocking runtime] Ran deterministic browser verification with scoped localhost permission**

- **Found during:** Task 2 and overall verification
- **Issue:** The sandbox denied the fixture server's `127.0.0.1:4173` bind with `EPERM`.
- **Fix:** Reran only the repository-owned Playwright command with scoped loopback permission; no household service, provider, DNS target, or external image host was contacted.
- **Files modified:** None.
- **Verification:** 13 focused tests pass in Chromium-mobile and 13 pass in WebKit-mobile.
- **Committed in:** Not applicable (runtime permission only).

---

**Total deviations:** 2 auto-fixed blocking issues.
**Impact on plan:** The stale assertion repair was required to make the old baseline agree with the stronger locked Plan 02-06 contract. No security limit, persistence boundary, or external dependency was widened.

## Issues Encountered

- Docker is not installed in this execution environment, so an actual local image build could not run. The Dockerfile hash/anchor gates were statically verified, and the exact local companion suites pass; deployment must still execute the Docker build gate.
- The broad 64-test Chromium suite has 15 pre-existing legacy Phase 1 fixture failures that send the retired pre-contract-v2 shape or expect the retired image action; 49 tests pass. They are recorded in `deferred-items.md`. The current contract remains fail-closed, and all Plan 02-07 focused tests pass in both browser engines.

## Authentication Gates

None. All authentication behavior was exercised with local middleware/fixtures; no external credential was requested or used.

## Known Stubs

None. The neutral `Image not loaded` surface is the intentional zero-request demand-load state, not an implementation stub.

## Threat Model Verification

- **T-02-07-01:** Every hop is reparsed/resolved, rejects any non-global or mixed answer, connects to an approved IP with proxies/redirects disabled, and verifies the actual peer before redirect or body handling.
- **T-02-07-02:** Connect/total deadlines, Content-Length refusal, incremental first-excess-byte abort, exact byte/MIME/magic gates, and Grocy decoded dimensions/pixels are independently enforced.
- **T-02-07-03:** Distinct random variant-bound handles have exact TTL/capacity, remain absent from DOM/diagnostics, and fail generically on unknown/expired/wrong-variant use.
- **T-02-07-04:** Companion and Grocy authentication/permission checks occur before handle redemption, resolver calls, transport work, or response bytes.
- **T-02-07-05:** Docker installs the complete recorded constraints set and fails on its hash, Python version, HTTPX, Starlette, or Uvicorn drift.
- **T-02-07-06:** Full bytes become a transient File only after explicit selection; the native picture input changes only at final staging, and persistence remains behind normal Save.

No security-relevant surface outside the plan's threat register was introduced.

## Verification Results

- Companion: 29/29 secure-media, producer-contract, enrichment, and HTTP API tests pass.
- Grocy: PHP lint passes; 113/113 native contract checks pass, including the standalone decoded-pixel gate.
- Browser: 13/13 focused Chromium-mobile and 13/13 focused WebKit-mobile ENR-07/08/09 tests pass with zero external requests and zero non-Save writes.
- JavaScript syntax and `git diff --check` pass.

## User Setup Required

None. Existing `GROCY_AI_SERVICE_API_KEY` / `MCP_API_KEYS` configuration remains required; no new secret, package, or environment variable was added.

## Next Phase Readiness

- Plan 02-08 can mirror the complete secure-media portable surface to stable and verify byte parity against these task commits.
- The deployment Docker build must execute the recorded constraints/hash gate because Docker was unavailable locally.
- Legacy Phase 1 browser fixtures should be migrated or archived in their assigned maintenance scope without restoring the retired permissive payload.

## Self-Check: PASSED

- All key files exist, including `grocy_mcp/secure_media.py` and this summary.
- Grocy task commit `0b6e208d` and companion task commit `9b18af9` exist; neither commit deleted tracked files.
- Both repositories are clean except for this uncommitted close-out documentation.
- The locked companion/PHP/Chromium/WebKit media gates are green, external URL/token canaries remain absent, and zero-write counters remain clean.
- Nutrition Facts remains deferred, and Phase 1 physical acceptance remains `SKIPPED — NOT ACCEPTED`.

---
*Phase: 02-enrichment-contract-barcode-handoff-secure-media*
*Completed: 2026-08-13*
