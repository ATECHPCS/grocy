# Shopping list capture verification

## Scope

The feature follows the approved shopping-list capture specification. Work is isolated on `codex/shopping-list-capture` from `c96b79a1`; the main checkout's restore notes and unmerged production worktree are preserved. No production sessions, products, shopping-list entries or stock were changed.

## Verified behavior

- A remembered native list is visible in capture. Exact products precede direct generic parents; targeted unknowns require explicit permanent UPC linking or branded-child approval.
- Durable operation IDs separate scans, catalog approval and collection settlement. Lost responses are rechecked without another scan or product creation. Stored recovery requests are restricted to this session's shopping endpoints.
- Verified conversions and barcode stock-unit package amounts determine collected quantities. Missing conversions require an explicit list-unit amount. Shared completion retains the native requested amount and row.
- Native edits advance monotonic row versions; tombstones and incarnation identity prevent stale Undo and progress reuse after row deletion/recreation. Undo preserves other shoppers' prior completion and permanent catalog links.
- Trip ownership, lifecycle and native shopping/master-data permissions are checked server-side. Stock changes remain confined to existing explicit purchase commit.
- Mobile controls have accessible names, keyboard support and at least 44px targets. The list rows scroll within a bounded panel; selecting a target brings the scanner into view. Unsaved curation and ambiguous operations participate in navigation safeguards.

## Evidence

The initial PHP core test failed with `Shopping capture service missing`; the initial API test failed with `Shopping capture API missing`. The first browser case failed because the shopping-list selector was absent. Core/API implementation and the new browser panel subsequently passed their focused gates.

Additional valid RED→GREEN cases pinned barcode multipack amounts, deleted/recreated row progress, and promotion of a local-only UPC draft into ordinary background research when later scanned without a target. No provider is called by these tests.

Current passing gates:

- `php8.5 custom/grocy_AI/tests/run.php`: 286 checks; standard absent real-data snapshot rehearsals are skipped by the existing suite.
- `php8.5 custom/grocy_AI/tests/run.php shopping-capture` and `shopping-capture-api`: all focused core and controller assertions passed.
- `php8.5 custom/grocy_AI/tests/run.php shopping-capture-native`: full repository Grocy schema, collection/Undo, integrity and zero stock writes passed. The rehearsal uses a new private temporary database and removes it afterward.
- `node --test public/custom/grocy_AI/*.test.js`: 78 passed, zero failures/skips.
- Focused browser gate: 34 passed across Chromium and WebKit. It covers quantity progress, explicit linking/creation, permissions, manual text entries, normal no-list scans, remembered selection, explicit amount, failed/ambiguous responses, reload recovery, navigation, late refresh ownership, restricted recovery endpoints, 320px layout and touch targets.
- Fourteen affected standalone capture/research/receipt scripts passed, including the isolated full native purchase rehearsal with idempotent commit retry.

The full serial browser release gate is running; its final count and independent branch review result will be added before completion. Asset token is `2.6.23`, customization marker `ATECHPCS-grocy_AI-46`; the portable module version is unchanged. Existing Docker directory copies include the new classes and script.

## Existing legacy failures

`php8.5 custom/grocy_AI/tests/run.php capture-contract` reports `EXPECTED_RED: capture.contract_shapes` because it expects only the original trip/line/audit tables and migration ledger. `capture-invariants` reports `EXPECTED_RED: capture.engine_invariants` for the old partial-commit expectation. Both fail with identical messages on the unchanged `atech-release` checkout at the baseline. They are not new regressions and were not weakened to accommodate this feature.

## Release limits

The code has not been merged or deployed. A physical-phone acceptance session remains a post-deployment gate for shopping-list selection, camera scans, unknown UPC curation and shared completion. No offline mode or household merging of partial quantities is included. Monitoring and off-server backup automation remain separate work.
