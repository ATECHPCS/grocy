---
phase: 03-food-taxonomy-categorization-pilot
verified: 2026-08-18T03:26:20Z
status: gaps_found
score: 5/7 must-haves verified
overrides_applied: 0
gaps:
  - truth: "User can see provider category evidence, local mapping/ruleset version, confidence, and reason behind a suggested food type."
    status: failed
    reason: "The production read path consumes grocy_ai_taxonomy_evidence, but no production code creates or updates that table from enrichment/provider output. Only the test fixture inserts evidence, so a real product has no route to a suggested type or its evidence."
    artifacts:
      - path: custom/grocy_AI/src/GrocyAiTaxonomyService.php
        issue: "Evidence() is read-only; it selects evidence but never receives a real producer."
      - path: custom/grocy_AI/src/GrocyAiTaxonomyMigration.php
        issue: "Defines the evidence table but no production ingestion/persistence path."
    missing:
      - "A narrow, review-safe production evidence-snapshot path that accepts validated Phase 2 food-type evidence and persists provider category, confidence, reason, and ruleset version without assigning a leaf."
  - truth: "Maintainer can validate taxonomy v1 against all in-scope existing products and record the frozen/preserved identity decision."
    status: failed
    reason: "ValidateInventoryTaxonomy() is an uncalled service method. The documented command invokes the in-memory test harness, not the application database, so a maintainer cannot run the report against existing inventory."
    artifacts:
      - path: custom/grocy_AI/README.md
        issue: "Documents php custom/grocy_AI/tests/run.php taxonomy-validation, which creates fixture SQLite data."
      - path: custom/grocy_AI/src/GrocyAiTaxonomyService.php
        issue: "Contains report logic but exposes no production CLI, secured endpoint, or maintainer command that invokes it."
    missing:
      - "A documented, production-safe maintainer entry point that invokes the report against the configured Grocy database and emits only redacted aggregate counts."
---

# Phase 3: Food Taxonomy & Categorization Pilot Verification Report

**Phase Goal:** Users can explicitly inspect evidence and assign one current household-food taxonomy leaf or Unclassified to a product, while maintainers can validate the pilot safely and all unrelated Grocy data remains untouched.

**Verified:** 2026-08-18T03:26:20Z  
**Status:** gaps_found  
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Maintainer can define a small versioned two-level household food taxonomy with stable IDs/slugs independent of provider display labels. | ✓ VERIFIED | `GrocyAiTaxonomyMigration::Seed()` defines three depth-1 groups and nine depth-2 leaves with source-controlled IDs/slugs; `LeafBySlug()` resolves only local leaves. |
| 2 | Taxonomy omits baby-food and pet-food types and provider mappings cannot silently reintroduce them. | ✓ VERIFIED | Seed validation rejects `baby`, `pet`, `frozen`, and `preserved`; closed mapping rules mark `baby_food`/`pet_food` excluded; `Evidence()` returns Unclassified for excluded mappings. |
| 3 | User can explicitly leave a product Unclassified when evidence is absent, conflicting, or below the accepted threshold. | ✓ VERIFIED | `AssignProductTaxonomy()` accepts only `{unclassified: true, ruleset_version}` and writes a null `leaf_id`; missing/unknown/excluded/low evidence resolves as Unclassified. PHP and mobile-browser contract checks pass. |
| 4 | User can review and assign exactly one current taxonomy leaf without changing stock, units, recipes, prices, history, or location. | ✓ VERIFIED | Narrow protected PUT route calls an UPSERT keyed by `product_id` in the module table only. `taxonomy-assignment` snapshots all fixture upstream tables; `@tax03` confirms no generic product/stock/recipe/location write requests. |
| 5 | User can see provider category evidence, local mapping/ruleset version, confidence, and reason behind a suggested food type. | ✗ FAILED | The UI and DTO render this data when supplied, but `grocy_ai_taxonomy_evidence` has no production writer. Every non-test product is therefore limited to `no_accepted_evidence`. |
| 6 | Maintainer can validate taxonomy v1 against all in-scope existing products and explicitly record frozen/preserved as handling, not food identity. | ✗ FAILED | Service logic returns redacted counts and boundary text, but it is not wired to a production maintainer command. The sole documented command is the in-memory fixture test. |
| 7 | Maintainer can migrate and version module-owned taxonomy data through namespaced schema objects without upstream migration collisions. | ✓ VERIFIED | `GrocyAiTaxonomyMigration` creates `grocy_ai_taxonomy_*` objects and a module migration ledger; idempotence/schema checks and release gate pass. |

**Score:** 5/7 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
| --- | --- | --- | --- |
| `custom/grocy_AI/src/GrocyAiTaxonomyMigration.php` | Namespaced, versioned taxonomy bootstrap | ✓ VERIFIED | Transactional idempotent bootstrap and closed v1 seed. |
| `custom/grocy_AI/src/GrocyAiTaxonomyService.php` | Evidence read, assignment, validation | ⚠️ HOLLOW | Assignment and read logic are substantive, but evidence has no production source and validation has no production caller. |
| `custom/grocy_AI/src/GrocyAiApiController.php` + `custom/grocy_AI/routes.php` | Authenticated narrow taxonomy API | ✓ VERIFIED | GET/PUT routes use `MASTER_DATA_EDIT`, numeric ID checks, bounded error mapping. |
| `views/productform.blade.php` + `public/custom/grocy_AI/product-taxonomy.js` | Edit-only classification panel | ✓ VERIFIED | Card loads only in edit mode, uses narrow GET/PUT, and does not serialize taxonomy into the product form. |
| `custom/grocy_AI/tests/taxonomy.php` | Deterministic taxonomy and mutation-isolation proof | ✓ VERIFIED | All focused and full PHP module checks pass. |

### Key Link Verification

| From | To | Via | Status | Details |
| --- | --- | --- | --- |
| Product-edit panel | `/api/grocy-ai/products/{id}/taxonomy` | XMLHttpRequest GET/PUT | ✓ WIRED | `product-taxonomy.js` handles response and rerenders state. |
| Assignment API | Module classification table | `AssignProductTaxonomy()` transaction | ✓ WIRED | Exact typed payload; UPSERT keyed by one product ID. |
| Provider/enrichment output | `grocy_ai_taxonomy_evidence` | Production persistence | ✗ NOT WIRED | Repository search finds inserts only in `custom/grocy_AI/tests/taxonomy.php`. |
| Maintainer validation invocation | `ValidateInventoryTaxonomy()` | Production CLI/API | ✗ NOT WIRED | Repository search finds only method definition; README points to fixture harness. |
| Portable module manifest | Stable release source | SHA parity script | ✓ WIRED | `check-portable-parity.sh --stable-sha 368ff411...` reports 19 identical paths. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| --- | --- | --- | --- | --- |
| Taxonomy panel | `data.suggested_leaf`, evidence fields | Taxonomy GET → `Evidence()` | No — table has no production producer | ✗ DISCONNECTED |
| Taxonomy panel | `data.current_leaf` | GET → module classification table | Yes after direct narrow assignment | ✓ FLOWING |
| Inventory report | aggregate outcome counters | `products` and evidence tables | Logic is real, but no maintainer production invocation | ⚠️ UNREACHABLE |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| --- | --- | --- | --- |
| Taxonomy PHP contracts and isolation | `php custom/grocy_AI/tests/run.php` | `All 113 grocy_AI checks passed` | ✓ PASS |
| Taxonomy release gate | `bash custom/grocy_AI/tests/release-gate.sh taxonomy` | `RELEASE_GATE: PASS (taxonomy)` | ✓ PASS |
| Mobile taxonomy UI behavior | `npm --prefix custom/grocy_AI/tests/browser test -- --grep @tax03` | 2 passed (Chromium mobile, WebKit mobile) | ✓ PASS |
| Portable/stable parity | `custom/grocy_AI/tests/check-portable-parity.sh --stable-sha 368ff4115464641e3cd4cec4c7319d6bf1559f75` | 19 identical, 0 mismatched, 0 missing | ✓ PASS |

### Probe Execution

No phase-declared or conventional `probe-*.sh` scripts found. Not applicable.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| --- | --- | --- | --- | --- |
| TAX-01 | 03-01 | Versioned stable two-level taxonomy | ✓ SATISFIED | Closed seed and IDs/slugs in migration. |
| TAX-02 | 03-01 | Exclude baby/pet and block reintroduction | ✓ SATISFIED | Exclusion checks and closed mappings. |
| TAX-03 | 03-02 | Explicit Unclassified | ✓ SATISFIED | Typed null-leaf assignment plus UI action. |
| TAX-04 | 03-02 | One leaf, no unrelated mutations | ✓ SATISFIED | Module-only UPSERT and passing isolation tests. |
| TAX-05 | 03-01/03-02 | Explainable provider evidence | ✗ BLOCKED | Display exists but no production evidence ingestion. |
| TAX-06 | 03-03 | Validate existing inventory and frozen/preserved boundary | ✗ BLOCKED | Report has no production maintainer entry point. |
| TAX-07 | 03-01/03-03 | Namespaced module migration/versioning | ✓ SATISFIED | Module ledger/namespaced schema/release proof. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| --- | --- | --- | --- | --- |
| `custom/grocy_AI/src/GrocyAiTaxonomyService.php` | 144 | Evidence table is queried without any production writer | 🛑 Blocker | Suggested classifications and their explanations cannot reach real users. |
| `custom/grocy_AI/README.md` | 151 | Fixture test presented as the validation command | 🛑 Blocker | Cannot validate existing inventory as TAX-06 requires. |

### Human Verification Required

Once the blockers are closed, verify the card on a real mobile edit-product screen: assign a leaf, set Unclassified, reload, and confirm the UI remains usable and ordinary Save controls remain independent. This is visual/device behavior and cannot be proven by static inspection.

## Gaps Summary

Two production wiring gaps block Phase 3. The taxonomy model, narrow assignment API, UI, tests, and release parity are real, but the evidence panel is fed only by test fixtures and the inventory validation report is likewise runnable only as a fixture test. These are not deferred Phase 6 bulk features: they are the Phase 3 evidence and maintainer-validation contracts themselves.

---

_Verified: 2026-08-18T03:26:20Z_  
_Verifier: the agent (gsd-verifier)_
