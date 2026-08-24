# Quick Capture PWA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver an installable, live-connected Quick Capture PWA that turns a camera or hardware GTIN scan into a reviewed Quick Add or Quick Purchase without bypassing Grocy's normal persistence controls.

**Architecture:** Preserve Phase 2's contract-v2 product-editor flow. Add a separate Quick Capture v3 contract between grocy-mcp and the custom Grocy module, then provide an authenticated PWA page, a read-only server-owned draft, and a closed typed confirmation/recovery service.

**Tech Stack:** PHP 8.5, Slim 4, Blade, SQLite 3.40+, Bootstrap 4.6, jQuery, ZXing, plain JavaScript, Python 3, unittest, Playwright.

**Spec:** docs/superpowers/specs/2026-08-24-quick-capture-pwa-design.md

## Global Constraints

- Execute only after Phase 4 has a selected projection and passing activation evidence. This feature must never activate or write reusable universal conversions.
- Create isolated worktrees for both grocy and grocy-mcp. Tests use fixtures, never household data.
- Keep custom implementation in custom/grocy_AI and public/custom/grocy_AI. Document unavoidable core hooks in CUSTOMIZATIONS.md.
- Keep contract-v2 and product editor behavior unchanged. Nutrition is permitted only in Quick Capture v3.
- Browser responses expose no provider body, raw URL, secret, or provider-controlled mutation authority.
- Lookup, review, cancellation, retry, and navigation create no persistent data. Only confirmation mutates.
- Require MASTER_DATA_EDIT for drafts and product work; require STOCK_PURCHASE for Quick Purchase.
- Groups come only from closed mappings to active local groups. No provider value may create a group or assign Phase 3 taxonomy.
- Product-scoped package/count conversions alone can be saved. Preserve normal Grocy product, barcode, image, conversion, and purchase paths.
- Use tabs and next-line braces in PHP and JavaScript. Run php -l for each changed PHP file.

---

## File Structure

| File | Responsibility |
| --- | --- |
| ../grocy-mcp/grocy_mcp/quick_capture_contract.py | Exact, redacted provider envelope builder. |
| ../grocy-mcp/grocy_mcp/enrichment.py | Supplies OFF and Barcode Federation evidence to v3. |
| ../grocy-mcp/grocy_mcp/http_api.py | Registers the v3 companion endpoint. |
| custom/grocy_AI/src/GrocyAiQuickCaptureContract.php | Strict v3 response validator. |
| custom/grocy_AI/src/GrocyAiQuickCaptureMigration.php | Module nutrition and recovery schema. |
| custom/grocy_AI/src/GrocyAiQuickCaptureService.php | Draft, confirmation, and exact-once recovery. |
| custom/grocy_AI/src/GrocyAiQuickCaptureController.php | Authenticated page and API actions. |
| views/grocyaiquickcapture.blade.php | PWA shell. |
| public/custom/grocy_AI/quick-capture.js | Scan/review/confirm state machine. |
| public/custom/grocy_AI/quick-capture-sw.js | Static asset cache only. |
| public/custom/grocy_AI/quick-capture.webmanifest | Install metadata. |
| custom/grocy_AI/tests/quick-capture.php | Fixture-only PHP contract and workflow tests. |
| custom/grocy_AI/tests/browser/specs/quick-capture.spec.js | Mobile browser and a11y tests. |

## Task 1: Build the versioned companion enrichment contract

**Files:**
- Create: ../grocy-mcp/grocy_mcp/quick_capture_contract.py, ../grocy-mcp/tests/test_quick_capture_contract.py
- Modify: ../grocy-mcp/grocy_mcp/enrichment.py, ../grocy-mcp/grocy_mcp/http_api.py, ../grocy-mcp/tests/test_http_api.py

**Interfaces:**
- Consumes: existing normalized OFF/Barcode Federation lookup evidence.
- Produces: build_quick_capture_envelope(gtin: str, evidence: dict[str, Any]) -> dict[str, Any] and GET /v3/quick-capture/enrich/upc/{gtin}.

- [ ] **Step 1: Write failing contract tests**

    def test_agreed_high_confidence_field_is_selected() -> None:
        envelope = build_quick_capture_envelope("012345678905", fixture_evidence())
        assert envelope["contract_version"] == 3
        assert envelope["fields"]["name"]["state"] == "agreed"
        assert envelope["fields"]["name"]["selected"] is True

    def test_conflicting_values_are_not_selected() -> None:
        envelope = build_quick_capture_envelope("012345678905", conflicting_name_evidence())
        assert envelope["fields"]["name"]["state"] == "conflict"
        assert envelope["fields"]["name"]["selected"] is False

    def test_nutrition_has_a_source_and_100g_basis() -> None:
        nutrition = build_quick_capture_envelope("012345678905", fixture_evidence())["nutrition"]
        assert nutrition["basis"] == {"amount": 100, "unit": "g"}
        assert nutrition["source"]["id"] == "openfoodfacts"

- [ ] **Step 2: Run the failing tests**

Run: cd /Users/ian/Documents/Repos/grocy-mcp && python -m unittest tests.test_quick_capture_contract -v

Expected: FAIL because the v3 builder and route do not exist.

- [ ] **Step 3: Implement the closed v3 envelope**

    CONTRACT_VERSION = 3
    ALLOWED_NUTRIENTS = {
        "energy-kcal", "protein-g", "carbohydrate-g", "fat-g",
        "fiber-g", "sugars-g", "sodium-mg",
    }

    def build_quick_capture_envelope(gtin: str, evidence: dict[str, Any]) -> dict[str, Any]:
        return {
            "contract_version": CONTRACT_VERSION,
            "barcode": canonical_barcode(gtin),
            "fields": normalize_fields(evidence),
            "nutrition": normalize_nutrition(evidence),
            "media": normalize_media(evidence),
            "diagnostics": redacted_diagnostics(evidence),
        }

Emit field states agreed, conflict, missing, or low_confidence. Only agreed high-confidence values are selected. Emit nutrition only from structured OFF evidence and include source ID/reference, retrieval time, finite non-negative nutrient values, and an explicit g basis. Reject unknown members, duplicate IDs, provider URLs, invalid units, unsupported sources, and non-finite values. Do not modify /v1/products/enrich/upc or contract-v2 output.

- [ ] **Step 4: Run focused companion verification**

Run: cd /Users/ian/Documents/Repos/grocy-mcp && python -m unittest tests.test_quick_capture_contract tests.test_enrichment_contract tests.test_http_api -v

Expected: PASS; v3 works and v2 tests remain green.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy-mcp
    git add grocy_mcp/quick_capture_contract.py grocy_mcp/enrichment.py grocy_mcp/http_api.py tests/test_quick_capture_contract.py tests/test_http_api.py
    git commit -m "feat: add quick capture enrichment contract"

## Task 2: Validate v3 and create a zero-write draft

**Files:**
- Create: custom/grocy_AI/src/GrocyAiQuickCaptureContract.php, custom/grocy_AI/src/GrocyAiQuickCaptureService.php, custom/grocy_AI/tests/quick-capture.php, custom/grocy_AI/tests/fixtures/quick-capture-v3-cases.json
- Modify: custom/grocy_AI/routes.php, custom/grocy_AI/tests/run.php

**Interfaces:**
- Consumes: GET {GROCY_AI_SERVICE_URL}/v3/quick-capture/enrich/upc/{gtin} and GrocyAiBarcodeService::ResolveBeforeProvider.
- Produces: GrocyAiQuickCaptureService::CreateDraft(string $gtin, int $userId): array and GET /api/grocy-ai/quick-capture/drafts/{gtin}.

- [ ] **Step 1: Write failing PHP contract tests**

    $draft = $service->CreateDraft('012345678905', 42);
    quickCaptureAssert($draft['status'] === 'review_new', 'unused GTIN is review-only');
    quickCaptureAssert($draft['fields']['name']['selected'] === true, 'agreed name selected');
    quickCaptureAssertNoDatabaseWrites($fixturePdo, 'draft lookup');
    quickCaptureExpectInvalidEnvelope('provider_url_member');
    quickCaptureExpectInvalidEnvelope('unknown_nutrient_unit');

- [ ] **Step 2: Run the failing test**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-contract

Expected: FAIL because no v3 validator or draft service exists.

- [ ] **Step 3: Implement strict validation and draft issuance**

    public function CreateDraft(string $gtin, int $userId): array
    {
        $ownership = $this->barcodeService->ResolveOwner($gtin);
        if ($ownership['status'] === 'owned_other')
        {
            return $this->existingOwnerDraft((int)$ownership['owner_product_id']);
        }

        $envelope = $this->quickCaptureClient->Enrich($gtin);
        return $this->draftStore->Issue($userId, $this->contract->Validate($envelope), $ownership);
    }

Check MASTER_DATA_EDIT before GTIN ownership resolution or provider work. Validate the complete v3 JSON before returning any field. Response contains only opaque draft ID, local owner outcome, editable candidates, active mapped group options, product-scoped conversion candidates, nutrition display data, and a redacted trace ID. Do not mutate taxonomy, products, barcodes, files, conversions, or stock.

- [ ] **Step 4: Run focused verification**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-contract && php -l custom/grocy_AI/src/GrocyAiQuickCaptureContract.php && php -l custom/grocy_AI/src/GrocyAiQuickCaptureService.php

Expected: PASS with zero fixture writes.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add custom/grocy_AI/routes.php custom/grocy_AI/src/GrocyAiQuickCaptureContract.php custom/grocy_AI/src/GrocyAiQuickCaptureService.php custom/grocy_AI/tests/run.php custom/grocy_AI/tests/quick-capture.php custom/grocy_AI/tests/fixtures/quick-capture-v3-cases.json
    git commit -m "feat: add quick capture review drafts"

## Task 3: Add source-stamped nutrition and recovery schema

**Files:**
- Create: custom/grocy_AI/src/GrocyAiQuickCaptureMigration.php
- Modify: custom/grocy_AI/routes.php, custom/grocy_AI/src/GrocyAiQuickCaptureService.php, custom/grocy_AI/tests/quick-capture.php

**Interfaces:**
- Consumes: validated selected nutrition and confirmed product ID.
- Produces: StoreSelectedNutrition(int $productId, array $nutrition, string $draftId): void and RecoveryStatus(string $draftId, int $userId): array.

- [ ] **Step 1: Write failing idempotency tests**

    $service->StoreSelectedNutrition(501, $selectedNutrition, 'qc_draft_01');
    $service->StoreSelectedNutrition(501, $selectedNutrition, 'qc_draft_01');
    quickCaptureAssertRowCount($pdo, 'grocy_ai_product_nutrition', 1);
    quickCaptureAssertSame('openfoodfacts', quickCaptureNutrition($pdo, 501)['source_id']);
    quickCaptureAssertRecovery($service->RecoveryStatus('qc_draft_01', 42), 'nutrition_complete');

- [ ] **Step 2: Run the failing test**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-persistence

Expected: FAIL because the module tables do not exist.

- [ ] **Step 3: Implement the registered module migration**

    CREATE TABLE grocy_ai_product_nutrition (
        product_id INTEGER PRIMARY KEY,
        basis_amount REAL NOT NULL,
        basis_unit TEXT NOT NULL CHECK (basis_unit = 'g'),
        nutrients_json TEXT NOT NULL,
        source_id TEXT NOT NULL,
        source_reference TEXT NOT NULL,
        retrieved_at TEXT NOT NULL,
        contract_version INTEGER NOT NULL,
        updated_at TEXT NOT NULL
    );

Also create grocy_ai_quick_capture_operations keyed by draft ID plus literal operation product, barcode, image, nutrition, or purchase. Store authenticated user ID, status, target ID, and timestamps only; do not store provider body or barcode. Register migration through the existing module boot pattern. Revalidate nutrition, transact its write, and permit replacement only for the same confirmed draft.

- [ ] **Step 4: Run persistence verification**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-persistence && php -l custom/grocy_AI/src/GrocyAiQuickCaptureMigration.php

Expected: PASS; a duplicate operation has one row and recovery data is redacted.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add custom/grocy_AI/routes.php custom/grocy_AI/src/GrocyAiQuickCaptureMigration.php custom/grocy_AI/src/GrocyAiQuickCaptureService.php custom/grocy_AI/tests/quick-capture.php
    git commit -m "feat: persist quick capture nutrition"

## Task 4: Implement a typed confirmation and exact-once recovery service

**Files:**
- Create: custom/grocy_AI/src/GrocyAiQuickCaptureController.php
- Modify: custom/grocy_AI/routes.php, custom/grocy_AI/src/GrocyAiQuickCaptureService.php, custom/grocy_AI/tests/quick-capture.php

**Interfaces:**
- Consumes: POST /api/grocy-ai/quick-capture/confirm body {draft_id, action, selections, purchase}.
- Produces: {draft_id, status, product_id, completed_operations, retryable_operation}; actions are literal quick_add and quick_purchase.

- [ ] **Step 1: Write failing confirmation tests**

    $result = $fixture->Confirm([
        'draft_id' => 'qc_draft_01',
        'action' => 'quick_purchase',
        'selections' => ['name' => 'Fixture oats', 'group_id' => 7],
        'purchase' => ['amount' => 2, 'location_id' => 1, 'shopping_location_id' => 3],
    ]);
    quickCaptureAssertSame(1, $fixture->productCreates());
    quickCaptureAssertSame(1, $fixture->barcodeCreates());
    quickCaptureAssertSame(1, $fixture->purchaseCreates());
    quickCaptureAssertSame($result, $fixture->Confirm($sameRequest));

- [ ] **Step 2: Run the failing test**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-confirm

Expected: FAIL because confirmation is absent.

- [ ] **Step 3: Implement the closed command**

    public function Confirm(Request $request, Response $response, array $args): Response
    {
        $payload = $request->getParsedBody();
        User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
        if (($payload['action'] ?? null) === 'quick_purchase')
        {
            User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
        }

        return $this->ApiResponse($response, $this->service->Confirm($payload, $this->CurrentUserId()));
    }

Rehydrate the draft by authenticated user. Accept only fixed selection keys and action literals; reject browser source metadata and verify selected IDs against the stored draft. Check group activity, package/count conversion scope, owner race, and stock API input. Call the same Grocy product/barcode/image/conversion/purchase flows used by normal forms. Record completion before each next operation; retries reuse stored IDs and execute only the incomplete operation. Expired, foreign, stale, or universal conversion input fails closed.

- [ ] **Step 4: Run confirmation verification**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-confirm && php custom/grocy_AI/tests/run.php barcode-handoff && php -l custom/grocy_AI/src/GrocyAiQuickCaptureController.php

Expected: PASS; confirmation is exact-once and Phase 2 recovery is unchanged.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add custom/grocy_AI/routes.php custom/grocy_AI/src/GrocyAiQuickCaptureController.php custom/grocy_AI/src/GrocyAiQuickCaptureService.php custom/grocy_AI/tests/quick-capture.php
    git commit -m "feat: confirm quick add and purchase"

## Task 5: Build the installable PWA shell

**Files:**
- Create: views/grocyaiquickcapture.blade.php, public/custom/grocy_AI/quick-capture.webmanifest, public/custom/grocy_AI/quick-capture-sw.js, public/custom/grocy_AI/quick-capture.css
- Modify: custom/grocy_AI/routes.php, custom/grocy_AI/src/GrocyAiQuickCaptureController.php, custom/grocy_AI/portable-files.txt, CUSTOMIZATIONS.md, custom/grocy_AI/tests/quick-capture.php

**Interfaces:**
- Consumes: authenticated GET /grocy-ai/quick-capture.
- Produces: Scan and Review landmarks with no provider values server-rendered.

- [ ] **Step 1: Write failing shell tests**

    quickCaptureAssertContains('id="quick-capture-scan"', $rendered);
    quickCaptureAssertContains('id="quick-capture-review"', $rendered);
    quickCaptureAssertContains('rel="manifest"', $rendered);
    quickCaptureAssertNotContains('openfoodfacts.org', $rendered);
    quickCaptureAssertNotContains('api/grocy-ai', $serviceWorkerSource);

- [ ] **Step 2: Run the failing test**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-shell

Expected: FAIL because the page and assets are absent.

- [ ] **Step 3: Implement static-only PWA assets**

    $app->get('/grocy-ai/quick-capture', [GrocyAiQuickCaptureController::class, 'Page']);

Render a mobile-first labelled GTIN input, camera target, live status, review region, source/conflict list, nutrition table, action selector, purchase fields, and disabled confirmation buttons. Reuse the existing camera barcode component. Manifest icon URLs are checked-in local assets. Worker uses a versioned cache and cache.addAll for exact local assets; fetch handling can serve only same-origin GET requests from that allowlist and must forward HTML, API, media, and non-GET traffic to network.

- [ ] **Step 4: Run shell verification**

Run: cd /Users/ian/Documents/Repos/grocy && php custom/grocy_AI/tests/run.php quick-capture-shell && php -l custom/grocy_AI/src/GrocyAiQuickCaptureController.php

Expected: PASS; no API request is cacheable by the worker.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add views/grocyaiquickcapture.blade.php public/custom/grocy_AI/quick-capture.webmanifest public/custom/grocy_AI/quick-capture-sw.js public/custom/grocy_AI/quick-capture.css custom/grocy_AI/routes.php custom/grocy_AI/src/GrocyAiQuickCaptureController.php custom/grocy_AI/portable-files.txt CUSTOMIZATIONS.md custom/grocy_AI/tests/quick-capture.php
    git commit -m "feat: add quick capture PWA shell"

## Task 6: Build scan, review, and device-preference behavior

**Files:**
- Create: public/custom/grocy_AI/quick-capture.js, public/custom/grocy_AI/quick-capture.test.js, custom/grocy_AI/tests/browser/fixtures/quick-capture.html, custom/grocy_AI/tests/browser/specs/quick-capture.spec.js
- Modify: views/grocyaiquickcapture.blade.php, custom/grocy_AI/tests/browser/support/server.mjs, custom/grocy_AI/tests/browser/package.json

**Interfaces:**
- Consumes: v3 draft response and Grocy.Components.CameraBarcodeScanner.
- Produces: QuickCapture.startScan(gtin), QuickCapture.cancelScan(), QuickCapture.renderDraft(draft), and localStorage key grocy-ai.quick-capture.purchase.v1.

- [ ] **Step 1: Write failing mobile tests**

    test('new scan ignores older delayed draft', async ({ page }) => {
      await page.fill('#quick-capture-gtin', '012345678905');
      await page.keyboard.press('Enter');
      await page.fill('#quick-capture-gtin', '036000291452');
      await page.keyboard.press('Enter');
      await expect(page.getByText('Second fixture product')).toBeVisible();
      await expect(page.getByText('First fixture product')).toHaveCount(0);
    });

    test('review makes no mutation request', async ({ page }) => {
      await scanFixture(page, '012345678905');
      expect(await page.evaluate(() => window.__fixtureCounters.mutations)).toBe(0);
    });

- [ ] **Step 2: Run the failing tests**

Run: cd /Users/ian/Documents/Repos/grocy && node --test public/custom/grocy_AI/quick-capture.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @quickcapture

Expected: FAIL because client assets and fixtures are absent.

- [ ] **Step 3: Implement the scan/review state machine**

    function beginGeneration(gtin)
    {
        state.generation++;
        var generation = state.generation;
        return requestDraft(gtin).then(function(draft)
        {
            if (generation !== state.generation)
            {
                return;
            }
            renderDraft(draft);
        });
    }

Bind camera output, hardware scanner Enter, and typed Enter to beginGeneration. Validate GTIN locally before network. Cancel/navigation increments generation and aborts pending work. Use textContent and fixed DOM controls for all provider-derived data. Existing owner hides Quick Add. Store only successful Quick Purchase defaults amount, price, best_before_date, location_id, and shopping_location_id; validate them on read. Do not store GTIN, product ID, provider data, nutrition, draft ID, cookie, or pending operation.

- [ ] **Step 4: Run browser verification**

Run: cd /Users/ian/Documents/Repos/grocy && node --test public/custom/grocy_AI/quick-capture.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @quickcapture

Expected: PASS for camera/wedge parity, cancellation, conflict UI, zero-write review, local preference limits, keyboard operation, and phone width.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add public/custom/grocy_AI/quick-capture.js public/custom/grocy_AI/quick-capture.test.js custom/grocy_AI/tests/browser/fixtures/quick-capture.html custom/grocy_AI/tests/browser/specs/quick-capture.spec.js custom/grocy_AI/tests/browser/support/server.mjs custom/grocy_AI/tests/browser/package.json views/grocyaiquickcapture.blade.php
    git commit -m "feat: add quick capture scan review UI"

## Task 7: Wire confirm/retry behavior

**Files:**
- Modify: public/custom/grocy_AI/quick-capture.js, public/custom/grocy_AI/quick-capture.test.js, custom/grocy_AI/tests/browser/fixtures/quick-capture.html, custom/grocy_AI/tests/browser/specs/quick-capture.spec.js, custom/grocy_AI/tests/quick-capture.php

**Interfaces:**
- Consumes: {draft_id, status, product_id, completed_operations, retryable_operation}.
- Produces: one disabled confirmation interaction and an exact retry control.

- [ ] **Step 1: Write failing recovery tests**

    test('purchase retry resumes only the missing purchase', async ({ page }) => {
      await scanFixture(page, '012345678905');
      await page.getByRole('button', { name: 'Confirm Quick Purchase' }).click();
      await expect(page.getByRole('button', { name: 'Retry purchase' })).toBeVisible();
      await page.getByRole('button', { name: 'Retry purchase' }).click();
      expect(await page.evaluate(() => window.__fixtureCounters.productCreates)).toBe(1);
      expect(await page.evaluate(() => window.__fixtureCounters.purchaseCreates)).toBe(1);
    });

- [ ] **Step 2: Run the failing tests**

Run: cd /Users/ian/Documents/Repos/grocy && node --test public/custom/grocy_AI/quick-capture.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @quickcapture-recovery && php custom/grocy_AI/tests/run.php quick-capture-confirm

Expected: FAIL because browser confirmation is not wired.

- [ ] **Step 3: Implement closed confirmation choreography**

    function confirmCurrentDraft(action)
    {
        setConfirmBusy(true);
        Grocy.Api.Post('grocy-ai/quick-capture/confirm', buildConfirmRequest(action), handleCompletion, handleFailure);
    }

Build the request only from opaque draft ID, literal action, form values, and server-issued candidate IDs. Disable both confirmation buttons before POST. Show retry barcode attachment, image attachment, nutrition save, or purchase only if the server returns that exact retryable operation. Retry reuses only the original draft ID. Reset scan state and persist preferences only after complete Quick Purchase.

- [ ] **Step 4: Run recovery verification**

Run: cd /Users/ian/Documents/Repos/grocy && node --test public/custom/grocy_AI/quick-capture.test.js && npm --prefix custom/grocy_AI/tests/browser test -- --grep @quickcapture && php custom/grocy_AI/tests/run.php quick-capture-confirm

Expected: PASS; repeated click, reload, or delayed response cannot duplicate a product, barcode, nutrition record, or purchase.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add public/custom/grocy_AI/quick-capture.js public/custom/grocy_AI/quick-capture.test.js custom/grocy_AI/tests/browser/fixtures/quick-capture.html custom/grocy_AI/tests/browser/specs/quick-capture.spec.js custom/grocy_AI/tests/quick-capture.php
    git commit -m "feat: recover quick capture confirmations"

## Task 8: Add parity gates and phase documentation

**Files:**
- Modify: custom/grocy_AI/README.md, custom/grocy_AI/portable-files.txt, custom/grocy_AI/tests/release-gate.sh, custom/grocy_AI/tests/deployment-gate.sh, .planning/ROADMAP.md, .planning/REQUIREMENTS.md, CUSTOMIZATIONS.md

**Interfaces:**
- Consumes: completed PWA, v3 route, and Phase 4 gate evidence.
- Produces: portable main/stable verification and a roadmap that names Quick Capture as Phase 5.

- [ ] **Step 1: Write failing release-gate checks**

    require_portable_path 'public/custom/grocy_AI/quick-capture.js'
    require_portable_path 'public/custom/grocy_AI/quick-capture-sw.js'
    require_page_asset '/grocy-ai/quick-capture' 'quick-capture.js'
    require_http_status '/api/grocy-ai/quick-capture/drafts/012345678905' '401|403'

- [ ] **Step 2: Run the gate to verify it fails**

Run: cd /Users/ian/Documents/Repos/grocy && bash custom/grocy_AI/tests/release-gate.sh quick-capture

Expected: FAIL until paths, authentication, v3 shape, and parity checks exist.

- [ ] **Step 3: Add release evidence and docs**

Document the v3 endpoint, permissions, live-only PWA, service-worker allowlist, source-stamped nutrition, conflict behavior, device preference limits, retry semantics, and universal-conversion prohibition. Gate v2 regression, v3 redaction, unauthenticated draft/confirm denial, worker API non-caching, and main/stable byte parity. Update ROADMAP to insert Phase 5 Quick Capture PWA, move Bulk Maintenance to Phase 6, and shift following numbers/references consistently. Add explicit requirements for scan parity, zero-write preview, selected-only confirmation, conflicts, nutrition provenance, exact-once recovery, and static-only caching.

- [ ] **Step 4: Run complete verification**

    cd /Users/ian/Documents/Repos/grocy
    php custom/grocy_AI/tests/run.php
    node --test public/custom/grocy_AI/quick-capture.test.js
    npm --prefix custom/grocy_AI/tests/browser test -- --grep '@quickcapture|@enr|@mob'
    bash custom/grocy_AI/tests/check-portable-parity.sh
    bash custom/grocy_AI/tests/release-gate.sh quick-capture
    git diff --check

Expected: PASS. Keep all evidence fixture-based/redacted.

- [ ] **Step 5: Commit**

    cd /Users/ian/Documents/Repos/grocy
    git add custom/grocy_AI/README.md custom/grocy_AI/portable-files.txt custom/grocy_AI/tests/release-gate.sh custom/grocy_AI/tests/deployment-gate.sh .planning/ROADMAP.md .planning/REQUIREMENTS.md CUSTOMIZATIONS.md
    git commit -m "docs: document quick capture PWA release gate"

## Plan Self-Review

- Spec coverage: Tasks 1-2 cover sources, strict validation, selection/conflicts, and no raw data. Tasks 3-4 cover nutrition, normal persistence, permissions, and recovery. Tasks 5-7 cover installability, camera/wedge scanning, live behavior, manual editing, preferences, and accessibility. Task 8 covers parity and the new roadmap phase.
- Placeholder scan: every task names files, interfaces, a failing assertion, implementation behavior, verification command, and commit.
- Type consistency: draft_id, contract version 3, quick_add, quick_purchase, completed_operations, and retryable_operation are defined once and used consistently.
