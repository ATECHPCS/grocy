# MISTAKES.md

Repo-specific, code-level traps for this Grocy fork. Cross-project facts live in the `code-memory`
store, not here.

## Enforced Rules (check every task)

- **GREP-01** — When a test greps a single method's body for a required/forbidden idiom (BEGIN
  IMMEDIATE / COMMIT / ROLLBACK counts, forbidden PDO txn tokens), slice the body between
  `public function X` and the NEXT `function` declaration and grep only that slice; never grep the whole
  file. And never write a banned keyword (UPDATE/DELETE/REPLACE/BEGIN IMMEDIATE) adjacent to the guarded
  table/identifier name in a docblock or comment — source-grep gates match prose too. (hits: 6)

## Patterns (promote at 3 hits)

- To force a genuine mid-transaction write-throw (apply OR rollback), target the write path exclusively
  with a `BEFORE INSERT`/`BEFORE UPDATE` trigger on `grocy_ai_taxonomy_classifications` that
  `RAISE(ABORT)` for one product_id — reads (and TOCTOU/optimistic-concurrency re-reads) still pass, so
  the throw lands after an earlier item already wrote in-txn. Note the taxonomy write is an
  `INSERT ... ON CONFLICT` upsert: a fresh product resolves to INSERT, a re-written product (e.g. a
  rollback over an applied row) resolves to UPDATE — install BOTH triggers to be safe. (hits: 2)

- SQLite `total_changes()` is NOT decremented by `ROLLBACK` — a rolled-back INSERT/UPDATE still bumps the
  counter. Prove byte-identical rollback with row-value equality (snapshot `SELECT *`), not a
  `total_changes()` delta. Only assert a `total_changes()` delta for committed no-op paths (an idempotent
  re-apply/re-rollback that executes zero INSERT/UPDATE/DELETE). (hits: 2)

- `custom/grocy_AI/tests/release-gate.sh` assumes the maintainer's macOS layout and toolchain:
  hardcoded `/Users/ian/Documents/Repos/...` repo paths, `shasum -a 256`, a bare `php`, a `HEAD`
  stable ref, and a literal portable-path count. Each assumption breaks on a single-checkout Linux
  workspace. → Any new gate work must resolve repos and refs via env override with a same-checkout
  fallback (`GROCY_AI_STABLE_REPO`, `GROCY_AI_STABLE_REF`), run PHP through `$php_runner`
  (`GROCY_AI_PHP`), hash through the `sha256()` helper, and derive counts from
  `portable-files.txt` rather than a literal. (hits: 2)

## Observations (first sightings)

- 2026-09-12: Adding a new bulk operation to `GrocyAiBulkService::RegisteredOperations()` breaks the
  arg-based `bulk-contract` + `bulk-registry` suites: both pin `array_keys(RegisteredOperations()) ===
  ['assign_taxonomy_leaf','set_unclassified']` AND require every member to declare `delegate_write ===
  'AssignProductTaxonomy'`. → Register a non-taxonomy op (e.g. `suggest_product_group`, native
  `products` write) via a `ResolveOperation()` branch + a payload-key descriptor, NOT in
  `RegisteredOperations()`; keep that method the closed taxonomy-only contract the tests pin. (hits: 1)

- 2026-09-12: A test assertion `($item['before_image']['product_group_id'] ?? 'x') === null` FALSE-fails
  a legitimately-null nullable JSON field, because `null ?? 'x'` yields `'x'`. before/proposed images
  store `{"key": null}` for the ungrouped/unclassified before-state. → Assert nullable decoded-JSON
  fields with `array_key_exists('key', $arr) && $arr['key'] === null`, never `?? default`. (hits: 1)

- 2026-09-12: 06-01-PLAN.md (and STATE.md) asserted "no direct SSH key to 10.10.0.156" and pushed a
  Komodo-terminal `docker cp` acquisition path. Both were wrong: 1Password vault `API/SSH/Tokens` holds
  item **`ssh:Personal Docker (102)`** (ed25519) that logs in as `root@10.10.0.156`, and moving ~20 MB
  through a Komodo Server terminal overflows periphery's 16 MiB core↔periphery frame cap and wedges the
  whole link (server → NotOk; fix = `systemctl restart periphery.service` on .156). → For prod Grocy
  access, SSH directly with that key and transfer files via `scp`, never through a Komodo terminal; trust
  the vault over stale plan notes. Snapshot tooling lives in `custom/grocy_AI/bin/snapshot-refresh.sh`.
  (hits: 1)

- 2026-09-02: A module service that must call a core Grocy class (e.g. `StockService::AddProduct`) can't
  reference it eagerly — `run.php` dispatch modes don't register Grocy's Composer autoloader, so a
  `\Grocy\Services\StockService::CONST` or `new`/`GetInstance()` evaluated in the hot path fatals the
  standalone unit test with "class not found". → Inject the core dependency (constructor seam) so tests
  pass a fake, use a literal for stable native values (`'purchase'`), and reach the real class ONLY behind
  a null-coalesce that a fake short-circuits (`$this->Stock ?? StockService::GetInstance()`). (hits: 1)

- 2026-08-29: Ran the module suite with the default `php` and got misleading results. `composer.json`
  requires `8.5.*` and the vendor dir is `packages/`, but `/usr/bin/php` on this box is **8.4.25**;
  `php8.5` is the correct interpreter. → Always invoke `php8.5` for `custom/grocy_AI/tests/run.php`,
  `php -l`, and the release gate (`GROCY_AI_PHP=php8.5 bash custom/grocy_AI/tests/release-gate.sh …`).
  (hits: 1)

- 2026-08-29: Adding a real `grocy_ai_conversion_activation_evidence` table to
  `GrocyAiConversionMigration::Bootstrap()` broke `conversion-resolution` and
  `conversion-product-status` with "table already exists". Those Plan 04-05/04-06 fixtures had
  pre-created a **placeholder** table of the same name with a different shape, using a bare
  `CREATE TABLE` after Bootstrap runs. → Before adding any `grocy_ai_conversion_*` table, grep
  `custom/grocy_AI/tests/conversions.php` for that name; earlier plans plant same-named spy tables.
  (hits: 1)

- 2026-08-29: Assumed a universal (`product_id IS NULL`) conversion row would produce a matching
  `product_id IS NULL` row in `cache__quantity_unit_conversions_resolved`. It does not.
  `migrations/0225.sql` rebuilds the cache from `quantity_unit_conversions_resolved`, which is
  **product-scoped**: a universal rule surfaces as resolved rows per product. The same
  `quantity_unit_conversions_INS` trigger also auto-inserts the **inverse** row, so N inserted
  universal rows become 2N native rows. → Never assert conversion cache/row counts from reasoning;
  run the fixture and read the actual rows first. (hits: 1)

- 2026-08-30: A native-safety snapshot over `sqlite_master` flagged a phantom "created object" after
  bootstrapping a module migration. Cause: any table declared `INTEGER PRIMARY KEY AUTOINCREMENT`
  makes SQLite create the internal `sqlite_sequence` table on first such table. → When asserting a
  bootstrap creates/drops no native object, exclude `name LIKE 'sqlite_%'` internals from the
  `sqlite_master` diff (the `grocy_ai_bulk_*` tables use AUTOINCREMENT). (hits: 1)

- 2026-08-30: `run.php` only `require_once`s the Phase 1-4 `src/*` classes; a new test module
  (`bulk.php`) whose classes live in new `src/*` files must require them itself or `class_exists()`
  stays false and the test reports "not implemented". → A new `tests/<mode>.php` must `require_once`
  its own `src/*` dependencies (guarded by `is_file`), mirroring the run.php top block. (hits: 1)

- 2026-08-29: A CLI that builds its evidence bundle from the same document the service re-reads is
  self-consistent, so a tampered document promoted successfully. → When one component validates
  another's input against a file, at least one immutable anchor must live outside that file. Fixed by
  pinning `CHARACTERIZATION_FACTS_SHA256` in `GrocyAiConversionService`. (hits: 1)

