# Shopping capture follow-up verification

The follow-up addresses the independent Opus report from October 17 at `/tmp/grocy-shopping-opus-review.md`, against deployed release `8f6f5a18`.

## Changes

- Safe scan rejections retain camera input and typed curation. Ambiguous outcomes still lock scanning and preserve durable recovery.
- Multiple branded children require an explicit product choice; new products require an explicit inventory location. Approval names the UPC and selected product.
- Requested-amount edits recalculate remaining quantities without losing this trip's contributions. List, incarnation, product and unit continue to define progress identity, including existing serialized operations.
- Undo separates capture-only removal from guarded shared completion reversal. Deleted entries and unrelated native notes allow capture-only Undo. A stale shared reversal is never written: scan Undo can remove the capture contribution while explicitly preserving the current shared state. Remaining null-conversion scan/manual confirmations retain completion until the last one is undone.
- Operations show the actual product, clear conflict reasons and compatible list choices. Unsettled scans can be undone or explicitly retained as normal capture through an idempotent, permission-gated endpoint. Curation remains a separate permanent approval and its successful save remains visible if settlement needs attention.
- Quantities are rounded for display and labeled as this trip's contribution. Shared completion is labeled separately. Only explicitly targeted unknown scans focus the curation control; untargeted and off-list scans retain UPC input focus for keyboard scanners.
- A durable migration marker eliminates routine shopping bootstrap writes during polling. Trigger-presence reads retain repair after native table rebuilds; repair invalidates uncertain incarnations. Candidate rows are read once per list per poll, missing rows are skipped, and compatibility options are computed only for unsettled known scans.
- Asset token advances to `2.6.24`, customization marker to `ATECHPCS-grocy_AI-47`.

## Evidence

Regression cases were observed failing before their respective fixes: safe camera recovery, multiple-child choice, native amount progress, null-target scan/manual Undo, read under a reserved SQLite writer, mismatch recovery, state-based feedback, decimal/session labels and curation focus.

The PHP release gate passed the 286 module checks, three shopping suites, 14 affected capture/research/receipt suites, 78 JavaScript unit tests, syntax and diff checks. Native tests use a disposable full Grocy schema, verify integrity and assert zero stock/stock-log changes. Sixteen focused browser recovery cases passed across Chromium and WebKit before the final curation-focus change.

Full serial browser release verification and independent Opus follow-up review are recorded below when complete.

## Decisions and limits

Exact-product precedence over generic-parent matches remains as approved, even when the exact row is already collected. The UI distinguishes shared completion from this trip's progress; changing that precedence would alter the approved matching rule.

Physical phone/camera acceptance remains a separate human check. No production data or deployment was changed by this follow-up verification. Two previously documented legacy modes (`capture-contract`, `capture-invariants`) are pre-existing baseline failures; they are not the supported shopping or module gates.

## Opus round 2 follow-up

Opus confirmed the original five findings resolved, then found a focus regression and a remaining completed-row native-edit Undo dead end. Both were reproduced with failing tests and corrected. The new tests cover a second keyboard scan after unknown/off-list results, completed 3→4 native amount increments plus extra/earlier scan Undo, note edits, legacy NULL-shape manual confirmations, unit changes, trigger loss and reincarnation, disappearance during DTO reads, query scaling, manual labels, empty choices and a deleted target after catalog success.

The approved collection-history rule remains: purchase-review quantity edits do not imply items were returned to a shelf. Such edits retain collection history and require explicit shared-list changes. The former advice to edit purchase review as a workaround for scan Undo has been removed. Missing native triggers invalidate uncertain versions conservatively on repair; this can reset progress after a native table rebuild but prevents stale identity reuse.

Round 2 minor recovery items were addressed: manual row/action labels, safe Undo after identity change, trigger repair and missing-row races, hiding empty match controls, immediate clearing/refresh of rejected targets, confirmation summary before its button, promise-safe settlement after target removal, and bounded poll query growth. Exact-product precedence remains the approved rule.

## Independent final review

Claude Opus 5.5 round 3 reviewed `8f6f5a18..ad546514` and returned **GO for merge, functionality and UX**, with zero Critical or Important findings. It independently passed all 70 shopping browser tests, eight focus probes, three PHP shopping suites and nine full-schema scenarios. Full report: `/tmp/grocy-shopping-opus-followup-round3.md`.

The spec now describes capture-only Undo after native changes and the explicit pause for targeted unknown UPC curation. Continuous keyboard scanning retains input focus for untargeted unknown/off-list results; targeted unknowns require curation or an explicit keep/Undo choice before the next UPC.

Nonblocking deferred copy improvements: preserved-state guidance can suggest marking an already-open row remaining after a unit change; deleted targets still use generic 404 copy (the target clears and refreshes safely); stale manual Undo could give clearer next-step guidance, and undoing “Marked remaining” could say “list action” rather than “check-off”. These do not change ownership, stock or retry behavior.

Limits remain: no physical-phone, live production PHP UI or multi-device load acceptance was claimed. The supported test gates pass; the two old alternative contract/invariant modes retain their documented baseline failures.

## Final release result

The complete final browser suite passed **735/735** on frozen code `ad546514`: shard 1 ran 386/386 in 12.2 minutes; shard 2 ran 349/349 in 11.6 minutes. Each shard used one serial worker and its own fixture server/state. Chromium mobile/desktop and WebKit mobile were covered. Logs: `/tmp/shop-final-shard1.log`, `/tmp/shop-final-shard2.log`. This supersedes the earlier 725-case run stopped after review corrections.

The frozen PHP/Node gate passed again: 286 module checks, three shopping suites, 14 affected capture/research/receipt suites, 78 JavaScript tests, syntax and diff checks. Log: `/tmp/shop-final-php-frozen.log`. The exact native completed-row amount increment, extra scan Undo and earlier scan Undo regression also passed. Opus independently confirmed zero stock/stock-log rows in its nine full-schema scenarios.

Follow-up PR: https://github.com/ATECHPCS/grocy/pull/41 into `atech-release`. The code is ready for merge. Production deployment and physical-phone acceptance have not been performed in this follow-up. Main checkout restore documentation edits and the unmerged production worktree remain intact.
