<?php

declare(strict_types=1);

// Regression targets: early stock writes, lost scan quantities/approval ownership,
// ignored receipt allocations, tare subtraction, price/store collapse, and duplicate commit.

use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiReceiptService;
use GrocyAI\Controllers\Api\GrocyAiApiController;

if (!defined('GROCY_MODE')) define('GROCY_MODE', 'production');
$dataPath = sys_get_temp_dir() . '/grocy-automated-trip-' . bin2hex(random_bytes(8));
mkdir($dataPath);
if (!defined('GROCY_DATAPATH')) define('GROCY_DATAPATH', $dataPath);
register_shutdown_function(static function () use ($dataPath): void
{
	foreach (glob($dataPath . '/grocy_ai/receipts/*/*') ?: [] as $file) @unlink($file);
	foreach (glob($dataPath . '/grocy_ai/receipts/*') ?: [] as $directory) @rmdir($directory);
	@rmdir($dataPath . '/grocy_ai/receipts');
	@rmdir($dataPath . '/grocy_ai');
	@unlink($dataPath . '/grocy.db');
	@rmdir($dataPath);
});
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptImageStore', 'GrocyAiReceiptService', 'GrocyAiReceiptExtractor', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService', 'GrocyAiApiController'] as $class)
{
	require_once __DIR__ . '/../src/' . $class . '.php';
}
require_once dirname(__DIR__, 3) . '/helpers/extensions.php';
require_once __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
require_once __DIR__ . '/../src/GrocyAiTaxonomyService.php';
require_once __DIR__ . '/../src/GrocyAiCaptureProductService.php';
if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);

function rehearsalAssert(bool $ok, string $message): void
{
	if (!$ok) throw new RuntimeException($message);
}

function rehearsalCall(GrocyAiApiController $controller, string $method, array $args, ?array $body = null, array $files = []): array
{
	$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/grocy-ai/capture');
	if ($body !== null) $request = $request->withParsedBody($body);
	if ($files !== []) $request = $request->withUploadedFiles($files);
	$response = $controller->$method($request, (new Slim\Psr7\Factory\ResponseFactory())->createResponse(), $args);
	$json = json_decode((string)$response->getBody(), true);
	rehearsalAssert(is_array($json) && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300, $method . ' returned HTTP ' . $response->getStatusCode());
	return $json;
}

function rehearsalUpload(GrocyAiApiController $controller, int $trip, string $requestId, string $png): array
{
	$stream = (new Slim\Psr7\Factory\StreamFactory())->createStream($png);
	$file = new Slim\Psr7\UploadedFile($stream, 'receipt.png', 'image/png', strlen($png));
	return rehearsalCall($controller, 'UploadCaptureReceipt', ['tripId' => (string)$trip], ['request_id' => $requestId], ['image' => $file]);
}

// Load repository defaults only: no runtime config, environment, providers, or live data.
foreach (array_keys(getenv()) as $name)
{
	if (str_starts_with($name, 'GROCY_')) putenv($name);
}
define('GROCY_AI_SERVICE_URL', '');
define('GROCY_AI_SERVICE_API_KEY', '');
require dirname(__DIR__, 3) . '/config-dist.php';
(new Grocy\Services\DatabaseMigrationService())->MigrateDatabase();
$pdo = Grocy\Services\DatabaseService::getInstance()->GetDbConnectionRaw();
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("INSERT OR IGNORE INTO locations (id, name, active) VALUES (1, 'Rehearsal shelf', 1)");
$pdo->exec('UPDATE locations SET active = 1');
$pdo->exec("INSERT OR IGNORE INTO quantity_units (id, name, name_plural, active) VALUES (901, 'Rehearsal piece', 'Rehearsal pieces', 1)");
$pdo->exec('UPDATE quantity_units SET active = 1');
$pdo->exec("INSERT INTO shopping_locations (id, name) VALUES (7, 'Rehearsal store A'), (8, 'Rehearsal store B')");
$pdo->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, enable_tare_weight_handling, tare_weight) VALUES (101, 'Known fixture milk', 1, 901, 901, 901, 901, 1, 1)");
$pdo->exec("INSERT INTO product_barcodes (product_id, barcode) VALUES (101, '012345678905')");
// Native stock seed proves additive behavior with existing inventory and tare handling.
$stock = new Grocy\Services\StockService();
$stock->AddProduct(101, 4, '2027-01-01', $stock::TRANSACTION_TYPE_PURCHASE, '2026-10-12', 1, 1, 7, addExactAmount: true);
$stockSnapshot = static fn() => array_map(static fn($table) => $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), ['stock', 'stock_log']);
$before = $stockSnapshot();
$controller = (new ReflectionClass(GrocyAiApiController::class))->newInstanceWithoutConstructor();
$capture = new GrocyAiCaptureService($pdo, true, $stock);
$trip = (int)$capture->StartTrip('automated-rehearsal')['id'];
foreach (['012345678905', '012345678905', '4006381333931', '4006381333931', '4012345678901'] as $barcode) $capture->ScanIntoTrip($trip, $barcode, 'automated-rehearsal');
$lines = $pdo->query('SELECT * FROM grocy_ai_capture_lines ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
rehearsalAssert(count($lines) === 3 && array_column($lines, 'quantity') == [2, 2, 1], 'Duplicate scans must coalesce quantities 2/2/1');
rehearsalAssert(array_column($lines, 'status') === ['known', 'unknown', 'unknown'], 'Known and unknown UPC resolution');
$research = new GrocyAI\Services\GrocyAiCaptureResearchService($pdo);
$jobs = $research->ClaimJobs(2, 'deterministic-rehearsal');
rehearsalAssert(count($jobs) === 2, 'Two unique unknowns enqueue two jobs');
foreach ($jobs as $job)
{
	$name = $job['canonical_gtin'] === '04006381333931' ? 'Fixture cereal' : 'Fixture honey carrots';
	$research->CompleteJob((int)$job['id'], $job['lease_token'], ['contract_version' => 1, 'canonical_gtin' => $job['canonical_gtin'], 'outcome' => 'found', 'name_candidates' => [$name], 'name_candidate_sources' => [['openfoodfacts']], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => ['openfoodfacts']]);
}
rehearsalAssert($stockSnapshot() === $before, 'Scans and research must preserve inventory');
$capture->SetStatus($trip, 'reviewing', 'automated-rehearsal');
$approval = new GrocyAI\Services\GrocyAiCaptureProductService($pdo);
$approved = [];
foreach ($research->ReviewForTrip($trip)['drafts'] as $draft)
{
	$fields = ['name' => $draft['selected']['name'], 'location_id' => 1, 'qu_id_purchase' => 901, 'qu_id_stock' => 901, 'parent_mode' => 'standalone'];
	if ($draft['canonical_gtin'] === '04012345678901') $fields = [...$fields, 'parent_mode' => 'create', 'new_parent_name' => 'Carrots (generic)'];
	$approved[] = $approval->ApproveDraft($trip, (int)$draft['line_id'], (int)$draft['revision'], $fields, 'automated-rehearsal');
}
$lines = $pdo->query('SELECT * FROM grocy_ai_capture_lines ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
$productIds = array_map('intval', array_column($lines, 'resolved_product_id'));
$parent = (int)$pdo->query('SELECT parent_product_id FROM products WHERE id = ' . $productIds[2])->fetchColumn();
rehearsalAssert(count($approved) === 2 && $parent > 0 && (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 4, 'One standalone and one parent/child approval must create three catalog rows');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM product_barcodes WHERE product_id = ' . $parent)->fetchColumn() === 0, 'Generic parent receives no barcode');
rehearsalAssert($pdo->query('SELECT parent_product_id FROM products WHERE id = ' . $productIds[1])->fetchColumn() === null, 'Standalone cereal must have no parent product');
rehearsalAssert($stockSnapshot() === $before, 'Product approval must preserve inventory');

$canvas = imagecreatetruecolor(1, 1);
ob_start(); imagepng($canvas); $png = ob_get_clean(); imagedestroy($canvas);
$first = rehearsalUpload($controller, $trip, 'trip-store-A', $png);
$second = rehearsalUpload($controller, $trip, 'trip-store-B', $png);
$argsA = ['tripId' => (string)$trip, 'receiptId' => (string)$first['receipt']['id']];
$argsB = ['tripId' => (string)$trip, 'receiptId' => (string)$second['receipt']['id']];
rehearsalAssert(count(rehearsalCall($controller, 'ListCaptureReceipts', ['tripId' => (string)$trip])['receipts']) === 2 && count(glob($dataPath . '/grocy_ai/receipts/' . $trip . '/*') ?: []) === 2, 'Two receipt uploads persist private images');
foreach ([$argsA, $argsB] as $args) rehearsalAssert(rehearsalCall($controller, 'ExtractCaptureReceipt', $args)['status'] === 'manual_entry', 'Unavailable OCR keeps manual review');
rehearsalCall($controller, 'UpdateCaptureReceipt', $argsA, ['printed_total' => 9, 'shopping_location_id' => 7]);
rehearsalCall($controller, 'UpdateCaptureReceipt', $argsB, ['printed_total' => 11.5, 'shopping_location_id' => 8]);
$allocate = static function (array $args, int $captureIndex, float $quantity, float $price) use ($controller, $lines, $productIds): void
{
	$view = rehearsalCall($controller, 'AddCaptureReceiptLine', $args, ['description' => 'Fixture item ' . $captureIndex, 'decision' => 'include', 'quantity' => $quantity, 'line_total' => $quantity * $price, 'product_id' => $productIds[$captureIndex]]);
	$line = $view['lines'][count($view['lines']) - 1];
	rehearsalCall($controller, 'UpdateCaptureReceiptAllocation', $args + ['lineId' => (string)$line['id']], ['capture_line_id' => (int)$lines[$captureIndex]['id'], 'quantity' => $quantity, 'unit_price' => $price]);
};
$allocate($argsA, 0, 1, 3);
$allocate($argsA, 1, 2, 3);
$allocate($argsB, 0, 1, 4);
$allocate($argsB, 2, 1, 5);
$ignored = rehearsalCall($controller, 'AddCaptureReceiptLine', $argsB, ['description' => 'Receipt-only snack disregarded', 'decision' => 'ignore', 'quantity' => 1, 'line_total' => 2]);
rehearsalAssert(count($ignored['lines'][2]['allocations']) === 0, 'Ignored receipt-only amount creates no allocation');
$balanced = rehearsalCall($controller, 'FinishCaptureReceipt', $argsA);
rehearsalAssert((float)$balanced['totals']['difference'] === 0.0, 'First receipt reconciles exactly');
rehearsalAssert(!rehearsalCall($controller, 'CaptureReceiptReadiness', ['tripId' => (string)$trip])['ready'], 'Unfinished second receipt blocks commit');
$accepted = rehearsalCall($controller, 'UpdateCaptureReceipt', $argsB, ['accept_difference' => true]);
rehearsalAssert((float)$accepted['totals']['difference'] === 0.5, 'Second receipt accepts explicit 0.50 difference');
rehearsalCall($controller, 'FinishCaptureReceipt', $argsB);
rehearsalAssert(rehearsalCall($controller, 'CaptureReceiptReadiness', ['tripId' => (string)$trip])['ready'], 'Reviewed full trip ready');
rehearsalAssert($stockSnapshot() === $before && (int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() === 0, 'No inventory or commit evidence changes before explicit commit');
echo "Before commit: 3 scan lines (2/2/1), 2 researched approvals, generic parent without barcode; 2 private receipt images, 4 allocations, ignored 2.00, accepted difference 0.50; existing stock 4 unchanged\n";
$checksum = $capture->ChecksumForTrip($trip);
$result = $capture->CommitTrip($trip, 'automated-rehearsal', $checksum);
rehearsalAssert($result['outcome'] === 'committed' && $result['applied'] === 4, 'Native commit applies four allocations: ' . json_encode($result));
$actual = $pdo->query('SELECT product_id, amount, price, shopping_location_id FROM stock_log WHERE id > ' . $before[1][0]['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$expected = [[$productIds[0], 1, 3, 7], [$productIds[1], 2, 3, 7], [$productIds[0], 1, 4, 8], [$productIds[2], 1, 5, 8]];
rehearsalAssert($actual == $expected, 'Native log preserves exact product/quantity/price/store: ' . json_encode($actual));
$amounts = $pdo->query('SELECT product_id, SUM(amount) FROM stock GROUP BY product_id ORDER BY product_id')->fetchAll(PDO::FETCH_NUM);
rehearsalAssert($amounts == [[$productIds[0], 6], [$productIds[1], 2], [$productIds[2], 1]], 'Native inventory deltas +2/+2/+1, generic parent zero: ' . json_encode($amounts));
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() === 4, 'Four allocation commit audits');
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'accept_difference' AND actor = '1'")->fetchColumn() === 1, 'Difference acceptance records the authenticated controller actor');
$stockRows = $pdo->query('SELECT product_id, amount, price, shopping_location_id FROM stock WHERE id > ' . $before[0][0]['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
rehearsalAssert($stockRows == $expected, 'Native stock entries preserve each allocation quantity/price/store');
$committed = $stockSnapshot();
$again = $capture->CommitTrip($trip, 'automated-rehearsal', $checksum);
rehearsalAssert($again['outcome'] === 'already_committed' && $stockSnapshot() === $committed, 'Repeat commit preserves every stock/log row');
echo 'After native commit: ' . json_encode($amounts) . '; allocation stock logs ' . json_encode($actual) . "; retry already_committed, zero duplicate writes\n";
echo 'PASS: isolated automated full-trip service/controller rehearsal; native migration rows ' . $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() . "\n";
