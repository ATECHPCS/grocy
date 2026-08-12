---
phase: 01
slug: safety-baseline-mobile-diagnostics
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-12
---

# Phase 01 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Frameworks** | Existing native PHP CLI contract harness; Python `unittest`; `@playwright/test` 1.62.1 `[ASSUMED]` after human package verification |
| **Config files** | `custom/grocy_AI/tests/run.php`; `/Users/ian/Documents/Repos/grocy-mcp/tests/`; `custom/grocy_AI/tests/browser/playwright.config.js` (Wave 0) |
| **Quick run command** | `php custom/grocy_AI/tests/run.php && npm --prefix custom/grocy_AI/tests/browser run test:smoke` |
| **Full suite command** | `php custom/grocy_AI/tests/run.php && (cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics) && npm --prefix custom/grocy_AI/tests/browser test` |
| **Estimated runtime** | Target under 120 seconds for deterministic suites; physical-phone evidence is a separate release gate |

---

## Sampling Rate

- **After every task commit:** Run the changed tier's focused test plus syntax checks; browser-facing tasks also run `npm --prefix custom/grocy_AI/tests/browser run test:smoke`.
- **After every plan wave:** Run the full PHP, companion, and two-engine Playwright suite.
- **Before `$gsd-verify-work`:** Full deterministic suite, stable deployment smoke, branch parity, and physical-phone acceptance must be green.
- **Max feedback latency:** 120 seconds for deterministic per-wave feedback; do not put physical LAN sampling in the inner loop.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| W0-01 | TBD | 1 | MOB-01–MOB-08 | T-01 / T-06 | Only the approved Playwright package is installed after human verification; provider behavior is deterministic | harness | `npm --prefix custom/grocy_AI/tests/browser run test:smoke` | ❌ W0 | ⬜ pending |
| SVC-01 | TBD | 1 | MOB-01, MOB-05, MOB-06 | T-01 / T-02 / T-03 | GTIN and trace input are validated; diagnostics serialize only allowlisted fields | contract | `php custom/grocy_AI/tests/run.php` | ✅ extend | ⬜ pending |
| SVC-02 | TBD | 1 | MOB-02, MOB-05, MOB-07 | T-03 / T-05 / T-06 | Companion stages use bounded deadlines, finite outcomes, and never forward trace headers to providers | contract | `(cd ../grocy-mcp && .venv/bin/python -m unittest tests.test_enrichment tests.test_http_api tests.test_diagnostics)` | ❌ W0 | ⬜ pending |
| UI-01 | TBD | 2 | MOB-01 | T-02 | Manual and camera input share immediate length/checksum validation and preserve leading zeros | browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob01` | ❌ W0 | ⬜ pending |
| UI-02 | TBD | 2 | MOB-02 | T-05 / T-06 | Named states, 15-second deadline, Cancel, and explicit Retry occur without automatic retry | browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob02` | ❌ W0 | ⬜ pending |
| UI-03 | TBD | 2 | MOB-03, MOB-04 | T-04 / T-05 | Stale responses cannot render; repeated same-intent actions coalesce; no persistence endpoint is called | browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep '@mob03|@mob04'` | ❌ W0 | ⬜ pending |
| UI-04 | TBD | 2 | MOB-05, MOB-06 | T-01 / T-02 / T-03 | Copied report excludes GTIN, secrets, cookies, URLs, payloads, image tokens, headers, and exceptions | privacy | `npm --prefix custom/grocy_AI/tests/browser test -- --grep '@mob05|@mob06'` | ❌ W0 | ⬜ pending |
| UI-05 | TBD | 2 | MOB-07 | T-07 | Ordinary fields, selected file, Save controls, and metadata survive every degraded path | browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob07` | ❌ W0 | ⬜ pending |
| REL-01 | TBD | 3 | MOB-08 | T-01 / T-05 / T-06 | Responsive/a11y contract, latency budgets, stable cache/version behavior, and branch parity are release gates | browser + CLI | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @mob08` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Threat references are defined in the phase PLAN threat models. The planner must replace each `T-*` placeholder with the final plan-local threat IDs and assign real plan/task IDs before execution.

---

## Wave 0 Requirements

- [ ] Human verification checkpoint for `[ASSUMED] @playwright/test@1.62.1` before install.
- [ ] `custom/grocy_AI/tests/browser/package.json` and lockfile — isolated test scripts with no production dependency changes.
- [ ] `custom/grocy_AI/tests/browser/playwright.config.js` — `chromium-mobile` and `webkit-mobile` projects.
- [ ] `custom/grocy_AI/tests/browser/fixtures/productform.html` and `support/server.mjs` — synthetic form fixture using actual phase-owned JS/CSS and Node's built-in server.
- [ ] `custom/grocy_AI/tests/browser/specs/gtin-validation.spec.js` — MOB-01.
- [ ] `custom/grocy_AI/tests/browser/specs/states.spec.js` — MOB-02.
- [ ] `custom/grocy_AI/tests/browser/specs/concurrency.spec.js` — MOB-03/MOB-04.
- [ ] `custom/grocy_AI/tests/browser/specs/diagnostics.spec.js` — MOB-05/MOB-06 privacy canaries.
- [ ] `custom/grocy_AI/tests/browser/specs/preservation.spec.js` — MOB-04/MOB-07 zero-write and form preservation.
- [ ] `custom/grocy_AI/tests/browser/specs/responsive-a11y.spec.js` — MOB-08 at 320, 375, 390, and 768 px.
- [ ] Extend `custom/grocy_AI/tests/run.php` with shared GTIN vectors, trace parsing, 12 s/2 s timeout options, outcome DTO, and forbidden-canary checks.
- [ ] `/Users/ian/Documents/Repos/grocy-mcp/tests/test_diagnostics.py` plus focused companion test extensions for stage outcomes, budgets, trace termination, and privacy.
- [ ] `01-PHONE-ACCEPTANCE.md`, redacted `evidence/phone-timings.jsonl`, and a deterministic nearest-rank p50/p95 checker.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Physical-phone LAN workflow and latency evidence | MOB-08 | Device/browser/Wi-Fi behavior and actual household topology cannot be proven by emulation | On an available household phone, record 20 redacted samples for cached, metadata, and image paths; verify p95 ≤1 s, ≤5 s, and ≤5 s respectively, plus one exact 15 s timeout case. Record device/OS/browser/viewport/network and versions, never GTIN or product data. |
| Stable deployment route/render/cache smoke | MOB-02, MOB-07, MOB-08 | Local Composer/Yarn/Docker assets are unavailable and the stable image has branch-specific integration | On the established build/deploy host, adapt portable changes to `atech-release`, bump the custom cache marker when route/view integration changes, deploy the stable image, and verify the product form, enrichment route, selected-image route, degraded workflow, and normal Save. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verification or explicit Wave 0 dependencies.
- [ ] Sampling continuity: no 3 consecutive implementation tasks without automated verification.
- [ ] Wave 0 covers every missing test/config reference.
- [ ] No watch-mode flags are used.
- [ ] Deterministic feedback latency remains under 120 seconds.
- [ ] Privacy canaries are absent from DOM, clipboard/fallback, console, response logs, and structured diagnostics.
- [ ] Normal Grocy Save remains the only persistence path; enrichment tests observe zero product/stock/save calls.
- [ ] `nyquist_compliant: true` and `wave_0_complete: true` are set only after the harness and test references exist and pass.

**Approval:** pending
