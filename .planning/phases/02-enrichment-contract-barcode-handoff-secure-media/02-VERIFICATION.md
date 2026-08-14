---
phase: 02-enrichment-contract-barcode-handoff-secure-media
verified: 2026-08-14T19:44:11Z
status: gaps_found
score: 1/4 must-haves verified
overrides_applied: 0
gaps:
  - truth: "User sees current values beside strictly validated, versioned suggestions for every supported product field, each with source, confidence band, reason, and freshness."
    status: failed
    reason: "The closed contract accepts duplicate suggestion fields and an unbounded/deep raw companion payload before recursive parsing; therefore it is neither all-or-nothing per reviewed field nor bounded against malformed provider input."
    artifacts:
      - path: "custom/grocy_AI/src/GrocyAiContract.php"
        issue: "ValidateSuggestions enforces unique IDs but not unique fields; DecodeAndValidateRaw has no byte/depth limit."
      - path: "custom/grocy_AI/src/GrocyAiService.php"
        issue: "The entire HTTP response body is materialized before validation."
    missing:
      - "Reject duplicate suggestion field values in both PHP and browser validators, with regression tests."
      - "Enforce a streaming/Content-Length contract budget and a non-recursive or depth-limited raw JSON duplicate-key walk before decoding."
  - truth: "User can review one final diff and save only selected suggestions; search, preview, cancel, timeout, and failed media retrieval leave products, barcodes, categories, stock, conversions, and files unchanged."
    status: failed
    reason: "Accepted duplicate-field suggestions are rendered then collapsed into reviewState.rows[field]; the last row silently replaces the first reducer row, so the visible selection and final diff can diverge."
    artifacts:
      - path: "public/custom/grocy_AI/product-enrichment.js"
        issue: "renderReview assigns reviewState.rows[row.field] = row without a duplicate-field guard."
    missing:
      - "Reject the malformed response before render and add a browser regression proving no duplicate-field row can reach staging or final diff."
  - truth: "User sees an exact structured-source front image before clearly unverified search alternatives and can demand-load/select same-origin media through short-lived handles with URL, redirect, byte, time, MIME, signature, and pixel safeguards."
    status: failed
    reason: "A front_package media item may claim structured-direct/high evidence while naming searxng, and a search alternative may claim Open Food Facts; source ID and label are not bound to the media evidence classification."
    artifacts:
      - path: "custom/grocy_AI/src/GrocyAiContract.php"
        issue: "ValidateMedia calls a permissive ValidateSource after checking kind/evidence independently."
      - path: "public/custom/grocy_AI/product-enrichment.js"
        issue: "validMedia has the same independent checks, so the browser accepts the mislabeled payload too."
    missing:
      - "Use a closed source-ID/label map and require front_package=Open Food Facts structured evidence and search_alternative=SearXNG unverified search evidence in both validators."
      - "Add PHP and browser negative tests for crossed source/kind/evidence combinations."
---

# Phase 02: Enrichment Contract, Barcode Handoff & Secure Media Verification Report

**Phase Goal:** Users can review trustworthy structured suggestions, hand off barcodes without duplicates, and select real package images without hidden persistence or unsafe media access.
**Verified:** 2026-08-14T19:44:11Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### MVP Format Gate

Phase 02 is marked `mode: mvp`, but `gsd-sdk query user-story.validate` returned `false` for the roadmap goal. It is not in the required `As a …, I want to …, so that ….` form. This report therefore verifies the four explicit roadmap success criteria without treating the mode flag as evidence of user-story coverage. Before a future MVP-mode re-verification, correct the phase goal with `/gsd mvp-phase 2`.

### Observable Truths

| # | Truth | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Current values are beside strictly validated, versioned field suggestions with provenance. | ✗ FAILED | `DecodeAndValidateRaw` accepted a valid payload with two `name` fields (`ACCEPTED_DUPLICATE_FIELD`); `GrocyAiService` materializes the full body before the recursive parser. |
| 2 | Original scan is shown; canonical ownership routes an existing owner or adds a new barcode exactly once after normal Save. | ✓ VERIFIED | `GrocyAiBarcodeService::ResolveOwner`, migration `0256.php`, the normal-Save continuation, 84 barcode checks, and the deployed acceptance record provide matching code and behavioral evidence. |
| 3 | Final diff stages only selected changes and all non-Save activity is zero-write. | ✗ FAILED | `renderReview` overwrites `reviewState.rows[row.field]` for accepted duplicate fields; the reducer can no longer faithfully represent every visible choice in final diff/staging. |
| 4 | Structured front media is truly structured, clearly precedes unverified alternatives, and uses safeguarded same-origin handles. | ✗ FAILED | A payload with `kind: front_package`, high structured evidence, and `source.id: searxng` was accepted (`ACCEPTED_MISLABELED_MEDIA`). Safe transport exists, but the evidence distinction is not trustworthy. |

**Score:** 1/4 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
| --- | --- | --- | --- |
| `custom/grocy_AI/src/GrocyAiContract.php` | Closed v2 validation | ⚠️ HOLLOW | Exists, substantive, and called by service, but accepts duplicate fields/mislabeled media and has no raw byte/depth budget. |
| `custom/grocy_AI/src/GrocyAiBarcodeService.php` | Canonical owner resolution | ✓ VERIFIED | Permission-first caller, canonical lookup, bounded server-owned product ID; 84 dedicated checks pass. |
| `migrations/0256.php` | Atomic canonical uniqueness | ✓ VERIFIED | Checks collisions before creating `ix_product_barcodes_canonical_gtin`; barcode suite passes. |
| `public/custom/grocy_AI/product-enrichment.js` | Review reducer, selected-only staging, transient media | ⚠️ HOLLOW | Wired to API/native controls, but duplicate field keys overwrite reducer state and browser media validation repeats provenance gap. |
| `/Users/ian/Documents/Repos/grocy-mcp/grocy_mcp/secure_media.py` | Peer-bound bounded opaque-handle fetch | ✓ VERIFIED | Directly implements variant-bound redemption, peer binding, redirect, byte, MIME/signature, and dimension checks; 41 companion tests pass. |
| `custom/grocy_AI/tests/release-gate.sh` | Immutable release evidence gate | ⚠️ PARTIAL | Syntax is valid, but current `candidate` execution fails `main_post_candidate_scope_unexpected_path` because post-candidate planning/review files are not in the immutable manifest allowlist. |

### Key Link Verification

| From | To | Via | Status | Details |
| --- | --- | --- | --- | --- |
| Grocy raw companion body | `GrocyAiContract::DecodeAndValidateRaw` | `GrocyAiService` call before `json_decode` | ⚠️ PARTIAL | Call exists at `GrocyAiService.php:74`, but body is fully materialized at line 180 and the parser is unbounded. |
| Validated suggestions | Native controls/final staging | closed target adapters and `stageSelectedRows()` | ⚠️ PARTIAL | Native-control wiring exists, but duplicate field entries collapse in `reviewState.rows`. |
| Scan | `ResolveOwner` | same-origin `grocy-ai/barcodes/resolve` | ✓ WIRED | Browser uses `Grocy.Api.Get`; controller checks `MASTER_DATA_EDIT`; service queries canonical DB expression. |
| Product Save | `AttachStagedBarcode(productId)` | trusted post-save continuation | ✓ WIRED | `public/viewjs/productform.js:39` invokes attachment after the trusted product ID is available. |
| Candidate handle | `/api/grocy-ai/images/{variant}/{token}` | explicit same-origin fetch | ✓ WIRED | Browser constructs only same-origin variant/token routes with `credentials: same-origin`; Grocy route permission-checks before proxying. |
| Companion production route | HTTP API test | `/v1/products/images/{variant}/{token}` | ⚠️ PARTIAL | `test_http_api.py` mounts the retired `/v1/products/images/{token}` handler, not `server.build_app()`'s deployed secure route. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| --- | --- | --- | --- | --- |
| Enrichment review UI | `reviewState.rows` | authenticated Grocy enrichment API → companion v2 body | Yes, but malformed duplicate fields are accepted | ⚠️ HOLLOW |
| Barcode handoff | `stagedBarcode` / owner result | authenticated Grocy API → canonical SQLite lookup/index | Yes | ✓ FLOWING |
| Media UI | candidate handles → transient `File` | authenticated Grocy proxy → companion `SecureMediaService` | Yes, but source provenance can be forged inside a valid DTO | ⚠️ HOLLOW |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| --- | --- | --- | --- |
| Grocy v2 contract and review harness | `php custom/grocy_AI/tests/run.php` | `All 113 grocy_AI checks passed` | ✓ PASS (coverage gap remains) |
| Barcode canonicalization/save behavior | `php custom/grocy_AI/tests/barcode-handoff.php` | `All 84 barcode handoff checks passed` | ✓ PASS |
| Companion media/contract suite | `cd ../grocy-mcp && .venv/bin/python -m unittest discover -s tests` | 41 tests, `OK` | ✓ PASS (HTTP route coverage is stale) |
| Duplicate suggestion fields are rejected | Direct `GrocyAiContract::DecodeAndValidateRaw` probe | `ACCEPTED_DUPLICATE_FIELD` | ✗ FAIL |
| Media provenance is bound to evidence kind | Direct `GrocyAiContract::DecodeAndValidateRaw` probe | `ACCEPTED_MISLABELED_MEDIA` | ✗ FAIL |
| Browser release suite | `npm --prefix custom/grocy_AI/tests/browser run test:release` | Fixture server could not bind `127.0.0.1:4173` (`EPERM`) in this sandbox | ? SKIP — environment limitation |
| Current immutable release gate | `custom/grocy_AI/tests/release-gate.sh candidate …/02-RELEASE-MANIFEST.md` | Fails `main_post_candidate_scope_unexpected_path` | ⚠️ FAIL — evidence gate no longer re-runs at current HEAD |

### Probe Execution

Step 7c: SKIPPED — no declared or conventional `scripts/*/tests/probe-*.sh` probes exist for this phase.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| --- | --- | --- | --- | --- |
| ENR-01 | 02-01, 02-02, 02-08–14 | Strict, versioned external suggestion contract | ✗ BLOCKED | Duplicate fields and unbounded recursive raw-body parsing violate strict validation. |
| ENR-02 | 02-04, 02-08–14 | Display original scan and check canonical equivalents | ✓ SATISFIED | String-preserving GTIN resolver plus dedicated suite. |
| ENR-03 | 02-04, 02-08–14 | Existing barcode routes to owning product | ✓ SATISFIED | Server-owned ID, trusted link, dedicated suite, deployed acceptance. |
| ENR-04 | 02-05, 02-08–14 | Unused barcode writes once only after Save | ✓ SATISFIED | Save continuation plus canonical unique index and 84 tests. |
| ENR-05 | 02-01–03, 02-08–14 | Independent seven-family review | ✓ SATISFIED | Seven-family review implementation/tests exist; ENR-01 still blocks trust in malformed input. |
| ENR-06 | 02-03, 02-05, 02-08–14 | Exact selected-only final diff/Save | ✗ BLOCKED | Accepted duplicate field entries can make visible selection and reducer/final diff diverge. |
| ENR-07 | 02-06–14 | Structured front image before unverified search alternatives | ✗ BLOCKED | Source/evidence classification is forgeable by accepted v2 DTO. |
| ENR-08 | 02-06–14 | Same-origin demand-load with bounded opaque handles | ✓ SATISFIED | Peer-bound companion service, authenticated Grocy proxy, and explicit variant fetch are implemented; production-route HTTP coverage is incomplete. |
| ENR-09 | 02-01–09, 02-12–14 | No persistence before normal Save | ✓ SATISFIED | Reducer/native staging design, zero-write tests, and deployment/acceptance evidence support it. |

No ENR requirement is orphaned: every ENR-01 through ENR-09 appears in at least one Phase 02 plan frontmatter.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| --- | --- | --- | --- | --- |
| `custom/grocy_AI/src/GrocyAiService.php` | 180 | Full unbounded response materialization | 🛑 BLOCKER | Compromised companion can exhaust a Grocy PHP worker before contract validation. |
| `custom/grocy_AI/src/GrocyAiContract.php` | 265 | Unique IDs only; duplicate fields accepted | 🛑 BLOCKER | Contract/UI are no longer all-or-nothing per reviewed field. |
| `public/custom/grocy_AI/product-enrichment.js` | 1225 | Field-keyed row overwrite | 🛑 BLOCKER | Final diff/staging can lose a visible row. |
| `custom/grocy_AI/src/GrocyAiContract.php` | 304 | Source not bound to media kind/evidence | 🛑 BLOCKER | Search image can impersonate trusted structured front media. |
| `grocy-mcp/tests/test_http_api.py` | 19, 105–149 | Retired route test fixture | ⚠️ WARNING | Green HTTP tests do not prove deployed variant-bound media route behavior. |
| `custom/grocy_AI/tests/release-gate.sh` | 189–194 | Current HEAD outside closed manifest list | ⚠️ WARNING | Candidate evidence cannot be freshly replayed after Phase 02 documentation commits. |

No unreferenced `TBD`, `FIXME`, or `XXX` debt marker was found in the reviewed phase implementation files.

### Human Verification Required

The deferred signed-in Chrome checkpoint from Plan 02-14 is already recorded as PASS in `02-PHASE-ACCEPTANCE.md`; no additional human-only test is needed to classify the discovered code defects. The browser release suite remains unrun here solely because this sandbox denies its loopback bind; rerun it in a normal development environment after fixing the blockers.

### Gaps Summary

The phase goal is not achieved. The happy-path and deployment summaries do not falsify three direct counterexamples: duplicate `field` values are accepted and collapse browser state, SearXNG media can claim structured-source provenance, and arbitrary companion response bodies reach an unbounded recursive parser. These defects break the required trustworthy-review and structured-media claims. The stale HTTP-route test and currently non-replayable release candidate gate are additional warnings.

No later phase specifically covers contract strictness, final-diff integrity, or source-bound Phase 02 media provenance, so none of these gaps is deferred.

---

_Verified: 2026-08-14T19:44:11Z_
_Verifier: the agent (gsd-verifier)_
