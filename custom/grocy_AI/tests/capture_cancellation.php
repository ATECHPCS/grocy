<?php

declare(strict_types=1);

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureService'] as $name)
{
	require_once __DIR__ . '/../src/' . $name . '.php';
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE shopping_locations (id INTEGER PRIMARY KEY, name TEXT)');
\GrocyAI\Services\GrocyAiCaptureMigration::Bootstrap($db);
\GrocyAI\Services\GrocyAiReceiptMigration::Bootstrap($db);
$capture = new \GrocyAI\Services\GrocyAiCaptureService($db);
$keep = $capture->StartTrip('test');
$cancel = $capture->StartTrip('test');
$cancelId = (int)$cancel['id'];
$db->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes) VALUES (?, 'retained', 'image/png', 20)")->execute([$cancelId]);
$receiptId = (int)$db->lastInsertId();
$result = $capture->CancelTrip($cancelId, 'test');
if ($result !== ['trip_id' => $cancelId, 'canceled' => true]) throw new RuntimeException('Cancellation response');
if (array_column($capture->ListTrips(), 'id') != [$keep['id']]) throw new RuntimeException('Canceled trip appears in active list');
if ((int)$db->query('SELECT COUNT(*) FROM grocy_ai_receipts')->fetchColumn() !== 1) throw new RuntimeException('Receipt history lost');
if ((int)$db->query("SELECT COUNT(*) FROM grocy_ai_capture_audit WHERE action = 'cancel_trip'")->fetchColumn() !== 1) throw new RuntimeException('Cancellation audit missing');
try { $capture->ScanIntoTrip($cancelId, '012345678905', 'test'); throw new RuntimeException('Canceled trip accepted scan'); }
catch (InvalidArgumentException $error) { /* expected */ }
try { (new \GrocyAI\Services\GrocyAiReceiptService($db))->UpdateReceipt($receiptId, ['merchant' => 'X'], 'test'); throw new RuntimeException('Canceled trip accepted receipt edit'); }
catch (InvalidArgumentException $error) { /* expected */ }
try { $capture->CancelTrip((int)$keep['id'], 'test'); }
catch (Throwable $error) { throw new RuntimeException('Second independent trip could not cancel', 0, $error); }
echo "capture cancellation passed\n";
