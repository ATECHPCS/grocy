# Quick Capture PWA Design

## Status

Approved for implementation planning on 2026-08-24. This is the proposed new
Phase 5, after Phase 4: Reusable Conversion Model. The current Bulk Maintenance
& Recovery Engine moves to Phase 6 and later roadmap phases increment by one.

## Goal

Make adding household inventory from a phone or hardware barcode scanner fast
without surrendering human control: a user scans a GTIN, reviews enriched
product data once, and explicitly confirms either product creation alone or
product creation plus a purchase.

## Scope

The feature provides an installable, live-connection-only PWA at
`/grocy-ai/quick-capture`.

It supports:

- Camera scanning over HTTPS through Grocy's existing ZXing barcode capability.
- Keyboard-wedge USB and Bluetooth scanners that enter a GTIN and submit with
  Enter; typed GTIN entry follows the identical validation path.
- Local canonical GTIN ownership lookup before provider enrichment.
- Open Food Facts and Barcode Federation evidence normalized by the Grocy AI
  companion and exposed only through a strict Grocy AI contract.
- A compact, editable review of title, brand, existing product group,
  product-scoped package conversion, image, and structured nutrition facts.
- Quick Add, which creates only the selected product data, and Quick Purchase,
  which creates the selected product data and then performs one purchase.
- Per-device remembered defaults for purchase amount, price, best-before date,
  location, and shopping location, always shown before confirmation.

Out of scope:

- Offline scans, queued writes, background synchronization, or anonymous use.
- Direct browser requests to external providers or exposure of provider bodies,
  URLs, or credentials.
- Automatically creating product groups, taxonomy assignments, universal
  conversion rules, recipes, stock locations, or shopping locations.
- Automatic save or purchase without the user pressing a confirmation button.
- Nutrition, dietary, allergen, or medical recommendations; nutrition is
  provider-sourced product reference data only.
- Editing existing product conversions. Phase 6 owns reviewed cleanup.

## Existing Contracts Preserved

- `GrocyAiGtin` remains the one GTIN validator and canonical-owner predicate.
- An existing barcode owner suppresses provider enrichment and routes only to
  that existing product's Quick Purchase review.
- Enrichment lookup, image loading, conflict resolution, cancellation, retries,
  and navigation are zero-write actions.
- A new barcode attaches through the established normal Grocy product Save
  continuation, with duplicate re-resolution and at-most-once attachment.
- A reusable/universal conversion remains inactive until the Phase 4
  `ActivateVerifiedRuleset` evidence gate permits projection. This feature can
  save only a selected product-scoped package/count conversion.
- External data remains a reviewable suggestion and uses Grocy's existing
  authentication, `MASTER_DATA_EDIT`, and `STOCK_PURCHASE` permission checks.

## Architecture

```text
Camera / hardware scanner / typed GTIN
              |
              v
        GTIN validation
              |
              v
    local barcode-owner resolution
        |                    |
  existing owner         unused barcode
        |                    |
        v                    v
Quick Purchase       Grocy AI enrichment contract
review                    |
                          v
                source agreement / conflict rules
                          |
                          v
                 editable selected-field review
                          |
                  +-------+--------+
                  |                |
                  v                v
             Quick Add       Quick Purchase
                  |                |
                  +--- normal Grocy save paths ---+
                                   |
                                   v
                    selected source-stamped nutrition record
```

### PWA shell

The custom module owns the route, Blade view, manifest, service worker,
page-specific JavaScript, and CSS. The service worker caches only the static
application shell and versioned local assets. It must not cache authenticated
API responses, enrichment results, product data, or write requests. A live
connection is required before lookup and before either confirmation action.

### Provider and contract boundary

The Grocy AI companion queries Open Food Facts and Barcode Federation. It
normalizes their data into a versioned envelope that Grocy validates in full
before returning anything to the browser. The envelope adds a bounded
nutrition structure: serving basis, nutrient key/value/unit, source identity,
source reference/version, and retrieval timestamp. Unknown members, duplicate
JSON keys, raw URLs, invalid units, malformed nutrient values, unsupported
sources, and unbounded payloads reject the entire response as one redacted
contract error.

The browser receives no raw provider payload and never selects a source by
posting provider-defined data. It posts only server-issued suggestion IDs and
the user's selected local values; the server revalidates IDs, source metadata,
and target shapes before persistence.

### Suggestion selection and conflicts

For a new product, high-confidence values that agree across the accepted
sources are selected by default. All controls remain editable and
deselectable. A disagreement in title, brand, package size, product-group
mapping, conversion factor, nutrition value, or image results in a visible
conflict state. Conflicting values are displayed with provenance but none is
preselected. A user may select exactly one displayed value or enter a local
value. A missing or low-confidence provider result leaves the local field
empty and editable.

Provider categories map only through a maintained, closed mapping to active
existing Grocy product groups. No mapping leaves the group empty; no provider
label can create, rename, or activate a group. Phase 3 taxonomy evidence
remains informational and may not assign a taxonomy leaf automatically.

### Persistence

The feature introduces no general-purpose module mutation endpoint and no
arbitrary CRUD or SQL input. Confirmation invokes the existing authenticated
Grocy product, barcode, image, conversion, and stock-purchase persistence
paths with a narrow server-owned continuation for selected nutrition.

Quick Add creates or updates the selected product details and attaches the
scanned barcode once. Quick Purchase performs that same selected product work
then creates exactly one purchase transaction using the displayed values.
Product creation, barcode attachment, selected image attachment, nutrition
record storage, and purchase each have an idempotency/recovery token bound to
the current review. Retrying a partial completion reuses the known product and
transaction identities and retries only the missing operation; it never
duplicates a product, barcode, nutrition record, stock entry, or purchase.

Nutrition is a module-owned, source-stamped per-product record. It stores the
chosen serving basis, nutrient values and units, provider source and reference,
retrieval timestamp, and the selected contract version. Replacing nutrition
requires a later explicit confirmation; scan preview alone cannot write it.

### Device preferences

After a successful Quick Purchase, local browser storage records the selected
purchase defaults under a versioned `grocy_AI` key scoped to the authenticated
Grocy origin. Values are merely proposed on the next review; the user sees and
may change all of them. Browser storage contains no provider payload, nutrition
record, authentication secret, session cookie, product identifiers, barcode
history, or pending write queue.

## User Experience

The Scan state prioritizes a focused GTIN field, a camera button, an
instruction for hardware scanner input, and concise connection/status copy.
Submitting a valid code starts a request generation. Starting a new scan,
cancelling, leaving the page, or receiving a newer generation aborts/ignores
the older result.

The Review state shows the scanned code, local owner outcome, source and
confidence beside every external suggestion, conflict warnings, editable
fields, nutrition facts, and source timestamp. For an existing owner, it shows
only the existing product and Quick Purchase fields. For a new barcode, the
user chooses Quick Add or Quick Purchase before the final confirmation button
appears. The exact buttons are `Confirm Quick Add` and `Confirm Quick Purchase`.

Provider error, timeout, unavailable network, invalid GTIN, unsupported
barcode, missing group mapping, missing nutrition, and conflict outcomes are
actionable and leave manual input available. No outcome blocks a user from
manually completing a product unless an authoritative duplicate-owner check
shows another product owns the barcode.

## Error Handling and Recovery

| Condition | Result | Writes |
| --- | --- | --- |
| Invalid/unsupported GTIN | Explain validation result; allow a new scan/input. | None |
| Existing local owner | Route to existing-product Quick Purchase review. | None before confirmation |
| Provider unavailable/timeout | Show recoverable error and manual-entry review. | None |
| Provider conflict | Show source-labelled alternatives with no selection. | None |
| Newer/cancelled scan | Discard stale outcome and restore Scan state. | None |
| Product save succeeds; follow-on step fails | Show product identity and retry only the missing barcode, image, nutrition, or purchase step. | At most the already completed step |
| Duplicate/race on barcode attachment | Re-resolve authoritative owner; current product succeeds idempotently, different product routes/blocks. | No duplicate attachment |
| Repeated confirmation | Continue/recover the known operation; never duplicate stock or purchase. | At most once per operation |

## Security and Privacy

- Require authenticated access before owner lookup or enrichment; verify
  `MASTER_DATA_EDIT` before product-related work and `STOCK_PURCHASE` before a
  purchase confirmation.
- Preserve the companion's bounded request timeouts, redirects prohibition,
  response size/depth limits, source allowlist, trace redaction, and strict
  all-or-nothing JSON validation.
- Render provider-derived strings only through safe text APIs and fixed DOM
  structures. Do not add HTML or URL fields to the browser contract.
- Use server-issued opaque handles for selected package media and preserve
  existing MIME, signature, byte, redirect, and dimension limits.
- Do not log product names, barcodes, nutrition payloads, raw provider data,
  cookies, credentials, or device-preference contents in diagnostics.

## Acceptance Criteria

1. A phone camera, a keyboard-wedge scanner, and typed valid GTIN input reach
   the same validation/ownership/enrichment state machine.
2. The page is installable and its static shell loads after installation, but
   it clearly requires a live connection for lookup and confirmation.
3. A barcode owned locally never creates a duplicate product and reaches only
   Quick Purchase review for the owning product.
4. A new barcode presents a review-only draft. Before confirmation no product,
   barcode, conversion, nutrition record, image, stock, or purchase is written.
5. Source-agreeing, high-confidence fields start selected; every conflict is
   source-labelled and starts unselected.
6. Product groups come only from active local mapped groups. Absent mappings
   remain unassigned, and no provider value assigns a taxonomy leaf.
7. The user can confirm Quick Add or Quick Purchase exactly once; retries after
   partial completion resume only the incomplete operation.
8. Selected product-scoped package conversions save through normal Grocy
   persistence; the PWA cannot create or activate a reusable universal rule.
9. Nutrition stores only selected, validated, source-stamped records and is
   absent after cancelled, failed, or unconfirmed reviews.
10. The last confirmed Quick Purchase choices are proposed on the same device
    later, remain visible/editable, and do not store sensitive or provider data.
11. Mobile browser tests cover camera and scanner state changes, stale results,
    duplicate confirmation, zero-write previews, owner routing, conflict UI,
    recovery, permissions, and accessible keyboard operation.
12. PHP contract and integration tests cover schema validation, nutrition
    normalization, closed group mapping, server revalidation, idempotency, and
    redaction; stable/main parity gates cover module assets and the new route.

## Implementation Boundaries

Expected custom-module additions are a quick-capture controller/service,
versioned contract and nutrition validator, module migration, route and view,
manifest/service worker, PWA JavaScript/CSS, and PHP/Playwright fixtures.
Unavoidable upstream hooks must be minimal, guarded, recorded in
`CUSTOMIZATIONS.md`, and mirrored across maintained main and stable branches.

Phase 4's disposable dual-branch characterization remains the first production
implementation task. This PWA work does not begin until its conversion safety
contract is selected and gated.
