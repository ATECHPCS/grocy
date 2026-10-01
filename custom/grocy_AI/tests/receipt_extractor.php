<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiReceiptMigration;
use GrocyAI\Services\GrocyAiReceiptImageStore;
use GrocyAI\Services\GrocyAiReceiptService;
use GrocyAI\Services\GrocyAiReceiptExtractor;

foreach (['GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptImageStore', 'GrocyAiReceiptService', 'GrocyAiReceiptExtractor'] as $class) require_once __DIR__ . '/../src/' . $class . '.php';

function extractionCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function extractionFixture(): array
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
	GrocyAiCaptureMigration::Bootstrap($pdo);
	GrocyAiReceiptMigration::Bootstrap($pdo);
	$pdo->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test')");
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
	$path = sys_get_temp_dir() . '/grocy-extract-' . bin2hex(random_bytes(8));
	mkdir($path . '/grocy_ai/receipts/1', 0700, true);
	$id = str_repeat('a', 48);
	file_put_contents($path . '/grocy_ai/receipts/1/' . $id, $png);
	$statement = $pdo->prepare('INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (21, 1, ?, ?, ?)');
	$statement->execute([$id, 'image/png', strlen($png)]);
	return [$pdo, $path, $png];
}
function extractionCleanup(string $path): void
{
	unlink($path . '/grocy_ai/receipts/1/' . str_repeat('a', 48));
	if (is_file($path . '/grocy_ai/receipts/1/' . str_repeat('b', 48))) unlink($path . '/grocy_ai/receipts/1/' . str_repeat('b', 48));
	rmdir($path . '/grocy_ai/receipts/1'); rmdir($path . '/grocy_ai/receipts'); rmdir($path . '/grocy_ai'); rmdir($path);
}
function extractionResponse(): string
{
	return json_encode(['merchant' => 'Market', 'purchase_date' => '2026-09-30', 'printed_total' => '2.75', 'currency' => 'USD', 'lines' => [['description' => 'Milk', 'quantity' => '1', 'line_total' => '2.50', 'kind' => 'item', 'confidence' => 0.9]], 'adjustments' => [['description' => 'Tax', 'amount' => '0.25', 'kind' => 'tax', 'confidence' => 0.8]], 'diagnostics' => ['provider' => 'gemini', 'warnings' => []]], JSON_THROW_ON_ERROR);
}
[$pdo, $path, $png] = extractionFixture();
try
{
	$calls = [];
	$transport = function (string $url, array $headers, string $body) use (&$calls): array
	{
		$calls[] = [$url, $headers, $body];
		return ['status' => 200, 'body' => extractionResponse()];
	};
	$extractor = new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', $transport);
	$first = $extractor->Extract(1, 21);
	extractionCheck($first['status'] === 'needs_review' && count($first['receipt']['lines']) === 2, 'valid OCR imports reviewable lines');
	extractionCheck($first['receipt']['lines'][1]['line_total'] == 0.25 && $first['receipt']['lines'][1]['decision'] === 'ignore', 'adjustment maps to ignored review line');
	extractionCheck($calls[0][0] === 'http://companion.local/v1/receipts/extract' && $calls[0][1]['X-API-Key'] === 'private-test-key' && $calls[0][1]['Content-Type'] === 'image/png' && $calls[0][2] === $png, 'private image and key go to companion');
	$revision = $first['receipt']['receipt']['revision'];
	$retry = $extractor->Retry(1, 21);
	extractionCheck(count($calls) === 1 && $retry['receipt']['receipt']['revision'] === $revision, 'duplicate retry does not call provider or change revision');
	$service = new GrocyAiReceiptService($pdo);
	$service->UpdateReceipt(21, ['merchant' => 'Corrected'], 'test');
	$edited = $extractor->Retry(1, 21);
	extractionCheck($edited['receipt']['receipt']['merchant'] === 'Corrected' && count($calls) === 1, 'retry preserves reviewed edits');
	try { $service->ImportExtraction(21, ['lines' => []], 'test', (int)$revision); throw new RuntimeException('stale import accepted'); }
	catch (InvalidArgumentException $expected) { extractionCheck($expected->getMessage() === 'Receipt changed during OCR', 'stale revision rejected inside import transaction'); }
	file_put_contents($path . '/grocy_ai/receipts/1/' . str_repeat('b', 48), $png);
	$pdo->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (22, 1, '" . str_repeat('b', 48) . "', 'image/png', " . strlen($png) . ")");
	foreach ([['status' => 504, 'body' => 'private receipt text'], ['status' => 503, 'body' => 'private receipt text'], ['status' => 200, 'body' => '{broken'], ['status' => 200, 'body' => json_encode(['lines' => [['description' => 'private receipt text']]])]] as $response)
	{
		$failed = (new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', fn() => $response))->Extract(1, 22);
		extractionCheck($failed['status'] === 'manual_entry' && $failed['receipt']['receipt']['status'] !== 'finished' && $failed['receipt']['lines'] === [], 'provider failure leaves editable manual state');
		extractionCheck(!str_contains(json_encode($failed), 'private receipt text') && !str_contains(json_encode($failed), 'private-test-key'), 'failure omits private data');
	}
	$thrown = (new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', fn() => throw new RuntimeException('private receipt text private-test-key')))->Extract(1, 22);
	extractionCheck($thrown['status'] === 'manual_entry' && !str_contains(json_encode($thrown), 'private receipt text'), 'transport exception stays private');
	$huge = (new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', fn() => ['status' => 200, 'body' => str_repeat('x', 131073)]))->Extract(1, 22);
	extractionCheck($huge['status'] === 'manual_entry', 'oversize response is rejected');
	$pdo->exec('UPDATE grocy_ai_receipts SET image_bytes = 6000000 WHERE id = 22');
	$oversize = (new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', fn() => throw new RuntimeException('should not send')));
	$limit = $oversize->Extract(1, 22);
	extractionCheck($limit['status'] === 'manual_entry', 'oversize image enters manual fallback');
	$pdo->exec('UPDATE grocy_ai_receipts SET image_bytes = ' . strlen($png) . ' WHERE id = 22');
	$ordered = json_decode(extractionResponse(), true, 32, JSON_THROW_ON_ERROR);
	$ordered['lines'][0] = array_reverse($ordered['lines'][0], true);
	$ordered['diagnostics'] = array_reverse($ordered['diagnostics'], true);
	$reordered = (new GrocyAiReceiptExtractor($pdo, $path, 'http://companion.local', 'private-test-key', fn() => ['status' => 200, 'body' => json_encode($ordered, JSON_THROW_ON_ERROR)]))->Extract(1, 22);
	extractionCheck($reordered['status'] === 'needs_review', 'valid JSON object key order is irrelevant');
	print "Receipt extractor tests passed\n";
}
finally { extractionCleanup($path); }
