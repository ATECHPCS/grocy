---
quick_task: 260828-4qc
title: Fix the Phase 1/Phase 2 mobile overflow baseline
date: 2026-08-28
subsystem: browser-test-harness
tags: [playwright, fixture, responsive, baseline]
key-files:
  modified:
    - custom/grocy_AI/tests/browser/fixtures/productform.html
requirements-touched: [MOB-01, MOB-08, ENR-02, ENR-03, ENR-05, ENR-09]
---

# Quick Task 260828-4qc: Fix the Phase 1/Phase 2 mobile overflow baseline

**The browser suite is green for the first time: 184/184 across Chromium and WebKit, up from 174 passed / 10 failed.**

## Problem

Five specs failed on both engines and had failed continuously since the end-of-Phase-3 commit `ccade631`, before any Phase 4 code existed:

| Spec | Assertion |
|---|---|
| `happy-path.spec.js` `@mob01` | enrichment card stays above Picture without phone-width overflow |
| `responsive-a11y.spec.js` `@mob08` 320px | `document.scrollWidth - clientWidth <= 0` (observed 16) |
| `responsive-a11y.spec.js` `@enr05` 320px | responsive integrated review stays side by side |
| `barcode-handoff.spec.js` `@enr02 @enr03 @enr09` | unused barcode stages once transiently |
| `contract-review.spec.js` `@enr05 @enr06 @enr09` | seven-family final diff is stale-safe and zero-write |

This contradicted the Phase 1 and Phase 2 completion records, which claim the mobile and integrated-review gates passed.

## Root cause

Both causes are in the browser fixture `productform.html`, not in production code. The fixture models Grocy's product form by hand, and two of its layout rules diverge from what Bootstrap actually does:

1. **`.product-form-grid` used an implicit auto grid track.** A grid item's automatic minimum size is its min-content size, so the enrichment card — a Bootstrap `.row` carrying `margin: 0 -8px` — forced its track to 312px inside a 288px container. In production that `.row` is a direct child of Grocy's Bootstrap container, whose padding absorbs the negative margin; there is no CSS grid. Measured contribution: 8px of the overflow.

2. **The bare `<input type="file">` was unconstrained.** File inputs keep a large intrinsic width and do not shrink, so the picture input rendered 312px wide inside the 288px container. Production wraps it in Bootstrap's `.custom-file`, whose `.custom-file-input` is `width: 100%`. Measured contribution: the remaining 8px.

Together they produced exactly the 16px document overflow the `@mob08` 320px assertion reported. The three non-overflow specs were downstream of the same layout: elements pushed outside the viewport were not interactable.

## Fix

Two fixture CSS changes that make the harness model Bootstrap faithfully:

- `.product-form-grid` now uses `grid-template-columns: minmax(0, 1fr)` (and `minmax(0, 1fr) minmax(0, 1fr)` at the 992px breakpoint), so a grid item cannot force its track wider than the container.
- `.form-group input[type="file"]` is `display: block; width: 100%; max-width: 100%`, mirroring `.custom-file-input`.

No production file was changed. No spec assertion was weakened, relaxed, or skipped.

## Verification

- `npx playwright test` (full matrix) — **184 passed, 0 failed** (was 174 passed, 10 failed).
- `php custom/grocy_AI/tests/run.php` — all 122 checks passed.
- `conversion-rules`, `conversion-resolution`, `conversion-product-status`, `conversion-native-save-hook`, `conversion-coverage`, `conversion-readonly-cli`, `taxonomy-validation` — all passed.
- `node --test conversion-explanations.test.js` (28) and `conversion-coverage.test.js` (8) — passed.
- `git diff --check` — clean.

## Consequence

Plan 04-08's release gate requires green deterministic suites on both maintained branches. That precondition is now satisfiable on `atech-main`. The same two fixture rules must be mirrored when the portable fixture is next synced to `atech-release`, since `custom/grocy_AI/tests/browser/fixtures/productform.html` is a portable file.
