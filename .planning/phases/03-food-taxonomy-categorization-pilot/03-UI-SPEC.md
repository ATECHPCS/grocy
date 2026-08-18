---
phase: 03
slug: food-taxonomy-categorization-pilot
status: approved
reviewed_at: 2026-08-17
shadcn_initialized: false
preset: not applicable
created: 2026-08-17
---

# Phase 3 — UI Design Contract

> Visual and interaction contract for a narrow, mobile-safe food-classification panel on an existing Grocy product edit page.

## Design System

| Property | Value |
|----------|-------|
| Tool | none — retain the established Grocy system |
| Preset | not applicable |
| Component library | Bootstrap 4.6.2 |
| Icon library | Font Awesome 6 Free |
| Font | Roboto (400, 500, 700 available; use 400 and 500 in this panel) |

The panel extends the existing `grocy_AI` card, form-control, alert, badge, and button patterns. No new framework, registry, or third-party component is allowed.

## Visual Hierarchy and Interaction

- **Placement:** On edit-product pages only, directly adjacent to the existing grocy_AI review surface; it is not a new global navigation destination and it must not alter ordinary product Save controls.
- **Focal point:** The current classification state is the first item in the card. A clear `Unclassified` badge is neutral, never styled as an error.
- **Evidence order:** Current state → suggested leaf and confidence → concise reason/provider evidence → manual broad-group/leaf controls → explicit action.
- **Choice model:** Show one expandable broad group at a time on narrow viewports; leaf choices are radio controls with visible text labels. Keep a persistent, labeled `Leave Unclassified` action.
- **Feedback:** After assignment, replace the state/evidence region in place with a bounded success alert and restore focus to the current-classification heading. Do not refresh, navigate, or submit the product form.
- **Staleness/failure:** Disable only classification controls during its narrow request. Preserve the current product form, ordinary Save controls, visible evidence, and manual selection after a recoverable failure.
- **Touch/accessibility:** All direct action controls have a 44px minimum target. Native radio/button semantics, visible text labels, programmatic alert status, and a 2px primary-color focus outline are required. Icon use is supplementary, never the only label.

## Spacing Scale

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Badge/icon and inline metadata gaps |
| sm | 8px | Control rows and compact evidence separation |
| md | 16px | Card body, default field separation, action gaps |
| lg | 24px | Separation between current state, evidence, and assignment sections |
| xl | 32px | Major card-to-form spacing |
| 2xl | 48px | Not used inside the compact product panel |
| 3xl | 64px | Not used inside the compact product panel |

Exceptions: none. Minimum 44px touch targets are an accessibility dimension, not a spacing token.

## Typography

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body | 16px | 400 | 1.5 |
| Label/metadata | 14px | 400 | 1.5 |
| Section heading | 20px | 500 | 1.2 |
| Card title | 24px | 500 | 1.2 |

## Color

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#ffffff` | Product page and card surfaces |
| Secondary (30%) | `#f8f9fa` / `#dee2e6` border | Evidence grouping and neutral separators |
| Accent (10%) | Bootstrap `var(--primary)` / `#007bff` | Card left border, current focus outline, selected leaf indicator, primary `Assign food type` action |
| Destructive | `#dc3545` | Only an unrecoverable classification error state; never `Unclassified` |

Accent reserved for: the selected taxonomy leaf, the specific assignment CTA, focus outline, and the card identity border. Secondary/outline buttons serve `Leave Unclassified` and non-mutating navigation.

Night mode must use the existing `.night-mode` patterns; do not introduce hard-coded light-only text colors.

## Copywriting Contract

| Element | Copy |
|---------|------|
| Primary CTA | `Assign food type` |
| Explicit safe action | `Leave Unclassified` |
| Current-state heading | `Food classification` |
| Evidence heading | `Why this type is suggested` |
| Empty state heading | `No food type suggested` |
| Empty state body | `No accepted evidence is available. You can leave this product Unclassified or choose a household food type.` |
| Error state | `This food type could not be saved. Check the product is still available, then try again. Your product details and stock were not changed.` |
| Success state | `Food type updated. Product details, stock, recipes, prices, history, and location were not changed.` |
| Destructive confirmation | Replacing a current leaf or setting Unclassified is a reversible classification change, not deletion; show the selected before/after values in the action summary and require the explicit action button, with no modal confirmation. |

Provider labels are evidence, not final identities. Display local taxonomy label and stable ruleset version separately; never expose raw provider payloads, URLs, credentials, or diagnostics.

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| Existing Grocy assets | Bootstrap 4.6.2, Font Awesome 6, native controls | not required — repository-pinned assets only |

## Checker Sign-Off

- [x] Dimension 1 Copywriting: PASS
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-08-17
