# Capture Unit Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Suggest purchase and stock units for unknown UPC products in receipt review, while requiring reviewer approval and an existing Grocy conversion for different units.

**Architecture:** Grocy derives safe local suggestions from current active quantity units and exact package or receipt tokens. The existing reserved classification call can add nullable unit IDs from bounded active choices and eligible global conversion pairs; no new paid job is created. Grocy validates and merges candidates with saved reviewer edits, then the existing product-approval transaction validates the final pair again.

**Tech Stack:** PHP 8.5, SQLite, plain JavaScript/Blade, Python 3.11+, httpx, standalone PHP contracts, pytest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-06-capture-unit-suggestions-design.md`

## Global Constraints

- Work in isolated worktrees based on `origin/atech-release` for Grocy and `origin/main` for grocy-mcp. Do not modify the user's current checkout.
- Keep Grocy behavior in `custom/grocy_AI/` and `public/custom/grocy_AI/`; document any core hook in `CUSTOMIZATIONS.md`.
- Preserve current durable reservation, one paid call per draft result revision, UTC-day ceiling, and no paid rerun of existing trip #12 jobs.
- Use only active Grocy unit IDs; a different-unit suggestion needs an existing positive global `quantity_unit_conversions` row from purchase to stock.
- Package or receipt wording alone never creates a conversion. Research, suggestions, and draft edits never create products, barcodes, conversions, or stock entries.
- Reviewer selections and explicit clears override later suggestions. Existing classification JSON without unit fields remains valid.
- Never print keys or secrets; production data under `/etc/komodo/grocy` persists through deployment.

## Review Focus

- `6 x 330 mL` without other unit evidence must not preselect either unit or invent a pack-to-volume pair (Task 1 tests).
- A conversion in the reverse direction or with factor zero must not authorize a different-unit pair (Task 1 and Task 2 tests).
- A saved reviewer clear must stay blank when a late AI result supplies a unit (Task 1 and Task 3 tests).
- A preexisting four-field classification result must render without a new paid call or PHP/JS error (Task 2 and Task 3 tests).
- An inactive unit or an incompatible Generic Parent must not survive server validation or product approval (Task 1 and Task 3 tests).

---

### Task 1: Grocy unit candidates and draft precedence

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Test: `custom/grocy_AI/tests/capture_research_drafts.php`
- Test: `custom/grocy_AI/tests/capture_research_approval.php`

**Interfaces:**
- Produce `ClassificationInput(int $draftId): ?array` with `choices.quantity_units` (`id`, `name`, `name_plural`; at most 200) and `choices.global_unit_conversions` (`from_qu_id`, `to_qu_id`, `factor`; at most 500), restricted to active units; retain existing fields. If either bound is exceeded, omit AI unit choices and keep local/manual review available.
- Produce `ReviewDraft(int $tripId, int $lineId): array` with `purchase_unit_candidates` and `stock_unit_candidates`, each `[{id, name, source}]`; `selected.qu_id_purchase` and `selected.qu_id_stock` are nullable IDs when selected or explicitly cleared.
- Extend `UpdateDraft(..., array $changes, ...)` to accept nullable `qu_id_purchase` and `qu_id_stock`, validate active IDs, and record explicit edits using existing revision/audit semantics. A temporarily incompatible reviewer pair can remain a draft; approval rejects it.

- [ ] **Step 1: Write failing PHP cases.** Assert exact whole-unit evidence yields one local candidate; numeric multi-pack evidence alone preselects neither unit; ambiguous tokens remain blank; inactive units are excluded; reverse or zero-factor conversions are excluded; saved selections and null clears survive refreshed `ReviewDraft`; an incompatible parent/unit pair is flagged or rejected before approval.
- [ ] **Step 2: Run red checks.** `php8.5 custom/grocy_AI/tests/capture_research_drafts.php` and `php8.5 custom/grocy_AI/tests/capture_research_approval.php` fail on the new expectations.
- [ ] **Step 3: Implement the interfaces.** Enforce the stated bounds without silently substituting partial choice lists; reuse current active-unit and global-conversion queries, normalize whole-word aliases, and keep local evidence source labels. Leave unknown evidence blank. Reuse `UnitFactor` and parent compatibility rules when preselecting a pair.
- [ ] **Step 4: Run green checks.** Both focused PHP scripts pass; `php8.5 -l custom/grocy_AI/src/GrocyAiCaptureResearchService.php` passes.
- [ ] **Step 5: Commit.** `feat: suggest safe local capture units`.

### Task 2: Extend the existing classification contract

**Files:**
- Grocy modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Grocy test: `custom/grocy_AI/tests/capture_research_queue.php`
- Companion modify: `grocy_mcp/capture_classification.py`
- Companion test: `tests/test_capture_classification.py`
- Companion test: `tests/test_capture_worker.py` only if the result-shape fixture requires it

**Interfaces:**
- Consume Task 1 `ClassificationInput` unit choices and conversion pairs.
- Produce companion `classify_capture_product(input: dict, *, client: httpx.AsyncClient) -> dict` result with existing fields plus `qu_id_purchase: int | None` and `qu_id_stock: int | None`. No new endpoint or paid request.
- Grocy `CompleteClassification(int $id, string $token, array $result): array` accepts both legacy results without unit keys and new results, filters IDs against the live active catalog, and rejects pairs without the current positive global conversion.

- [ ] **Step 1: Write failing companion tests.** Assert strict schema contains unit enums from active choices, neutral results include null unit fields, a model-selected inactive/unknown ID is discarded, and an unlisted or reverse-only conversion cannot produce a selected pair. Assert one request and the existing reservation/worker behavior.
- [ ] **Step 2: Write failing Grocy tests.** Assert live choice validation, conversion direction/factor, stale completion handling, and acceptance of stored legacy four-field results for trip review. Assert no catalog or stock row changes from completion.
- [ ] **Step 3: Run red checks.** `.venv/bin/python -m pytest tests/test_capture_classification.py tests/test_capture_worker.py -q` in companion and `php8.5 custom/grocy_AI/tests/capture_research_queue.php` in Grocy fail on the new expectations.
- [ ] **Step 4: Implement the contract.** Keep strict bounded JSON output and the current single reservation; never call OpenAI from a local mapping branch solely for units. Normalize each unit independently, then apply pair and parent compatibility before preselection. Parse old result JSON by treating missing unit keys as null.
- [ ] **Step 5: Run green checks and commit separately.** Focused pytest and PHP scripts pass; `python -m compileall` through companion `.venv/bin/python -m compileall grocy_mcp/capture_classification.py` and PHP lint pass. Commit the companion and Grocy changes in their respective repositories.

### Task 3: Review controls and explicit approval

**Files:**
- Modify: `public/custom/grocy_AI/capture-product-research.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css` only for scoped mobile spacing or source labels
- Modify: `views/grocyai_capture_review.blade.php` asset version
- Test: `custom/grocy_AI/tests/browser/specs/purchase-capture.spec.js`
- Test: `custom/grocy_AI/tests/capture_research_approval.php`

**Interfaces:**
- Consume Task 1 `ReviewDraft` candidates, `selected` values, and `user_edits`, plus Task 2 normalized classification result.
- Save `qu_id_purchase` and `qu_id_stock` with the existing draft update endpoint on reviewer selection or clearing; keep the existing approval body and double-confirm product creation.

- [ ] **Step 1: Write failing browser and PHP cases.** At 390px and 320px, source-labeled unit controls have no horizontal overflow and minimum 44px touch height. Changing or clearing either unit persists through reload and wins over a late AI result. Legacy trip results render. Final confirmation names both units. Approval rejects inactive IDs, missing conversion, and incompatible parent/stock unit without partial writes.
- [ ] **Step 2: Run red checks.** Run focused Playwright `purchase-capture.spec.js` in Chromium mobile and WebKit mobile, plus focused PHP approval script; new cases fail.
- [ ] **Step 3: Implement review wiring.** Use the existing active quantity-unit list, preselect only server-vetted unique candidates, label source, preserve unsaved edit state during async refresh, and restore controls after save failure. Keep `Approve new product` as the only product write and `Commit purchase` as the only stock write.
- [ ] **Step 4: Run green checks.** Focused browser and PHP tests pass; `node --check public/custom/grocy_AI/capture-product-research.js` and PHP lint pass.
- [ ] **Step 5: Commit.** `feat: review suggested purchase and stock units`.

### Task 4: Release and live verification

**Files:**
- Modify: `custom/grocy_AI/README.md`
- Modify: `CUSTOMIZATIONS.md`
- Modify: `custom/grocy_AI/version.json` and `views/grocyai_capture_review.blade.php` asset token
- Modify: companion `README.md` only if the classification result contract documentation lives there

**Interfaces:**
- Consume Tasks 1–3 final contracts; publish compatible Grocy and companion versions together.

- [ ] **Step 1: Document unit evidence, existing-conversion rule, manual override, paid-call behavior, and legacy trip behavior; advance the Grocy customization marker and review asset token.**
- [ ] **Step 2: Run final verification.** Grocy `php8.5 custom/grocy_AI/tests/run.php`, browser `npm test` from `custom/grocy_AI/tests/browser`, companion `.venv/bin/python -m pytest -q`, PHP/JS lint, and `git diff --check` all pass.
- [ ] **Step 3: Obtain independent whole-branch review.** Resolve any Critical or Important finding, then re-run affected checks.
- [ ] **Step 4: Prepare production.** Record product/stock counts and trip #12 state, verify a fresh SQLite backup and persistent mount, create and link PRs for Grocy and companion, merge approved branches, and deploy both Komodo stacks.
- [ ] **Step 5: Smoke-test without purchase writes.** Verify the live review API renders existing trip #12 and legacy classification results; verify unit choices and conversion validation using read-only inspection or a test draft. Confirm product/stock counts and trip state did not change. Leave real product approval and `Commit purchase` to the reviewer.
