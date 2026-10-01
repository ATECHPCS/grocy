<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiCaptureResearchMigration;
use GrocyAI\Services\GrocyAiCaptureResearchService;
use GrocyAI\Services\GrocyAiCaptureService;

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService'] as $file)
{
	$path = __DIR__ . '/../src/' . $file . '.php';
	if (is_file($path)) require_once $path;
}

function checkResearch(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function rejectsResearch(callable $operation, string $message): void
{
	try { $operation(); } catch (PDOException $expected) { return; }
	throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$db->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
$db->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY)');
$db->exec("INSERT INTO products VALUES (1, 'Owned')");
$db->exec("INSERT INTO product_barcodes VALUES (1, 1, '012345678905')");
GrocyAiCaptureMigration::Bootstrap($db);
GrocyAI\Services\GrocyAiReceiptMigration::Bootstrap($db);
$db->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (9, 'reviewing', 'test')");
$db->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, status) VALUES (90, 9, 1, 'bad', 'unknown')");
$db->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (91, 9, 'existing-image', 'image/png', 5)");
$before = $db->query('SELECT * FROM grocy_ai_capture_lines WHERE id = 90')->fetch(PDO::FETCH_ASSOC);
GrocyAiCaptureResearchMigration::Bootstrap($db);
GrocyAiCaptureResearchMigration::Bootstrap($db);
checkResearch($db->query('SELECT * FROM grocy_ai_capture_lines WHERE id = 90')->fetch(PDO::FETCH_ASSOC) === $before, 'bootstrap preserves capture rows');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipts WHERE id = 91')->fetchColumn() === 1, 'bootstrap preserves receipt rows');

$capture = new GrocyAiCaptureService($db);
$oldUrl = getenv('GROCY_AI_SERVICE_URL');
putenv('GROCY_AI_SERVICE_URL=http://10.255.255.1:81');
$scanStarted = microtime(true);
$first = $capture->ScanIntoTrip(9, '4006381333931');
checkResearch(microtime(true) - $scanStarted < 1.0, 'unavailable provider does not delay a scan');
if ($oldUrl === false) putenv('GROCY_AI_SERVICE_URL');
else putenv('GROCY_AI_SERVICE_URL=' . $oldUrl);
$again = $capture->ScanIntoTrip(9, '04006381333931');
checkResearch((int)$first['id'] === (int)$again['id'] && (int)$again['quantity'] === 2, 'equivalent GTIN scans coalesce');
checkResearch(array_keys($first) === array_keys($again), 'scan DTO shape stays fixed');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'one job per canonical GTIN');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts')->fetchColumn() === 1, 'one draft per line');
$job = $db->query('SELECT * FROM grocy_ai_capture_research_jobs')->fetch(PDO::FETCH_ASSOC);
checkResearch($job['canonical_gtin'] === '04006381333931' && $job['state'] === 'queued' && (int)$job['attempts'] === 0, 'job starts queued');
$drafts = (new GrocyAiCaptureResearchService($db))->DraftsForTrip(9);
checkResearch(count($drafts) === 1 && (int)$drafts[0]['line_id'] === (int)$first['id'], 'trip draft is visible');
checkResearch(!array_key_exists('lease_hash', $drafts[0]), 'browser draft omits lease secret');

$capture->ScanIntoTrip(9, '012345678905');
$capture->ScanIntoTrip(9, '4006381333932');
$capture->ScanIntoTrip(9, 'unsupported');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'known and invalid scans never queue');
checkResearch((int)$db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn() === 0, 'scan never books stock');

$secondTrip = $capture->StartTrip();
$capture->ScanIntoTrip((int)$secondTrip['id'], '4006381333931');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_jobs')->fetchColumn() === 1, 'jobs are shared across trips');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts')->fetchColumn() === 2, 'each capture line gets its own review draft');
$otherLineId = (int)$db->query('SELECT id FROM grocy_ai_capture_lines WHERE trip_id = ' . (int)$secondTrip['id'])->fetchColumn();
rejectsResearch(fn() => $db->exec("UPDATE grocy_ai_capture_research_drafts SET trip_id = 9 WHERE line_id = $otherLineId"), 'draft cannot reference a line from another trip');
$db->exec("INSERT INTO grocy_ai_receipt_lines (id, receipt_id, seq, description) VALUES (92, 91, 1, 'item')");
rejectsResearch(fn() => $db->exec("UPDATE grocy_ai_capture_research_drafts SET trip_id = " . (int)$secondTrip['id'] . ", receipt_line_id = 92 WHERE line_id = $otherLineId"), 'draft cannot reference receipt evidence from another trip');
$firstDraftId = (int)$db->query('SELECT id FROM grocy_ai_capture_research_drafts WHERE line_id = ' . (int)$first['id'])->fetchColumn();
$db->exec("UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = 92 WHERE id = $firstDraftId");
rejectsResearch(fn() => $db->exec('UPDATE grocy_ai_receipts SET trip_id = ' . (int)$secondTrip['id'] . ' WHERE id = 91'), 'moving receipt cannot strand linked draft in another trip');
$otherDraftId = (int)$db->query('SELECT id FROM grocy_ai_capture_research_drafts WHERE line_id = ' . $otherLineId)->fetchColumn();
rejectsResearch(fn() => $db->exec("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action) VALUES (9, $otherDraftId, 'test', 'bad')"), 'audit cannot reference a draft from another trip');
$db->exec('DELETE FROM grocy_ai_capture_lines WHERE id = ' . (int)$first['id']);
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit')->fetchColumn() === 2, 'audit survives capture-line deletion');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE line_id IS NULL')->fetchColumn() === 1, 'deleted line clears only the live association');
$db->exec("UPDATE grocy_ai_capture_research_drafts SET receipt_line_id = NULL WHERE id = $firstDraftId");
rejectsResearch(fn() => $db->exec('UPDATE grocy_ai_capture_research_drafts SET trip_id = ' . (int)$secondTrip['id'] . " WHERE id = $firstDraftId"), 'draft cannot move away from its audit trip');

try
{
	$db->exec('DELETE FROM grocy_ai_capture_research_audit');
	throw new RuntimeException('audit delete was allowed');
}
catch (PDOException $expected) {}

$db->exec("CREATE TRIGGER reject_research_queue BEFORE INSERT ON grocy_ai_capture_research_jobs BEGIN SELECT RAISE(ABORT, 'forced queue failure'); END");
$failedTrip = $capture->StartTrip();
$failedTripId = (int)$failedTrip['id'];
rejectsResearch(fn() => $capture->ScanIntoTrip($failedTripId, '96385074'), 'queue failure propagates');
checkResearch((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_lines WHERE trip_id = $failedTripId")->fetchColumn() === 0, 'queue failure rolls back new line');
checkResearch((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_audit WHERE trip_id = $failedTripId AND action = 'scan_new_line'")->fetchColumn() === 0, 'queue failure rolls back scan audit');
$db->exec('DROP TRIGGER reject_research_queue');
$retried = $capture->ScanIntoTrip($failedTripId, '96385074');
checkResearch((int)$retried['quantity'] === 1 && count((new GrocyAiCaptureResearchService($db))->DraftsForTrip($failedTripId)) === 1, 'retry creates one line and one draft');

$repairTrip = $capture->StartTrip();
$repairTripId = (int)$repairTrip['id'];
$db->prepare("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, canonical_gtin, status) VALUES (?, 1, '96385074', '00000096385074', 'unknown')")->execute([$repairTripId]);
$repaired = $capture->ScanIntoTrip($repairTripId, '96385074');
checkResearch((int)$repaired['quantity'] === 2 && count((new GrocyAiCaptureResearchService($db))->DraftsForTrip($repairTripId)) === 1, 'coalesced legacy unknown line repairs missing draft');

echo "capture research queue: PASS\n";
