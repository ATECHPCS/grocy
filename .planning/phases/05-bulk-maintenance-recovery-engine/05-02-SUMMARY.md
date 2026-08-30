---
phase: 05-bulk-maintenance-recovery-engine
plan: "02"
subsystem: bulk-engine-schema
tags: [php, sqlite, migration, namespaced, append-only, native-safe]
provides:
  - idempotent namespaced bootstrap for grocy_ai_bulk_plans, grocy_ai_bulk_plan_items, and the append-only grocy_ai_bulk_audit ledger
  - require_once wiring of the migration in module bootstrap order
key-files:
  created:
    - custom/grocy_AI/src/GrocyAiBulkMigration.php
    - .planning/phases/05-bulk-maintenance-recovery-engine/05-02-SUMMARY.md
  modified:
    - custom/grocy_AI/routes.php
    - custom/grocy_AI/tests/bulk.php
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The three bulk tables carry exactly the Plan 05-01 DTO columns in DTO order; status/selected/outcome use CHECK constraints matching the closed vocabularies."
  - "The migration creates rows only (no row-rewrite/row-removal path), so grocy_ai_bulk_audit is append-only by construction."
  - "Bootstrap copies the GrocyAiConversionMigration idiom (transaction guard, module migration ledger, CREATE TABLE IF NOT EXISTS, INSERT OR IGNORE) and touches no native Grocy table or trigger."
requirements: [BULK-01, BULK-02, BULK-08]
---

# Phase 05 Plan 02: Inactive Namespaced Bulk Schema Summary

**The module owns an idempotent, native-safe, append-only bulk schema carrying every plan/plan-item/audit field the engine needs, wired into bootstrap with zero native-table impact.**

## Accomplishments

- Added `GrocyAI\Services\GrocyAiBulkMigration` (`VERSION = 'v1'`) with a `Bootstrap(PDO)` that mirrors the conversion migration idiom exactly and creates `grocy_ai_bulk_plans`, `grocy_ai_bulk_plan_items`, `grocy_ai_bulk_audit`, and the `grocy_ai_bulk_migrations` version ledger.
- Defined each table with exactly the Plan 05-01 DTO columns in order; `grocy_ai_bulk_plans.status`, `grocy_ai_bulk_plan_items.selected`/`outcome`, and `grocy_ai_bulk_audit.outcome` use CHECK constraints matching the closed vocabularies; `grocy_ai_bulk_plan_items.plan_id` and `grocy_ai_bulk_audit.plan_id`/`plan_item_id` foreign-key their parents; `module_version` is a per-row column.
- Wired `require_once __DIR__ . '/src/GrocyAiBulkMigration.php';` into `routes.php` immediately after the conversion service require and before the controller requires; added no route (deferred to 05-11).
- Added the `bulk-schema` test proving double-bootstrap idempotency, exact contract columns, an append-only ledger (no UPDATE/DELETE path in the migration source), the plan_id foreign key, native-table safety via before/after schema+row snapshots, and correct routes.php require ordering.
- Per MISTAKES.md: grepped `conversions.php`/`taxonomy.php` for a same-named `grocy_ai_bulk_*` spy table — none exists; proved native-safety by snapshot-diffing actual rows, not by reasoning.

## Verification

- `grep -n "grocy_ai_bulk" custom/grocy_AI/tests/conversions.php custom/grocy_AI/tests/taxonomy.php` — no pre-existing spy table.
- `php8.5 custom/grocy_AI/tests/run.php bulk-schema` — `Bulk schema tests passed`.
- `php8.5 -l` — clean on `GrocyAiBulkMigration.php`, `bulk.php`, `routes.php`.
- `bulk-contract` / `bulk-invariants` still exit 1 (RED as designed until later plans land).
- Phase 1-4 regression — default suite, `taxonomy-*`, and `conversion-*` modes all pass.

## Decision and next step

The schema is inactive: no service writes to it yet. Note: the CHECK constraint discovery — `AUTOINCREMENT` makes SQLite create an internal `sqlite_sequence` table, so native-safety snapshots must exclude `sqlite_%` internals. Plan 05-03 implements zero-mutation `GeneratePlan` persisting only these module tables.
