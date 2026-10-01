<?php

declare(strict_types=1);

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService'] as $file)
{
	require_once __DIR__ . '/../src/' . $file . '.php';
}
require_once __DIR__ . '/../bin/capture-research-backfill.php';

function backfillCheck(bool $ok, string $message): void
{
	if (!$ok) throw new RuntimeException($message);
}

function backfillReject(callable $operation, string $message): void
{
	try { $operation(); } catch (RuntimeException $expected) { return; }
	throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER, barcode TEXT)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY, note TEXT)');
$db->exec("INSERT INTO stock_log VALUES (1, 'untouched')");
GrocyAI\Services\GrocyAiCaptureResearchMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (12, 'reviewing', 'test'), (13, 'open', 'test'), (14, 'open', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status, selected) VALUES (120, 12, 1, '4006381333931', '04006381333931', 'unknown', 1), (121, 12, 2, '96385074', '00000096385074', 'unknown', 0), (122, 12, 3, 'bad', NULL, 'unknown', 1), (123, 12, 4, '012345678905', '00012345678905', 'known', 1), (130, 13, 1, '96385074', '00000096385074', 'unknown', 1)");
$db->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (3, 12, 'image', 'image/png', 5)");
$preserved = [];
foreach (['grocy_ai_capture_trips', 'grocy_ai_capture_lines', 'grocy_ai_receipts', 'stock_log', 'products', 'product_barcodes'] as $table) $preserved[$table] = json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
$preview = captureResearchBackfillPreview($db, 12);
backfillCheck($preview['candidate_ids'] === [120] && $preview['candidate_count'] === 1, 'only selected valid unknown trip 12 line is eligible');
backfillCheck($preview['blocker_counts']['unselected'] === 1 && $preview['blocker_counts']['invalid_gtin'] === 1 && $preview['blocker_counts']['known'] === 1, 'preview gives blocker counts');
backfillCheck(preg_match('/^[a-f0-9]{64}$/D', $preview['checksum']) === 1, 'preview checksum is SHA-256');
backfillCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 0, 'dry run does not queue');
$fixturePath = tempnam(sys_get_temp_dir(), 'capture-backfill-');
backfillCheck(is_string($fixturePath), 'temporary fixture path available');
unlink($fixturePath);
$db->exec('VACUUM INTO ' . $db->quote($fixturePath));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/capture-research-backfill.php') . ' --trip=12 --dry-run --db=' . escapeshellarg($fixturePath);
exec($command . ' 2>&1', $output, $exitCode);
backfillCheck($exitCode === 0 && json_decode(implode("\n", $output), true)['checksum'] === $preview['checksum'], 'CLI dry run returns fixture checksum');
backfillCheck((int)(new PDO('sqlite:' . $fixturePath))->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 0, 'CLI dry run is read-only');
unlink($fixturePath);
$db->exec('UPDATE grocy_ai_capture_lines SET selected = 0 WHERE id = 120');
backfillReject(fn() => captureResearchBackfillApply($db, 12, $preview['checksum']), 'changed selection must abort');
backfillCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 0, 'failed apply writes nothing');
$db->exec('UPDATE grocy_ai_capture_lines SET selected = 1 WHERE id = 120');
$preview = captureResearchBackfillPreview($db, 12);
$applied = captureResearchBackfillApply($db, 12, $preview['checksum']);
backfillCheck($applied['created_count'] === 1, 'apply queues one draft');
backfillCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE trip_id = 12 AND line_id = 120')->fetchColumn() === 1, 'apply queues only selected trip line');
backfillCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE trip_id = 13')->fetchColumn() === 0, 'apply leaves other trip alone');
$again = captureResearchBackfillApply($db, 12, $preview['checksum']);
backfillCheck($again['created_count'] === 0 && (int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit')->fetchColumn() === 1, 'repeated apply is idempotent');
foreach ($preserved as $table => $bytes) backfillCheck(json_encode($db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) === $bytes, $table . ' rows remain byte-identical');
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (12, 'test')");
backfillReject(fn() => captureResearchBackfillApply($db, 12, $preview['checksum']), 'canceled trip must abort');
$committedPreview = captureResearchBackfillPreview($db, 13);
$db->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = 13");
backfillReject(fn() => captureResearchBackfillApply($db, 13, $committedPreview['checksum']), 'commit after preview must abort');
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (140, 14, 1, '96385074', '00000096385074', 'unknown')");
$canceledPreview = captureResearchBackfillPreview($db, 14);
$db->exec("INSERT INTO grocy_ai_capture_trip_cancellations (trip_id, actor) VALUES (14, 'test')");
backfillReject(fn() => captureResearchBackfillApply($db, 14, $canceledPreview['checksum']), 'cancellation after preview must abort');
backfillCheck((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE trip_id IN (13, 14)')->fetchColumn() === 0, 'closed trips remain untouched');

echo "capture research backfill: PASS\n";
