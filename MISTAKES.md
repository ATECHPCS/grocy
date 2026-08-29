# MISTAKES.md

Repo-specific, code-level traps for this Grocy fork. Cross-project facts live in the `code-memory`
store, not here.

## Enforced Rules (check every task)

*(none promoted yet)*

## Patterns (promote at 3 hits)

*(none yet)*

## Observations (first sightings)

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

- 2026-08-29: A CLI that builds its evidence bundle from the same document the service re-reads is
  self-consistent, so a tampered document promoted successfully. → When one component validates
  another's input against a file, at least one immutable anchor must live outside that file. Fixed by
  pinning `CHARACTERIZATION_FACTS_SHA256` in `GrocyAiConversionService`. (hits: 1)

- 2026-08-29: `custom/grocy_AI/tests/release-gate.sh` was written for the macOS layout — hardcoded
  `/Users/ian/Documents/Repos/...` paths and `shasum -a 256`. On Linux both fail. Its Phase 2
  `candidate|predeploy|evidence` modes also hardcode `[ "$portable_count" -eq 12 ]` while
  `portable-files.txt` now has 35 paths. → New gate subcommands must resolve repos via env override
  with a same-checkout fallback, use a `sha256sum`/`shasum` helper, and derive counts from the
  manifest rather than a literal. (hits: 2 — hit again in 04-10: the taxonomy gate read the stable
  tree from `HEAD` and invoked a bare `php`; both now use `$stable_ref` and `$php_runner`.)
