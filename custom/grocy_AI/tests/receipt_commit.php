<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiReceiptService;

foreach (['GrocyAiGtin', 'GrocyAiBarcodeService', 'GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptService', 'GrocyAiCaptureService'] as $class) require_once __DIR__ . '/../src/' . $class . '.php';
require_once __DIR__ . '/capture.php';
require_once dirname(__DIR__, 3) . '/packages/autoload.php';
if (!defined('GROCY_USER_ID')) define('GROCY_USER_ID', 1);

function commitCheck(bool $ok, string $message): void
{
	if (!$ok) throw new RuntimeException($message);
}

class ReceiptCommitStock
{
	public bool $fail = false;
	public function __construct(private PDO $pdo) {}
	public function AddProduct(int $productId, float $amount, $bbd, $type, $date, $price, $location = null, $store = null, &$transactionId = null, $label = 0, $exact = false, $note = null)
	{
		$transactionId ??= 'receipt-transaction';
		$this->pdo->prepare('INSERT INTO stock (product_id, amount, transaction_id, price, store) VALUES (?, ?, ?, ?, ?)')->execute([$productId, $amount, $transactionId, $price, $store]);
		if ($this->fail) throw new RuntimeException('Simulated native write failure');
	}
}
// Exercise native AddProduct, including its tare arithmetic, stock writes, and compaction query.
// Only the broad product-details query is adapted to this small deterministic SQLite fixture.
class ReceiptNativeStock extends \Grocy\Services\StockService
{
	public function __construct(private PDO $pdo) {}
	protected function getDatabase()
	{
		return new \LessQL\Database($this->pdo);
	}
	public function GetProductDetails(int $productId)
	{
		$product = $this->pdo->query('SELECT * FROM products WHERE id = ' . $productId)->fetch(PDO::FETCH_OBJ);
		return ['product' => $product, 'stock_amount' => (float)$this->pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $productId)->fetchColumn()];
	}
}
function commitFixture(): array
{
	$pdo = captureFixturePdo(new CaptureBeginRacePdo('sqlite::memory:'));
	$pdo->exec('ALTER TABLE stock ADD COLUMN price REAL');
	$pdo->exec('ALTER TABLE stock ADD COLUMN store INTEGER');
	$stock = new ReceiptCommitStock($pdo);
	$capture = new GrocyAiCaptureService($pdo, true, $stock);
	$receipts = new GrocyAiReceiptService($pdo);
	$trip = (int)$capture->StartTrip('test')['id'];
	$capture->ScanIntoTrip($trip, '012345678905', 'test');
	return [$pdo, $capture, $receipts, $trip, $stock];
}
function commitReceipt(PDO $pdo, GrocyAiReceiptService $receipts, int $trip, ?int $captureLine = 1, int $store = 7, float $quantity = 1, float $price = 3): int
{
	$pdo->prepare("INSERT INTO grocy_ai_receipts (trip_id, image_id, mime_type, image_bytes, printed_total, shopping_location_id) VALUES (?, ?, 'image/png', 20, ?, ?)")->execute([$trip, uniqid(), $quantity * $price, $store]);
	$id = (int)$pdo->lastInsertId();
	$view = $receipts->AddLine($id, ['description' => 'Milk', 'decision' => 'include', 'quantity' => $quantity, 'line_total' => $quantity * $price, 'product_id' => 101], 'test');
	$receipts->UpdateAllocation($id, (int)$view['lines'][0]['id'], ['capture_line_id' => $captureLine, 'quantity' => $quantity, 'unit_price' => $price], 'test');
	$receipts->Finish($id, 'test');
	return $id;
}
$tests = [];
$tests['no receipts cannot write stock'] = function (): void
{
	[$pdo, $capture, , $trip] = commitFixture();
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'receipt_review_required' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 0, 'missing audit must block stock');
};
$tests['unfinished, needs review and missing price block'] = function (): void
{
	foreach (['unfinished', 'needs_review', 'missing_price'] as $case)
	{
		[$pdo, $capture, $receipts, $trip] = commitFixture();
		$id = commitReceipt($pdo, $receipts, $trip);
		if ($case === 'unfinished') $receipts->Reopen($id, 'test');
		if ($case === 'needs_review') $pdo->exec("UPDATE grocy_ai_receipt_lines SET decision = 'needs_review'");
		if ($case === 'missing_price') $pdo->exec('UPDATE grocy_ai_receipt_allocations SET active = 0');
		$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
		commitCheck($result['outcome'] === 'receipt_review_required' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 0, $case . ' must block stock');
	}
};
$tests['multi-store splits, accepted difference, receipt-only and repeat'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	$capture->ScanIntoTrip($trip, '012345678905');
	commitReceipt($pdo, $receipts, $trip, 1, 7, 1, 3);
	$id = commitReceipt($pdo, $receipts, $trip, 1, 8, 1, 4);
	commitReceipt($pdo, $receipts, $trip, null, 9, 1, 0);
	$receipts->UpdateReceipt($id, ['printed_total' => 5], 'test');
	$receipts->UpdateReceipt($id, ['accept_difference' => true], 'test');
	$receipts->Finish($id, 'test');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	$rows = $pdo->query('SELECT amount, price, store FROM stock ORDER BY id')->fetchAll(PDO::FETCH_NUM);
	commitCheck($result['outcome'] === 'committed' && $result['applied'] === 3 && $rows == [[1, 3, 7], [1, 4, 8], [1, 0, 9]], 'receipt allocations must determine separate stock entries');
	commitCheck($pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() == 3, 'every stock allocation must be audited');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'already_committed' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 3, 'repeat cannot duplicate');
};
$tests['checksum and lock revalidation'] = function (): void
{
	foreach (["UPDATE grocy_ai_receipt_allocations SET unit_price = 9", "UPDATE grocy_ai_receipts SET status = 'needs_review'", "UPDATE grocy_ai_receipts SET revision = revision + 1"] as $edit)
	{
		[$pdo, $capture, $receipts, $trip] = commitFixture();
		commitReceipt($pdo, $receipts, $trip);
		$checksum = $capture->ChecksumForTrip($trip);
		$pdo->beforeBegin = static fn(PDO $db) => $db->exec($edit);
		$result = $capture->CommitTrip($trip, 'test', $checksum);
		commitCheck(in_array($result['outcome'], ['checksum_mismatch', 'receipt_review_required'], true) && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 0, 'concurrent receipt edit must block before stock');
		commitCheck($capture->ChecksumForTrip($trip) !== $checksum, 'receipt change must affect checksum');
	}
};
$tests['partial retries and applied allocation immutability'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	$id = commitReceipt($pdo, $receipts, $trip);
	$other = commitReceipt($pdo, $receipts, $trip, null, 8);
	$pdo->exec('UPDATE product_barcodes SET product_id = 102');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'partial' && $result['applied'] === 1 && $result['conflicted'] === 1, 'owner conflict allows only nonconflicting allocations');
	foreach ([['id' => 2, 'unit_price' => 8], ['id' => 2, 'delete' => true]] as $change)
	{
		try { $receipts->UpdateAllocation($other, 2, $change, 'test'); throw new LogicException('Applied allocation was mutable'); }
		catch (RuntimeException|InvalidArgumentException $expected) {}
	}
	$again = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($again['applied'] === 0 && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 1, 'partial retry duplicates no stock');
	$pdo->exec('UPDATE product_barcodes SET product_id = 101');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'committed' && $result['applied'] === 1 && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 2, 'resolved conflict retries only pending allocation');
};
$tests['applied store inheritance and audit tampering are blocked'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	commitReceipt($pdo, $receipts, $trip);
	$id = commitReceipt($pdo, $receipts, $trip, null);
	$pdo->exec('UPDATE product_barcodes SET product_id = 102');
	$capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	try { $receipts->UpdateReceipt($id, ['shopping_location_id' => 99], 'test'); throw new LogicException('Applied inherited store was mutable'); }
	catch (RuntimeException|InvalidArgumentException $expected) {}
	$pdo->exec('UPDATE grocy_ai_receipt_allocations SET unit_price = 88 WHERE id = 2');
	$pdo->exec('UPDATE product_barcodes SET product_id = 101');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'receipt_review_required' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 1, 'changed committed evidence blocks further writes');
};
$tests['capture audit v1 upgrade preserves history and permits scan-only delete'] = function (): void
{
	[$pdo, $capture, , $trip] = commitFixture();
	$pdo->exec('DROP TABLE grocy_ai_capture_audit');
	$pdo->exec("CREATE TABLE grocy_ai_capture_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, line_id INTEGER NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, transaction_id TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id), FOREIGN KEY (line_id) REFERENCES grocy_ai_capture_lines(id))");
	$pdo->exec("INSERT INTO grocy_ai_capture_audit (id, trip_id, line_id, actor, action, before_json) VALUES (90, 1, 1, 'test', 'scan', '{\"old\":true}')");
	$before = $pdo->query('SELECT * FROM grocy_ai_capture_audit')->fetchAll(PDO::FETCH_ASSOC);
	\GrocyAI\Services\GrocyAiCaptureMigration::Bootstrap($pdo);
	\GrocyAI\Services\GrocyAiCaptureMigration::Bootstrap($pdo);
	commitCheck($pdo->query('SELECT * FROM grocy_ai_capture_audit')->fetchAll(PDO::FETCH_ASSOC) === $before, 'upgrade must preserve all audit values and ids');
	$capture->ChecksumForTrip($trip);
	$result = $capture->UpdateLine($trip, 1, ['delete' => true], 'test');
	commitCheck($result['lines'] === [] && $pdo->query('SELECT line_id FROM grocy_ai_capture_audit WHERE id = 90')->fetchColumn() == 1, 'scan-only removal retains historic line identity');
	foreach (["UPDATE grocy_ai_capture_audit SET actor = 'other'", 'DELETE FROM grocy_ai_capture_audit'] as $sql)
	{
		try { $pdo->exec($sql); throw new LogicException('Audit is not append-only'); } catch (PDOException $expected) {}
	}
};
$tests['receipt-attached capture deletion is explicit and preserves audit'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	commitReceipt($pdo, $receipts, $trip);
	try { $capture->UpdateLine($trip, 1, ['delete' => true], 'test'); throw new LogicException('Receipt capture deleted'); }
	catch (\DomainException $expected) { commitCheck(str_contains($expected->getMessage(), 'deselect'), 'conflict tells user how to proceed'); }
	commitCheck($pdo->query('SELECT COUNT(*) FROM grocy_ai_capture_lines')->fetchColumn() == 1, 'referenced capture retained');
};
$tests['legacy partial capture never replays stock'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	$pdo->exec("UPDATE grocy_ai_capture_lines SET applied_at = CURRENT_TIMESTAMP, outcome = 'applied'");
	$pdo->exec("INSERT INTO stock (product_id, amount, transaction_id) VALUES (101, 1, 'legacy')");
	commitReceipt($pdo, $receipts, $trip);
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'receipt_review_required' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 1, 'legacy partial stock cannot replay');
};
$tests['inactive allocation skipped and stock unit price converted'] = function (): void
{
	[$pdo, $capture, $receipts, $trip] = commitFixture();
	$id = commitReceipt($pdo, $receipts, $trip, 1, 7, 1, 6);
	$receipts->UpdateAllocation($id, 1, ['id' => 1, 'delete' => true], 'test');
	$receipts->UpdateAllocation($id, 1, ['capture_line_id' => 1, 'quantity' => 1, 'unit_price' => 6], 'test');
	$receipts->Finish($id, 'test');
	$pdo->exec('UPDATE product_barcodes SET amount = 3');
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['applied'] === 1 && $pdo->query('SELECT amount, price FROM stock')->fetchAll(PDO::FETCH_NUM) == [[3, 2]], 'active confirmed allocation posts conserved amount and value');
};
$tests['native tare purchases add each allocation to existing stock exactly'] = function (): void
{
	foreach ([0, 4] as $existingAmount)
	{
		[$pdo, , $receipts, $trip] = commitFixture();
		$pdo->exec('ALTER TABLE products ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
		$pdo->exec('ALTER TABLE products ADD COLUMN enable_tare_weight_handling INTEGER NOT NULL DEFAULT 1');
		$pdo->exec('ALTER TABLE products ADD COLUMN tare_weight REAL NOT NULL DEFAULT 1');
		foreach (['stock', 'stock_log'] as $table)
		{
			foreach (['best_before_date TEXT', 'purchased_date TEXT', 'stock_id TEXT', 'location_id INTEGER', 'shopping_location_id INTEGER', 'note TEXT'] as $column) $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column);
		}
		$pdo->exec('ALTER TABLE stock_log ADD COLUMN price REAL');
		$pdo->exec('ALTER TABLE stock_log ADD COLUMN user_id INTEGER');
		$pdo->exec('CREATE VIEW stock_splits AS SELECT product_id FROM stock WHERE 0');
		if ($existingAmount > 0) $pdo->prepare('INSERT INTO stock (product_id, amount) VALUES (101, ?)')->execute([$existingAmount]);
		$pdo->exec("UPDATE grocy_ai_capture_lines SET quantity = 6, best_before_override = '2027-01-01'");
		commitReceipt($pdo, $receipts, $trip, 1, 7, 3, 2);
		commitReceipt($pdo, $receipts, $trip, 1, 8, 3, 4);
		$capture = new GrocyAiCaptureService($pdo, true, new ReceiptNativeStock($pdo));
		$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
		commitCheck($result['outcome'] === 'committed' && $result['applied'] === 2, 'native tare split must commit with starting stock ' . $existingAmount . ', got ' . $result['outcome']);
		commitCheck((float)$pdo->query('SELECT SUM(amount) FROM stock')->fetchColumn() === $existingAmount + 6.0, 'native tare total must increase by six');
		commitCheck($pdo->query('SELECT amount, price, shopping_location_id FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_NUM) == [[3, 2, 7], [3, 4, 8]], 'native stock log must preserve both allocation quantities, prices, and stores');
		$events = $pdo->query("SELECT after_json FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
		commitCheck(count($events) === 2 && json_decode($events[0], true)['amount'] == 3 && json_decode($events[1], true)['amount'] == 3, 'receipt events must match native tare stock log amounts');
		$repeat = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
		commitCheck($repeat['outcome'] === 'already_committed' && (float)$pdo->query('SELECT SUM(amount) FROM stock')->fetchColumn() === $existingAmount + 6.0, 'native tare repeat must not change stock');
	}
};
$tests['stock failure rolls back stock and audit'] = function (): void
{
	[$pdo, $capture, $receipts, $trip, $stock] = commitFixture();
	commitReceipt($pdo, $receipts, $trip);
	$stock->fail = true;
	$result = $capture->CommitTrip($trip, 'test', $capture->ChecksumForTrip($trip));
	commitCheck($result['outcome'] === 'commit_failed' && $pdo->query('SELECT COUNT(*) FROM stock')->fetchColumn() == 0 && $pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'commit_allocation'")->fetchColumn() == 0, 'native failure rolls back whole batch');
};
$failures = 0;
foreach ($tests as $name => $test)
{
	try { $test(); echo "PASS: $name\n"; }
	catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL: $name: {$error->getMessage()}\n"); }
}
exit($failures ? 1 : 0);
