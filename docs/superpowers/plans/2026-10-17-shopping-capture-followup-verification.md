# Shopping capture follow-up verification

The follow-up addresses the independent Opus report from October 17 at `/tmp/grocy-shopping-opus-review.md`, against deployed release `8f6f5a18`.

## Changes

- Safe scan rejections retain camera input and typed curation. Ambiguous outcomes still lock scanning and preserve durable recovery.
- Multiple branded children require an explicit product choice; new products require an explicit inventory location. Approval names the UPC and selected product.
- Requested-amount edits recalculate remaining quantities without losing this trip's contributions. List, incarnation, product and unit continue to define progress identity, including existing serialized operations.
- Undo separates capture-only removal from guarded shared completion reversal. Deleted entries and unrelated native notes allow capture-only Undo. A stale shared reversal remains blocked with review guidance. Remaining null-conversion scan/manual confirmations retain completion until the last one is undone.
- Operations show the actual product, clear conflict reasons and compatible list choices. Unsettled scans can be undone or explicitly retained as normal capture through an idempotent, permission-gated endpoint. Curation remains a separate permanent approval and its successful save remains visible if settlement needs attention.
- Quantities are rounded for display and labeled as this trip's contribution. Shared completion is labeled separately. Targeted unknown scans focus the explicit curation control.
- A durable migration marker eliminates routine shopping bootstrap writes during polling.
- Asset token advances to `2.6.24`, customization marker to `ATECHPCS-grocy_AI-47`.

## Evidence

Regression cases were observed failing before their respective fixes: safe camera recovery, multiple-child choice, native amount progress, null-target scan/manual Undo, read under a reserved SQLite writer, mismatch recovery, state-based feedback, decimal/session labels and curation focus.

The PHP release gate passed the 286 module checks, three shopping suites, 14 affected capture/research/receipt suites, 78 JavaScript unit tests, syntax and diff checks. Native tests use a disposable full Grocy schema, verify integrity and assert zero stock/stock-log changes. Sixteen focused browser recovery cases passed across Chromium and WebKit before the final curation-focus change.

Full serial browser release verification and independent Opus follow-up review are recorded below when complete.

## Decisions and limits

Exact-product precedence over generic-parent matches remains as approved, even when the exact row is already collected. The UI distinguishes shared completion from this trip's progress; changing that precedence would alter the approved matching rule.

Physical phone/camera acceptance remains a separate human check. No production data or deployment was changed by this follow-up verification. Two previously documented legacy modes (`capture-contract`, `capture-invariants`) are pre-existing baseline failures; they are not the supported shopping or module gates.
