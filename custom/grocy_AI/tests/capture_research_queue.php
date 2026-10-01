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
$db->exec('DELETE FROM grocy_ai_capture_lines WHERE id = ' . (int)$first['id']);
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit')->fetchColumn() === 2, 'audit survives capture-line deletion');
checkResearch((int)$db->query('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE line_id IS NULL')->fetchColumn() === 1, 'deleted line clears only the live association');

try
{
	$db->exec('DELETE FROM grocy_ai_capture_research_audit');
	throw new RuntimeException('audit delete was allowed');
}
catch (PDOException $expected) {}

echo "capture research queue: PASS\n";
