# OpenAI UPC Fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Research captured UPCs through one bounded OpenAI web search when both existing barcode providers definitively miss, then present cited, provisional names for human approval.

**Architecture:** The Grocy capture job remains the durable GTIN unit. Its SQLite reservation endpoint atomically caps paid calls; the companion queries existing providers first and calls OpenAI only after a dual miss and successful reservation. A versioned result carries a cited suggestion through Grocy validation to the phone review card without creating a product or stock entry.

**Tech Stack:** PHP 8.5, SQLite, Python 3.11+, httpx, OpenAI Responses API hosted `web_search`, vanilla JavaScript, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-02-openai-upc-fallback-design.md`

## Global Constraints

- Send only a canonical GTIN to OpenAI; never receipt content, inventory, or customer data.
- Keep `OPENAI_API_KEY` only in the grocy-mcp server environment and disable this fallback when absent.
- Never create a Grocy product or barcode before draft approval; never change stock before Commit purchase.
- Allow one automatic reservation per GTIN, at most one explicit manual retry reservation per GTIN, and a configurable daily ceiling. A reservation counts even if the OpenAI response is lost.
- Use source-attributed HTTPS citations on public hosts only; uncited findings remain barcode-only provisional.
- Retain existing provider precedence, user edits, canceled trip boundaries, and current receipt workflow.

## Review Focus

- A provider times out while the other misses: retry existing providers; do not reserve an OpenAI call (Task 2).
- A worker loses its completion response after OpenAI accepts a request: the next lease cannot spend again (Task 1).
- Two workers claim the same GTIN near the daily ceiling: exactly one transaction may consume the last reservation (Task 1).
- A cited URL points to a private host, is oversized, or contains control characters: reject the AI candidate (Tasks 2 and 3).
- A user has edited the draft while research completes: keep the edit and display the new evidence separately (Task 3).

---

### Task 1: Durable paid-call reservation in Grocy

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchMigration.php`
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchController.php`
- Modify: `custom/grocy_AI/routes.php`
- Modify: `config-dist.php`
- Test: `custom/grocy_AI/tests/capture_research_queue.php`

**Interfaces:**
- Produce: `ReserveWebSearch(int $jobId, string $leaseToken, string $actor): array` returning `{allowed: bool, reason: string, reservation_id: ?int}`.
- Produce: `POST /api/grocy-ai/capture/research/jobs/{jobId}/web-search/reserve` for the existing authenticated worker; body carries `lease_token`, response carries the reservation decision only.
- Persist one row per search in `grocy_ai_capture_web_search_reservations` with job/GTIN, UTC day, actor, job retry generation, and timestamp; a unique `(canonical_gtin, retry_generation)` constraint makes duplicate claims idempotent. Add a namespaced migration version.
- Use `GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT` (default 20) for the atomic UTC-day count. Existing manual `RetryJob` action is the only way to increment retry generation, at most once for OpenAI.

- [ ] **Step 1: Write failing reservation tests.** Assert invalid/expired lease, canceled or committed trip, unsupported line, two reservations for the same generation, lost response/reclaim, second manual retry, and two concurrent claims at the daily limit cannot trigger extra searches.
- [ ] **Step 2: Run `php custom/grocy_AI/tests/capture_research_queue.php`; expect the new assertions to fail.**
- [ ] **Step 3: Implement the migration, transaction and worker route.** Recheck live eligible drafts inside `BEGIN IMMEDIATE`; reserve before calling OpenAI; never refund a spent slot. Record only identifiers and safe status, no prompt or key.
- [ ] **Step 4: Run `php custom/grocy_AI/tests/capture_research_queue.php` and `php -l` on changed PHP; expect pass.**
- [ ] **Step 5: Commit Grocy reservation changes.**

### Task 2: Bounded OpenAI web search in grocy-mcp

**Files (grocy-mcp repository):**
- Create: `grocy_mcp/capture_web_search.py`
- Modify: `grocy_mcp/capture_research.py`
- Modify: `grocy_mcp/capture_worker.py`
- Modify: `compose.yaml`
- Test: `tests/test_capture_research.py`
- Test: `tests/test_capture_worker.py`
- Create: `tests/test_capture_web_search.py`

**Interfaces:**
- Consume Task 1 reservation route; the worker passes job ID and lease token only after a definitive dual provider miss.
- Produce: `search_capture_gtin(gtin: str, *, client: httpx.AsyncClient) -> dict[str, object]` with a single candidate name, exact-code claim boolean, and at most two bounded citations `{title, url}` or an explicit inconclusive status.
- Produce companion result contract version 2 only when web search is involved: `sources: ['openai-web']`, `name_candidate_sources: [['openai-web']]`, `web_evidence: [{candidate_index: 0, exact_gtin_claim: bool, citations: [{title, url}]}]`. Provider-only results may remain version 1.
- Configure `OPENAI_API_KEY`, `OPENAI_CAPTURE_WEB_MODEL`, output token limit, and a bounded timeout in grocy-mcp only; use the Responses API with `web_search` required and inspect actual search-call and URL-citation annotations.

- [ ] **Step 1: Write failing adapter and worker tests.** Assert provider hit bypass, dual miss with key absent, one-provider failure bypass, one reservation per retry generation, request body containing only GTIN/fixed instructions, required search call, exact-code citation, absent/mismatched citation, invalid URL, refusal, 429/timeout, and no second call after lost completion.
- [ ] **Step 2: Run `python -m unittest tests.test_capture_web_search tests.test_capture_research tests.test_capture_worker`; expect new tests to fail.**
- [ ] **Step 3: Implement the small adapter and worker integration.** Strip control characters and bound names/titles/URLs; validate DNS/IP targets and HTTPS; do not infer an exact UPC from uncited model prose. Reconcile the existing 10-second worker timeout with the bounded web-search timeout and Grocy lease before enabling calls.
- [ ] **Step 4: Run `python -m unittest tests.test_capture_web_search tests.test_capture_research tests.test_capture_worker`; expect pass.**
- [ ] **Step 5: Commit companion changes.**

### Task 3: Grocy result validation and mobile review

**Files:**
- Modify: `custom/grocy_AI/src/GrocyAiCaptureResearchService.php`
- Modify: `public/custom/grocy_AI/capture-product-research.js`
- Modify: `public/custom/grocy_AI/grocy-ai.css`
- Test: `custom/grocy_AI/tests/capture_research_queue.php`
- Test: `custom/grocy_AI/tests/capture_research_approval.php`
- Test: `custom/grocy_AI/tests/browser/specs/product-research.spec.js`

**Interfaces:**
- Consume Task 2 version 2 `web_evidence` contract. Preserve version 1 compatibility.
- Produce `name_alternatives[].web_evidence` for an `openai-web` candidate, with bounded title/domain/HTTPS URL and `exact_gtin_claim`; expose no raw model response.
- UI renders `OpenAI web suggestion — verify UPC`, safe links with `target="_blank" rel="noopener noreferrer"`, and `Verify against package`; evidence is read-only and never auto-selects product group, taxonomy, receipt match, or stock.

- [ ] **Step 1: Write failing PHP and browser tests.** Assert exact contract shape, cited URL host checks, rejected javascript/private URLs and control characters, version 1 compatibility, user-edit precedence, no product/barcode/stock write before approval, and narrow-screen link display.
- [ ] **Step 2: Run `php custom/grocy_AI/tests/capture_research_queue.php`, `php custom/grocy_AI/tests/capture_research_approval.php`, and `npm --prefix custom/grocy_AI/tests/browser test -- --grep "OpenAI"`; expect new tests to fail.**
- [ ] **Step 3: Implement strict normalization, DTO evidence and review card.** Escape text through DOM text nodes, construct anchors only from validated URLs, and keep the existing approve/link/Commit purchase actions unchanged.
- [ ] **Step 4: Run the same PHP and Playwright tests plus `php custom/grocy_AI/tests/run.php`; expect pass.**
- [ ] **Step 5: Commit Grocy review changes and document fork boundary in `CUSTOMIZATIONS.md` and module behavior in `custom/grocy_AI/README.md`.**

### Task 4: Full verification and controlled rollout

**Files:**
- Modify: `grocy-mcp/README.md` in its repository, deployment notes and runbook as appropriate.
- No production data edits except backup and bounded trip #12 job requeue after deployment.

**Interfaces:**
- Deployed companion must report its health without revealing key, prompt, UPC, or receipt data.
- Trip #12 remains reviewing and uncommitted; backfill preview lists eligible job IDs and maximum chargeable calls before any requeue.

- [ ] **Step 1: Run full companion unittest suite and Grocy PHP/receipt contract suite; capture exact results.**
- [ ] **Step 2: Run mobile Chromium and WebKit review suites; check 375px layout, citations and approval boundaries.**
- [ ] **Step 3: Review both branches, fix findings, and open/link required PRs; merge only after checks pass.**
- [ ] **Step 4: Configure a dedicated OpenAI project key and hard spend limit through secret management, deploy companion/Grocy in compatible order, then perform a secret-free health check.**
- [ ] **Step 5: Make a fresh full-data backup, preview trip #12 provisional GTINs and maximum calls, requeue only the approved bounded set, and verify cited suggestions on phone. Do not approve products or commit stock on the user's behalf.**
