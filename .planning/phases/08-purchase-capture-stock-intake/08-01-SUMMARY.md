---
phase: 08-purchase-capture-stock-intake
plan: "01"
subsystem: purchase-capture-contract
tags: [php, sqlite, tdd, red-gate, contract, schema]
provides:
  - RED capture-engine contract suite fixing the trip/line/audit DTO shapes, closed vocabularies, coalescing key, and the six behavioral invariants
  - inactive, namespaced, idempotent grocy_ai_capture_* schema created via CREATE TABLE IF NOT EXISTS with its own migration ledger
key-files:
  created:
    - custom/grocy_AI/src/GrocyAiCaptureMigration.php
    - custom/grocy_AI/tests/capture.php
    - custom/grocy_AI/tests/fixtures/capture-cases.json
    - .planning/phases/08-purchase-capture-stock-intake/08-01-SUMMARY.md
  modified:
    - custom/grocy_AI/tests/run.php
key-decisions:
  - "The trip/line/audit DTO key sets, the closed trip-status (open/reviewing/committed), line-status (known/unknown/conflict) and line-outcome (applied/conflict/skipped) vocabularies, and the coalescing key are fixed in fixtures AND pinned as hardcoded expectations in the test, so a fixture edit alone cannot widen the contract."
  - "The schema is created in THIS wave (Task 2), so the migration-shape/idempotency/native-safety/coalescing assertions run GREEN now; every ENGINE assertion stays RED behind a single missing-GrocyAiCaptureService guard until later Phase 8 plans land the service."
  - "Both modes route their RED through one engine-surface guard (captureEngineMissingMethods over StartTrip/ScanIntoTrip/LoadTrip/UpdateLine/SetTripDefaults/SetStatus/ChecksumForTrip/CommitTrip), so each emits exactly one EXPECTED_RED marker and the gate shrinks as the surface is implemented."
requirements: [CAP-01, CAP-02, CAP-06, CAP-07, CAP-08]
---

# Phase 08 Plan 01: Purchase-Capture RED Contract + Schema Summary

**The Phase 8 capture contract is locked as a failing (RED) suite, and the inactive namespaced `grocy_ai_capture_*` schema stands up green, before any capture engine code exists.**

## RED test list

Registered in `run.php` as two argv-dispatched modes (mirroring the `bulk-*` conditional pattern), each emitting exactly one standalone `EXPECTED_RED:` marker:

- **`capture-contract`** → `EXPECTED_RED: capture.contract_shapes`. Pins the closed trip/line/audit DTO key sets, the trip-status / line-status / line-outcome vocabularies, and the coalescing key `(trip_id, COALESCE(canonical_gtin, scanned_barcode))`. Then proves the wave's schema GREEN: double-bootstrap idempotency (exactly the trip/line/audit tables + one version row), column shapes equal to the DTOs, a working coalescing UNIQUE index (a second same-canonical scan is rejected), an append-only migration source (no UPDATE/DELETE/DROP/ALTER), and native-safety (no native object created/altered/dropped, no native row mutated, `sqlite_%` internals excluded). RED at the missing `GrocyAiCaptureService`.
- **`capture-invariants`** → `EXPECTED_RED: capture.engine_invariants`. Pins the closed vocabularies, proves the known/unknown split resolves through the shipped `GrocyAiBarcodeService::ResolveOwner` (unused → ownerless unknown line; owned → known line owner), and fixes the eight behavioral invariant claims (Q5 lifecycle → `SetStatus`; Q6 coalescing + Q3 known/unknown → `ScanIntoTrip`; Q10 review re-resolve → `LoadTrip`; Q12 checksum-before-write, Q12 per-item `applied_at` idempotency, Q9 partial commit, and the stock-write boundary → `CommitTrip`). RED at the missing engine.

## Schema / migration reference

`custom/grocy_AI/src/GrocyAiCaptureMigration.php` (`VERSION = 'v1'`) mirrors `GrocyAiBulkMigration::Bootstrap` exactly: transaction guard, own `grocy_ai_capture_migrations` ledger, `CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE` of the version. Tables (matching 08-DESIGN.md):

- `grocy_ai_capture_trips` — CHECK `status IN ('open','reviewing','committed')`, trip-level location/store defaults, and the committed `transaction_id`/`committed_at`/`checksum`.
- `grocy_ai_capture_lines` — CHECK `status IN ('known','unknown','conflict')`, `selected IN (0,1)` default 1, nullable `outcome IN ('applied','conflict','skipped')`, plus the `grocy_ai_capture_lines_coalesce_idx` UNIQUE index on `(trip_id, COALESCE(canonical_gtin, scanned_barcode))`.
- `grocy_ai_capture_audit` — append-only actor/action ledger carrying the grouping `transaction_id`.

No native Grocy table is touched. The migration is not yet wired into `routes.php` (that belongs to a later wave, mirroring bulk 05-02).

## Verification

- `assert-expected-red.sh 'EXPECTED_RED: capture.contract_shapes' -- php8.5 run.php capture-contract` — one clean marker, exit 1 (RED_OK).
- `assert-expected-red.sh 'EXPECTED_RED: capture.engine_invariants' -- php8.5 run.php capture-invariants` — one clean marker, exit 1 (RED_OK).
- Raw runs confirm both modes reach the engine guard (all pre-guard schema + barcode-resolution assertions pass), so the RED is the missing engine, not a broken migration.
- `php8.5 -l` clean on `GrocyAiCaptureMigration.php`, `capture.php`, `run.php`; `capture-cases.json` parses as JSON.
- Regression: default suite (`All 127 grocy_AI checks passed`), `bulk-contract`, and `bulk-schema` still pass.
- `git status`: only `tests/run.php` modified; `src/GrocyAiCaptureMigration.php`, `tests/capture.php`, `tests/fixtures/capture-cases.json` new; no other `src`/`public`/`routes.php` change.

## Decision and next step

The capture contract is immovable: later Phase 8 waves are graded against these DTO shapes, vocabularies, and invariants. The next wave implements `GrocyAiCaptureService` (`StartTrip`/`ScanIntoTrip`/`LoadTrip` first) to turn the `capture-contract` engine assertions green, and wires the migration into `routes.php`.
