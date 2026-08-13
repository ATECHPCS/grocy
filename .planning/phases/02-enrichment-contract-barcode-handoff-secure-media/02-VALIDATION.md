---
phase: 02
slug: enrichment-contract-barcode-handoff-secure-media
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-13
---

# Phase 02 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **PHP framework** | Existing deterministic harness in `custom/grocy_AI/tests/run.php` |
| **Browser framework** | Playwright 1.62.1 in `custom/grocy_AI/tests/browser/` |
| **Companion framework** | Python `unittest` in `/Users/ian/Documents/Repos/grocy-mcp/tests/` |
| **Quick run command** | `php custom/grocy_AI/tests/run.php` |
| **Focused browser command** | `npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr"` |
| **Focused companion command** | `(cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment_contract tests.test_secure_media)` |
| **Full suite command** | `php custom/grocy_AI/tests/run.php && (cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest discover -s tests) && npm --prefix custom/grocy_AI/tests/browser run test:release` |
| **Estimated runtime** | Quick under 10 seconds; focused under 30 seconds; full suite under 3 minutes |

---

## Sampling Rate

- **After every task commit:** Run the smallest affected PHP/Python test plus one focused Chromium-mobile `@enrXX` spec.
- **After every plan wave:** Run the PHP harness, full companion tests, and all Chromium-mobile grocy_AI specs.
- **Before `$gsd-verify-work`:** Full Chromium-mobile/WebKit release suite, companion suite, PHP harness/lint, temp-SQLite invariant test, portable parity, stable parity, and authenticated deployment smoke must be green.
- **Max feedback latency:** 30 seconds per task-level check.
- Phase 2 evidence must not be used to reclassify the explicitly skipped Phase 1 physical-phone timing gate.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 02-W0-01 | TBD | 0 | ENR-01 | contract injection | Unknown/missing/type-invalid/raw-URL/nutrition members fail closed | PHP + Python contract | `php custom/grocy_AI/tests/run.php && (cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment_contract)` | ❌ W0 | ⬜ pending |
| 02-W0-02 | TBD | 0 | ENR-02, ENR-03 | barcode collision | Original scan remains visible; canonical owner resolution is deterministic and unique | PHP + temp SQLite + browser | `php custom/grocy_AI/tests/barcode-handoff.php && npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr0[23]"` | ❌ W0 | ⬜ pending |
| 02-W0-03 | TBD | 0 | ENR-04 | duplicate write | Unused barcode remains transient and normal Save inserts it once | browser + temp SQLite | `npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr04"` | ❌ W0 | ⬜ pending |
| 02-W0-04 | TBD | 0 | ENR-05, ENR-06 | unintended overwrite | Blank direct-evidence fields may preselect; replacements require explicit selection; final diff stages selected live rows only | browser + PHP contract | `npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr0[56]"` | ❌ W0 | ⬜ pending |
| 02-W0-05 | TBD | 0 | ENR-07, ENR-08 | SSRF/content confusion | Structured front image ranks first; browser sees same-origin handles only; all URL/redirect/byte/time/MIME/signature/dimension bounds fail closed | Python + PHP + browser | `(cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_secure_media) && npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr0[78]"` | ❌ W0 | ⬜ pending |
| 02-W0-06 | TBD | 0 | ENR-09 | hidden persistence | Search, preview, cancel, stale response, timeout, and media failure produce zero durable writes | browser route counters | `npm --prefix custom/grocy_AI/tests/browser test -- --project=chromium-mobile --grep "@enr09"` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `custom/grocy_AI/src/GrocyAiContract.php` and adversarial JSON fixtures — closed ENR-01 consumer contract.
- [ ] `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment_contract.py` — producer schema, provenance, preselection, and ranking.
- [ ] `custom/grocy_AI/src/GrocyAiBarcodeService.php` and `custom/grocy_AI/tests/barcode-handoff.php` — canonical ownership and SQLite uniqueness/collision checks.
- [ ] `custom/grocy_AI/tests/browser/specs/contract-review.spec.js` — ENR-01/05/06 vertical UI slice.
- [ ] `custom/grocy_AI/tests/browser/specs/barcode-handoff.spec.js` — ENR-02/03/04 vertical UI slice.
- [ ] `custom/grocy_AI/tests/browser/specs/secure-media.spec.js` and `/Users/ian/Documents/Repos/grocy-mcp/tests/test_secure_media.py` — ENR-07/08 adversarial media cases.
- [ ] `custom/grocy_AI/tests/browser/specs/zero-write.spec.js` plus fixture mutation counters — ENR-09 persistence boundary.
- [ ] Read-only production inventory checkpoints for deployed userfield destinations, canonical-barcode collisions, media bounds, and companion dependency versions.

---

## Required Adversarial Coverage

- **Contract:** wrong version; unknown/missing/duplicate fields; wrong types; HTML-bearing values; missing freshness; raw external URLs; nutrition members.
- **GTIN:** valid 8/12/13/14 forms; leading-zero equivalents; invalid checksum; arbitrary numeric-looking barcode; exact/equivalent owner; pre-existing collision; concurrent insert conflict.
- **Selection:** blank versus whitespace/non-empty; automatic deselection/reselection; explicit overwrite; post-response manual edit; GTIN change; stale/duplicate response.
- **Media:** private/loopback/link-local/multicast/metadata targets; DNS rebinding class changes; redirect loop/hop overflow/downgrade; length/stream overflow; MIME/signature mismatch; malformed/zero/huge dimensions; expired or wrong-variant handle; cancellation and object-URL revocation.
- **Persistence:** zero writes for every pre-Save outcome; one barcode insert after normal Save; unselected fields untouched; image failure preserves prior staged values.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Side-by-side suggestion review remains readable and touch-usable on the actual signed-in product form | ENR-05, ENR-06 | Production userfields and real mobile layout require visual confirmation | At 390×844 and 320px, compare current/suggested rows, provenance, selection state, and final diff without recording household values |
| Existing barcode owner routing reaches the correct product without exposing its identity in evidence | ENR-02, ENR-03 | Requires authenticated household data | Scan an operator-chosen assigned equivalent; record only route PASS/FAIL and zero-write result |
| Normal Save persists only the selected final diff and staged unused barcode exactly once | ENR-04, ENR-06, ENR-09 | Uses the real Grocy product persistence flow | Use a non-sensitive operator-selected test item, save, reload, restore, and record only redacted booleans/timings |
| Deployed stable secure media flow returns the selected real package image without exposing an external URL | ENR-07, ENR-08 | Requires deployed authentication and proxy path | Demand-load one structured candidate and one unverified fallback; record source class, status, and bounds only |

---

## Validation Sign-Off

- [x] Every phase requirement has an automated verification target or explicit Wave 0 dependency.
- [x] Sampling continuity forbids three consecutive tasks without automated verification.
- [x] Wave 0 lists every currently missing test/reference artifact.
- [x] No watch-mode flags are used.
- [x] Task-level feedback target is under 30 seconds.
- [x] `nyquist_compliant: true` is set in frontmatter.

**Approval:** pending execution
