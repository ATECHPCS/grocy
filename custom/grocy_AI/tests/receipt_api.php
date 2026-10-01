<?php

declare(strict_types=1);

use GrocyAI\Controllers\Api\GrocyAiApiController;
use GrocyAI\Services\GrocyAiCaptureMigration;
use GrocyAI\Services\GrocyAiReceiptMigration;

if (!defined('GROCY_MODE')) define('GROCY_MODE', 'production');
$receiptApiPath = sys_get_temp_dir() . '/grocy-receipt-api-' . bin2hex(random_bytes(8));
mkdir($receiptApiPath);
if (!defined('GROCY_DATAPATH')) define('GROCY_DATAPATH', $receiptApiPath);
if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
foreach (['GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptImageStore', 'GrocyAiReceiptService', 'GrocyAiReceiptExtractor', 'GrocyAiApiController'] as $file) require_once __DIR__ . '/../src/' . $file . '.php';
function receiptApiCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function receiptApiCall(GrocyAiApiController $controller, string $method, array $args, ?array $body = null, array $files = []): Psr\Http\Message\ResponseInterface
{
	$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/test');
	if ($body !== null) $request = $request->withParsedBody($body);
	if ($files !== []) $request = $request->withUploadedFiles($files);
	return $controller->$method($request, (new Slim\Psr7\Factory\ResponseFactory())->createResponse(), $args);
}
$routes = (string)file_get_contents(__DIR__ . '/../routes.php');
foreach (['UploadCaptureReceipt', 'ListCaptureReceipts', 'CaptureReceipt', 'CaptureReceiptImage', 'ExtractCaptureReceipt', 'RetryCaptureReceipt', 'UpdateCaptureReceipt', 'AddCaptureReceiptLine', 'UpdateCaptureReceiptLine', 'UpdateCaptureReceiptAllocation', 'FinishCaptureReceipt', 'ReopenCaptureReceipt', 'SuggestCaptureReceiptMatches'] as $method)
{
	receiptApiCheck(method_exists(GrocyAiApiController::class, $method) && str_contains($routes, "'" . $method . "'"), $method . ' route/controller missing');
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE user_permissions_resolved (id INTEGER PRIMARY KEY, user_id INTEGER, permission_name TEXT)');
$pdo->exec("INSERT INTO user_permissions_resolved VALUES (1, 1, 'STOCK_PURCHASE')");
GrocyAiCaptureMigration::Bootstrap($pdo);
GrocyAiReceiptMigration::Bootstrap($pdo);
$pdo->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'reviewing', 'test'), (2, 'reviewing', 'test')");
$pdo->exec("INSERT INTO grocy_ai_receipts (id, trip_id, image_id, mime_type, image_bytes) VALUES (10, 1, '" . str_repeat('a', 48) . "', 'image/png', 68)");
$reflection = new ReflectionClass(Grocy\Services\DatabaseService::class);
foreach (['DbConnectionRaw' => $pdo, 'DbConnection' => new LessQL\Database($pdo), 'instance' => $reflection->newInstance()] as $propertyName => $value) $reflection->getProperty($propertyName)->setValue(null, $value);
$controller = (new ReflectionClass(GrocyAiApiController::class))->newInstanceWithoutConstructor();
$list = receiptApiCall($controller, 'ListCaptureReceipts', ['tripId' => '1']);
receiptApiCheck($list->getStatusCode() === 200 && count(json_decode((string)$list->getBody(), true)['receipts']) === 1, 'trip-scoped receipt list');
$wrong = receiptApiCall($controller, 'CaptureReceipt', ['tripId' => '2', 'receiptId' => '10']);
receiptApiCheck($wrong->getStatusCode() === 404, 'wrong-trip receipt is hidden');
$missingUpload = receiptApiCall($controller, 'UploadCaptureReceipt', ['tripId' => '1']);
receiptApiCheck($missingUpload->getStatusCode() === 400, 'missing upload rejected');
$canvas = imagecreatetruecolor(1, 1);
ob_start(); imagepng($canvas); $png = ob_get_clean(); imagedestroy($canvas);
$stream = (new Slim\Psr7\Factory\StreamFactory())->createStream($png);
$upload = new Slim\Psr7\UploadedFile($stream, 'receipt.png', 'image/png', strlen($png));
$created = receiptApiCall($controller, 'UploadCaptureReceipt', ['tripId' => '1'], null, ['image' => $upload]);
receiptApiCheck($created->getStatusCode() === 201, 'valid upload created receipt: ' . $created->getStatusCode() . ' ' . (string)$created->getBody());
$createdView = json_decode((string)$created->getBody(), true, 512, JSON_THROW_ON_ERROR);
receiptApiCheck(array_keys($createdView) === ['receipt', 'lines', 'totals', 'issues'], 'receipt uses separate closed view');
$createdId = (string)$createdView['receipt']['id'];
$image = receiptApiCall($controller, 'CaptureReceiptImage', ['tripId' => '1', 'receiptId' => $createdId]);
receiptApiCheck($image->getStatusCode() === 200 && $image->getHeaderLine('Content-Type') === 'image/png' && $image->getHeaderLine('Cache-Control') === 'private, no-store' && (string)$image->getBody() === $png, 'private image response is safe and exact');
$crossImage = receiptApiCall($controller, 'CaptureReceiptImage', ['tripId' => '2', 'receiptId' => $createdId]);
receiptApiCheck($crossImage->getStatusCode() === 404, 'image cannot cross trip');
$invalid = receiptApiCall($controller, 'UpdateCaptureReceipt', ['tripId' => '1', 'receiptId' => '10'], ['merchant' => 'Store', 'url' => 'http://example.test']);
receiptApiCheck($invalid->getStatusCode() === 400, 'closed receipt edit rejects URL');
$manual = receiptApiCall($controller, 'AddCaptureReceiptLine', ['tripId' => '1', 'receiptId' => '10'], ['description' => 'Milk', 'quantity' => 1, 'line_total' => 2.5]);
receiptApiCheck($manual->getStatusCode() === 200 && count(json_decode((string)$manual->getBody(), true)['lines']) === 1, 'manual entry works after OCR failure');
$retry = receiptApiCall($controller, 'RetryCaptureReceipt', ['tripId' => '1', 'receiptId' => '10']);
receiptApiCheck($retry->getStatusCode() === 200 && json_decode((string)$retry->getBody(), true)['status'] === 'manual_entry', 'retry preserves manual state');
$extract = receiptApiCall($controller, 'ExtractCaptureReceipt', ['tripId' => '1', 'receiptId' => $createdId]);
receiptApiCheck($extract->getStatusCode() === 200 && json_decode((string)$extract->getBody(), true)['status'] === 'manual_entry', 'OCR failure leaves manual state');
$pdo->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = 1");
$blocked = receiptApiCall($controller, 'UpdateCaptureReceipt', ['tripId' => '1', 'receiptId' => '10'], ['merchant' => 'Other']);
receiptApiCheck($blocked->getStatusCode() === 409, 'committed trip is read only');
$committedUpload = receiptApiCall($controller, 'UploadCaptureReceipt', ['tripId' => '1'], null, ['image' => new Slim\Psr7\UploadedFile((new Slim\Psr7\Factory\StreamFactory())->createStream($png), 'receipt.png', 'image/png', strlen($png))]);
receiptApiCheck($committedUpload->getStatusCode() === 409, 'committed trip rejects upload');
$pdo->exec('DELETE FROM user_permissions_resolved');
try { receiptApiCall($controller, 'ListCaptureReceipts', ['tripId' => '1']); throw new RuntimeException('permission allowed'); }
catch (Grocy\Controllers\Users\PermissionMissingException $expected) {}
unlink($receiptApiPath . '/grocy_ai/receipts/1/' . $createdView['receipt']['image_id']);
rmdir($receiptApiPath . '/grocy_ai/receipts/1'); rmdir($receiptApiPath . '/grocy_ai/receipts'); rmdir($receiptApiPath . '/grocy_ai'); rmdir($receiptApiPath);
echo "receipt API contract passed\n";
