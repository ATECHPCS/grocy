# Optional generic parent verification — October 8, 2026

## Approved scope

The user approved automatic compatible existing-parent suggestions, standalone when a parent is absent/uncertain/incompatible, and optional parent creation during unknown-product review after approval. The implementation uses the existing capture research and approval endpoints, existing draft JSON, and existing transaction/audit/revision/checksum boundaries. No new schema, provider call, companion contract, stock booking path, worktree, or dependency is introduced.

Modes are `standalone`, `existing`, and `create`. A new parent proposal is the current reviewed product name plus `(generic)`, editable without removing flavor/preparation. Both confirmation prompts list the new parent and its same purchase/stock unit, and state no stock is added. Parent and scanned product are created immediately after the two confirmations. Receipt reconciliation and Commit purchase remain separate.

The parent uses the reviewed location/group/classification and child stock unit for both unit roles (implicit factor 1), no barcode, no ancestor, and no stock. Atomic approval stores both IDs in the existing audit. Identical retries return both IDs; changed confirmation rejects. Current catalog duplicate names are checked with Unicode case folding under `BEGIN IMMEDIATE`; duplicates need an existing choice or renamed proposal, never automatic attachment. Live existing-parent/unit validations and barcode uniqueness remain authoritative.

## Test-driven evidence

- New approval and draft-intent tests initially failed with `Invalid approval fields` / `Invalid draft changes` before implementation.
- Initial browser feature tests failed for missing Parent handling controls and missing actionable incompatibility copy.
- Product-name edit test initially proposed the old name; implementation now derives the proposal from the current reviewed name and retains explicit parent-name corrections.
- Actionable duplicate-parent API test initially received generic conflict feedback; only allowlisted safe validation messages now reach the user.
- Unicode child-name collision initially succeeded; transaction now rejects both parent and child case collisions consistently.
- Automatic-parent/unit-change test initially retained incompatible mode; it now defaults standalone without clearing the chosen stock unit. Explicit parent choices retain incompatibility feedback and Keep standalone recovery.

Intermediate full research runs were interrupted after concrete failures: newly overlapping label text required exact existing-parent selectors; a new success assertion initially targeted the generic error element instead of the persistent product review notice. These were test selector errors, corrected without weakening permission, unit, confirmation, or persistence assertions. An obsolete backend assertion expected an incompatible parent to erase the suggested stock unit; it now verifies the approved behavior of retaining explicit parent and unit for review.

## Verification commands and results

- `php8.5 custom/grocy_AI/tests/run.php` — 274 checks passed (real-data taxonomy snapshot absent; existing graceful skip reported).
- `php8.5 custom/grocy_AI/tests/capture_research_approval.php` — PASS; parent/child atomic rollback, no parent UPC/stock, compatible units, invalid child references/taxonomy, name collisions, changed/identical retry, barcode protection, and existing permission checks.
- `php8.5 custom/grocy_AI/tests/capture_research_drafts.php` — PASS; persisted parent mode/name/revision, edit ownership, compatible candidate bounds, standalone default, legacy automatic mismatch, explicit clearing and provider precedence.
- `php8.5 custom/grocy_AI/tests/capture_research_queue.php` — PASS; existing worker unit safeguards and preserved explicit parent/unit review.
- `php8.5 custom/grocy_AI/tests/capture_research_worker_api.php` — PASS.
- `php8.5 custom/grocy_AI/tests/capture_research_queue_prerequisites.php` — PASS.
- `php8.5 custom/grocy_AI/tests/capture_research_backfill.php` — PASS.
- `php8.5 custom/grocy_AI/tests/receipt_commit.php` — PASS; existing stock/readiness/checksum and retry gates.
- `php8.5 custom/grocy_AI/tests/receipt_rehearsal.php` — PASS in memory; explicit fixture commit/retry only, no live stock.
- `node --test public/custom/grocy_AI/capture.test.js public/custom/grocy_AI/capture-review.test.js` — 8 passed.
- `npm --prefix custom/grocy_AI/tests/browser test -- --grep @optionalparent --project chromium-mobile --workers=1` — 4 passed after targeted red→green cycles.
- `npm --prefix custom/grocy_AI/tests/browser test -- specs/product-research.spec.js --workers=1` — 108 passed (3.5 minutes), Chromium mobile/desktop and WebKit mobile. Includes all four new parent scenarios plus existing permissions, missing fields, dirty receipt edits, source safety, explicit clearing, required markers, and success feedback.
- PHP lint on all three changed service/controller files, JavaScript syntax check, and `git diff --check` — clean.

## Release and limitations

Both capture/review Blade hooks and browser fixtures use asset token `2.6.21`; exact module tests verify synchronization. Customization marker is `ATECHPCS-grocy_AI-44`, portable module version unchanged. Existing shared scoped purchase-flow styling handles the new optional controls.

No production database writes, paid research calls, browser stock commits, or live catalog approvals were made. Full unrelated browser release suite was not rerun; verification is bounded to affected research behavior and existing stock/backend gates. Independent review and production rollout belong to the controller agent after this implementation commit. Physical iPhone acceptance remains pending: refresh, inspect optional parent modes, verify missing required parent name feedback, review both confirmation prompts, and approve only a real intended catalog creation. Keep Commit purchase untouched until the receipt purchase is ready.
