# Phase 4 — Native conversion editor status (04-04)

**Goal:** Explain current conversion validation in Grocy's normal editor without adding a parallel write path.

## Safety contract

- Preserve existing form IDs, `#save-quconversion-button`, `Grocy.Components.UserfieldsForm.Save`, navigation/modal behavior, and native `objects/quantity_unit_conversions` POST/PUT.
- Before Plan 08 activation proof, reusable universal candidates remain visibly inactive and cannot enable Save.
- Product-scoped package/count and measured-density candidates retain native Save after normal validation.
- Use only the permission-checked read validation endpoint; browser never projects, activates, or saves through a custom route.
- Use request/candidate revision tokens so stale responses cannot update current state.

## Task 1 — Status region and revision-safe client state

Add fast node fixture coverage and Playwright `@conv04` coverage before implementation. Render accessible validation status after `#qu-conversion-inverse-info` with incomplete, pending, active-ready, inactive-gate, blocked, request-failure, and product-normal states. Include unit pair, exact factor/source/version and impact result where available; never claim inactive rules are active. Controls meet existing UI contract (live-status/alert semantics, focus recovery, non-color state cues, 44px controls).

## Task 2 — Native authority proof

Prove product-scope validation leads to exactly one existing native POST/PUT, while inactive/blocked reusable states make no write request. Preserve fields after server rejection and never manufacture source/cache status in browser.

## Required verification

- `node --test public/custom/grocy_AI/conversion-explanations.test.js`
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @conv04`
- `php -l views/quantityunitconversionform.blade.php`
- `node --check public/viewjs/quantityunitconversionform.js`
- relevant PHP conversion suites and `git diff --check`
- independent scoped review with no Critical or Important findings.

