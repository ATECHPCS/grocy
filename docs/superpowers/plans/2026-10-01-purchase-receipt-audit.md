# Purchase Receipt Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a capture trip attach and audit multiple receipts, confirm stock prices and quantities, then commit only after every receipt is finished.

**Architecture:** The Grocy extension owns durable receipt state, image storage, matching, and the commit guard. The `grocy-mcp` companion provides bounded, authenticated image extraction as suggestions. The review page coordinates explicit human decisions; the server enforces them under the existing SQLite write lock.

**Tech Stack:** PHP 8.5, SQLite, Slim/Blade, plain JavaScript, Starlette/Python companion, module PHP contract tests, Python tests, mobile Playwright.

**Spec:** `docs/superpowers/specs/2026-09-30-purchase-receipt-audit-design.md`

## Global Constraints

- One trip may contain multiple receipts; every attached receipt must be finished before commit.
- Receipt-only items start excluded; Ignore never creates products or stock and remains visible in the audit.
- Every receipt line needs an explicit Include or Ignore decision; Needs review blocks completion.
- Included stock allocations require a known Grocy product, positive quantity, and confirmed nonnegative price.
- A visible receipt total difference can be reconciled or explicitly accepted as is; editing invalidates that acceptance.
- OCR and matching are suggestions; only explicit human actions change Grocy stock or products.
- Keep module changes under `custom/grocy_AI/` and `public/custom/grocy_AI/`, with minimal documented hooks elsewhere.
- Receipt images persist under the Grocy data path, outside the public web root; no image or personal receipt data in logs or URLs.
- Production Grocy is based on `atech-release`; preserve `/etc/komodo/grocy` across rebuilds.

## Review Focus

- A duplicate camera upload or OCR retry must not silently duplicate receipt lines or stock allocations (Tasks 2, 4).
- A receipt edit racing with commit must invalidate the checksum and prevent stale writes (Task 6).
- A user must not read or modify another trip's receipt image or allocation through a guessed ID (Tasks 2, 5).
- Discounts, tax, and deposits must affect total reconciliation without becoming stock entries (Tasks 3, 4).
- An OCR outage or malformed response must leave a usable manual audit path and a blocked commit until review finishes (Tasks 1, 4, 7).

---

### Task 1: Companion receipt extraction contract

**Files:** `grocy-mcp/grocy_mcp/receipts.py` (create), `grocy-mcp/grocy_mcp/server.py` (modify), `grocy-mcp/tests/test_receipts.py` (create), companion deployment/dependency files as needed.

**Interfaces:** Produce authenticated `POST /v1/receipts/extract` accepting one validated image and returning a closed JSON document with `merchant`, `purchase_date`, `printed_total`, `currency`, `lines[]` (`description`, `quantity`, `line_total`, `kind`, `confidence`), `adjustments[]`, and `diagnostics`. Use one request ID for retries; no persistence or Grocy write.

- [ ] Write tests for valid image, unsupported type, oversized body, duplicate request ID, provider timeout, malformed provider output, and safe error response.
- [ ] Run `pytest tests/test_receipts.py -q`; confirm the new tests fail for the missing endpoint.
- [ ] Implement extraction adapter and Starlette route with size/time limits and existing `MCP_API_KEYS` authentication; keep provider selection in environment configuration.
- [ ] Run focused and existing companion tests; confirm all pass. Commit in the companion repository.

### Task 2: Receipt schema and private images

**Files:** `custom/grocy_AI/src/GrocyAiReceiptMigration.php` (create), `custom/grocy_AI/src/GrocyAiReceiptImageStore.php` (create), `custom/grocy_AI/tests/receipt_storage.php` (create), `custom/grocy_AI/routes.php` (modify for class loading).

**Interfaces:** `GrocyAiReceiptMigration::Bootstrap(PDO $pdo): void`; image store `Save(int $tripId, UploadedFileInterface $image): array` and `Read(int $tripId, string $opaqueId): array`. New namespaced tables: receipt header, receipt line, stock allocation, append-only receipt audit, with trip/receipt foreign keys and revision fields.

- [ ] Write failing SQLite and file tests for multiple receipts, migration repeatability, private image access, invalid image bytes/type/size, guessed IDs, and duplicate upload request IDs.
- [ ] Run `php custom/grocy_AI/tests/receipt_storage.php` in a PHP runtime with `pdo_sqlite`; confirm failures.
- [ ] Implement migration and image store with bounded, validated, opaque files under `GROCY_DATAPATH`; atomically link image and header metadata.
- [ ] Run focused tests and `php -l` on changed PHP; commit.

### Task 3: Receipt audit domain model and matching

**Files:** `custom/grocy_AI/src/GrocyAiReceiptService.php` (create), `custom/grocy_AI/tests/receipt_audit.php` (create), `custom/grocy_AI/routes.php` (modify for class loading).

**Interfaces:** `ListForTrip(int $tripId): array`, `ImportExtraction(int $receiptId, array $suggestions, ?string $actor): array`, `UpdateLine(int $receiptId, int $lineId, array $change, ?string $actor): array`, `UpdateAllocation(int $receiptId, int $lineId, array $change, ?string $actor): array`, `Finish(int $receiptId, ?string $actor): array`, `Readiness(int $tripId): array`.

- [ ] Write failing tests for multi-receipt/store allocations, duplicate and split matches, explicit Ignore, receipt-only Include requiring known product, tax/discount reconciliation, total difference acceptance and invalidation, and unchanged audit history.
- [ ] Run `php custom/grocy_AI/tests/receipt_audit.php`; confirm failures.
- [ ] Implement immutable audit events, transactional revisions, suggestion-only matching, and readiness reasons. A finished receipt reopens after a substantive edit; no method writes Grocy stock.
- [ ] Run focused tests and `php -l`; commit.

### Task 4: Grocy-to-companion OCR and manual fallback

**Files:** `custom/grocy_AI/src/GrocyAiReceiptExtractor.php` (create), `custom/grocy_AI/tests/receipt_extractor.php` (create), module configuration/docs as needed.

**Interfaces:** `Extract(int $tripId, int $receiptId): array` sends server-held bytes to Task 1's endpoint with the configured API key, validates the closed response, then calls Task 3's `ImportExtraction`; `Retry` is idempotent for a given receipt revision.

- [ ] Write failing tests for valid OCR, timeout, provider outage, malformed JSON, size limits, duplicate retry, and no secret/receipt text in error output.
- [ ] Run focused PHP test; confirm failures.
- [ ] Implement bounded transport and response validation. On failure retain image and editable manual receipt state; do not mark it finished.
- [ ] Run focused test and PHP syntax checks; commit.

### Task 5: Receipt API and permissions

**Files:** `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/routes.php`, `custom/grocy_AI/tests/receipt_api.php` (create).

**Interfaces:** STOCK_PURCHASE-gated upload/list/read-image/extract/retry/edit-line/edit-allocation/finish/reopen endpoints under `/api/grocy-ai/capture/trips/{tripId}/receipts`; return receipt DTOs separately from existing exact-shape trip and line DTOs.

- [ ] Write failing API tests for auth, wrong trip/receipt relation, closed shapes, committed-trip read-only, invalid upload, safe image response, retry, and manual state after OCR failure.
- [ ] Run focused test; confirm failures.
- [ ] Wire Tasks 2–4 into controller/routes with explicit 4xx/5xx results and no arbitrary path/URL fetch.
- [ ] Run focused and existing capture contract tests, PHP syntax checks; commit.

### Task 6: Atomic receipt-gated purchase commit

**Files:** `custom/grocy_AI/src/GrocyAiCaptureService.php`, `custom/grocy_AI/src/GrocyAiApiController.php`, `custom/grocy_AI/tests/capture.php`, `custom/grocy_AI/tests/receipt_commit.php` (create).

**Interfaces:** Extend `ChecksumForTrip(int $tripId): string` with receipt revisions, decisions, prices, quantities, and allocations; `CommitTrip` returns `receipt_review_required` for missing/incomplete audit and rechecks readiness/checksum under `BEGIN IMMEDIATE`. Use receipt-level shopping location for each included allocation while retaining idempotent stock writes.

- [ ] Write failing tests for no receipt, unfinished receipt, Needs review, missing price, accepted difference, multi-store purchase, concurrent edit/checksum mismatch, conflict/partial commit, and repeat commit without duplicate stock rows.
- [ ] Run focused tests; confirm failures.
- [ ] Implement server guard and allocation-based stock writes with audit correlation; preserve already-committed trip behavior.
- [ ] Run all capture/receipt contract tests and PHP syntax checks; commit.

### Task 7: Mobile review experience

**Files:** `views/grocyai_capture_review.blade.php`, `public/custom/grocy_AI/capture-review.js`, `public/custom/grocy_AI/capture-receipts.js` (create), `public/custom/grocy_AI/grocy-ai.css`, `custom/grocy_AI/tests/browser/fixtures/capture-review.html`, `custom/grocy_AI/tests/browser/specs/purchase-receipts.spec.js` (create).

**Interfaces:** Add receipt camera/file upload, receipt list, image/line editing, match and Include/Ignore choices, total difference actions, finish/reopen, readiness summary, and disabled commit with specific blocking reasons. Preserve existing review link and double-confirm Finish scanning.

- [ ] Write failing mobile Chromium/WebKit tests for multiple photos, OCR failure/manual entry, receipt-only Ignore, price correction, total difference acceptance/edit invalidation, and commit readiness.
- [ ] Run `npx playwright test specs/purchase-receipts.spec.js` from `custom/grocy_AI/tests/browser`; confirm failures.
- [ ] Implement accessible 44px+ touch controls and explicit save/error states; update asset and view-cache versions.
- [ ] Run focused and existing purchase-capture browser tests on mobile Chromium/WebKit; commit.

### Task 8: Integrated acceptance and deployment

**Files:** `custom/grocy_AI/README.md`, `CUSTOMIZATIONS.md`, receipt runbook/acceptance document under `docs/`, production configuration for both repositories.

**Interfaces:** Release companion before Grocy, preserve persistent data, and verify OCR plus manual fallback. Production remains gated by the same server-side receipt readiness rule.

- [ ] Run complete companion and Grocy module tests in runtimes with required dependencies; inspect migration and data-path backup/recovery behavior.
- [ ] Run the mobile browser suite and an end-to-end rehearsal with two receipts, one ignored purchased item, and an accepted total difference; confirm no stock write before final commit.
- [ ] Perform independent whole-branch review and fix important findings; update docs and version markers; commit.
- [ ] Create/link PRs for both repositories, merge and deploy in dependency order, then verify authenticated live upload, OCR, audit, commit guard, and one user-observed phone acceptance without synthetic stock writes.

