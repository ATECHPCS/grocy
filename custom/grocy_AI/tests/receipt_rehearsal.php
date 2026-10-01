<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiReceiptService;
use GrocyAI\Controllers\Api\GrocyAiApiController;

if (!defined('GROCY_MODE')) define('GROCY_MODE', 'production');
$dataPath = sys_get_temp_dir() . '/grocy-receipt-rehearsal-' . bin2hex(random_bytes(8));
mkdir($dataPath);
if (!defined('GROCY_DATAPATH')) define('GROCY_DATAPATH', $dataPath);
register_shutdown_function(static function () use ($dataPath): void
{
	foreach (glob($dataPath . '/grocy_ai/receipts/*/*') ?: [] as $file) @unlink($file);
	foreach (glob($dataPath . '/grocy_ai/receipts/*') ?: [] as $directory) @rmdir($directory);
	@rmdir($dataPath . '/grocy_ai/receipts');
	@rmdir($dataPath . '/grocy_ai');
	@rmdir($dataPath);
});
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptImageStore', 'GrocyAiReceiptService', 'GrocyAiReceiptExtractor', 'GrocyAiCaptureResearchMigration', 'GrocyAiCaptureResearchService', 'GrocyAiCaptureService', 'GrocyAiApiController'] as $class)
{
	require_once __DIR__ . '/../src/' . $class . '.php';
}
require_once __DIR__ . '/capture.php';
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

class RehearsalStockWriter
{
	public int $calls = 0;
	public function __construct(private PDO $pdo) {}
	public function AddProduct(int $productId, float $amount, $bbd, $type, $date, $price, $location = null, $store = null, &$transactionId = null, $label = 0, $exact = false, $note = null): void
	{
		$this->calls++;
		$transactionId ??= 'rehearsal-transaction';
		$this->pdo->prepare('INSERT INTO stock (product_id, amount, transaction_id) VALUES (?, ?, ?)')->execute([$productId, $amount, $transactionId]);
	}
}

$pdo = captureFixturePdo();
$pdo->exec('CREATE TABLE user_permissions_resolved (id INTEGER PRIMARY KEY, user_id INTEGER, permission_name TEXT)');
$pdo->exec("INSERT INTO user_permissions_resolved VALUES (1, 1, 'STOCK_PURCHASE')");
$reflection = new ReflectionClass(Grocy\Services\DatabaseService::class);
foreach (['DbConnectionRaw' => $pdo, 'DbConnection' => new LessQL\Database($pdo), 'instance' => $reflection->newInstance()] as $name => $value)
{
	$reflection->getProperty($name)->setValue(null, $value);
}
$controller = (new ReflectionClass(GrocyAiApiController::class))->newInstanceWithoutConstructor();
$stock = new RehearsalStockWriter($pdo);
$capture = new GrocyAiCaptureService($pdo, true, $stock);
$trip = (int)$capture->StartTrip('rehearsal')['id'];
$capture->ScanIntoTrip($trip, '012345678905', 'rehearsal');
$canvas = imagecreatetruecolor(1, 1);
ob_start(); imagepng($canvas); $png = ob_get_clean(); imagedestroy($canvas);

$first = rehearsalUpload($controller, $trip, 'receipt-one', $png);
$second = rehearsalUpload($controller, $trip, 'receipt-two', $png);
$firstArgs = ['tripId' => (string)$trip, 'receiptId' => (string)$first['receipt']['id']];
$secondArgs = ['tripId' => (string)$trip, 'receiptId' => (string)$second['receipt']['id']];
rehearsalAssert($firstArgs['receiptId'] !== $secondArgs['receiptId'] && count(rehearsalCall($controller, 'ListCaptureReceipts', ['tripId' => (string)$trip])['receipts']) === 2, 'Two API uploads did not persist');
rehearsalAssert(count(glob($dataPath . '/grocy_ai/receipts/' . $trip . '/*') ?: []) === 2, 'Private receipt images were not stored');
$manual = rehearsalCall($controller, 'ExtractCaptureReceipt', $firstArgs);
rehearsalAssert($manual['status'] === 'manual_entry', 'Unavailable OCR must keep manual entry');

rehearsalCall($controller, 'UpdateCaptureReceipt', $firstArgs, ['printed_total' => 3, 'shopping_location_id' => 7]);
$included = rehearsalCall($controller, 'AddCaptureReceiptLine', $firstArgs, ['description' => 'Scanned purchase', 'decision' => 'include', 'quantity' => 1, 'line_total' => 3, 'product_id' => 101]);
rehearsalCall($controller, 'UpdateCaptureReceiptAllocation', $firstArgs + ['lineId' => (string)$included['lines'][0]['id']], ['capture_line_id' => 1, 'quantity' => 1, 'unit_price' => 3]);
rehearsalCall($controller, 'FinishCaptureReceipt', $firstArgs);
$unfinished = rehearsalCall($controller, 'CaptureReceiptReadiness', ['tripId' => (string)$trip]);
rehearsalAssert($unfinished['ready'] === false && in_array('receipt_' . $secondArgs['receiptId'] . '_unfinished', $unfinished['reasons'], true), 'Unfinished second receipt must block readiness');

rehearsalCall($controller, 'UpdateCaptureReceipt', $secondArgs, ['printed_total' => 2.5, 'shopping_location_id' => 8]);
$ignored = rehearsalCall($controller, 'AddCaptureReceiptLine', $secondArgs, ['description' => 'Receipt-only purchased item', 'decision' => 'ignore', 'quantity' => 1, 'line_total' => 2]);
rehearsalAssert($ignored['lines'][0]['decision'] === 'ignore', 'Purchased item must remain ignored');
$review = rehearsalCall($controller, 'UpdateCaptureReceipt', $secondArgs, ['accept_difference' => true]);
rehearsalAssert((float)$review['totals']['difference'] === 0.5, 'Accepted difference was not 0.50');
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'accept_difference' AND actor = '1'")->fetchColumn() === 1, 'Difference acceptance lacks audited actor');
rehearsalCall($controller, 'FinishCaptureReceipt', $secondArgs);

$readiness = rehearsalCall($controller, 'CaptureReceiptReadiness', ['tripId' => (string)$trip]);
rehearsalAssert($readiness['ready'] === true && count($readiness['receipts']) === 2, 'Two finished receipts must be ready');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_receipt_allocations')->fetchColumn() === 1, 'Ignored item gained an allocation');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() === 0 && $stock->calls === 0, 'Stock changed before explicit commit');
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() === 0, 'Commit audit appeared before explicit commit');
echo "Before commit: two receipts ready; one ignored item; accepted difference 0.50; stock rows 0; stock calls 0; commit audit rows 0\n";

$result = $capture->CommitTrip($trip, 'rehearsal', $capture->ChecksumForTrip($trip));
rehearsalAssert($result['outcome'] === 'committed' && $result['applied'] === 1, 'Explicit commit did not apply one allocation');
rehearsalAssert($stock->calls === 1 && (int)$pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() === 1, 'Explicit commit did not write exactly one stock row');
rehearsalAssert((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() === 1, 'Commit allocation audit missing');
$again = $capture->CommitTrip($trip, 'rehearsal', $capture->ChecksumForTrip($trip));
rehearsalAssert($again['outcome'] === 'already_committed' && $stock->calls === 1, 'Idempotent retry duplicated stock');
rehearsalAssert((int)$pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() === 1, 'Idempotent retry duplicated stock row');
echo "After explicit commit and retry: stock rows 1; stock calls 1; commit audit rows 1; retry already_committed\n";
