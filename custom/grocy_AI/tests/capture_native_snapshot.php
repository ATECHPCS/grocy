<?php

declare(strict_types=1);

use Grocy\Services\DatabaseService;
use Grocy\Services\StockService;
use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiGtin;

$snapshot = realpath(__DIR__ . '/../.snapshots/grocy-prod.sqlite');
if ($snapshot === false || $snapshot !== realpath((string)(getenv('GROCY_AI_SNAPSHOT') ?: $snapshot)))
{
	fwrite(STDERR, "A scrubbed local Phase 6 snapshot is required\n");
	exit(2);
}

$directory = sys_get_temp_dir() . '/grocy-ai-capture-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700) || !copy($snapshot, $directory . '/grocy.db'))
{
	fwrite(STDERR, "Could not stage the disposable snapshot copy\n");
	exit(2);
}

register_shutdown_function(static function () use ($directory): void
{
	foreach (glob($directory . '/*') ?: [] as $file)
	{
		unlink($file);
	}
	rmdir($directory);
});

define('GROCY_DATAPATH', $directory);
require_once __DIR__ . '/../../../packages/autoload.php';
require_once __DIR__ . '/../../../helpers/extensions.php';
require_once __DIR__ . '/../../../config-dist.php';
require_once __DIR__ . '/../src/GrocyAiGtin.php';
require_once __DIR__ . '/../src/GrocyAiBarcodeService.php';
require_once __DIR__ . '/../src/GrocyAiCaptureMigration.php';
require_once __DIR__ . '/../src/GrocyAiCaptureService.php';

$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$userId = (int)$db->query('SELECT MIN(id) FROM users')->fetchColumn();
if ($userId < 1)
{
	throw new RuntimeException('The scrubbed snapshot has no test actor');
}
define('GROCY_USER_ID', $userId);

$candidate = null;
$barcodes = $db->query('SELECT pb.barcode, p.id FROM product_barcodes pb JOIN products p ON p.id = pb.product_id WHERE p.active = 1 AND pb.amount IS NULL ORDER BY p.id, pb.id');
foreach ($barcodes as $row)
{
	if (GrocyAiGtin::CanonicalOrNull((string)$row['barcode']) !== null)
	{
		$candidate = $row;
		break;
	}
}
if ($candidate === null)
{
	throw new RuntimeException('The scrubbed snapshot has no valid owned GTIN for native acceptance');
}

$productId = (int)$candidate['id'];
$capture = new GrocyAiCaptureService($db, true);
$tripId = (int)$capture->StartTrip('snapshot-test')['id'];
$line = $capture->ScanIntoTrip($tripId, (string)$candidate['barcode'], 'snapshot-test');
if ($line['status'] !== 'known' || (int)$line['resolved_product_id'] !== $productId)
{
	throw new RuntimeException('The snapshot GTIN did not resolve to its native product');
}

$note = 'grocy_AI purchase capture trip #' . $tripId;
$stock = StockService::GetInstance();
$transactionId = null;
$stock->AddProduct($productId, 1.0, null, 'purchase', date('Y-m-d'), null, null, null, $transactionId, 0, false, $note);
$before = (float)$db->query('SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = ' . $productId)->fetchColumn();
$rowCountBefore = (int)$db->query('SELECT COUNT(*) FROM stock WHERE product_id = ' . $productId)->fetchColumn();

$checksum = $capture->ChecksumForTrip($tripId);
$result = $capture->CommitTrip($tripId, 'snapshot-test', $checksum);
if ($result['outcome'] !== 'committed' || (int)$result['applied'] !== 1 || $result['transaction_id'] === null)
{
	throw new RuntimeException('The real native purchase did not commit atomically');
}
$after = (float)$db->query('SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = ' . $productId)->fetchColumn();
$rowCountAfter = (int)$db->query('SELECT COUNT(*) FROM stock WHERE product_id = ' . $productId)->fetchColumn();
$booking = $db->prepare("SELECT amount FROM stock_log WHERE product_id = ? AND transaction_id = ? AND transaction_type = 'purchase'");
$booking->execute([$productId, $result['transaction_id']]);
$bookedAmount = $booking->fetchColumn();
if ($bookedAmount === false || abs(($after - $before) - (float)$bookedAmount) > 1e-8)
{
	throw new RuntimeException('The native stock delta disagrees with the booked purchase amount');
}
if ($rowCountAfter !== $rowCountBefore)
{
	throw new RuntimeException('Native compaction did not fold the purchase into the matching stock row');
}
$repeat = $capture->CommitTrip($tripId, 'snapshot-test', $checksum);
if ($repeat['outcome'] !== 'already_committed' || (float)$db->query('SELECT IFNULL(SUM(amount), 0) FROM stock WHERE product_id = ' . $productId)->fetchColumn() !== $after)
{
	throw new RuntimeException('A repeated native commit changed stock');
}

fwrite(STDOUT, "Disposable snapshot native purchase, stock delta, and idempotency passed\n");
