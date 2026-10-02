<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiReceiptMigration;
use GrocyAI\Services\GrocyAiCaptureResearchMigration;
use GrocyAI\Services\GrocyAiReceiptService;

require_once __DIR__ . '/../src/GrocyAiCaptureMigration.php';
require_once __DIR__ . '/../src/GrocyAiReceiptMigration.php';
require_once __DIR__ . '/../src/GrocyAiCaptureResearchMigration.php';
require_once __DIR__ . '/../src/GrocyAiReceiptService.php';

function checkMatch(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE shopping_locations (id INTEGER PRIMARY KEY, name TEXT)');
GrocyAiCaptureResearchMigration::Bootstrap($db);
$db->exec("INSERT INTO products VALUES (101, 'Whole Milk')");
$db->exec("INSERT INTO grocy_ai_capture_trips (id,status,module_version) VALUES (1,'reviewing','test'),(2,'reviewing','test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id,trip_id,seq,scanned_barcode,status,quantity,selected) VALUES (11,1,1,'1111111111111','unknown',1,1),(12,1,2,'2222222222222','unknown',1,1),(13,2,1,'3333333333333','unknown',1,1)");
$db->exec("INSERT INTO grocy_ai_capture_research_jobs (id,canonical_gtin) VALUES (1,'01111111111111'),(2,'02222222222222'),(3,'03333333333333')");
$db->exec("INSERT INTO grocy_ai_capture_research_drafts (job_id,trip_id,line_id,scanned_barcode,selected_json,suggested_json,outcome) VALUES (1,1,11,'1111111111111','{\"name\":\"Organic Bananas\"}','{\"name_candidates\":[\"Organic Bananas\"]}','ready'),(2,1,12,'2222222222222','{}','{\"name_candidates\":[\"Dark Chocolate\"]}','ready'),(3,2,13,'3333333333333','{\"name\":\"Organic Bananas\"}','{}','ready')");
$db->exec("INSERT INTO grocy_ai_receipts (id,trip_id,image_id,mime_type,image_bytes) VALUES (21,1,'a','image/png',20)");
$db->exec("INSERT INTO grocy_ai_receipt_lines (id,receipt_id,seq,description,kind,decision) VALUES (31,21,1,'ORGANIC BANANAS','item','needs_review'),(32,21,2,'DARK CHOCOLATE','item','needs_review'),(33,21,3,'UNKNOWN PRODUCE','item','needs_review')");
$service = new GrocyAiReceiptService($db);
$lines = $service->ListForTrip(1)[0]['lines'];
checkMatch($lines[0]['capture_match_status'] === 'unique' && $lines[0]['capture_candidates'][0]['capture_line_id'] === 11, 'research draft name suggests same-trip unknown scan');
checkMatch($lines[1]['capture_match_status'] === 'unique' && $lines[1]['capture_candidates'][0]['capture_line_id'] === 12, 'suggested name works before draft selection');
checkMatch($lines[2]['capture_match_status'] === 'none' && $lines[2]['capture_candidates'] === [], 'unrelated OCR text never matches by barcode alone');
checkMatch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipt_allocations')->fetchColumn() === 0, 'read suggestions do not allocate');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET selected_json = '{\"name\":\"Organic Bananas\"}' WHERE line_id = 12");
$lines = $service->ListForTrip(1)[0]['lines'];
checkMatch($lines[0]['capture_match_status'] === 'ambiguous' && count($lines[0]['capture_candidates']) === 2, 'equal names remain ambiguous');
checkMatch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipt_allocations')->fetchColumn() === 0, 'ambiguous suggestion does not allocate');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = 31, outcome = 'approved' WHERE line_id = 11");
$paired = $service->ListForTrip(1)[0]['lines'][0];
checkMatch($paired['paired_capture_line_id'] === 11, 'approved research evidence pairing remains visible');
checkMatch(count($paired['capture_candidates']) === 2, 'pairing does not silently hide ambiguous alternatives');
$db->exec('UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = 31 WHERE line_id = 12');
checkMatch($service->ListForTrip(1)[0]['lines'][0]['paired_capture_line_id'] === null, 'duplicate research pairings never pick an arbitrary scan');
$db->exec('UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = NULL WHERE line_id = 12');
$reviewLines = $service->ListForTrip(1)[0]['lines'];
checkMatch(count($reviewLines[0]['capture_options']) === 2, 'current receipt line can see its own paired scan and unclaimed scans');
checkMatch($reviewLines[0]['capture_options'][0]['paired_receipt_line_id'] === 31, 'manual scan option carries existing pairing');
checkMatch($reviewLines[0]['capture_options'][1]['display_name'] === 'Organic Bananas', 'manual options use researched name when available');
checkMatch(count($reviewLines[1]['capture_options']) === 1 && $reviewLines[1]['capture_options'][0]['capture_line_id'] === 12, 'other receipt line cannot select already paired scan');
checkMatch(count($reviewLines[1]['capture_candidates']) === 1 && $reviewLines[1]['capture_candidates'][0]['capture_line_id'] === 12, 'paired scan is excluded from unrelated strong suggestions');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET selected_json = '{}', suggested_json = '{}' WHERE line_id = 12");
$fallback = $service->ListForTrip(1)[0]['lines'][1]['capture_options'][0];
checkMatch($fallback['display_name'] === 'UPC 2222222222222' && $fallback['source'] === 'barcode', 'unresearched unknown scan remains manually selectable by UPC');
echo "receipt scan matches passed\n";
