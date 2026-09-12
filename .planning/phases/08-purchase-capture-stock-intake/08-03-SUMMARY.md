---
phase: 08-purchase-capture-stock-intake
plan: "03"
subsystem: purchase-capture-mobile-page
tags: [blade, javascript, ui, scanner-reuse, sidebar, stock-purchase]
provides:
  - mobile scan-loop capture page bound to the live capture endpoints, reusing the enrichment card's camera scanner
  - sidebar "Purchase capture" tile (STOCK_PURCHASE) + GET /grocyai/capture page route
key-files:
  created:
    - views/grocyai_capture.blade.php
    - public/custom/grocy_AI/capture.js
    - public/custom/grocy_AI/capture.test.js
    - custom/grocy_AI/src/GrocyAiCaptureController.php
    - .planning/phases/08-purchase-capture-stock-intake/08-03-SUMMARY.md
  modified:
    - custom/grocy_AI/routes.php
    - views/layout/default.blade.php
key-decisions:
  - "The scanner is REUSED, not re-implemented: a `.barcodescanner-input` with data-target='grocyai-capture-barcode' plus @include('components.camerabarcodescanner') gives the core camera control; capture.js listens for the jQuery `Grocy.BarcodeScanned` event (filtered on that target) and submits exactly as a manual add — identical to the enrichment card's path."
  - "Product names are resolved CLIENT-side (via Grocy.Api.Get('objects/products/{id}'), cached, with a 'Product #<id>' fallback) so the pinned closed line DTO from 08-01 is never widened; the scan endpoint keeps returning exactly the line DTO."
  - "capture.js follows the bulk-review UMD pattern: pure helpers (isLinePayload/upsertLines/lineLabel/describeQuantity) are unit-tested via node --test; DOM wiring lives in attachCapture. Same-origin fetch to the module endpoints, no core-API-key coupling for our own routes."
  - "Sidebar injection is the core-hook mechanism used by the existing bulk tile: a permission-scoped <li> in views/layout/default.blade.php (permission-STOCK_PURCHASE, active-page on viewName grocyai_capture)."
  - "portable-files.txt is deliberately NOT touched — the main release-gate asserts an exact portable count (12); registering the capture assets belongs to the Phase 8 deploy wave, not this UI wave."
requirements: [CAP-01, CAP-02, CAP-08]
---

# Phase 08 Plan 03: Mobile Purchase-Capture Page Summary

**A STOCK_PURCHASE user opens a mobile scan-loop page from the sidebar, starts a trip automatically, and rapid-scans barcodes with instant known/unknown + running-quantity feedback — reusing the enrichment card's camera scanner, writing no stock.**

## Page / asset / menu references

| Artifact | Reference |
|---|---|
| Page route | `GET /grocyai/capture` → `GrocyAiCaptureController::Capture` (STOCK_PURCHASE), `custom/grocy_AI/routes.php:59` |
| View | `views/grocyai_capture.blade.php` (extends `layout.default`, asset token `2.5.0`, `@include('components.camerabarcodescanner')`) |
| Client | `public/custom/grocy_AI/capture.js` (UMD; `attachCapture` + pure helpers) |
| Client test | `public/custom/grocy_AI/capture.test.js` (node --test, 4 passing) |
| Controller | `custom/grocy_AI/src/GrocyAiCaptureController.php` |
| Sidebar tile | `views/layout/default.blade.php:470` — `<li permission-STOCK_PURCHASE>` "Purchase capture", `fa-cart-shopping`, href `/grocyai/capture` |

## Scan loop

On load, `attachCapture` POSTs `data-trips-endpoint` (`/api/grocy-ai/capture/trips`) to start a trip, then:
- **Camera scan** — the core `#camerabarcodescanner-start-button` (auto-created for the `.barcodescanner-input`) fires `Grocy.BarcodeScanned` with target `grocyai-capture-barcode`; capture.js submits it.
- **Manual** — the GTIN input (Enter) or the big **Add** button submits.
- Each submit POSTs `.../trips/{id}/scan` `{barcode}`; the returned closed line DTO is validated (`isLinePayload`) then `upsertLines` replaces the same line id in place (a coalesced/incremented repeat) or appends, kept ordered by seq.
- Each row shows the product name (known, resolved via `Grocy.Api` with a `Product #<id>` fallback) or **"Unknown — needs product"**, plus **× quantity**. Known/unknown status drives the `status-<known|unknown|conflict>` row class.
- **Start new trip** resets the trip and list.

All dynamic labels come from `$__t`-localized `data-*` attributes (mirrors the enrichment `localized()` pattern). The page declares no write form and never touches stock.

## Verification

- Task 1: `php8.5 -l GrocyAiCaptureController.php` clean; `node --check capture.js` clean.
- Task 2: `php8.5 -l routes.php` clean; `rg 'grocyai/capture|Purchase capture'` finds the route + sidebar tile.
- `node --test capture.test.js` — 4/4 pass (line-DTO validation, coalescing reducer, label model, quantity).
- Real Blade compiler produces parseable PHP for `grocyai_capture.blade.php` and `layout/default.blade.php`.
- Real Slim app registers `GET /grocyai/capture`; `Capture` is STOCK_PURCHASE-gated (per-method-body grep, GREP-01).
- Regression: default suite `All 127 grocy_AI checks passed`; `capture-contract` GREEN; `capture-invariants` still RED at the deferred commit surface.

## Decision and next step

The workflow now has a fast, menu-discoverable mobile face over the live queue. The next wave builds the review surface (`/grocyai/capture/review`) — trip list, per-line edits, trip defaults, unknown→enrichment handoff — and then the commit wave adds `ChecksumForTrip`/`CommitTrip` (turning `capture-invariants` green).
