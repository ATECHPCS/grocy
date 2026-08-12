---
phase: 1
slug: safety-baseline-mobile-diagnostics
status: approved
shadcn_initialized: false
preset: none
created: 2026-08-12
reviewed_at: 2026-08-12T22:06:34Z
---

# Phase 1 — UI Design Contract

> Approved visual and interaction contract for Safety Baseline & Mobile Diagnostics.

---

## Scope and Source of Truth

This phase hardens the deployed grocy_AI enrichment card inside Grocy's product form. It does not redesign the product form, add structured field review, change image-candidate presentation, or create a second save path. Those concerns remain with later phases.

Locked outcomes from REQUIREMENTS.md and ROADMAP.md:

- Start enrichment through camera scan or manual EAN-8, UPC-A/GTIN-12, EAN-13, or GTIN-14 entry.
- Validate digit length and GS1 check digit locally before any request.
- Show distinct invalid, not-found, timeout, offline, provider-error, partial-success, success, and cancelled states.
- Bound every wait, offer Cancel or Retry as appropriate, coalesce duplicate intent, and suppress stale responses.
- Keep the normal Grocy product form, Save actions, and inventory workflows available when enrichment is unavailable.
- Expose a user-initiated, redacted diagnostic report with component versions, correlation ID, stage outcomes, and timings.
- Meet measured mobile/LAN budgets and record a physical-phone acceptance pass.

Existing baseline retained from productform.blade.php, product-enrichment.js, and grocy-ai.css:

- The feature-gated card remains in the product form's existing right column, immediately above Picture.
- Search and preview never persist data; normal Grocy Save remains the only persistence action.
- Existing metadata summary, image grid, Apply suggested name, and Use as product picture behaviors remain visually compatible.
- The card's primary left border, Bootstrap cards/alerts/buttons/forms, Roboto, and Font Awesome remain the visual vocabulary.

---

## Design System

| Property | Value | Source |
|----------|-------|--------|
| Tool | Existing Grocy UI; no new design-system tool | PROJECT.md and project research |
| Preset | Not applicable | Non-React Blade/plain-JavaScript stack |
| Component library | Bootstrap 4.6.2 with Grocy conventions and Bootbox camera modal | Repository stack and existing UI |
| Icon library | Font Awesome 6 Free, solid icons | views/layout/default.blade.php |
| Font | Roboto, sans-serif fallback | public/css/grocy.css and layout assets |
| Theme behavior | Inherit Grocy light/night-mode classes; no phase-owned theme toggle or hardcoded dark-mode fork | Existing Grocy design system |

shadcn and third-party component registries are not applicable. Do not add React, Tailwind, a CSS-in-JS layer, or a new frontend package.

---

## Spacing Scale

Declared values are the only new phase-owned spacing values:

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Icon-to-label gap, compact inline separation |
| sm | 8px | Related controls, status icon gap |
| md | 16px | Default control and status-block separation |
| lg | 24px | Card-body section separation |
| xl | 32px | Mobile stacked action/result separation when needed |
| 2xl | 48px | Major page-region break; preserve existing Grocy layout utility behavior |
| 3xl | 64px | Page-level spacing only; do not use inside the enrichment card |

Exceptions:

- Interactive controls must be at least 44px by 44px on touch layouts. This is a target-size requirement, not a spacing token.
- Existing Bootstrap 1px borders and the deployed 4px card accent rule remain unchanged.
- Existing image-candidate dimensions remain unchanged in this phase.

Within the card, use 16px between input/actions, status, diagnostics, and results; use 8px between controls in the same action group; use 24px only between major card subsections. Do not introduce one-off 6px, 10px, 12px, or 20px gaps.

---

## Typography

Use exactly four sizes and two weights for phase-owned UI. Inherit Roboto and do not add another font weight.

| Role | Size | Weight | Line Height | Usage |
|------|------|--------|-------------|-------|
| Supporting | 14px | 400 | 1.5 | Help, stage timing, source, trace summary |
| Body / control | 16px | 400 | 1.5 | Input, buttons, state copy, result body |
| Section / status heading | 20px | 500 | 1.2 | Result and diagnostics subsection headings |
| Card title | 24px | 500 | 1.2 | grocy_AI product enrichment heading |

Rules:

- Allowed weights are 400 and 500 only in new or changed enrichment UI.
- Keep sentence case. Do not use all caps for status or diagnostics labels.
- Keep user-facing error and recovery text at 16px; do not demote it to small muted copy.
- Use tabular numerals for elapsed-time values when available; do not use a monospace font for the whole diagnostic report control.

---

## Color

Use Bootstrap/Grocy semantic classes so user night-mode settings continue to work. The light-theme values below declare the visual hierarchy; existing night-mode overrides are authoritative in dark mode.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | #FFFFFF | Page and card surfaces, inputs, result surface |
| Secondary (30%) | #F8F9FA | Neutral status/diagnostic surfaces and subtle grouping; pair with #DEE2E6 borders |
| Accent (10%) | #007BFF | Card left rule, enabled primary Search product/Retry search action, Scan barcode control, focus treatment, diagnostic disclosure link |
| Destructive / error | #DC3545 | Error icon/border/status only in this phase; there are no destructive actions |

Accent is reserved for the enrichment card rule, the current primary search/retry action, camera-scan affordance, keyboard focus, and the diagnostics disclosure. It must not color every label, metadata row, result card, or secondary action.

### Semantic state colors

| State | Bootstrap treatment | Required non-color cue |
|-------|---------------------|------------------------|
| Success | alert-success; light background #D4EDDA, text #155724 | Check-circle icon and explicit success heading |
| Partial / not found / timeout / offline | alert-warning; light background #FFF3CD, text #856404 | Triangle-exclamation icon, named condition, recovery action |
| Provider or unexpected error | alert-danger; light background #F8D7DA, text #721C24 | Circle-exclamation icon, named failing stage, recovery action |
| Cancelled / idle / camera unavailable | alert-secondary or secondary surface; light background #E2E3E5, text #383D41 | Neutral icon and explicit state text |
| In progress | secondary surface plus Bootstrap spinner | Visible “Searching product details…” text and Cancel search action |

Never communicate status by color or spinner alone. Preserve existing night-mode contrast by relying on Grocy's night-mode selectors; any new phase-specific selector must have an equivalent night-mode rule when Bootstrap inheritance is insufficient.

---

## Visual Hierarchy and Layout

### Existing page placement

- Preserve the product form's Bootstrap grid: two columns at lg and above, one stacked column below lg.
- Keep the enrichment card in its current right-column location above Picture. Do not move normal Grocy Save buttons into the enrichment card.
- Do not hide, disable, dim, or overlay the rest of the product form while enrichment is unavailable or running.
- The card remains a single visual container. Status, diagnostics, and results appear inside it; do not add nested decorative cards.

### Card anatomy, top to bottom

1. Card title: “grocy_AI product enrichment”.
2. One-sentence safety description.
3. Visible GTIN label, numeric input, and camera control.
4. Search/Cancel action row.
5. One persistent live status region.
6. Collapsible diagnostics disclosure for the current request.
7. Existing preview/results region.

Only one status block and one result set may be visible. A new search replaces the prior terminal state after its own busy state begins; it must not append another alert or duplicate result grid.

### Responsive contract

- Support 320px viewport width without horizontal scrolling.
- At widths below 576px, GTIN input occupies a full row; Scan barcode and Search product occupy the following wrapping action row. Primary and recovery actions are full-width only when needed to preserve 44px targets and readable labels.
- At 576px and above, the input and search action may use the existing Bootstrap input-group arrangement; Scan barcode remains adjacent and visually secondary.
- Status copy, trace summary, and actions wrap naturally. Never truncate recovery instructions or correlation IDs with horizontal scrolling.
- The camera modal uses the existing responsive Bootbox/Zxing component. Closing the modal stops the camera and returns to the unchanged manual-entry state.
- Orientation change, browser background/foreground, and browser Back must not leave a modal, spinner, disabled search control, or obsolete result on screen.

---

## Component Inventory

| Component | Contract | Existing primitive |
|-----------|----------|--------------------|
| Enrichment card | One bordered card; no persistence controls inside | Bootstrap card plus grocy-ai-card |
| GTIN field | Visible label; inputmode numeric; autocomplete off; preserves leading zeroes; local length and check-digit feedback | form-control and invalid-feedback |
| Camera scan | Text label “Scan barcode” at phone widths; camera icon may accompany but never replace accessible name | Existing camera scanner / Font Awesome camera icon |
| Search action | One primary CTA; enabled only for a locally valid GTIN and no equivalent request in flight | btn-primary |
| Cancel action | Visible only while a request is active; aborts the current request and returns controls immediately | btn-outline-secondary |
| Retry action | Visible on recoverable terminal failures; starts one new request for the unchanged valid GTIN | btn-primary |
| Live status | Single persistent visible region for progress and terminal outcome | Bootstrap alerts; ARIA live region |
| Diagnostic disclosure | Compact summary plus expandable allowlisted details | Bootstrap collapse/details-compatible disclosure |
| Copy diagnostics | Secondary action with Copy icon and explicit success/failure feedback | btn-outline-secondary and clipboard API fallback |
| Preview results | Preserve deployed summary, name action, image candidates, and feedback | Existing grocy_AI result components |

Recommended Font Awesome icons: camera, magnifying-glass, spinner, circle-check, triangle-exclamation, circle-exclamation, circle-info, copy, and xmark. All decorative icons use aria-hidden=true.

---

## Interaction State Machine

| State | Entry and visual contract | Available actions | Exit rules |
|-------|---------------------------|-------------------|------------|
| Idle | Empty-state copy; no alert color; Search product disabled until GTIN is valid | Type GTIN; Scan barcode; continue normal form work | Local validation runs on every edit/scan |
| Invalid length | Input is-invalid; inline assertive error names supported lengths; no network request | Correct input; scan again; continue manually | Clears immediately when length becomes supported |
| Invalid check digit | Input is-invalid; separate checksum message; no network request | Correct input; scan again; continue manually | Clears immediately when checksum becomes valid |
| Valid / ready | Input is valid without showing a global success alert; Search product enabled | Search product; scan replacement; continue manually | Search starts one request; input change returns through validation |
| Camera active | Standard camera modal with visible close action and camera/torch controls where supported | Scan; switch camera; close | Decoded GTIN populates the input, closes camera, validates within 250ms, then starts search only once |
| Searching | Within 250ms, show spinner plus exact current GTIN and “Searching product details…”; disable only Search product; show Cancel search | Cancel search; continue viewing/editing other form fields | Terminal response, 15s browser deadline, input change, navigation, or explicit cancel |
| Cancelled | Neutral status: request stopped and no changes made; results from the cancelled request remain hidden | Search product; change GTIN; continue manually | New intent begins normally |
| Offline | Warning names phone/network state and leaves all Grocy form controls usable | Retry search after reconnect; continue manually; copy diagnostics | Retry is explicit; never auto-loop on online event |
| Timeout | Warning names timeout and offers recovery; diagnostic disclosure visible | Retry search; continue manually; copy diagnostics | Retry creates one new trace/request |
| Not found | Warning says no exact match; no empty result grid | Check/change GTIN; Retry search; continue manually; copy diagnostics | New valid intent or retry |
| Provider/companion error | Error names the safe failing stage, never raw exception text; diagnostic disclosure visible | Retry search; continue manually; copy diagnostics | New valid intent or retry |
| Partial success | Warning states which optional evidence is unavailable; usable metadata remains visible | Review available preview; Retry search; continue manually; copy diagnostics | Explicit user action only |
| Success | Success heading and existing preview; diagnostic disclosure available but collapsed | Review existing preview actions; new search; copy diagnostics; normal Save | Input change/new scan clears old preview before any new result can render |

### Concurrency and stale-response rules

- Maintain exactly one active request token per enrichment card.
- Repeated taps or the same decoded scan while an equivalent request is active are coalesced: no second request, duplicate alert, or duplicate result.
- Editing the GTIN, scanning a different value, cancelling, navigating away, or starting a newer search aborts the old XHR when possible and invalidates its token unconditionally.
- A success or error callback updates the UI only when both request token and normalized current GTIN match.
- Late callbacks are ignored silently. Never flash “stale result” to the user and never restore old controls or results.
- Search/retry are idempotent reads. This phase must not add an automatic whole-request retry.
- Enrichment state may never disable Save & continue, Save & return to products, or unrelated product fields.

### Timing contract

- Visible validation or busy feedback: within 250ms of entry/scan/search intent.
- Browser-to-Grocy interactive deadline: 15 seconds overall, followed by the named timeout state.
- Initial internal budgets: 2 seconds companion connect; 12 seconds Grocy-to-companion total; 2 seconds provider connect; 5–6 seconds provider read. No individual provider path may exceed a 10-second interactive deadline without a named timeout.
- Acceptance targets on the recorded household LAN: cached/existing-Grocy p95 at or below 1 second; provider metadata p95 at or below 5 seconds; image attachment p95 at or below 5 seconds.
- These are initial release-gate values from project research. Re-baseline only from a recorded physical-phone run; record device, OS/browser version, network condition, p50, p95, and sample count without logging product or GTIN values.

---

## Diagnostics Contract

Diagnostics are local, request-scoped, collapsed by default, and generated only for the current interaction. The terminal status exposes a compact “Diagnostics” disclosure and Copy diagnostic report action; users do not need diagnostics to continue working.

Allowlisted copied fields:

- Diagnostic schema version and generated-at time.
- Grocy version, grocy_AI module version, companion version when available, and enrichment contract version.
- Correlation/trace ID.
- Overall outcome and coarse online/offline/cancelled classification.
- Named stages: browser, Grocy, companion, and provider enum(s).
- For each stage: status enum, status-class/error-code enum, cache outcome where applicable, and bounded duration in milliseconds.
- Overall elapsed time and whether the browser deadline was reached.

Forbidden fields:

- Credentials, API keys, authorization/session headers, cookies, CSRF values, or user identity.
- GTIN/UPC value, current or prior GTIN history, product name, response/request payload body, suggested values, or local inventory contents.
- Full provider/image URL, query string, opaque image/download token, stack trace, or raw exception text.
- Arbitrary request headers or browser navigation history.

The visible collapsed summary shows only correlation ID suffix, overall stage outcome, and total duration. Copy success is announced as “Diagnostic report copied.” If clipboard access fails, reveal a selected read-only text area containing the same redacted report and announce “Copy was blocked. Select and copy the redacted report manually.”

---

## Copywriting Contract

All strings must pass through Grocy localization helpers when implemented.

| Element | Exact copy |
|---------|------------|
| Card description | Scan or enter a GTIN to search product details. Results are previews and are not saved automatically. |
| Input label | GTIN |
| Input placeholder | 8, 12, 13, or 14 digits |
| Camera action | Scan barcode |
| Primary CTA | Search product |
| Busy label | Searching product details… |
| Cancel action | Cancel search |
| Retry action | Retry search |
| Diagnostic action | Copy diagnostic report |
| Empty state heading | No enrichment result yet |
| Empty state body | Scan or enter a GTIN, then search. You can continue editing this product without enrichment. |
| Invalid length | Enter an 8, 12, 13, or 14 digit GTIN. |
| Invalid checksum | That GTIN has an invalid check digit. Check the number and try again. |
| Camera unavailable | Camera scanning is unavailable. Enter the GTIN manually. |
| Cancelled | Search cancelled. No changes were made. |
| Offline | This phone is offline. Reconnect and retry, or continue editing manually. |
| Timeout | The search took too long. Retry, or continue editing manually. |
| Not found | No exact product match was found. Check the GTIN or continue editing manually. |
| Companion unavailable | Product search is temporarily unavailable. Retry, or continue editing manually. |
| Provider error | A product data provider could not respond. Retry, or continue editing manually. |
| Partial image failure | Product details were found, but images are unavailable. You can continue without an image. |
| Success heading | Product details found |
| Success body | Review the preview before applying anything. Changes are saved only when you save the product. |
| Diagnostic copy success | Diagnostic report copied. |
| Diagnostic copy fallback | Copy was blocked. Select and copy the redacted report manually. |
| Destructive confirmation | None. Phase 1 has no destructive action and must not introduce a confirmation modal. |

Do not use “Something went wrong,” “Unknown error,” or raw backend messages as the only error copy. Name the safe failure class and always pair it with Retry or continue-manually guidance.

---

## Accessibility and Input Contract

- Use a visible label; the placeholder is supplemental, never the label.
- Preserve leading zeroes by treating GTIN as text with inputmode=numeric, not as a numeric value.
- Enter starts Search product only when local validation passes. Escape cancels only an active enrichment request; it must not clear the product form.
- Invalid field feedback uses aria-describedby and role=alert or an assertive live region. Progress, cancellation, partial success, and success use role=status with aria-live=polite. Avoid nesting competing live regions.
- Set aria-busy=true on the result/status region only while searching; clear it on every terminal, abort, and navigation path.
- Keep focus in the initiating control while live text announces progress. On local validation failure, focus the GTIN input. Do not force focus to a result card after asynchronous completion.
- Every actionable control is keyboard reachable, has a visible focus indicator, and is at least 44px square on touch layouts.
- Icon-only controls, if retained above phone widths, require an accessible name and tooltip; phone-width actions use visible text.
- Do not depend on hover, toast-only feedback, vibration, sound, or color.
- Spinner animation must respect prefers-reduced-motion and cannot be the only busy indication.

---

## Degraded-Path and Preservation Contract

- When the companion, Open Food Facts, SearXNG, an image host, or the LAN path is unavailable, the enrichment card fails locally and the surrounding Grocy page remains interactive.
- Preserve every manual product-field value, current picture selection, and existing preview selection across timeout, offline, provider failure, image failure, cancel, and diagnostics copy.
- Do not disable or intercept normal product Save actions because enrichment is invalid, unavailable, cancelled, or in progress.
- Search, retry, cancel, back, and diagnostic copy perform zero durable product, barcode, stock, conversion, or file writes.
- If camera permission is denied or HTTPS support is absent, explain camera unavailability once and leave manual GTIN entry ready.
- Partial provider success keeps safe available data visible and labels the missing stage; it does not collapse to a generic total failure.

---

## Mobile Verification Contract

Automated coverage must exercise Chromium and WebKit mobile profiles at 320px, 375px, 390px, and tablet width, including:

- Valid manual entry and camera-event handoff.
- Invalid length and invalid check digit with zero request.
- Immediate busy feedback, cancel, timeout, offline, companion failure, provider failure, partial image failure, not found, and success.
- Duplicate tap/scan coalescing and late-response suppression after GTIN edit, cancel, newer request, navigation Back, and background/foreground transition.
- No horizontal scroll, no obscured controls, 44px targets, keyboard flow, live-region announcements, and reduced-motion behavior.
- Normal product fields and both Save actions remain usable on every degraded path.
- Copied diagnostics match the allowlist and contain none of the forbidden fields.

The physical-phone acceptance record must identify the supported device, OS, browser/version, connection scenario, viewport/orientation, and observed p50/p95. It must cover normal LAN, slow LAN, disconnected/reconnected LAN, permission-denied camera, repeat scan/tap, cancel, background/foreground, back navigation, search/review, existing name/image selection, Save, and reload.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | None | Not applicable — shadcn is not initialized and this is not a React project |
| Third-party registries | None | Not applicable — no registry components permitted for this phase |

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: PASS
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved — 2026-08-12
