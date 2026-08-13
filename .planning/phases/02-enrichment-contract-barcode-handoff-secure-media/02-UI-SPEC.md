---
phase: 2
slug: enrichment-contract-barcode-handoff-secure-media
status: draft
shadcn_initialized: false
preset: none
created: 2026-08-13
---

# Phase 2 — UI Design Contract

> Visual and interaction contract for Enrichment Contract, Barcode Handoff & Secure Media. This extends the approved Phase 1 enrichment card without redesigning Grocy's product form.

---

## Scope and Authority

The product form remains the workspace and Grocy's existing Save buttons remain the only persistence authority. Phase 2 replaces the current summary-style preview with an independent review flow for exactly these suggestion families: name, brand, package size, product group, quantity unit, food type, and product image.

The UI must make four guarantees visible:

1. The originally scanned GTIN remains visible while Grocy checks canonical equivalents and ownership.
2. Every supported field shows current and suggested values side by side with source, confidence, reason, and freshness at that row.
3. Suggestions are reversible selections until a final diff is reviewed and staged into the native product form; staging is still not saving.
4. Image candidates load only after an explicit same-origin request; no external URL appears in the DOM, copied diagnostics, or browser-visible contract.

Locked behavior from `02-CONTEXT.md`:

- A validated, direct structured-source suggestion for the canonical GTIN may start selected only when its current field is blank.
- A non-empty current value is never preselected for replacement.
- Search-derived, inferred, transformed, or mapped evidence is never preselected regardless of numeric score.
- Automatic selections and selections made by the user remain distinguishable in the final diff.
- Search, review, cancellation, timeout, stale responses, and media failure perform zero writes.
- Normal Grocy Save remains available and authoritative; do not add an enrichment Save action.

Phase 1 physical-phone evidence remains skipped and incomplete. Phase 2 verification must report only Phase 2 evidence and must not represent that earlier gate as passed.

---

## Design System

| Property | Value | Source |
|----------|-------|--------|
| Tool | Existing Grocy UI; no new design-system tool | Approved Phase 1 UI contract and repository inspection |
| Preset | Not applicable | Non-React Blade/plain-JavaScript stack |
| Component library | Bootstrap 4.6.2 with Grocy conventions | Existing product form and research standard stack |
| Icon library | Font Awesome 6 Free, solid icons | Existing layout and enrichment card |
| Font | Roboto, sans-serif fallback | `public/css/grocy.css` |
| Theme behavior | Inherit Grocy light/night-mode classes | Existing Grocy design system |

No shadcn initialization gate applies because this is not a React, Next.js, or Vite project. Do not add React, Tailwind, a CSS-in-JS layer, a new frontend package, or a second component library.

---

## Spacing Scale

Declared values are the only phase-owned spacing values:

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Icon-to-label gap, compact badge gap |
| sm | 8px | Related metadata lines, action groups, field-cell padding |
| md | 16px | Comparison-row padding, grid gaps, status and action separation |
| lg | 24px | Major subsections: barcode, field review, images, final diff |
| xl | 32px | Separation before the final review action on narrow layouts |
| 2xl | 48px | Existing page-region separation only |
| 3xl | 64px | Page-level spacing only; never inside the enrichment card |

Exceptions:

- Every actionable control and the full checkbox label hit area must be at least 44px by 44px on touch layouts. This is a target-size rule, not a spacing token.
- Preserve existing Bootstrap 1px borders and the deployed 4px enrichment-card left rule.
- Image thumbnail frames are 120px high because this is content sizing, not spacing.
- Preserve existing Grocy form utility margins outside the enrichment card; do not normalize unrelated form layout.

Use 16px between comparison rows and 24px between the barcode, fields, image, and final-diff sections. Use 8px between provenance lines or controls in one group. Do not introduce one-off phase-owned gaps such as 6px, 10px, 12px, or 20px.

---

## Typography

Use exactly four sizes and two weights for new or changed enrichment UI:

| Role | Size | Weight | Line Height | Usage |
|------|------|--------|-------------|-------|
| Supporting | 14px | 400 | 1.5 | Provenance, freshness, confidence, helper text |
| Body / control | 16px | 400 | 1.5 | Values, actions, errors, barcode text, diff rows |
| Section / status heading | 20px | 500 | 1.2 | Field review, images, final diff, state headings |
| Card title | 24px | 500 | 1.2 | Existing grocy_AI product enrichment title |

Rules:

- Allowed weights are 400 and 500 only.
- Use sentence case. Never uppercase confidence, state, source, or selection-origin labels.
- Error and recovery instructions stay at 16px; do not demote them to muted metadata.
- Barcode and elapsed-time numerals use tabular numerals where available. Treat the barcode as text; do not use a numeric control or numeric coercion.
- Wrap long provider values and source labels with `overflow-wrap: anywhere`; do not ellipsize decision-critical values or provenance.

---

## Color

Use Bootstrap/Grocy semantic classes so existing night-mode behavior remains authoritative. The light-theme values declare hierarchy:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | #FFFFFF | Product form, enrichment card, value cells, image frames |
| Secondary (30%) | #F8F9FA | Current-value cells, provenance bands, neutral placeholders, final-diff surface; #DEE2E6 border |
| Accent (10%) | #007BFF | Card rule, active primary review/staging action, checked review control, automatic-selection badge, focus treatment, demand-load control |
| Destructive / error | #DC3545 | Contract failure, stale conflict, media failure, and invalid input only; no destructive Phase 2 action |

Accent is reserved for the enrichment-card rule, the active search/review/stage CTA, checked suggestion control, `Preselected` badge, demand-load action, and keyboard focus. Do not use accent on every field label, provider label, metadata line, or image frame.

### Semantic states

| Meaning | Bootstrap treatment | Required non-color cue |
|---------|---------------------|------------------------|
| Selected / staged | Success border or `alert-success` | Check icon plus `Selected` or `Staged in form` text |
| High confidence | `badge-success` | Exact text `High confidence` |
| Medium / caution | `badge-warning` | Exact band label and reason text |
| Unverified / unavailable | `badge-secondary` or neutral surface | Exact `Unverified` or unavailable explanation |
| Stale / blocked | `alert-warning` for reviewable staleness; `alert-danger` for invalid contract or failed attachment | Named condition plus recovery action |
| Informational barcode ownership | `alert-info` or neutral bordered surface | Barcode/owner text plus explicit route action |

Never communicate confidence, selection, ownership, verification, or failure through color alone. Any new phase selector whose contrast is not inherited must include a `.night-mode` rule.

---

## Visual Hierarchy and Layout

### Existing placement

- Keep one `grocy-ai-card` in the product form's existing right column immediately above Picture.
- Keep the page two-column layout at `lg` and above and the existing one-column stack below `lg`.
- Do not move, clone, hide, dim, or overlay Grocy's normal Save buttons.
- The enrichment card remains the single outer visual container. Internal bordered comparison rows are functional groups, not nested decorative cards.

### Card anatomy, top to bottom

1. Existing card title and zero-write description.
2. Existing GTIN input, Scan barcode, Search product, Cancel, status, and diagnostics controls.
3. Barcode ownership block.
4. `Review suggested fields` section.
5. `Choose a product image` section.
6. Current selection count and `Review selected changes` action.
7. Inline final-diff panel when requested.
8. Staging result message pointing the user to Grocy's unchanged Save buttons.

Only one enrichment response, one barcode ownership block, one field-review set, and one final-diff panel may exist at a time. A new GTIN intent clears all prior suggestion, selection, staged-barcode, media, diff, and object-URL state before new results render.

### Phone comparison row

Every supported non-image field uses one bordered row:

- Header line: field name at left; a Bootstrap custom checkbox labeled `Use suggested value` at right. The checkbox and label form one 44px-minimum target.
- Comparison grid: two equal `minmax(0, 1fr)` columns at every supported width, including 320px. Left header is `Current`; right header is `Suggested`.
- Empty current values render `Blank`, never an empty cell.
- The suggested cell contains the value first, followed by source, confidence, reason, and freshness in that order.
- Selection-origin text sits directly below the control: `Preselected — blank field and exact structured match` or `Selected by you`.
- Long values wrap within their column. No comparison row may cause horizontal page scrolling.

The locked side-by-side relationship must not collapse into a vertical current-then-suggested stack on phones. At 320px, reduce cell padding to 8px and allow multi-line values and metadata.

### Responsive widths

- Support 320px, 375px, 390px, and tablet widths without horizontal scrolling.
- At less than 576px, section actions become full-width when needed and remain 44px high; comparison cells remain side by side.
- At 576px and above, section actions may be content-width and aligned to the end of the section.
- Image candidates use one column at 320px when necessary, two columns from 375px when each remains at least 144px wide, and the existing auto-fill grid above that.
- Final-diff before/after values remain side by side using the same two-column contract.

---

## Component Inventory

| Component | Contract | Primitive |
|-----------|----------|-----------|
| Enrichment card | Single feature container; no independent persistence control | Bootstrap `card` + `grocy-ai-card` |
| GTIN entry and lifecycle | Preserve approved Phase 1 validation, request ownership, Cancel/Retry, diagnostics, and 44px behavior | Existing form control, buttons, alerts, details |
| Barcode ownership block | Show exact scanned text, canonical-check summary, ownership state, and route/staging outcome | Bordered definition group + Bootstrap alert |
| Existing owner action | `Open existing product`; route uses trusted server product ID | `btn-primary` link/button |
| Unused barcode state | `Ready to add on Save`; transient and reversible | Neutral/success bordered row, check icon |
| Field comparison row | One independently selectable current/suggested pair | Bootstrap custom checkbox + CSS grid |
| Selection-origin badge | Distinguish automatic from user selection | `badge-primary` for `Preselected`; neutral text/badge for `Selected by you` |
| Provenance stack | Source, confidence, reason, source freshness, retrieval time | 14px fixed-label definition list or stacked text |
| Unmapped suggestion | Visible evidence with disabled selection and exact reason | Disabled custom checkbox + neutral helper text |
| Image group | Structured front image section first; unverified search section second | Section headings + image candidate grid |
| Image placeholder | No image request until `Load thumbnail` | Neutral 120px frame + `btn-outline-primary` |
| Image candidate | Loaded same-origin blob, source/evidence labels, explicit Select action | `img-thumbnail`, badges, button |
| Selected image | Visible check, `Selected` text, success border; reversible | Success treatment + `Remove selection` secondary action |
| Selection summary | `{n} changes selected`; updates on every selection | `role=status`, `aria-live=polite` |
| Final diff | Inline, review-only list of selected before/after values and origin | Bordered section, not a modal |
| Stage action | `Stage selected changes`; updates native controls but performs no request | `btn-primary` |
| Staging confirmation | Explains that changes are in the form and not yet saved | `alert-success` |
| Post-Save barcode attachment failure | Preserve created product context and allow barcode-only retry | `alert-danger` + `Retry barcode attachment` |

Recommended Font Awesome icons: barcode, arrow-right, circle-check, rotate-left, image, cloud-arrow-down, triangle-exclamation, circle-exclamation, shield-halved, and xmark. Decorative icons use `aria-hidden="true"`; text remains the accessible name.

---

## Suggestion Selection Contract

### Automatic selection eligibility

A field suggestion starts checked only when all conditions are true:

- The current value snapshot is blank after the native field's established blank normalization.
- `confidence_band` is `high`.
- `evidence_kind` is `structured_direct`.
- The reason is an exact canonical structured match.
- The suggestion maps directly to a valid, active native target when a target is required.

Any failed condition leaves the suggestion unchecked. A high numeric provider score is never sufficient. Product-group and quantity-unit suggestions are transformed local mappings and therefore start unchecked. Image candidates always require explicit demand-load and selection.

### Existing-value protection

- If current content is non-empty, `Use suggested value` starts unchecked regardless of confidence.
- Selecting it is an explicit user action and changes origin to `Selected by you`.
- Deselecting any automatic or explicit selection is immediate and reversible.
- Re-selecting a formerly automatic row through a user click changes its origin to `Selected by you`.
- Unselected rows never dispatch input/change events and never enter the final diff.

### Target availability

- Brand and package-size suggestions can be selected only when the corresponding deployed product userfield is present and active. Otherwise show `No matching Grocy field is configured.`
- Product group and quantity unit show the mapped local display label, not an external identifier. Missing or inactive mappings disable selection with `No matching Grocy option is available.`
- Food type evidence remains visible and independently reviewable. When no active local destination exists, disable selection and show `No local food type is configured.` Never stage an unmapped free-text substitute.

### Freshness and reason presentation

- Show the source's human-readable label, not only its machine ID.
- Translate closed reason codes to short text such as `Exact canonical barcode match`, `Mapped to a Grocy option`, `Inferred from provider data`, or `Unverified search result`.
- Always show `Retrieved {localized date/time}`.
- If a provider-supplied source update time exists, also show `Source updated {localized date/time}`.
- If it does not exist, show `Source update time unavailable`; never imply that retrieval time is source freshness.

---

## Barcode Handoff Contract

### Display

- Label the immutable value `Scanned barcode` and display the exact scanned string, including leading zeroes.
- Beneath it show `Canonical equivalents checked` followed by the bounded server-returned display strings. Do not expose the database expression or present the padded key as though it were scanned.
- Never run `parseInt`, numeric input conversion, locale-number formatting, or scientific notation on a barcode.

### Ownership outcomes

| Outcome | Visual and copy contract | Action |
|---------|--------------------------|--------|
| Unused | Check icon; `This barcode is not assigned in Grocy.` and `Ready to add on Save` | It is staged transiently; user may `Remove staged barcode` |
| Owned by current product | Info icon; `This barcode is already attached to this product.` | No insert is staged |
| Owned by another product | Owner product name when returned safely; `This barcode already belongs to an existing product.` | `Open existing product`; do not render suggestions for creating a duplicate |
| Ownership changed before staging | Warning; `Barcode ownership changed while you were reviewing.` | Clear staged barcode; `Open existing product` or search again |
| Attachment failed after product creation | Error names the partial result: product exists, barcode is not attached | `Retry barcode attachment`; must not repeat product creation |

Removing a staged barcode is a reversible review action, not a destructive action and not a write. The barcode is attached exactly once only in the continuation of normal Save, with a final owner check.

---

## Secure Media Contract

### Ordering and trust labels

- Render an exact structured-source `Front package image` first when available.
- Put all search results in a separate subsection headed `Unverified search alternatives`.
- Every search candidate shows `Unverified` and `Search result`; it is never preselected.
- Do not mix structured and search candidates in one undifferentiated grid.

### Demand-load interaction

1. Initial candidate shows a neutral placeholder, source/evidence labels, and `Load thumbnail`.
2. `Load thumbnail` performs one authenticated same-origin request for the thumbnail variant and shows `Loading thumbnail…` in the same 44px control.
3. Success renders a same-origin blob URL inside the existing frame and reveals `Select image`.
4. `Select image` explicitly fetches the full variant through the same-origin route, validates the response, stores the resulting `File` only in transient module state, and marks the candidate `Selected`.
5. `Remove selection` clears that transient file and revokes all replaced object URLs. The native picture input remains unchanged until the final diff is confirmed with `Stage selected changes`.

No candidate may render an external `src`, `href`, CSS URL, source-domain tooltip, or raw opaque handle. Handles may exist only in transient JavaScript state and same-origin route construction. Candidate bytes use `Cache-Control: private, no-store`; the UI must not promise that an expired handle can be retried without a new search.

### Media states

| State | Contract |
|-------|----------|
| Not loaded | Placeholder and `Load thumbnail`; zero request until activation |
| Loading | Inline text plus spinner; candidate control disabled; other form work remains available |
| Thumbnail loaded | Image with bounded dimensions, descriptive alt text, source/trust labels, and `Select image` |
| Selected | Success border, check icon, `Selected`, and `Remove selection` |
| Expired | `This image preview expired. Search again to load it.` |
| Rejected / unavailable | `This image could not be loaded safely. Choose another image or continue without one.` |
| Cancelled or stale | Revoke object URL, hide obsolete result, announce no change only when user initiated cancellation |

Media failure never clears field selections, current product values, the current native picture, or normal Save controls.

---

## Final Diff and Staging Contract

`Review selected changes` is enabled when at least one live field, image, or unused barcode is selected. Activating it performs no write and opens an inline panel headed `Review selected changes`.

Each diff item must show:

- Field label.
- Current value and selected value side by side.
- Source label and selection origin (`Preselected` or `Selected by you`).
- For the barcode, `Not attached` → the exact scanned barcode.
- For an image, current-picture thumbnail/`No picture` → selected same-origin preview, never an external URL.

Before displaying the diff and again before staging, re-read every native control and compare it with the captured current-value snapshot. If a value changed:

- Mark the row `Needs review`.
- Clear any automatic selection.
- Do not stage the row.
- Show `This field changed after the search. Review it again before staging.`
- Require a fresh explicit selection against the new current value.

Final-diff actions:

- `Back to suggestions` returns without changing selections.
- `Stage selected changes` dispatches the native input/change behavior only for the live selected rows and stages the image file and barcode handoff state. It performs no API call.
- After staging, show `Selected changes are staged in the form. Review the form, then use Grocy's Save button to save them.`
- If the user edits a staged native field afterward, that manual edit becomes authoritative; the diff must not silently reapply the suggestion.

Do not add `Save enrichment`, intercept Save merely because enrichment is incomplete, or persist anything when the diff opens or closes.

---

## Interaction State Machine

The approved Phase 1 states and transition rules remain authoritative: idle, invalid length, invalid check digit, ready, camera active, searching, cancelled, offline, timeout, not found, companion unavailable, provider error, partial image failure, and success. Phase 2 adds these substates:

| State | Entry and visual contract | Available actions | Exit rules |
|-------|---------------------------|-------------------|------------|
| Contract invalid | Entire enrichment result rejected; no partial rows render | Retry search; continue manually; diagnostics | New valid request only |
| Existing owner | Barcode and owner notice render; duplicate-creation review is suppressed | Open existing product; new scan | Trusted owner route or new intent |
| Review ready | Barcode block and independent rows render; eligible blank fields visibly preselected | Toggle rows; load images; Review selected changes | Selection, new GTIN, or final review |
| No usable suggestions | Exact barcode outcome remains; no empty comparison grid | Continue manually; retry; stage unused barcode if applicable | New intent or normal form work |
| Stale current value | Affected row marked Needs review and excluded from staging | Review and explicitly reselect; leave unselected | Fresh explicit decision or new intent |
| Final diff | Inline selected-only diff; current values revalidated | Back to suggestions; Stage selected changes | Back, stale detection, or staging |
| Staged in form | Confirmation names zero-write staging and points to normal Save | Edit form; change selection; normal Save | Manual edit, new GTIN, navigation, or Save |
| Media loading | Candidate-local busy state; no card-wide lock | Continue form work; cancel current enrichment if applicable | Loaded, rejected, expired, stale, or cancelled |
| Barcode attachment failed | Product creation is retained; barcode outcome named separately | Retry barcode attachment; open product | Same-product success, other-owner route, or user leaves |

### Concurrency and preservation

- Keep the Phase 1 request sequence plus normalized GTIN as the sole result-ownership guard.
- Repeated actions coalesce; stale callbacks cannot restore suggestions, images, selections, diagnostics, or staged barcode state.
- A new GTIN, scan, Cancel, orientation invalidation, navigation, or newer request revokes candidate blob URLs and invalidates handles in browser state.
- Current form edits made while a request is in flight are never overwritten by automatic selection.
- Search and media requests may disable only their initiating control. They never disable native fields or Save buttons.

---

## Copywriting Contract

All strings pass through Grocy localization helpers. Use these exact English source strings:

| Element | Exact copy |
|---------|------------|
| Card description | Scan or enter a GTIN to find product suggestions. Review and stage selected changes; nothing is saved until you save the product. |
| Barcode label | Scanned barcode |
| Equivalents label | Canonical equivalents checked |
| Unused barcode | This barcode is not assigned in Grocy. |
| Staged barcode badge | Ready to add on Save |
| Remove barcode action | Remove staged barcode |
| Existing owner | This barcode already belongs to an existing product. |
| Existing owner CTA | Open existing product |
| Field section heading | Review suggested fields |
| Current column | Current |
| Suggested column | Suggested |
| Blank current value | Blank |
| Selection control | Use suggested value |
| Automatic origin | Preselected — blank field and exact structured match |
| Explicit origin | Selected by you |
| No Grocy field | No matching Grocy field is configured. |
| No Grocy option | No matching Grocy option is available. |
| No food-type target | No local food type is configured. |
| Source update absent | Source update time unavailable |
| Image section heading | Choose a product image |
| Structured image heading | Front package image |
| Search image heading | Unverified search alternatives |
| Thumbnail action | Load thumbnail |
| Thumbnail busy | Loading thumbnail… |
| Image select | Select image |
| Image selected | Selected |
| Image remove | Remove selection |
| Selection summary | %s changes selected |
| Primary CTA | Review selected changes |
| Empty selection heading | No changes selected |
| Empty selection body | Select one or more suggestions, or continue editing the product manually. |
| Staging CTA | Stage selected changes |
| Back action | Back to suggestions |
| Staging success | Selected changes are staged in the form. Review the form, then use Grocy's Save button to save them. |
| Stale field | This field changed after the search. Review it again before staging. |
| Contract error | Suggestions could not be verified. Retry the search, or continue editing manually. Nothing was changed. |
| Media error | This image could not be loaded safely. Choose another image or continue without one. |
| Media expired | This image preview expired. Search again to load it. |
| Partial barcode error | The product was saved, but the barcode was not attached. Retry the barcode only; the product will not be created again. |
| Barcode retry | Retry barcode attachment |
| Destructive confirmation | None. Phase 2 has no destructive action and must not introduce a confirmation modal. |

Do not use `Apply`, `Save suggestion`, `Overwrite`, `Trust`, `Verified image`, raw reason codes, raw backend messages, or generic `Something went wrong` as standalone user copy.

---

## Accessibility and Input Contract

- Use native checkbox semantics for suggestion selection. Set the input's checked state, not only a CSS class; the visible label is part of its 44px hit target.
- Associate each row header, checkbox, current value, suggested value, and provenance group through stable `id`, `for`, `aria-labelledby`, and `aria-describedby` relationships.
- Announce selection count and staging success with one polite live region. Keep contract errors and stale blocking feedback assertive. Do not nest competing live regions.
- On `Review selected changes`, move focus to the inline diff heading only after the synchronous panel opens. `Back to suggestions` returns focus to the review button.
- On a stale row, focus the row's selection control or heading after announcing the exact stale message.
- Do not force focus when asynchronous suggestions or thumbnails finish loading.
- Image alt text uses `{product name or Product} — {source label} package candidate`; trust and source remain visible text outside the image.
- Every action is keyboard reachable, has a visible focus indicator, and is at least 44px square on touch layouts.
- Do not depend on hover, drag, swipe, toast-only feedback, animation, color, or icon-only labeling.
- Spinners respect `prefers-reduced-motion` and always have visible busy text.

---

## Zero-Write and Degraded-Path Contract

- GTIN validation, owner lookup, enrichment search, suggestion selection, deselection, review, diff display, staging into controls, thumbnail loading, full-image selection, Cancel, timeout, Retry, diagnostics, and handle failure create no product, barcode, category, stock, conversion, or durable file write.
- A validated image `File` may exist only in transient module state during review. Native controls and the native picture input may change only after `Stage selected changes`; durable APIs remain behind normal Save.
- Unselected native controls remain byte-for-byte/value-for-value unchanged and receive no dirty/change event from enrichment.
- Provider, companion, contract, image-host, or network failure leaves the surrounding product form usable and preserves manual values.
- If all suggestion evidence is rejected, show the named contract error rather than a plausible partial review.
- If media fails, retain metadata rows and allow Save without a new picture.
- If barcode attachment fails after product creation, preserve the new product ID and use a barcode-only retry; never repeat the product create request.

---

## Mobile Verification Contract

Automated coverage must exercise Chromium and WebKit mobile profiles at 320px, 375px, 390px, and tablet width:

- Side-by-side current/suggested layout for all seven families with long values, long source labels, and no horizontal scroll.
- Blank direct structured suggestion preselection; manual reversal; re-selection origin change; non-empty protection; mapped/search/inferred suggestions unselected.
- Missing/inactive local targets visibly disabled with their exact explanation.
- Exact scanned GTIN display, leading-zero canonical equivalent ownership, existing-owner routing, unused staging, removal, and pre-Save zero writes.
- Final diff includes selected rows only, distinguishes automatic and explicit origin, blocks stale snapshots, and stages only after explicit confirmation.
- Structured front image first, search alternatives labeled unverified, zero image request before demand-load, same-origin-only DOM URLs, expired/rejected handles, object-URL revocation, and reversible selection.
- Zero durable writes across every non-Save path and exactly one barcode attachment after normal Save.
- Partial product-save/barcode-failure recovery retries the barcode only and cannot create a second product.
- 44px targets, keyboard traversal, focus return, live-region announcements, visible non-color cues, reduced motion, light/night-mode contrast, and orientation invalidation.

Physical validation for Phase 2 must record this phase's comparison, selection, final-diff, media, barcode, and Save behaviors. It does not complete or replace the separately skipped Phase 1 timing acceptance.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | None | Not applicable — shadcn is not initialized and this is not a React project |
| Third-party registries | None | Not applicable — no registry components are permitted for this phase |

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
