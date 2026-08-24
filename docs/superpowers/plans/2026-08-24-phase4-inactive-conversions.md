# Phase 4 Inactive Conversion Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an inactive, dimension-safe reusable conversion catalog while preserving native product-scoped conversions and blocking every reusable universal write before cache mutation.

**Architecture:** Module-owned tables/services validate a fixed mass/volume catalog and inactive revisions. A minimal guarded hook in Grocy's GenericEntityApiController runs the same validation before native quantity-unit conversion create/update, allowing valid product-scoped conversions but failing closed for reusable scope. It never projects or activates a universal rule.

**Tech Stack:** PHP 8.5, SQLite 3.40+, existing Grocy REST generic entity controller, standalone custom contract tests.

**Spec:** .planning/phases/04-reusable-conversion-model/04-CONTEXT.md

## Global Constraints

- Phase 04-01 evidence is required and is already committed on this branch; preserve its cache/trigger contract.
- Do not activate, project, or write gate-created universal cache rows. Only the later ActivateVerifiedRuleset command may do so.
- Keep existing product-specific conversion rows unchanged. Valid product-scoped package/count and measured-density native POST/PUT retain normal Grocy Save.
- Reusable universal/profile package/count, cross-dimension, cyclic, conflicting, stale, non-finite, zero, or unapproved candidates fail closed before native row/cache mutation.
- Catalog contains only mg, g, kg, oz, lb and mL, L, tsp, tbsp, cups, fl oz, pints, quarts, gallons; no temperature or length.
- Store full precision and source/version; display rounding remains Grocy-owned.
- Keep module code under custom/grocy_AI, make the core controller hook minimal/documented, use tabs and next-line braces, and run php -l on changed PHP.

---

## Task 1: Create the inactive catalog and deterministic validator

**Files:**
- Create: custom/grocy_AI/src/GrocyAiConversionMigration.php, custom/grocy_AI/src/GrocyAiConversionService.php
- Modify: custom/grocy_AI/routes.php, custom/grocy_AI/tests/conversions.php, custom/grocy_AI/tests/run.php

**Interfaces:**
- Produces: GrocyAiConversionMigration::Bootstrap(PDO $pdo): void and GrocyAiConversionService::ValidateNativeConversionBeforeWrite(array $candidate, ?int $objectId): array.
- Validation result is a fixed-key DTO: status, scope, blockers, factor, dimension, source_version, and inactive_revision_id.

- [ ] **Step 1: Write failing conversion-rule tests**

    $result = $service->ValidateNativeConversionBeforeWrite([
        'product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'
    ], null);
    conversionAssertSame('inactive', $result['status']);

    conversionAssertBlocked(['factor' => '0'], 'factor_non_positive');
    conversionAssertBlocked(['factor' => 'NAN'], 'factor_not_finite');
    conversionAssertBlocked(crossDimensionCandidate(), 'dimension_mismatch');
    conversionAssertBlocked(reusablePackageCandidate(), 'reusable_count_scope');
    conversionAssertAllowed(productScopedPackageCandidate(), 'product_native');

- [ ] **Step 2: Run the focused test to prove it fails**

Run: php custom/grocy_AI/tests/run.php conversion-rules

Expected: FAIL because module migration/service/dispatch are absent.

- [ ] **Step 3: Implement idempotent catalog and validation**

Create module-owned catalog, revision, rule, and validation-ledger objects using the existing taxonomy migration bootstrap transaction pattern. Seed only specified mass/volume unit identities and NIST-backed metric factors with exact decimal strings/source version. Validate scope before graph eligibility: product_id permits product package/count or measured-density save; reusable scopes reject count/package units and remain inactive. Validate positive finite factors, dimensions, reciprocal tolerance, cycles, competing paths, and stale revision identity. Return bounded reason codes; do not mutate native conversions or cache.

- [ ] **Step 4: Run focused verification**

Run: php custom/grocy_AI/tests/run.php conversion-rules && php -l custom/grocy_AI/src/GrocyAiConversionMigration.php && php -l custom/grocy_AI/src/GrocyAiConversionService.php

Expected: PASS; invalid/reusable candidates produce no projection and valid product scope remains eligible.

- [ ] **Step 5: Commit**

    git add custom/grocy_AI/src/GrocyAiConversionMigration.php custom/grocy_AI/src/GrocyAiConversionService.php custom/grocy_AI/routes.php custom/grocy_AI/tests/conversions.php custom/grocy_AI/tests/run.php
    git commit -m "feat: add inactive conversion catalog"

## Task 2: Enforce scope before native AddObject/EditObject mutation

**Files:**
- Modify: custom/grocy_AI/src/GrocyAiApiController.php, custom/grocy_AI/routes.php, controllers/Api/GenericEntityApiController.php, custom/grocy_AI/tests/conversions.php, custom/grocy_AI/tests/run.php, CUSTOMIZATIONS.md

**Interfaces:**
- Produces: authenticated read-only validation endpoint and a quantity_unit_conversions-only pre-save hook.
- The hook calls ValidateNativeConversionBeforeWrite($requestBody, $objectId) after filtering and before createRow()->save()/row->update().

- [ ] **Step 1: Write failing native-hook integration tests**

    conversionInvokeAddObject(productScopedPackageCandidate());
    conversionAssertNativeRowAndCacheChangedExactlyOnce();

    conversionInvokeEditObject(existingProductConversionId(), productScopedDensityCandidate());
    conversionAssertNativeRowAndCacheChangedExactlyOnce();

    conversionExpectNoWrite(reusableUniversalCandidate());
    conversionExpectNoWrite(reusablePackageCandidate());
    conversionExpectNoWrite(crossDimensionCandidate());

- [ ] **Step 2: Run focused test to prove it fails**

Run: php custom/grocy_AI/tests/run.php conversion-native-save-hook

Expected: FAIL because native pre-save validation is absent.

- [ ] **Step 3: Implement minimal guarded interception**

Add only permission-checked read validation route(s), never a custom conversion-save endpoint. In GenericEntityApiController AddObject and EditObject, branch only when entity is quantity_unit_conversions. Invoke the module service after request filtering and before persistence, passing actual existing object ID during edit. If product scope is valid, preserve native path untouched. For reusable/invalid scope, optionally deduplicate a named inactive module revision then return bounded API error before native save, triggers, cache, or projection. The hook must never activate/project a rule. Document exact core lines/intent in CUSTOMIZATIONS.md.

- [ ] **Step 4: Run full focused verification**

Run: php custom/grocy_AI/tests/run.php conversion-rules && php custom/grocy_AI/tests/run.php conversion-native-save-hook && php custom/grocy_AI/tests/run.php conversion-characterization && php -l custom/grocy_AI/src/GrocyAiApiController.php && php -l custom/grocy_AI/routes.php && php -l controllers/Api/GenericEntityApiController.php && php -l custom/grocy_AI/tests/conversions.php && php -l custom/grocy_AI/tests/run.php

Expected: PASS; product native POST/PUT works, reusable POST/PUT has no native/cache write, and the characterization remains unchanged.

- [ ] **Step 5: Commit**

    git add custom/grocy_AI/src/GrocyAiApiController.php custom/grocy_AI/routes.php controllers/Api/GenericEntityApiController.php custom/grocy_AI/tests/conversions.php custom/grocy_AI/tests/run.php CUSTOMIZATIONS.md
    git commit -m "feat: enforce native conversion scope"

## Plan Self-Review

- Task 1 provides only inactive catalog validation; Task 2 consumes it at the sole native write boundary.
- Neither task projects, activates, or changes existing product rows outside a valid submitted product-scoped Save.
- Tests cover bad factor, dimensions, conflict graph, scope, AddObject/EditObject, no-write rejection, and characterization regression.
