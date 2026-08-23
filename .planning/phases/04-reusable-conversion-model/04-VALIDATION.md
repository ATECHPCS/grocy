---
phase: 04
slug: reusable-conversion-model
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-21
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Existing PHP CLI contracts plus deterministic SQLite fixtures; Playwright where native conversion views change |
| **Config file** | `custom/grocy_AI/tests/run.php` and `custom/grocy_AI/tests/browser/package.json` |
| **Quick run command** | `php custom/grocy_AI/tests/run.php conversions` |
| **Full suite command** | `php custom/grocy_AI/tests/run.php && npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` |
| **Estimated runtime** | ~60 seconds after Wave 0 fixture setup |

## Sampling Rate

- **After every task commit:** Run the focused `conversions` case or the plan-specific test named in that task.
- **After every plan wave:** Run the full PHP suite and the focused browser suite where views changed.
- **Before `$gsd-verify-work`:** Run stable/main parity and the dual-branch characterization report in addition to the full suite.
- **Max feedback latency:** 60 seconds for fixture-only cases; characterization must use disposable SQLite databases only.

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 04-01-01 | 01 | 1 | CONV-08, CONV-09 | T-04-01 | Disposable main/stable fixture characterization records cache, triggers, and protected consumer outputs without household-data writes | integration | `php custom/grocy_AI/tests/run.php conversion-characterization` | ❌ W0 | ⬜ pending |
| 04-02-01 | 02 | 2 | CONV-01, CONV-02, CONV-04, CONV-06 | T-04-02 | Native AddObject/EditObject integration proves valid product-scoped package/count POST and measured-density PUT persist normally, while every reusable-scope POST/PUT remains blocked before gate/cache mutation | unit/integration | `php custom/grocy_AI/tests/run.php conversion-rules && php custom/grocy_AI/tests/run.php conversion-native-save-hook` | ❌ W0 | ⬜ pending |
| 04-03-01 | 03 | 3 | CONV-03, CONV-05, CONV-06 | T-04-03 | Explicit taxonomy-only profiles resolve deterministic approximate factors; conflicts, cycles, reciprocal drift, and tolerance violations block projection | integration | `php custom/grocy_AI/tests/run.php conversion-resolution` | ❌ W0 | ⬜ pending |
| 04-04-01 | 04 | 4 | CONV-02, CONV-04, CONV-06 | T-04-04 | Native form visual state retains normal product-scoped Save and cannot claim inactive reusable rules are active | browser | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` | ❌ W0 | ⬜ pending |
| 04-05-01 | 05 | 5 | CONV-04, CONV-05 | T-04-05 | Product-status API provides bounded provenance without any activation or row/cache mutation | integration | `php custom/grocy_AI/tests/run.php conversion-resolution && php custom/grocy_AI/tests/run.php conversion-native-save-hook` | ❌ W0 | ⬜ pending |
| 04-09-01 | 09 | 6 | CONV-04, CONV-05 | T-04-12A | Product status uses only a bounded read and never calls native universal mutation, activation, projection, or cache paths | DOM/browser | `node --test public/custom/grocy_AI/conversion-explanations.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` | ❌ W0 | ⬜ pending |
| 04-06-01 | 06 | 7 | CONV-05, CONV-07 | T-04-06 | Resolved table exposes bounded provenance while preserving native behavior | DOM/browser | `node --test public/custom/grocy_AI/conversion-explanations.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04` | ❌ W0 | ⬜ pending |
| 04-07-01 | 07 | 8 | CONV-07 | T-04-16 | Coverage/CLI diagnostics are aggregate, redacted, read-only, and cannot activate rules | integration/DOM | `php custom/grocy_AI/tests/run.php conversion-coverage && php custom/grocy_AI/tests/run.php conversion-readonly-cli && node --test public/custom/grocy_AI/conversion-coverage.test.js` | ❌ W0 | ⬜ pending |
| 04-08-01 | 08 | 9 | CONV-02, CONV-03, CONV-05, CONV-08, CONV-09 | T-04-18, T-04-19 | Only `ActivateVerifiedRuleset` with current immutable dual-branch proof may create gate-created universal native/cache projection; generic universal POST/PUT remains fail-closed after activation | integration/release | `php custom/grocy_AI/tests/run.php conversion-release-gate && php custom/grocy_AI/tests/run.php conversion-post-activation-bypass && bash custom/grocy_AI/tests/release-gate.sh conversions` | ❌ W0 | ⬜ pending |
| 04-10-01 | 10 | 10 | CONV-02, CONV-03, CONV-05, CONV-08, CONV-09 | T-04-20, T-04-21, T-04-22, T-04-23 | CLI-only authenticated maintainer command promotes only a named inactive revision with current immutable proof identifiers by invoking `ActivateVerifiedRuleset`; all stale/mismatched inputs, generic universal POST/PUT, browser paths, and arbitrary SQL/cache options fail closed | integration/release | `php custom/grocy_AI/tests/run.php conversion-activation-command && php custom/grocy_AI/tests/run.php conversion-release-gate && php custom/grocy_AI/tests/run.php conversion-post-activation-bypass && bash custom/grocy_AI/tests/release-gate.sh conversions` | ❌ W0 | ⬜ pending |

## Wave 0 Requirements

- [ ] `custom/grocy_AI/tests/conversions.php` — deterministic rule graph, source/provenance, and protected-output fixtures.
- [ ] `custom/grocy_AI/tests/run.php` — focused conversion dispatch cases.
- [ ] `custom/grocy_AI/tests/browser/specs/conversions.spec.js` — native conversion-screen provenance and unavailable-state assertions if view integration is selected by characterization.
- [ ] `custom/grocy_AI/tests/browser/fixtures/quantityunitconversionform.html` plus fixture routes — native quantity-unit form Save disablement, exact copy, and stale-response assertions.
- [ ] `public/custom/grocy_AI/conversion-explanations.test.js` — fast DOM/fixture checks for plans 04-04 through 04-06; Playwright remains E2E coverage.
- [ ] `public/custom/grocy_AI/conversion-coverage.test.js` — fast DOM/fixture checks for plan 04-06 report sequencing and exact state copy; Playwright remains E2E coverage.
- [ ] `custom/grocy_AI/tests/conversion-characterization.sh` or equivalent PHP fixture harness — branch-specific cache/trigger/protected-consumer report, never using `GROCY_DATAPATH`.

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Native Grocy conversion screen remains understandable after editable universal rules and package-derived proposal affordances are added | CONV-02, CONV-04, CONV-05 | Responsive semantics and the installed stable image cannot be fully proven with fixture DOMs | On both desktop and phone-sized stable product/quantity-unit screens, review a universal factor, a product override, an approximate profile result, and an unavailable result; confirm wording and native Save behavior. |

## Validation Sign-Off

- [ ] All tasks have `<automated>` verification or Wave 0 dependencies.
- [ ] Sampling continuity: no 3 consecutive tasks without automated verification.
- [ ] Wave 0 covers all missing conversion fixtures.
- [ ] No watch-mode flags.
- [ ] Feedback latency < 60 seconds except the explicit disposable dual-branch characterization gate.
- [ ] `nyquist_compliant: true` set after plans map each task to the final commands.

**Approval:** pending
