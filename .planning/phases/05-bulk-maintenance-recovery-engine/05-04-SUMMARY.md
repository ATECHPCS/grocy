---
phase: 05-bulk-maintenance-recovery-engine
plan: "04"
subsystem: bulk-operation-registry
tags: [php, registry, fail-closed, transaction-nesting, native-authority]
provides:
  - closed named-typed-operation registry resolving only the two shipped taxonomy operations
  - AssignProductTaxonomy transaction-nesting guard (optional $joinExistingTransaction, default false)
key-files:
  created:
    - .planning/phases/05-bulk-maintenance-recovery-engine/05-04-SUMMARY.md
  modified:
    - custom/grocy_AI/src/GrocyAiBulkService.php
    - custom/grocy_AI/src/GrocyAiTaxonomyService.php
    - custom/grocy_AI/tests/bulk.php
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The registry is a private/closed server-side map; RegisteredOperations exposes metadata and ResolveOperation returns a bound delegate (callable) or exactly one unknown_operation blocker with no callable, no partial resolution, and no fallback."
  - "Delegates build only the exact AssignProductTaxonomy assignment key sets and pin ruleset_version to GrocyAiTaxonomyMigration::VERSION server-side, never from the request."
  - "BLOCKER-1 fixed: AssignProductTaxonomy gains an optional bool $joinExistingTransaction = false; when true it skips its own BEGIN/COMMIT/rollBack (caller owns the transaction) while the single INSERT ... ON CONFLICT upsert is unchanged. Default false is byte-identical for the one controller caller and the taxonomy test callers."
requirements: [BULK-05]
---

# Phase 05 Plan 04: Closed Typed-Operation Registry Summary

**The engine dispatches only a closed set of named typed operations that delegate to the shipped taxonomy write; arbitrary CRUD, field targets, and SQL are refused fail-closed with one bounded blocker, and conversion-cleanup operations remain deferred to Phase 6.**

## Accomplishments

- Added `RegisteredOperations()` (closed map: `assign_taxonomy_leaf`, `set_unclassified`, each naming the `AssignProductTaxonomy` delegate and its fixed assignment key set) and `ResolveOperation()` to `GrocyAiBulkService`.
- `ResolveOperation` returns a bound delegate over the shipped taxonomy write for the two named operations only; the delegate builds the exact assignment shape, pins `ruleset_version` server-side, and joins the caller's outer transaction. Every other operation (free-form entity/field, CRUD verb, SQL string, empty string) returns exactly one `unknown_operation` blocker and no callable.
- Fixed BLOCKER-1: `AssignProductTaxonomy(int, array, bool $joinExistingTransaction = false)` — with `true` it runs the identical validation and the same single `INSERT ... ON CONFLICT` upsert but issues no transaction of its own; with the default `false` it behaves exactly as before. Confirmed by grep the sole production caller (`GrocyAiApiController.php:157`) and the `taxonomy.php` callers use the 2-arg form.
- The bulk registry introduces no new SQL or write statement; the taxonomy service keeps its single classification INSERT.
- Added the `bulk-registry` test proving closed resolution, correct delegation and returned DTOs, the single-INSERT/nesting-guard invariants, nesting-awareness (a `join=true` delegate is undone by an outer rollback and raises no nested-BEGIN error, while the default 2-arg form still commits its own transaction), and fail-closed rejection with a native-write spy (snapshot + `total_changes` delta of zero) for every rejected operation.

## Verification

- `php8.5 custom/grocy_AI/tests/run.php bulk-registry` — `Bulk registry tests passed`.
- `php8.5 -l` — clean on `GrocyAiBulkService.php` and `GrocyAiTaxonomyService.php`.
- Taxonomy suite regression — `taxonomy-schema`, `taxonomy-api`, `taxonomy-assignment`, `taxonomy-validation`, `taxonomy-production-paths` all pass after the guard change.
- `bulk-contract` now GREEN (all shapes satisfied); `bulk-invariants` still exits 1 (RED by design — apply/rollback/export land in later plans beyond wave 4).
- `bulk-schema`, `bulk-generate` still green; full default suite and all `conversion-*` modes pass.

## Decision and next step

Waves 1-4 are complete: the engine has an immutable, checksum-sealed, zero-mutation dry-run and a closed, fail-closed operation registry whose only members delegate to the shipped taxonomy write, ready to be nested inside a single apply transaction. Plan 05-05 onward (out of this scope) implements selection, the single-transaction optimistic-concurrency apply, the audit ledger writes, rollback preview, export, and the API/gate wiring that turn `bulk-invariants` green.
