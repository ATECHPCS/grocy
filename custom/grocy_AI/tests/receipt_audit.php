<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiReceiptMigration;
use GrocyAI\Services\GrocyAiReceiptService;

require_once __DIR__ . '/../src/GrocyAiCaptureMigration.php';
require_once __DIR__ . '/../src/GrocyAiReceiptMigration.php';
require_once __DIR__ . '/../src/GrocyAiReceiptService.php';

function checkReceipt(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectsReceipt(callable $action, string $message): void
{
	try { $action(); } catch (InvalidArgumentException|RuntimeException $error) { return; }
	throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY)');
$pdo->exec("INSERT INTO products VALUES (101, 'Milk'), (102, 'Bread')");
GrocyAiCaptureMigration::Bootstrap($pdo);
GrocyAiReceiptMigration::Bootstrap($pdo);
$pdo->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test')");
$pdo->exec("INSERT INTO grocy_ai_capture_lines (id, trip_id, seq, scanned_barcode, resolved_product_id, status, quantity, selected) VALUES (11, 1, 1, 'milk', 101, 'known', 2, 1), (12, 1, 2, 'bread', 102, 'known', 1, 1)");
$pdo->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes, printed_total) VALUES (21, 1, 'image-a', 'image/png', 20, 6.5), (22, 1, 'image-b', 'image/png', 20, 2.0)");
$service = new GrocyAiReceiptService($pdo);
$first = $service->ImportExtraction(21, ['merchant' => 'Store A', 'lines' => [
	['description' => 'Milk', 'quantity' => 2, 'line_total' => 6.0, 'kind' => 'item'],
	['description' => 'Tax', 'line_total' => 0.5, 'kind' => 'tax']
]], 'test');
checkReceipt($first['receipt']['merchant'] === 'Store A', 'OCR merchant retained');
$initialRevision = (int)$first['receipt']['revision'];
$retry = $service->ImportExtraction(21, ['merchant' => 'Store A', 'lines' => [
	['description' => 'Milk', 'quantity' => 2, 'line_total' => 6.0, 'kind' => 'item'],
	['description' => 'Tax', 'line_total' => 0.5, 'kind' => 'tax']
]], 'test');
checkReceipt((int)$retry['receipt']['revision'] === $initialRevision && count($retry['lines']) === 2, 'exact OCR retry is idempotent');
checkReceipt(count($first['lines']) === 2 && $first['lines'][0]['decision'] === 'needs_review', 'OCR item lines are suggestions');
checkReceipt($first['lines'][1]['decision'] === 'ignore', 'noninventory charge defaults to Ignore');
$line = (int)$first['lines'][0]['id']; $tax = (int)$first['lines'][1]['id'];
$service->UpdateLine(21, $line, ['decision' => 'include'], 'test');
$service->UpdateLine(21, $tax, ['decision' => 'ignore'], 'test');
$service->UpdateAllocation(21, $line, ['capture_line_id' => 11, 'quantity' => 1, 'unit_price' => 3, 'shopping_location_id' => 1], 'test');
$service->UpdateAllocation(21, $line, ['capture_line_id' => 11, 'quantity' => 1, 'unit_price' => 3, 'shopping_location_id' => 1], 'test');
rejectsReceipt(fn() => $service->UpdateAllocation(21, $line, ['capture_line_id' => 11, 'quantity' => 1, 'unit_price' => 3], 'test'), 'overallocated scan rejected');
$allocationId = (int)$pdo->query('SELECT MIN(id) FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ' . $line)->fetchColumn();
$service->UpdateAllocation(21, $line, ['id' => $allocationId, 'delete' => true], 'test');
checkReceipt((int)$pdo->query('SELECT active FROM grocy_ai_receipt_allocations WHERE id = ' . $allocationId)->fetchColumn() === 0, 'allocation correction retains audited row');
$service->UpdateAllocation(21, $line, ['capture_line_id' => 11, 'quantity' => 1, 'unit_price' => 3, 'shopping_location_id' => 1], 'test');
checkReceipt((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ' . $line . ' AND active = 1')->fetchColumn() === 2, 'corrected allocation can be recreated');
$second = $service->ImportExtraction(22, ['merchant' => 'Store B', 'lines' => [
	['description' => 'Bread', 'quantity' => 1, 'line_total' => 2, 'kind' => 'item'],
	['description' => 'Other household item', 'quantity' => 1, 'line_total' => 0, 'kind' => 'item']
]], 'test');
$bread = (int)$second['lines'][0]['id']; $other = (int)$second['lines'][1]['id'];
$service->UpdateLine(22, $bread, ['decision' => 'include'], 'test');
$service->UpdateLine(22, $other, ['decision' => 'ignore'], 'test');
rejectsReceipt(fn() => $service->UpdateAllocation(22, $bread, ['capture_line_id' => 12, 'quantity' => 1], 'test'), 'price confirmation is required');
$service->UpdateAllocation(22, $bread, ['capture_line_id' => 12, 'quantity' => 1, 'unit_price' => 2, 'shopping_location_id' => 2], 'test');
checkReceipt(!$service->Readiness(1)['ready'], 'unfinished receipts block trip');
$service->Finish(21, 'test'); $service->Finish(22, 'test');
checkReceipt($service->Readiness(1)['ready'], 'multi-receipt matching permits ready trip');
$pdo->exec('UPDATE grocy_ai_capture_lines SET quantity = 3 WHERE id = 11');
checkReceipt(!$service->Readiness(1)['ready'], 'capture quantity edit invalidates matching');
$pdo->exec('UPDATE grocy_ai_capture_lines SET quantity = 2 WHERE id = 11');
rejectsReceipt(fn() => $service->UpdateLine(22, $line, ['decision' => 'ignore'], 'test'), 'line ID cannot cross receipts');
checkReceipt(count($service->ListForTrip(1)) === 2, 'lists both receipts');
$beforeAudit = $pdo->query('SELECT * FROM grocy_ai_receipt_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$service->UpdateLine(21, $line, ['description' => 'Milk corrected'], 'test');
checkReceipt(!$service->Readiness(1)['ready'], 'edit reopens finished receipt');
checkReceipt($pdo->query('SELECT difference_accepted_amount FROM grocy_ai_receipts WHERE id = 21')->fetchColumn() === null, 'edit invalidates difference acceptance');
$afterAudit = $pdo->query('SELECT * FROM grocy_ai_receipt_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
checkReceipt(array_slice($afterAudit, 0, count($beforeAudit)) === $beforeAudit && count($afterAudit) > count($beforeAudit), 'audit history is append only');
$service->UpdateReceipt(21, ['printed_total' => 7], 'test');
rejectsReceipt(fn() => $service->Finish(21, 'test'), 'difference requires acceptance');
$service->UpdateReceipt(21, ['accept_difference' => true], 'test');
$service->Finish(21, 'test');
checkReceipt($service->Readiness(1)['ready'], 'accepted difference permits readiness');
$service->Reopen(21, 'test');
checkReceipt(!$service->Readiness(1)['ready'], 'explicit reopen blocks readiness');
checkReceipt($pdo->query('SELECT difference_accepted_amount FROM grocy_ai_receipts WHERE id = 21')->fetchColumn() === null, 'reopen clears acceptance');
$service->UpdateReceipt(21, ['accept_difference' => true], 'test');
$service->Finish(21, 'test');
$service->UpdateLine(22, $other, ['decision' => 'include'], 'test');
checkReceipt(!$service->Readiness(1)['ready'], 'receipt only include needs known product');
$service->UpdateLine(22, $other, ['product_id' => 101], 'test');
$service->UpdateAllocation(22, $other, ['product_id' => 101, 'quantity' => 1, 'unit_price' => 0, 'shopping_location_id' => 2], 'test');
$service->Finish(22, 'test');
checkReceipt($service->Readiness(1)['ready'], 'receipt only known product can be included');
rejectsReceipt(fn() => $service->UpdateAllocation(22, $bread, ['capture_line_id' => 11, 'quantity' => 1, 'unit_price' => 2], 'test'), 'duplicate mismatched product rejected');
checkReceipt((int)$pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() === 0, 'no stock writes');
$pdo->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes, printed_total) VALUES (23, 1, 'image-c', 'image/png', 20, 1.0)");
$manual = $service->AddLine(23, ['description' => 'Manual item', 'quantity' => 1, 'line_total' => 1.0], 'test');
checkReceipt(count($manual['lines']) === 1 && $manual['lines'][0]['decision'] === 'needs_review', 'manual entry remains reviewable');
rejectsReceipt(fn() => $service->Finish(23, 'test'), 'manual line requires decision');
$pdo->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = 1");
rejectsReceipt(fn() => $service->UpdateLine(23, (int)$manual['lines'][0]['id'], ['decision' => 'ignore'], 'test'), 'committed trip is immutable');
$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
GrocyAiCaptureMigration::Bootstrap($legacy);
GrocyAiReceiptMigration::Bootstrap($legacy);
$legacy->exec('ALTER TABLE grocy_ai_receipt_allocations DROP COLUMN active');
GrocyAiReceiptMigration::Bootstrap($legacy);
checkReceipt(in_array('active', array_column($legacy->query('PRAGMA table_info(grocy_ai_receipt_allocations)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true), 'v3 migration upgrades old allocations');
echo "receipt audit contract passed\n";
