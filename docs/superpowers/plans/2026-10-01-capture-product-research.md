# Capture Product Research Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Research unknown purchase scans automatically, let the user approve a product draft or link an existing product during receipt review, and keep stock unchanged until Commit purchase.

**Architecture:** Grocy stores a durable canonical-GTIN research queue, versioned drafts, and an append-only audit. A bounded companion worker uses Barcode Buddy Federation and Open Food Facts, then submits normalized results; Grocy alone approves and persists products. Separate review endpoints and UI leave existing capture DTOs and receipt commit rules intact.

**Tech Stack:** PHP 8.5, Slim 4, SQLite, Blade, plain JavaScript, Python 3, Starlette, httpx, pytest/unittest.

**Spec:** `docs/superpowers/specs/2026-10-01-capture-product-research-design.md`

## Global Constraints

- Grocy work stays under `custom/grocy_AI/` and `public/custom/grocy_AI/`, with minimal documented route/version/configuration hooks in `routes.php` and `config-dist.php`.
- Companion work lives in the sibling `grocy-mcp` repository and reuses `lookup_barcode()` read-only; do not call the interactive `grocy_product_from_barcode` write tool from the worker.
- Unknown valid GTINs queue without blocking a scan. No normal Grocy product, barcode ownership, or stock write occurs before signed-in review approval.
- An approved product writes no stock; only the existing receipt-gated **Commit purchase** action does.
- Existing trip/line DTO keys remain closed. New data uses separate versioned endpoints. Product approval needs `STOCK_PURCHASE` and `MASTER_DATA_EDIT`.
- Provider responses, job leases, images, and request bodies are bounded; secrets, receipt text, and raw provider payloads stay out of logs.
- The first backfill is a dry-run-reviewed set of selected, valid unknown lines from trip #12; its receipt and stock are preserved.

## Review Focus

1. Same GTIN scanned in several text forms or trips: one job and one eventual owner, with no duplicate product after concurrent approval (Tasks 2 and 6).
2. Worker crashes after claiming or after a result reaches Grocy: lease recovery and duplicate completion do not lose or overwrite edits (Tasks 3 and 4).
3. Receipt OCR text resembles the wrong scanned item: no automatic name assignment without an explicit or unique association (Task 5).
4. Product approval succeeds while a purchase review tab is stale: readiness and checksum demand refreshed ownership and receipt allocations (Task 8).
5. Open Food Facts misses or returns an unsupported category while Federation has only a name: a usable provisional draft remains reviewable, with category unset (Tasks 1 and 5).

---

## File map

| Area | Files and responsibility |
| --- | --- |
| Companion research | `grocy-mcp/grocy_mcp/capture_research.py` normalizes read-only lookup; `server.py` exposes authenticated `POST /v1/capture/research`; `tests/test_capture_research.py` pins the contract. |
| Grocy persistence | `custom/grocy_AI/src/GrocyAiCaptureResearchMigration.php` owns namespaced jobs, drafts and append-only audit; `GrocyAiCaptureResearchService.php` owns enqueue, claim, completion, draft edits and evidence; focused tests under `custom/grocy_AI/tests/`. |
| Worker | `grocy-mcp/grocy_mcp/capture_worker.py` polls Grocy, researches a bounded claim, and reports results; `server.py` starts/stops it; `tests/test_capture_worker.py` proves retries. |
| Product approval | `custom/grocy_AI/src/GrocyAiCaptureProductService.php` owns transaction, native product/barcode persistence and idempotent link/create; `GrocyAiCaptureResearchController.php` owns permissions and closed HTTP bodies. |
| Capture integration | `GrocyAiCaptureService.php` queues new unknown scans and extends checksum; `GrocyAiReceiptService.php` extends readiness; `custom/grocy_AI/routes.php` adds endpoints. |
| Mobile review | `public/custom/grocy_AI/capture-product-research.js`, `capture-review.js`, `grocy-ai.css`, and browser fixtures/tests render and operate the research cards. |
| Release | `custom/grocy_AI/version.json`, `README.md`, and a namespaced backfill CLI under `custom/grocy_AI/bin/` document and safely run trip #12 backfill. |

### Task 1: Companion read-only research contract

**Files:** Create `grocy-mcp/grocy_mcp/capture_research.py`, `grocy-mcp/tests/test_capture_research.py`; modify `grocy-mcp/grocy_mcp/server.py`.

**Interfaces:** Produce `async def research_capture_gtin(gtin: str) -> dict[str, object]` and authenticated `POST /v1/capture/research` with `{ "contract_version": 1, "gtin": "..." }`. Return only `contract_version`, `canonical_gtin`, `outcome` (`found` or `miss`), bounded `name_candidates`, `brand`, `package`, `categories`, `sources`, and optional image handle. Reuse `lookup_barcode()`; Federation contributes names, OFF contributes facts/categories.

- [ ] **Step 1: Write failing tests** in `test_capture_research.py`: provider hit preserves Federation-first names and OFF category source; provider miss returns `miss` with empty candidates; invalid GTIN, oversized names/categories and missing inbound API key fail closed.
- [ ] **Step 2: Run** `cd grocy-mcp && python -m pytest tests/test_capture_research.py -q`; expect failures for missing route/helper.
- [ ] **Step 3: Implement** `research_capture_gtin()` and route in the named files, using the existing `MCP_API_KEYS` middleware and a 64 KiB response cap; keep the existing `/v1/products/enrich/upc/{upc}` contract untouched.
- [ ] **Step 4: Run** the focused test and `python -m pytest tests/test_http_api.py -q`; expect all pass.
- [ ] **Step 5: Commit** the companion change as `feat: expose capture research contract`.

### Task 2: Durable Grocy queue and scan hook

**Files:** Create `custom/grocy_AI/src/GrocyAiCaptureResearchMigration.php`, `GrocyAiCaptureResearchService.php`, `tests/capture_research_queue.php`; modify `GrocyAiCaptureService.php`, `routes.php` class requires.

**Interfaces:** Produce `EnqueueUnknown(int $tripId, int $lineId, string $scannedBarcode): ?array` and `DraftsForTrip(int $tripId): array`. Schema keys jobs by canonical GTIN; a job has state, attempts, retry time, lease hash/expiry, and revision. Drafts/audit use foreign keys and append-only triggers. `ScanIntoTrip()` calls enqueue after a new unknown line, without provider I/O; repeated scans only coalesce.

- [ ] **Step 1: Write failing tests**: valid UPC queues once across repeat scans and equivalent GTIN forms; known/invalid scans do not queue; a provider outage cannot affect scan latency; schema bootstrap preserves existing capture/receipt rows.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/capture_research_queue.php`; expect missing class/table failures.
- [ ] **Step 3: Implement** migration/service and the narrow scan hook. Keep the existing trip and line DTO key sets unchanged.
- [ ] **Step 4: Run** the focused test plus `php8.5 custom/grocy_AI/tests/run.php`; expect pass and 261 baseline checks.
- [ ] **Step 5: Commit** as `feat: queue unknown capture barcodes`.

### Task 3: Worker claim and result boundary

**Files:** Modify `GrocyAiCaptureResearchService.php`; create `custom/grocy_AI/src/GrocyAiCaptureResearchController.php`, `tests/capture_research_worker_api.php`; modify `routes.php`, `config-dist.php` only for `GROCY_AI_RESEARCH_WORKER_KEY` setting.

**Interfaces:** Produce `ClaimJobs(int $limit, string $workerId): array`, `CompleteJob(int $jobId, string $leaseToken, array $result): array`, and `FailJob(int $jobId, string $leaseToken, string $safeCode): array`. Routes `POST /api/grocy-ai/capture/research/jobs/claim`, `/{jobId}/complete`, `/{jobId}/fail` require ordinary Grocy API authentication plus a timing-safe `X-Grocy-AI-Worker-Key` comparison; reject an unset key. Claim limit is 1–5, lease 60 seconds, maximum 5 attempts with bounded backoff. Store only normalized contract-v1 fields.

- [ ] **Step 1: Write failing tests** for absent/wrong worker key, two concurrent claimers, lease expiry, repeated completion, stale token, malformed provider result, worker crash/retry, and a manually edited draft surviving a later result.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/capture_research_worker_api.php`; expect missing methods/routes.
- [ ] **Step 3: Implement** transactional claim/complete/fail and controller validation. Never hold a SQLite transaction across provider I/O; do not return a lease token in a browser draft response.
- [ ] **Step 4: Run** focused tests and `php8.5 custom/grocy_AI/tests/run.php`; expect pass.
- [ ] **Step 5: Commit** as `feat: accept bounded capture research results`.

### Task 4: Companion background worker

**Files:** Create `grocy-mcp/grocy_mcp/capture_worker.py`, `grocy-mcp/tests/test_capture_worker.py`; modify `grocy-mcp/grocy_mcp/server.py`, `README.md`.

**Interfaces:** Produce `async def run_research_once(client: httpx.AsyncClient) -> int` and `async def run_research_forever(stop: asyncio.Event) -> None`. Use `GROCY_BASE_URL`, `GROCY_API_KEY`, and a new `GROCY_AI_RESEARCH_WORKER_KEY`; call Grocy claim/complete/fail endpoints with the shared key and research each claimed GTIN through Task 1. Start the loop in a Starlette lifespan wrapper around FastMCP's existing lifespan, with one bounded worker and clean shutdown.

- [ ] **Step 1: Write failing tests**: no configured worker key means no claims; one cycle handles at most five jobs; provider timeout reports a safe failure; lost completion response is reconciled by lease/idempotent retry; shutdown cancels the loop cleanly.
- [ ] **Step 2: Run** `cd grocy-mcp && python -m pytest tests/test_capture_worker.py -q`; expect missing worker failure.
- [ ] **Step 3: Implement** the worker and lifespan startup without changing MCP or receipt OCR startup behavior.
- [ ] **Step 4: Run** focused tests and `python -m pytest tests/test_http_api.py tests/test_receipts.py -q`; expect pass.
- [ ] **Step 5: Commit** as `feat: run capture research worker`.

### Task 5: Reviewable drafts, receipt evidence, and category candidates

**Files:** Modify `GrocyAiCaptureResearchService.php`, `GrocyAiCaptureResearchController.php`, `GrocyAiReceiptService.php`, `routes.php`; create `custom/grocy_AI/tests/capture_research_drafts.php`.

**Interfaces:** Produce `UpdateDraft(int $tripId, int $lineId, int $revision, array $changes, string $actor): array`, `SetReceiptEvidence(int $tripId, int $lineId, ?int $receiptLineId, string $actor): array`, and `RetryJob(int $tripId, int $lineId, string $actor): array`. Expose `GET /capture/trips/{tripId}/research`, `PUT /capture/trips/{tripId}/lines/{seq}/research`, `PUT .../receipt-evidence`, and `POST .../retry`. Draft results include source-labeled alternatives, exact active local product-group candidates, versioned taxonomy candidates, and possible existing products; no candidate is applied silently.

- [ ] **Step 1: Write failing tests**: provider miss plus explicitly paired OCR gives a provisional name; ambiguous or wrong-trip OCR does not; an edited name survives retry; unsupported/ambiguous category stays unset; Federation-only name gives no OFF category; canceled/committed trips reject edits.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/capture_research_drafts.php`; expect missing methods.
- [ ] **Step 3: Implement** the review DTO, optimistic revision checks, explicit receipt evidence, exact group matching, taxonomy rule lookup, and append-only audit. Keep receipt decisions/allocations unchanged.
- [ ] **Step 4: Run** focused tests and existing receipt scripts `for script in custom/grocy_AI/tests/receipt_*.php; do php8.5 "$script" || exit 1; done`; expect pass.
- [ ] **Step 5: Commit** as `feat: review captured product drafts`.

### Task 6: Explicit product approval and existing-product link

**Files:** Create `custom/grocy_AI/src/GrocyAiCaptureProductService.php`, `tests/capture_research_approval.php`; modify `GrocyAiCaptureResearchController.php`, `routes.php`, `GrocyAiCaptureService.php` only for post-approval re-resolution.

**Interfaces:** Produce `ApproveDraft(int $tripId, int $lineId, int $revision, array $fields, string $actor): array` and `LinkDraft(int $tripId, int $lineId, int $revision, int $productId, string $actor): array`. Routes `POST .../research/approve` and `POST .../research/link` require `STOCK_PURCHASE` plus `MASTER_DATA_EDIT`; client-confirmed fields are `name`, `location_id`, `qu_id_purchase`, `qu_id_stock`, optional `product_group_id`, `taxonomy_leaf_slug`, and `parent_product_id`. The server reads the original scanned barcode from the capture line. Use Grocy's LessQL product write on the same PDO transaction, attach barcode under canonical uniqueness, and finalize draft/audit in that transaction. Repeated approval returns the same product ID.

- [ ] **Step 1: Write failing tests**: no product/barcode/stock before approval; approval creates one product and attaches scanned UPC; concurrent owner or duplicate name returns review conflict; failed attachment rolls back creation; repeated request is idempotent; link needs explicit existing ID; wrong permissions, invalid parent/unit, canceled/committed trip reject.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/capture_research_approval.php`; expect missing service/methods.
- [ ] **Step 3: Implement** one `BEGIN IMMEDIATE` approval transaction with ownership/name/unit/location/parent checks, native product fields, barcode insert, selected taxonomy assignment, draft outcome and audit. Do not invoke `StockService::AddProduct()`.
- [ ] **Step 4: Run** focused test, receipt commit tests, and `php8.5 custom/grocy_AI/tests/run.php`; expect pass and unchanged stock logs in fixtures.
- [ ] **Step 5: Commit** as `feat: approve or link researched capture products`.

### Task 7: Mobile research review UI

**Files:** Create `public/custom/grocy_AI/capture-product-research.js` and its browser test; modify `public/custom/grocy_AI/capture-review.js`, `grocy-ai.css`, `views/grocyai_capture_review.blade.php`, and browser fixtures.

**Interfaces:** `GrocyAIProductResearch(host, {tripId, lines, readOnly, reload})` loads the separate research DTO. Per selected unknown scan it renders progress, sources, alternative names, existing matches, editable proposed fields, explicit receipt-line evidence, Retry, Approve, and Link. A two-stage confirmation shows the final product/barcode/category before posting; successful approval reloads capture and receipt review.

- [ ] **Step 1: Write failing mobile browser tests**: processing/ready/miss/error cards, corrections, receipt evidence, existing-product link, double confirmation, forbidden approval, and no Commit purchase enabled by a draft alone.
- [ ] **Step 2: Run** `npm --prefix custom/grocy_AI/tests/browser run test:release -- --grep 'product research'`; expect missing UI failure.
- [ ] **Step 3: Implement** the component and minimal integration, preserving existing receipt editor drafts and responsive camera/scan controls.
- [ ] **Step 4: Run** focused browser tests plus `npm --prefix custom/grocy_AI/tests/browser run test:release`; expect pass at iPhone-sized and desktop viewports.
- [ ] **Step 5: Commit** as `feat: review researched products on mobile`.

### Task 8: Server readiness and checksum guard

**Files:** Modify `GrocyAiReceiptService.php`, `GrocyAiCaptureService.php`, `tests/receipt_commit.php`, `tests/capture_research_approval.php`.

**Interfaces:** `Readiness(int $tripId): array` adds `capture_line_<id>_product_review_required` for a selected unknown or unapproved research draft. `ChecksumForTrip(int $tripId): string` binds selected line ownership, approved/linked draft ID and revision, and the existing receipts/allocations. `CommitTrip()` checks these before and after `BEGIN IMMEDIATE`.

- [ ] **Step 1: Write failing tests**: researched draft cannot commit; approved product plus unfinished receipt cannot commit; receipt allocation to approved product can commit exactly once; changed draft/product ownership invalidates a prior checksum; ignored/deselected scan never forces product approval.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/receipt_commit.php`; expect new guard assertions to fail.
- [ ] **Step 3: Implement** only the readiness/checksum and lock revalidation changes; keep stock write path and existing receipt decision rules intact.
- [ ] **Step 4: Run** focused test, all receipt scripts, and `php8.5 custom/grocy_AI/tests/run.php`; expect pass.
- [ ] **Step 5: Commit** as `feat: gate purchase commit on product approval`.

### Task 9: Bounded backfill, release, and acceptance

**Files:** Create `custom/grocy_AI/bin/capture-research-backfill.php` and `tests/capture_research_backfill.php`; modify `custom/grocy_AI/README.md`, `version.json`, deployment/acceptance docs.

**Interfaces:** CLI `php8.5 custom/grocy_AI/bin/capture-research-backfill.php --trip=12 --dry-run` reports candidate IDs/counts, blockers, and a SHA-256 candidate checksum; `--trip=12 --apply --checksum=<sha256>` enqueues only that recorded set of selected valid unknown lines. Both modes write no products, barcodes, receipts, or stock. Cancellation/commit between preview and apply aborts.

- [ ] **Step 1: Write failing tests**: dry run is read-only; changed line selection/checksum aborts apply; only eligible trip #12 lines queue; repeated apply is idempotent; stock/receipt rows are byte-identical.
- [ ] **Step 2: Run** `php8.5 custom/grocy_AI/tests/capture_research_backfill.php`; expect missing CLI/service failure.
- [ ] **Step 3: Implement** backfill and docs; bump customization version to refresh Slim route cache. Document the new config hook in `CUSTOMIZATIONS.md`.
- [ ] **Step 4: Run** focused tests, all Grocy module/receipt/browser tests, and all companion tests; expect pass. Inspect the complete cross-repository diff for secret and stock-write boundaries.
- [ ] **Step 5: Commit** as `feat: backfill capture product research`; open and link both repository PRs, obtain whole-branch review, and merge the tested revisions.
- [ ] **Step 6: Release** with the shared worker key configured outside Git on both services. Verify a full-data backup and restore rehearsal; deploy companion then Grocy; review the trip #12 backfill preview and apply only its recorded checksum. Confirm 17 saved scans and receipt #3 remain, product/stock counts stay unchanged before approvals, and phone review works for provider hit/miss. Record immutable image digests and acceptance evidence without receipt contents or secrets.
