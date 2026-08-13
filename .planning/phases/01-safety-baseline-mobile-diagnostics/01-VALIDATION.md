---
phase: 01
slug: safety-baseline-mobile-diagnostics
status: active
nyquist_compliant: true
wave_0_complete: true
created: 2026-08-12
updated: 2026-08-13
---

# Phase 01 — Validation Strategy

> Executable validation map for deterministic feedback, stable adaptation, and physical-phone release evidence.

## Current validation state

- Every declared deterministic harness, fixture, contract suite, and browser spec now exists and passes.
- Nyquist compliance is true for automated behavior: PHP, companion, and Chromium/WebKit suites cover the phase contracts without a deterministic test-file gap.
- The stable-deployment smoke and physical-phone timing file remain later release gates. Their pending external evidence does not represent a missing automated harness and must not be reported as completed.
- `evidence/phone-timings.jsonl` is intentionally empty. The production timing checker must exit nonzero until Plans 01-09 and 01-10 supply stable deployment and physical samples.

## Test infrastructure

| Property | Actual value |
|---|---|
| Frameworks | Native PHP CLI harness; Python `unittest`; approved and locked `@playwright/test` 1.62.1 |
| PHP harness | `custom/grocy_AI/tests/run.php` |
| Companion harness | `/Users/ian/Documents/Repos/grocy-mcp/tests/test_enrichment.py`, `test_http_api.py`, `test_diagnostics.py` |
| Browser config/fixture | `custom/grocy_AI/tests/browser/playwright.config.js`, `fixtures/productform.html`, `support/server.mjs` |
| Browser specs | `happy-path.spec.js`, `gtin-validation.spec.js`, `states.spec.js`, `concurrency.spec.js`, `diagnostics.spec.js`, `preservation.spec.js`, `responsive-a11y.spec.js` |
| Evidence/parity tools | `evidence/check-phone-timings.py`, `custom/grocy_AI/tests/check-portable-parity.sh`, `custom/grocy_AI/portable-files.txt` |
| Quick command | `php custom/grocy_AI/tests/run.php && npm --prefix custom/grocy_AI/tests/browser run test:smoke` |
| Full deterministic command | `php custom/grocy_AI/tests/run.php && (cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics) && npm --prefix custom/grocy_AI/tests/browser run test:release` |
| Feedback budget | Deterministic plan/wave suites target less than 120 seconds; physical LAN sampling stays outside the inner loop |

## Sampling rate

- After every task commit: run the changed tier's focused command plus applicable syntax checks.
- After every plan/wave: run PHP, companion, and the full two-engine `test:release` suite.
- Before stable adaptation: run timing-checker self-tests, shell syntax/forbidden-command scans, and a SHA-pinned parity report.
- Before `$gsd-verify-work`: require deterministic suites, immutable stable deployment evidence, SHA-pinned parity, stable smoke, the physical checklist, and locked timing thresholds.
- Do not use watch mode, browser retries, live providers, or a moving stable ref in any deterministic gate.

## Per-plan verification map

| Task ID | Wave | Requirement | Plan-local threat refs | Secure behavior and actual evidence | Command | Status |
|---|---:|---|---|---|---|---|
| 01-02 | 2 | MOB-01, MOB-02, MOB-04, MOB-07, MOB-08 | T-01-02-SC, T-01-02-01, T-01-02-02, T-01-02-03 | Approved exact Playwright install; loopback allowlist server; review-only happy-path counters in `happy-path.spec.js` and `support/server.mjs` | `npm --prefix custom/grocy_AI/tests/browser run test:smoke` | ✅ green |
| 01-03 | 3 | MOB-01, MOB-02, MOB-04, MOB-07 | T-01-03-01, T-01-03-02, T-01-03-03, T-01-03-04 | Shared GTIN/checksum vectors, camera/manual intent, bounded XHR, safe text rendering, and zero-write happy path in `gtin-validation.spec.js` and `happy-path.spec.js` | `npm --prefix custom/grocy_AI/tests/browser test -- --grep '@mob01|@smoke'` | ✅ green |
| 01-04 | 4 | MOB-01, MOB-02, MOB-05, MOB-07 | T-01-04-01, T-01-04-02, T-01-04-03, T-01-04-04, T-01-04-05 | Companion validates GTIN/trace, terminates provider propagation, enforces 10.5s/2s/6s budgets, and emits finite redacted stages in `tests/test_diagnostics.py` plus enrichment/API tests | `(cd /Users/ian/Documents/Repos/grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics)` | ✅ green |
| 01-05 | 5 | MOB-01, MOB-02, MOB-05, MOB-06, MOB-07 | T-01-05-01, T-01-05-02, T-01-05-03, T-01-05-04, T-01-05-05 | Grocy validates GTIN/trace, enforces 12s/2s transport, retains permission-first GET routes, and rebuilds closed diagnostics in `custom/grocy_AI/tests/run.php` | `php custom/grocy_AI/tests/run.php` | ✅ green |
| 01-06 | 6 | MOB-02, MOB-03, MOB-04, MOB-05, MOB-06, MOB-07 | T-01-06-01, T-01-06-02, T-01-06-03, T-01-06-04, T-01-06-05 | Finite states, explicit recovery, races, trace hops, privacy canaries, file/form preservation, and zero-write assertions in `states.spec.js`, `concurrency.spec.js`, `diagnostics.spec.js`, and `preservation.spec.js` | `npm --prefix custom/grocy_AI/tests/browser run test:release` | ✅ green |
| 01-07 | 7 | MOB-08 | T-01-07-01, T-01-07-02, T-01-07-03, T-01-07-04, T-01-07-SC | 320/375/390/768 responsive/a11y contract in `responsive-a11y.spec.js`; locked nearest-rank privacy checker; full-SHA read-only parity tool | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob08 && python3 .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py --self-test && bash -n custom/grocy_AI/tests/check-portable-parity.sh` | ✅ deterministic gates green |
| 01-08 | 8 | MOB-05, MOB-06, MOB-07, MOB-08 | T-01-08-01, T-01-08-02, T-01-08-03 | Seven paths in `portable-files.txt` must be mirrored in the separate stable working directory and byte-identical at the recorded commit | `custom/grocy_AI/tests/check-portable-parity.sh --stable-sha "$RECORDED_STABLE_SHA"` | ⬜ stable mirror pending |
| 01-09 | 9 | MOB-02, MOB-05, MOB-07, MOB-08 | T-01-09-01, T-01-09-02, T-01-09-03, T-01-09-04, T-01-09-05, T-01-09-06 | Separate adapter commit, stable cache-marker bump, immutable image/digest, persistent-volume continuity, auth/route/zero-write smoke per `custom/grocy_AI/README.md` | Stable native tests + SHA-pinned parity + documented deployment smoke | ⬜ stable deployment pending |
| 01-10 | 10 | MOB-01, MOB-02, MOB-03, MOB-04, MOB-05, MOB-06, MOB-07, MOB-08 | T-01-10-01, T-01-10-02, T-01-10-03, T-01-10-04, T-01-10-05 | Physical lifecycle/degraded/normal-Save restoration checklist in `01-PHONE-ACCEPTANCE.md`; closed JSONL requires 20 successful cached/metadata/image samples and one exact timeout | `python3 .planning/phases/01-safety-baseline-mobile-diagnostics/evidence/check-phone-timings.py` | ⬜ physical evidence pending by design |

*Status: ✅ deterministic gate passed · ⬜ later stable/physical release evidence pending*

## Wave 0 completion

- [x] The official `@playwright/test` package and exact 1.62.1 version were human-verified before installation.
- [x] The nested private package and lockfile contain no production dependency changes.
- [x] The Chromium/WebKit config and deny-by-default loopback fixture server exist and pass.
- [x] GTIN, state, race/coalescing, diagnostic/privacy, preservation/zero-write, and responsive/a11y browser specs exist and pass.
- [x] The PHP harness covers GTIN, trace, 12s/2s budgets, finite outcome DTOs, and forbidden canaries.
- [x] The companion suite covers stages/outcomes, provider budgets, trace termination, and privacy.
- [x] The physical acceptance template, comment-free JSONL, and deterministic nearest-rank checker exist.
- [x] The portable manifest and full-SHA read-only parity checker exist and reject implicit/moving refs.

## Locked release thresholds

| Path | Sample rule | Threshold |
|---|---:|---:|
| Browser validation/busy feedback | deterministic and physical observation | ≤250 ms |
| Cached/existing Grocy | 20 successful physical samples | nearest-rank p95 ≤1000 ms |
| Provider metadata | 20 successful physical samples | nearest-rank p95 ≤5000 ms |
| Image attachment | 20 successful physical samples | nearest-rank p95 ≤5000 ms |
| Browser deadline | deterministic clock plus at least one physical timeout | exactly 15000 ms |
| Grocy companion transport | PHP transport assertion | 12s total / 2s connect |
| Companion provider work | Python fake-clock and request assertions | 10.5s outer / 2s connect / 6s read |

These constants cannot be supplied or overridden by evidence. A future re-baseline requires a separate recorded decision and code change.

## Manual-only release verifications

| Behavior | Why manual | Procedure |
|---|---|---|
| Stable route/render/cache/image smoke | Stable Grocy 4.6 adapters, container cache behavior, authentication, and persistent storage are deployment-specific | Follow `custom/grocy_AI/README.md` Stable deployment smoke against the immutable recorded adapter SHA/digest |
| Physical phone/LAN workflow and latency | Device/browser/Wi-Fi/VPN behavior and the household topology cannot be proven by emulation | Complete every item in `01-PHONE-ACCEPTANCE.md`, collect only closed JSONL fields, and pass the production timing checker |

## Validation sign-off

- [x] Every executed task has an automated verification command and real evidence file.
- [x] Sampling continuity has no three-task gap.
- [x] Every deterministic file/config reference exists; no watch flags or retries are used.
- [x] Deterministic feedback remains within the 120-second target.
- [x] Privacy canaries are absent from DOM, clipboard/fallback, console, server DTOs, and structured diagnostics.
- [x] Normal Grocy Save remains the sole persistence path; enrichment tests observe zero product/barcode/stock/file/save writes.
- [x] `nyquist_compliant: true` and `wave_0_complete: true` reflect the passing deterministic harness.
- [ ] Stable adapter/deployment evidence is complete (Plan 01-09).
- [ ] Physical-phone acceptance and production timing thresholds are complete (Plan 01-10).

**Automated approval:** complete. **Final release approval:** pending stable deployment and physical-phone evidence.
