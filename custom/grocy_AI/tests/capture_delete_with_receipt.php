<?php

declare(strict_types=1);

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService'] as $name)
{
	require_once __DIR__ . '/../src/' . $name . '.php';
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, active INTEGER NOT NULL DEFAULT 1)');
$db->exec('CREATE TABLE product_barcodes (barcode TEXT)');
$db->exec('CREATE TABLE shopping_locations (id INTEGER PRIMARY KEY, name TEXT)');
\GrocyAI\Services\GrocyAiCaptureMigration::Bootstrap($db);
\GrocyAI\Services\GrocyAiReceiptMigration::Bootstrap($db);
\GrocyAI\Services\GrocyAiCaptureResearchMigration::Bootstrap($db);
$capture = new \GrocyAI\Services\GrocyAiCaptureService($db);
$trip = $capture->StartTrip('tester');
$tripId = (int)$trip['id'];
$db->prepare("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, status) VALUES (?, 1, '012345678905', 'unknown'), (?, 2, '036000291452', 'unknown')")->execute([$tripId, $tripId]);
$db->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes) VALUES (?, 'test-image', 'image/png', 10)")->execute([$tripId]);
$receiptId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, description) VALUES (?, 1, 'Receipt item')")->execute([$receiptId]);
$receiptLineId = (int)$db->lastInsertId();
$firstLineId = (int)$db->query('SELECT id FROM grocy_ai_capture_lines WHERE seq = 1')->fetchColumn();
$secondLineId = (int)$db->query('SELECT id FROM grocy_ai_capture_lines WHERE seq = 2')->fetchColumn();
$db->exec("INSERT INTO grocy_ai_capture_research_jobs (canonical_gtin) VALUES ('00012345678905'), ('00036000291452')");
$db->prepare("INSERT INTO grocy_ai_capture_research_drafts (job_id, trip_id, line_id, scanned_barcode, receipt_line_id) VALUES (1, ?, ?, '012345678905', ?), (2, ?, ?, '036000291452', NULL)")->execute([$tripId, $firstLineId, $receiptLineId, $tripId, $secondLineId]);

$after = $capture->UpdateLine($tripId, 1, ['delete' => true], 'tester');
if (array_column($after['lines'], 'seq') !== [2]) throw new RuntimeException('Unlinked scan was not deleted from receipt-bearing trip');
if ((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipts')->fetchColumn() !== 1) throw new RuntimeException('Receipt was removed');
if ((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_audit WHERE action = 'delete_line'")->fetchColumn() !== 1) throw new RuntimeException('Delete was not audited');
if ((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE line_id IS NULL AND receipt_line_id = ' . $receiptLineId)->fetchColumn() !== 1) throw new RuntimeException('Deleted scan research history was not retained');

$remainingId = (int)$after['lines'][0]['id'];
(new \GrocyAI\Services\GrocyAiCaptureResearchService($db))->SetReceiptEvidence($tripId, $remainingId, $receiptLineId, 'tester');
$db->prepare('INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, capture_line_id, quantity, unit_price) VALUES (?, ?, ?, ?, 1, 2)')->execute([$tripId, $receiptId, $receiptLineId, $remainingId]);
try
{
	$capture->UpdateLine($tripId, 2, ['delete' => true], 'tester');
	throw new RuntimeException('Receipt-linked scan was deleted');
}
catch (DomainException $expected)
{
	if (!str_contains($expected->getMessage(), 'receipt allocation')) throw $expected;
}
if ((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE trip_id = ' . $tripId)->fetchColumn() !== 1) throw new RuntimeException('Linked scan changed after rejected delete');
echo "capture delete with receipt passed\n";
