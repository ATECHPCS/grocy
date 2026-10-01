<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiReceiptService;

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureService'] as $class)
{
	require_once __DIR__ . '/../src/' . $class . '.php';
}
require_once __DIR__ . '/capture.php';
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);

function rehearsalAssert(bool $ok, string $message): void
{
	if (!$ok) throw new RuntimeException($message);
}

$pdo = captureFixturePdo();
$stock = new CaptureFakeStockService();
$capture = new GrocyAiCaptureService($pdo, true, $stock);
$receipts = new GrocyAiReceiptService($pdo);
$trip = (int)$capture->StartTrip('rehearsal')['id'];
$capture->ScanIntoTrip($trip, '012345678905', 'rehearsal');

$pdo->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes, printed_total, shopping_location_id) VALUES (?, ?, 'image/png', 10, ?, 7)")
	->execute([$trip, 'rehearsal-image-one', 3]);
$first = (int)$pdo->lastInsertId();
$included = $receipts->AddLine($first, ['description' => 'Scanned purchase', 'decision' => 'include', 'quantity' => 1, 'line_total' => 3, 'product_id' => 101], 'rehearsal');
$receipts->UpdateAllocation($first, (int)$included['lines'][0]['id'], ['capture_line_id' => 1, 'quantity' => 1, 'unit_price' => 3], 'rehearsal');
$receipts->Finish($first, 'rehearsal');

$pdo->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes, printed_total, shopping_location_id) VALUES (?, ?, 'image/png', 10, ?, 8)")
	->execute([$trip, 'rehearsal-image-two', 2.5]);
$second = (int)$pdo->lastInsertId();
$ignored = $receipts->AddLine($second, ['description' => 'Receipt-only purchased item', 'decision' => 'ignore', 'quantity' => 1, 'line_total' => 2], 'rehearsal');
$review = $receipts->UpdateReceipt($second, ['accept_difference' => true], 'rehearsal');
rehearsalAssert((float)$review['totals']['difference'] === 0.5, 'Accepted difference was not 0.50');
$receipts->Finish($second, 'rehearsal');

$readiness = $receipts->Readiness($trip);
rehearsalAssert($readiness['ready'] === true && count($readiness['receipts']) === 2, 'Two finished receipts must be ready');
rehearsalAssert($ignored['lines'][0]['decision'] === 'ignore', 'Purchased item must remain ignored');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_receipt_allocations')->fetchColumn() === 1, 'Ignored item gained an allocation');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() === 0, 'Stock changed before commit');
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() === 0, 'Commit audit appeared before commit');
rehearsalAssert($stock->calls === [], 'Native stock writer was called');

echo "Two receipts ready; one ignored purchased item; accepted difference 0.50; stock rows 0; stock calls 0; commit audit rows 0\n";
