# Capture Classification Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Suggest all three Grocy classification fields for identified unknown UPCs in receipt review, with bounded AI fallback and explicit product approval.

**Architecture:** Grocy owns the catalog choices, durable classification queue, paid-call reservations, and validated suggestions. The companion performs one schema constrained Responses call for a claimed name-only classification job and returns IDs/slugs from the supplied choices. The review UI shows source-labeled suggestions and preserves reviewer edits; approval remains the only catalog write.

**Tech Stack:** PHP 8.5, SQLite, Python 3.11+, httpx, OpenAI Responses API, vanilla JavaScript, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-06-capture-classification-suggestions.md`

## Global Constraints

- Keep fork code under `custom/grocy_AI/` and `public/custom/grocy_AI/`; document any upstream hook in `CUSTOMIZATIONS.md`.
- Keep the OpenAI key only in the companion environment and preserve the project's separate hard spend limit.
- Use provider category mappings first; never infer a category from an unrelated receipt store code.
- Make at most one paid classification reservation per draft research result revision and enforce a separate configurable UTC-day ceiling; a lost response does not refund a reservation.
- Accept only active local group IDs, current taxonomy leaf slugs, and active top-level parent IDs; revalidate on approval.
- Preserve user edits and explicit clearing. Never create a product before approval or change stock before Commit purchase.
- Keep existing exact-barcode products, receipt data, and trip state unchanged during classification.

## Review Focus

- An Open Food Facts category is excluded, ambiguous, or unsupported: it cannot silently become a trusted category suggestion (Task 1).
- Two workers race for the last daily reservation: only one may call OpenAI (Task 2).
- A completion arrives after a draft edit, new research result, cancellation, or product approval: it cannot replace the reviewer choice or finalized state (Task 2).
- A model returns an inactive group, stale slug, child parent, or mismatched ID: reject that field and retain valid independent fields (Tasks 2 and 3).
- A suggested parent has incompatible stock unit or the reviewer changes units: do not preselect an invalid parent and let approval revalidate (Task 3).

---

### Task 1: Grocy classification inputs and deterministic suggestions

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Test: `custom/grocy_AI/tests/capture_research_drafts.php`

**Interfaces:**
- Produce a bounded worker classification input for one live identified research draft: result revision, selected product identity evidence, and current active group/leaf/parent choices.
- Preserve existing exact Open Food Facts mapping rules; add a parent candidate only when a unique active top-level local parent matches the identified product identity and passes known compatibility checks. Mark candidate source.
- Emit review candidates for all three fields without writing `selected_json` or changing products.

- [ ] **Step 1: Write failing PHP tests** for a valid category, excluded/ambiguous category, name-only result, unique versus ambiguous parent, and canceled/finalized draft.
- [ ] **Step 2: Run `php8.5 custom/grocy_AI/tests/capture_research_drafts.php`; expect the new tests to fail.**
- [ ] **Step 3: Implement the input and deterministic candidate methods** with bounded strings and catalog arrays, retaining current category exclusion behavior.
- [ ] **Step 4: Run the same PHP test and `php8.5 -l` on changed PHP; expect pass.**
- [ ] **Step 5: Commit Task 1.**

### Task 2: Durable classification queue and companion classifier

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchMigration.php`
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchController.php`
- Modify: `custom/grocy_AI/routes.php`
- Modify: `config-dist.php`
- Create: `grocy_mcp/capture_classification.py` (companion repository)
- Modify: `grocy_mcp/capture_worker.py` (companion repository)
- Modify: `compose.yaml` (companion repository)
- Test: `custom/grocy_AI/tests/capture_research_queue.php`
- Test: `custom/grocy_AI/tests/capture_research_worker_api.php`
- Create: `tests/test_capture_classification.py` (companion repository)
- Modify: `tests/test_capture_worker.py` (companion repository)

**Interfaces:**
- Grocy worker routes claim, reserve, complete, and fail classification using existing API-key plus worker-key authentication. Claim returns a bounded immutable input and lease token. Reservation is atomic and append-only, keyed by draft ID and research result revision; `GROCY_AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT` defaults to 20.
- Companion `classify_capture_product(input: dict, *, client: httpx.AsyncClient) -> dict` returns nullable `product_group_id`, `taxonomy_leaf_slug`, `parent_product_id` and a neutral status. Use the existing `OPENAI_API_KEY`, a separately configured model, `store: false`, no tools, bounded timeout/output, strict JSON schema, and no automatic HTTP retry.
- Grocy validates each returned field against the choice snapshot and live catalog, stores source and revision for review, and ignores stale/finalized completions. A failure is visible without changing the research identity or retrying a paid call.

- [ ] **Step 1: Write failing PHP and Python tests** for eligibility, one reservation per revision, UTC-day race, malformed and stale responses, an absent key, bounded prompt, refusal, 429, timeout, and lost completion.
- [ ] **Step 2: Run the targeted PHP scripts with `php8.5` and `.venv/bin/python -m pytest tests/test_capture_classification.py tests/test_capture_worker.py -q` in the companion worktree; expect new assertions to fail.**
- [ ] **Step 3: Implement Grocy migration/routes/validation and companion classifier/worker integration** while preserving existing UPC research leases and provider precedence.
- [ ] **Step 4: Run targeted tests plus `php8.5 -l` on changed PHP; expect pass.**
- [ ] **Step 5: Commit each repository's Task 2 changes.**

### Task 3: Mobile review, edit precedence, and approval boundary

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Modify: `public/custom/grocy_AI/capture-product-research.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css`
- Modify: `custom/grocy_AI/README.md`
- Modify: `CUSTOMIZATIONS.md` only if an upstream file changes
- Test: `custom/grocy_AI/tests/capture_research_drafts.php`
- Test: `custom/grocy_AI/tests/capture_research_approval.php`
- Test: `custom/grocy_AI/tests/browser/specs/product-research.spec.js`

**Interfaces:**
- Review DTO exposes source-labeled candidates and classification status. Preselect a single valid candidate only when the field has not been saved or cleared by the reviewer; show unresolved fields plainly.
- Keep the existing approval request's `product_group_id`, `taxonomy_leaf_slug`, and `parent_product_id` contract. Recheck live parent stock-unit compatibility when units are chosen and in the approval transaction.
- Double confirmation names the exact final choices. Research completion does not mutate product, barcode, stock, or receipt allocations.

- [ ] **Step 1: Write failing PHP and browser tests** for AI candidates, source labels, explicit clearing, late result, parent unit mismatch, narrow mobile layout, and no write before approval.
- [ ] **Step 2: Run targeted PHP tests with `php8.5` and the existing Playwright project; expect new assertions to fail.**
- [ ] **Step 3: Implement review DTO/UI behavior and module documentation.**
- [ ] **Step 4: Run targeted tests and `php8.5 custom/grocy_AI/tests/run.php`; expect pass.**
- [ ] **Step 5: Commit Task 3.**

### Task 4: Cross-repository verification and rollout

**Files:**
- Modify: companion `README.md` for classification configuration and spend controls.

**Interfaces:**
- Deploy companion and Grocy compatible changes without mutating current purchase trips. Existing drafts receive suggestions only through the bounded queue; reviewers remain responsible for approving products and committing stock.

- [ ] **Step 1: Run full Grocy PHP contract suite, companion unit suite, and mobile Chromium/WebKit review specs.**
- [ ] **Step 2: Review both branches, fix findings, and open/link PRs after verification.**
- [ ] **Step 3: Merge and deploy in compatible order; verify read-only health and one controlled name-only classification result.**
- [ ] **Step 4: Report the live review path and any remaining human approval steps without approving products or committing stock.**
