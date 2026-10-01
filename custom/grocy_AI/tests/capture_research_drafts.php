<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiCaptureResearchService;

require_once __DIR__ . '/../../../packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiTaxonomyMigration', 'GrocyAiCaptureResearchService'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';

function checkDraft(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectDraft(callable $operation): void { try { $operation(); } catch (InvalidArgumentException|RuntimeException $expected) { return; } throw new RuntimeException('Expected rejection'); }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
GrocyAiCaptureMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test'), (2, 'reviewing', 'test'), (3, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (1, 1, 1, '4006381333931', '04006381333931', 'unknown'), (2, 2, 1, '96385074', '00000096385074', 'unknown'), (3, 3, 1, '012345678905', '00012345678905', 'unknown')");
$db->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Seafood', 1), (2, 'Produce', 1), (3, 'Other', 0)");
$db->exec("INSERT INTO products (id, name) VALUES (1, 'Existing fish')");
$service = new GrocyAiCaptureResearchService($db);
$service->EnqueueUnknown(1, 1, '4006381333931');
$service->EnqueueUnknown(2, 2, '96385074');
$service->EnqueueUnknown(3, 3, '012345678905');
$db->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = 3");
$db->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (1, 1, 'one', 'image/jpeg', 1), (2, 2, 'two', 'image/jpeg', 1)");
$db->exec("INSERT INTO grocy_ai_receipt_lines (id, receipt_id, seq, description, kind, decision) VALUES (1, 1, 1, 'Receipt Granola', 'item', 'needs_review'), (2, 1, 2, 'Other cereal', 'item', 'needs_review'), (3, 2, 1, 'Wrong trip', 'item', 'needs_review')");
$claim = $service->ClaimJobs(1, 'draft-test')[0];
$miss = ['contract_version' => 1, 'canonical_gtin' => '04006381333931', 'outcome' => 'miss', 'name_candidates' => [], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => []];
$service->CompleteJob((int)$claim['id'], $claim['lease_token'], $miss);
checkDraft(($service->ReviewForTrip(1)['drafts'][0]['selected']['name'] ?? null) === null, 'ambiguous OCR cannot provide a name');
rejectDraft(fn() => $service->SetReceiptEvidence(1, 1, 3, 'tester'));
$paired = $service->SetReceiptEvidence(1, 1, 1, 'tester');
checkDraft($paired['selected']['name'] === 'Receipt Granola' && $paired['receipt_evidence']['source'] === 'receipt_ocr', 'explicit same-trip receipt gives provisional name');
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (4, 1, 2, '036000291452', '00036000291452', 'unknown')");
$service->EnqueueUnknown(1, 4, '036000291452');
rejectDraft(fn() => $service->SetReceiptEvidence(1, 4, 1, 'tester'));
$db->exec("INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, capture_line_id, quantity, unit_price) VALUES (1, 1, 2, 4, 1, 1)");
rejectDraft(fn() => $service->SetReceiptEvidence(1, 1, 2, 'tester'));
$receiptService = new GrocyAI\Services\GrocyAiReceiptService($db);
$receiptService->UpdateLine(1, 1, ['description' => 'Corrected Granola'], 'tester');
$corrected = $service->ReviewForTrip(1)['drafts'][0];
checkDraft($corrected['selected']['name'] === 'Corrected Granola', 'receipt correction refreshes unedited proposal');
$edited = $service->UpdateDraft(1, 1, $corrected['revision'], ['name' => 'My granola'], 'tester');
rejectDraft(fn() => $service->UpdateDraft(1, 1, $paired['revision'], ['name' => 'Stale'], 'tester'));
checkDraft($edited['selected']['name'] === 'My granola', 'manual name selected');
$receiptService->UpdateLine(1, 1, ['description' => 'Later correction'], 'tester');
checkDraft($service->ReviewForTrip(1)['drafts'][0]['selected']['name'] === 'My granola', 'receipt correction preserves user edit');
$service->RetryJob(1, 1, 'tester');
checkDraft($service->ReviewForTrip(1)['drafts'][0]['selected']['name'] === 'My granola', 'retry preserves manual edit');
$reclaim = $service->ClaimJobs(1, 'draft-retry')[0];
$service->CompleteJob((int)$reclaim['id'], $reclaim['lease_token'], ['contract_version' => 1, 'canonical_gtin' => '04006381333931', 'outcome' => 'found', 'name_candidates' => ['Existing fish', 'OFF fish'], 'name_candidate_sources' => [['bb-federation'], ['openfoodfacts']], 'brand' => 'Brand', 'package' => '1 kg', 'categories' => ['Seafood'], 'sources' => ['bb-federation', 'openfoodfacts']]);
$review = $service->ReviewForTrip(1)['drafts'][0];
checkDraft($review['selected']['name'] === 'My granola' && $review['name_alternatives'][0]['sources'] === ['bb-federation'] && $review['name_alternatives'][1]['sources'] === ['openfoodfacts'], 'provider retry cannot overwrite user edit and each name has its own sources');
checkDraft($review['group_candidates'][0]['id'] === 1 && $review['taxonomy_candidates'][0]['slug'] === 'meat-seafood', 'exact active group and versioned taxonomy rule proposed');
checkDraft($review['possible_existing_products'][0]['id'] === 1 && !isset($review['selected']['product_group_id']), 'existing match and group remain unapplied');
$sharedNameClaim = $service->ClaimJobs(1, 'shared-name-test')[0];
checkDraft($sharedNameClaim['canonical_gtin'] === '00000096385074', 'second job claim is the expected barcode');
$service->CompleteJob((int)$sharedNameClaim['id'], $sharedNameClaim['lease_token'], ['contract_version' => 1, 'canonical_gtin' => '00000096385074', 'outcome' => 'found', 'name_candidates' => ['Shared Milk'], 'name_candidate_sources' => [['bb-federation', 'openfoodfacts']], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['bb-federation', 'openfoodfacts']]);
checkDraft($service->ReviewForTrip(2)['drafts'][0]['name_alternatives'][0]['sources'] === ['bb-federation', 'openfoodfacts'], 'same exact name retains both provider sources in review');
$unknownNameClaim = $service->ClaimJobs(1, 'unknown-name-source-test')[0];
checkDraft($unknownNameClaim['canonical_gtin'] === '00036000291452', 'fallback provenance claim uses second active scan');
$service->CompleteJob((int)$unknownNameClaim['id'], $unknownNameClaim['lease_token'], ['contract_version' => 1, 'canonical_gtin' => '00036000291452', 'outcome' => 'found', 'name_candidates' => ['Unattributed name'], 'name_candidate_sources' => [[]], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['bb-federation']]);
$unattributed = $service->ReviewForTrip(1)['drafts'][1]['name_alternatives'][0];
checkDraft($unattributed['sources'] === [] && $unattributed['provenance'] === 'unknown', 'companion fallback with empty per-name source is labeled unknown');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json = '{\"contract_version\":1,\"outcome\":\"found\",\"name_candidates\":[\"Existing fish\"],\"categories\":[\"Seafood\",\"Produce\"],\"sources\":[\"openfoodfacts\"]}' WHERE line_id = 1");
$ambiguous = $service->ReviewForTrip(1)['drafts'][0];
checkDraft($ambiguous['group_candidates'] === [] && $ambiguous['taxonomy_candidates'] === [], 'ambiguous categories remain unset');
$updateSuggestion = $db->prepare('UPDATE grocy_ai_capture_research_drafts SET suggested_json = ? WHERE line_id = 1');
foreach (['Baby Food', 'Unsupported Category'] as $unsafeCategory)
{
	$updateSuggestion->execute([json_encode(['contract_version' => 1, 'outcome' => 'found', 'name_candidates' => ['Existing fish'], 'name_candidate_sources' => [['openfoodfacts']], 'categories' => ['Seafood', $unsafeCategory], 'sources' => ['openfoodfacts']], JSON_THROW_ON_ERROR)]);
	$mixed = $service->ReviewForTrip(1)['drafts'][0];
	checkDraft($mixed['taxonomy_candidates'] === [] && $mixed['group_candidates'] === [], 'excluded or unsupported category prevents category proposals');
}
$db->exec("UPDATE grocy_ai_capture_research_drafts SET suggested_json = '{\"contract_version\":1,\"outcome\":\"found\",\"name_candidates\":[\"Existing fish\"],\"categories\":[],\"sources\":[\"bb-federation\"]}' WHERE line_id = 1");
$federation = $service->ReviewForTrip(1)['drafts'][0];
checkDraft($federation['taxonomy_candidates'] === [] && $federation['group_candidates'] === [] && $federation['name_alternatives'][0]['sources'] === [] && $federation['name_alternatives'][0]['provenance'] === 'unknown', 'legacy Federation-only name gives no OFF category or fabricated provenance');
rejectDraft(fn() => $service->UpdateDraft(2, 1, 1, ['name' => 'wrong'], 'tester'));
rejectDraft(fn() => $service->UpdateDraft(3, 3, 1, ['name' => 'No'], 'tester'));
$db->exec("INSERT INTO products (id, name) VALUES (2, 'Known product')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, status, resolved_product_id) VALUES (5, 1, 3, 'known', 'known', 2)");
$db->exec("INSERT INTO grocy_ai_receipt_lines (id, receipt_id, seq, description, kind, decision) VALUES (4, 1, 4, 'Second scan item', 'item', 'include')");
$db->exec("INSERT INTO grocy_ai_receipt_allocations (id, trip_id, receipt_id, receipt_line_id, capture_line_id, product_id, quantity, unit_price) VALUES (4, 1, 1, 4, 4, 2, 1, 1)");
$service->SetReceiptEvidence(1, 4, 4, 'tester');
rejectDraft(fn() => $receiptService->UpdateAllocation(1, 4, ['id' => 4, 'capture_line_id' => 5], 'tester'));
$receiptService->UpdateLine(1, 1, ['decision' => 'include'], 'tester');
rejectDraft(fn() => $receiptService->UpdateAllocation(1, 1, ['capture_line_id' => 5, 'product_id' => 2, 'quantity' => 1, 'unit_price' => 1], 'tester'));
$service->SetReceiptEvidence(1, 1, null, 'tester');
$allocated = $receiptService->UpdateAllocation(1, 1, ['capture_line_id' => 5, 'product_id' => 2, 'quantity' => 1, 'unit_price' => 1], 'tester');
checkDraft((int)$allocated['lines'][0]['allocations'][0]['capture_line_id'] === 5, 'allocation succeeds after conflicting evidence is cleared');
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (1, 'tester')");
rejectDraft(fn() => $service->UpdateDraft(1, 1, $edited['revision'], ['name' => 'No'], 'tester'));
rejectDraft(fn() => $service->RetryJob(1, 1, 'tester'));
checkDraft((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 2 && (int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'review never writes product or stock');
echo "capture research drafts: PASS\n";
