# Phase 4 — Sourced profile inspection (04-03)

**Goal:** Add narrow, source-stamped approximate profiles and a deterministic *inspection-only* resolver. No profile, universal rule, cache projection, native conversion, taxonomy assignment, or product row may be activated or mutated here.

## Safety contract

- Eligible profile requires only a current explicit non-null leaf in `grocy_ai_taxonomy_classifications`; provider evidence, product groups, absent, stale, and Unclassified values cannot qualify it.
- Start with at most three calibrated common-liquid/simple-ingredient profiles. Every offered profile is visibly approximate and has a named USDA FDC record, item ID, and reviewed release/version.
- Return a closed unavailable DTO with no factor for any unclassified, unprofiled, excluded, stale, or otherwise ineligible product.
- Resolve only for inspection: exact precedence is existing product override > eligible food-type profile > inactive universal candidate. Do not depend on SQLite row order or native recursive resolution.
- Same-rank collisions, competing paths, cycles, reciprocal inconsistency, dimension mismatch, tolerance drift, or malformed values produce correction-safe blockers and no factor.
- Existing product overrides may be read but never modified or cleaned up. Activation/projecting native/cache state remains reserved for later evidence-gated plans.

## Task 1 — Inactive sourced profiles and explicit taxonomy eligibility

**Owned files:** `custom/grocy_AI/src/GrocyAiConversionMigration.php`, `custom/grocy_AI/src/GrocyAiConversionService.php`, `custom/grocy_AI/tests/conversions.php`, `custom/grocy_AI/tests/run.php`.

1. Add focused `conversion-resolution` contract tests before production changes:
   - every starter profile is closed-leaf, finite/positive, source/versioned USDA FDC, and approximate;
   - provider/group evidence, absent/Unclassified/stale/excluded leaf, and absent profile return unavailable without a factor;
   - profiles remain inactive and leave taxonomy/products/native conversions/cache/projection unchanged.
2. Seed module-owned inactive profile data and lifecycle state. Read only current explicit taxonomy leaf assignments.
3. Implement a bounded inspection DTO. It returns unavailable until explicit eligibility and later activation evidence are present.
4. Run focused RED then GREEN; verify the native characterization is unchanged.

## Task 2 — Deterministic inactive inspection resolver

**Owned files:** `custom/grocy_AI/src/GrocyAiConversionService.php`, `custom/grocy_AI/tests/conversions.php`, `custom/grocy_AI/tests/run.php`.

1. Write tests proving exact precedence, winner provenance/source/version/status, and zero native/cache/product-row mutation.
2. Add direct deterministic cases for same-rank collision, competing paths, cycles, reciprocal drift, dimension mismatch, tolerance drift, and malformed factors; each must yield a bounded blocker and no factor.
3. Implement full graph validation and ranking without SQLite-order dependence. Preserve inactive lifecycle and do not project effective rules.
4. Run focused, characterization, full PHP suite, lint, and `git diff --check`.

## Completion evidence

- `php custom/grocy_AI/tests/run.php conversion-resolution`
- `php custom/grocy_AI/tests/run.php conversion-rules`
- `php custom/grocy_AI/tests/run.php conversion-native-save-hook`
- `php custom/grocy_AI/tests/run.php conversion-characterization`
- `php custom/grocy_AI/tests/run.php`
- PHP lint for every touched file and `git diff --check`
- Independent scoped review with no Critical or Important findings.

