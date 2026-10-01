<?php

declare(strict_types=1);

namespace Psr\Http\Message {
	if (!interface_exists(UploadedFileInterface::class))
	{
		interface UploadedFileInterface
		{
			public function getStream();
			public function moveTo(string $targetPath): void;
			public function getSize(): ?int;
			public function getError(): int;
			public function getClientFilename(): ?string;
			public function getClientMediaType(): ?string;
		}
	}
}

namespace {
	use GrocyAI\Services\GrocyAiCaptureMigration;
	use GrocyAI\Services\GrocyAiReceiptMigration;
	use GrocyAI\Services\GrocyAiReceiptImageStore;
	use Psr\Http\Message\UploadedFileInterface;

	foreach (['GrocyAiCaptureMigration', 'GrocyAiReceiptMigration', 'GrocyAiReceiptImageStore'] as $class)
	{
		$path = __DIR__ . '/../src/' . $class . '.php';
		if (is_file($path)) require_once $path;
	}

	final class ReceiptTestStream
	{
		private int $offset = 0;
		public function __construct(private string $bytes) {}
		public function read(int $length): string
		{
			$part = substr($this->bytes, $this->offset, $length);
			$this->offset += strlen($part);
			return $part;
		}
		public function eof(): bool { return $this->offset >= strlen($this->bytes); }
	}
	final class ReceiptTestUpload implements UploadedFileInterface
	{
		public function __construct(private string $bytes, private ?string $mediaType = 'image/png', private ?int $reportedSize = null) {}
		public function getStream() { return new ReceiptTestStream($this->bytes); }
		public function moveTo(string $targetPath): void { throw new \LogicException('No move expected'); }
		public function getSize(): ?int { return $this->reportedSize ?? strlen($this->bytes); }
		public function getError(): int { return UPLOAD_ERR_OK; }
		public function getClientFilename(): ?string { return 'receipt.png'; }
		public function getClientMediaType(): ?string { return $this->mediaType; }
	}
	function receiptCheck(bool $condition, string $message): void
	{
		if (!$condition) throw new \RuntimeException($message);
	}
	function receiptThrows(callable $call, string $message): void
	{
		try { $call(); } catch (\InvalidArgumentException|\RuntimeException $ex) { return; }
		throw new \RuntimeException($message);
	}
	final class ReceiptRacePdo extends \PDO
	{
		public ?\Closure $beforeWriteLock = null;
		public function exec(string $statement): int|false
		{
			if ($statement === 'BEGIN IMMEDIATE' && $this->beforeWriteLock !== null)
			{
				$callback = $this->beforeWriteLock;
				$this->beforeWriteLock = null;
				$callback($this);
			}
			return parent::exec($statement);
		}
	}
	function receiptPng(): string
	{
		$image = imagecreatetruecolor(2, 2);
		ob_start(); imagepng($image); $bytes = (string)ob_get_clean(); imagedestroy($image);
		return $bytes;
	}
	function receiptFixture(?\PDO $pdo = null): array
	{
		$pdo ??= new \PDO('sqlite::memory:');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		GrocyAiCaptureMigration::Bootstrap($pdo);
		$pdo->exec("INSERT INTO grocy_ai_capture_trips (id, status, module_version) VALUES (1, 'open', 'test'), (2, 'open', 'test')");
		GrocyAiReceiptMigration::Bootstrap($pdo);
		$directory = sys_get_temp_dir() . '/grocy-receipt-test-' . bin2hex(random_bytes(8));
		mkdir($directory, 0700);
		return [$pdo, $directory];
	}
	function receiptCleanup(string $path): void
	{
		foreach (glob($path . '/grocy_ai/receipts/*/*') ?: [] as $file) unlink($file);
		foreach (glob($path . '/grocy_ai/receipts/*') ?: [] as $dir) rmdir($dir);
		@rmdir($path . '/grocy_ai/receipts'); @rmdir($path . '/grocy_ai'); rmdir($path);
	}
	function receiptStorageTests(): void
	{
		[$pdo, $directory] = receiptFixture();
		try
		{
			$store = new GrocyAiReceiptImageStore($pdo, $directory);
			$png = receiptPng();
			$a = $store->Save(1, new ReceiptTestUpload($png), 'request-a');
			$b = $store->Save(1, new ReceiptTestUpload($png), 'request-b');
			receiptCheck($a['receipt_id'] !== $b['receipt_id'], 'one trip must hold multiple receipts');
			receiptCheck($a['image_id'] !== $b['image_id'], 'receipt image IDs must be unique');
			receiptCheck((int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE action = 'upload'")->fetchColumn() === 2, 'each upload must have an audit event');
			$again = $store->Save(1, new ReceiptTestUpload('different invalid bytes'), 'request-a');
			receiptCheck($again === $a, 'duplicate request ID must return original receipt');
			receiptCheck($store->Read(1, $a['image_id'])['bytes'] === $png, 'read must return private original bytes');
			receiptCheck($store->Read(1, $a['image_id'])['mime_type'] === 'image/png', 'read must return detected media type');
			receiptThrows(fn() => $store->Read(2, $a['image_id']), 'cross-trip image read must fail');
			receiptThrows(fn() => $store->Read(1, str_repeat('0', 32)), 'guessed image ID must fail');
			receiptThrows(fn() => $store->Read(1, '../' . $a['image_id']), 'path traversal must fail');
			receiptThrows(fn() => $store->Save(1, new ReceiptTestUpload('bad'), 'bad-image'), 'invalid image bytes must fail');
			receiptThrows(fn() => $store->Save(1, new ReceiptTestUpload($png, 'image/jpeg'), 'bad-type'), 'mismatched declared type must fail');
			receiptThrows(fn() => $store->Save(1, new ReceiptTestUpload(str_repeat('X', 12 * 1024 * 1024)), 'too-large'), 'oversized image must fail');
			$largeImage = imagecreatetruecolor(4100, 4000);
			ob_start(); imagepng($largeImage); $largeBytes = (string)ob_get_clean(); imagedestroy($largeImage);
			receiptThrows(fn() => $store->Save(1, new ReceiptTestUpload($largeBytes), 'too-many-pixels'), 'compressed image exceeding safe decode pixels must fail');
			$pdo->beginTransaction();
			receiptThrows(fn() => $store->Save(1, new ReceiptTestUpload($png), 'outer-transaction'), 'upload in an outer transaction must fail safely');
			receiptCheck($pdo->inTransaction(), 'a rejected upload must preserve the caller transaction');
			$pdo->rollBack();
			receiptCheck((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_receipts')->fetchColumn() === 2, 'rejected images must leave no receipt');
			GrocyAiReceiptMigration::Bootstrap($pdo);
			receiptCheck((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_receipts')->fetchColumn() === 2, 'repeat migration must preserve receipts');
			$pdo->exec("INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, description, decision) VALUES ({$a['receipt_id']}, 1, 'Milk', 'needs_review')");
			$lineId = (int)$pdo->lastInsertId();
			$pdo->exec("INSERT INTO grocy_ai_capture_lines (trip_id, seq, scanned_barcode, status) VALUES (2, 1, 'other', 'known')");
			$otherLine = (int)$pdo->lastInsertId();
			receiptThrows(fn() => $pdo->exec("INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, capture_line_id, quantity, unit_price) VALUES (1, {$a['receipt_id']}, $lineId, $otherLine, 1, 2)"), 'cross-trip allocation must fail');
			$pdo->exec("INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, description, decision) VALUES ({$b['receipt_id']}, 1, 'Bread', 'needs_review')");
			$otherReceiptLine = (int)$pdo->lastInsertId();
			$pdo->exec("INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, quantity, unit_price) VALUES (1, {$b['receipt_id']}, $otherReceiptLine, 1, 2)");
			$otherAllocation = (int)$pdo->lastInsertId();
			receiptThrows(fn() => $pdo->exec("INSERT INTO grocy_ai_receipt_audit (trip_id, receipt_id, allocation_id, actor, action) VALUES (1, {$a['receipt_id']}, $otherAllocation, 'test', 'allocate')"), 'audit must reject an allocation from another receipt');
			$pdo->exec("INSERT INTO grocy_ai_receipt_audit (trip_id, receipt_id, actor, action) VALUES (1, {$a['receipt_id']}, 'test', 'uploaded')");
			receiptThrows(fn() => $pdo->exec('UPDATE grocy_ai_receipt_audit SET action = \'changed\''), 'receipt audit must reject update');
		}
		finally { receiptCleanup($directory); }
		[$racePdo, $raceDirectory] = receiptFixture(new ReceiptRacePdo('sqlite::memory:'));
		try
		{
			$raceStore = new GrocyAiReceiptImageStore($racePdo, $raceDirectory);
			$racePdo->beforeWriteLock = static fn(\PDO $db) => $db->exec("UPDATE grocy_ai_capture_trips SET status = 'committed' WHERE id = 1");
			receiptThrows(fn() => $raceStore->Save(1, new ReceiptTestUpload(receiptPng()), 'race'), 'trip committed during upload must reject receipt');
			receiptCheck((int)$racePdo->query('SELECT COUNT(*) FROM grocy_ai_receipts')->fetchColumn() === 0, 'race rejection must leave no header');
		}
		finally { receiptCleanup($raceDirectory); }
	}
	receiptStorageTests();
	fwrite(STDOUT, "Receipt storage tests passed\n");
}
