# Phase 4 Disposable Conversion Characterization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a deterministic, disposable main/stable characterization report that establishes the resolved-conversion cache contract without accessing household data or selecting a projection by inference.

**Architecture:** A fixture-only PHP harness creates temporary SQLite databases outside GROCY_DATAPATH, captures cache/trigger metadata and aggregate protected-consumer outcomes on each maintained branch, then deletes its databases. It records a checked-in redacted result that names both immutable branch revisions and either a smallest compatible projection contract or a blocking incompatibility.

**Tech Stack:** PHP 8.5, SQLite 3.40+, existing custom/grocy_AI standalone contract runner, bash/git worktree paths.

**Spec:** .planning/phases/04-reusable-conversion-model/04-CONTEXT.md

## Global Constraints

- Never open, copy, inspect, write, or connect to GROCY_DATAPATH, production Docker data, or household databases.
- Characterize only temporary SQLite fixtures outside every configured data path and delete them in a finally block.
- Capture only schema, trigger, row-key/factor/path aggregate metadata, protected-consumer comparisons, query-plan metadata, and immutable branch commits. Never record product names, GTINs, provider URLs, secrets, or raw database dumps.
- Cover cache and trigger behavior plus stock, recipe, purchase, consumption, price, transfer, meal-plan, and quantity-display output categories on both main and stable.
- Do not select an alternative projection when branch reports disagree or protected output changes. Record the blocker and keep reusable rules inactive.
- Do not write existing product-specific conversion rows. Generic universal native POST/PUT remains out of scope and cannot activate a rule.
- Keep all fork behavior in custom/grocy_AI; use tabs/next-line braces; run php -l for changed PHP.

---

## Task 1: Create and run the disposable dual-branch characterization gate

**Files:**
- Create: custom/grocy_AI/tests/conversion-characterization.php, custom/grocy_AI/tests/fixtures/conversion-characterization-main.json, custom/grocy_AI/tests/fixtures/conversion-characterization-stable.json, .planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md, .planning/phases/04-reusable-conversion-model/04-01-SUMMARY.md
- Modify: custom/grocy_AI/tests/conversions.php, custom/grocy_AI/tests/run.php

**Interfaces:**
- Consumes: main checkout root, stable checkout root, migrations/0208.sql, migrations/0225.sql, and deterministic non-household fixture manifests.
- Produces: php custom/grocy_AI/tests/run.php conversion-characterization and a report with main/stable commits, cache/trigger facts, protected-output parity, and selected projection or explicit blocker.

- [ ] **Step 1: Add failing fixture-contract coverage**

    $result = runConversionCharacterization($mainRoot, $stableRoot, $fixtureRoot, $blockedDataPath);
    characterizationAssert($result['main']['fixture_deleted'] === true, 'main fixture is deleted');
    characterizationAssert($result['stable']['fixture_deleted'] === true, 'stable fixture is deleted');
    characterizationAssert($result['protected_outputs']['equal'] === true, 'protected categories are equivalent');
    characterizationAssertNoPathPrefix($result['opened_paths'], $blockedDataPath, 'configured data path is never opened');
    characterizationExpectFailure('missing_branch_manifest');
    characterizationExpectFailure('fixture_path_inside_grocy_datapath');

- [ ] **Step 2: Run the focused test to prove it fails**

Run: php custom/grocy_AI/tests/run.php conversion-characterization

Expected: FAIL because neither the dispatch command nor disposable main/stable harness exists.

- [ ] **Step 3: Implement the disposable harness**

    function CharacterizeBranch(string $branchName, string $root, array $manifest, string $blockedDataPath): array
    {
        $temporaryDatabase = CreateOutsideBlockedPath($blockedDataPath);
        try
        {
            SeedDeterministicConversionFixture($temporaryDatabase, $manifest);
            return [
                'commit' => ImmutableCommit($root),
                'schema' => RedactedSqliteMaster($temporaryDatabase),
                'cache' => AggregateCacheDelta($temporaryDatabase),
                'protected_outputs' => ProtectedFixtureOutputs($temporaryDatabase),
                'query_plan' => QueryPlanMetadata($temporaryDatabase),
            ];
        }
        finally
        {
            DeleteTemporaryDatabase($temporaryDatabase);
        }
    }

Reject absent manifests, non-repository branches, missing migration/cache definitions, a fixture path at/below the configured data path, and unequal baseline/probe protected outputs. Snapshot sqlite_master cache/trigger definitions and baseline rows; exercise one native default and one product override per branch; compare row-key/factor/path aggregates and every protected category. Add conversion-characterization dispatch in run.php without altering Phase 1–3 dispatches.

- [ ] **Step 4: Execute, inspect, and document evidence**

Run:

    php custom/grocy_AI/tests/run.php conversion-characterization
    php -l custom/grocy_AI/tests/conversion-characterization.php
    php -l custom/grocy_AI/tests/conversions.php
    php -l custom/grocy_AI/tests/run.php
    rg -n "main|stable|selected projection|inactive|stock|recipe|purchase|consumption|price|transfer|meal-plan|quantity" .planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md

Expected: PASS only if both immutable branch reports agree and every protected output remains equivalent. The report must otherwise state the concrete blocker, preserve inactive state, and omit any projected implementation choice.

- [ ] **Step 5: Self-review and commit**

    git diff --check
    git add custom/grocy_AI/tests/conversion-characterization.php custom/grocy_AI/tests/conversions.php custom/grocy_AI/tests/run.php custom/grocy_AI/tests/fixtures/conversion-characterization-main.json custom/grocy_AI/tests/fixtures/conversion-characterization-stable.json .planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md .planning/phases/04-reusable-conversion-model/04-01-SUMMARY.md
    git commit -m "test: characterize dual-branch conversion cache"

## Plan Self-Review

- Scope covers only the handoff's first Phase 4 task: characterization and its evidence report.
- The harness is fixture-only and has direct assertions for no blocked-path access, deletion, protected output parity, and fail-closed manifests.
- No later projection, catalog, activation, UI, or household-data work is included.
