<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiReceiptMigration
{
	public const VERSION = 'v1';

	public static function Bootstrap(PDO $pdo): void
	{
		if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1)
		{
			if ($pdo->inTransaction()) throw new \LogicException('Receipt migration requires SQLite foreign keys before a transaction');
			$pdo->exec('PRAGMA foreign_keys = ON');
		}
		$started = !$pdo->inTransaction();
		if ($started) $pdo->beginTransaction();
		try
		{
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_receipt_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_receipts (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, request_id TEXT NULL, image_id TEXT NOT NULL UNIQUE, mime_type TEXT NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')), image_bytes INTEGER NOT NULL CHECK (image_bytes > 0), status TEXT NOT NULL DEFAULT 'needs_review' CHECK (status IN ('processing', 'needs_review', 'finished')), merchant TEXT NULL, purchase_date TEXT NULL, printed_total REAL NULL, currency TEXT NULL, shopping_location_id INTEGER NULL, extraction_json TEXT NULL, difference_accepted_amount REAL NULL, difference_accepted_by TEXT NULL, difference_accepted_at TEXT NULL, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id), UNIQUE (trip_id, request_id), UNIQUE (trip_id, id))");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_receipts_trip_idx ON grocy_ai_receipts (trip_id, id)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_receipt_lines (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, seq INTEGER NOT NULL, raw_text TEXT NULL, description TEXT NOT NULL DEFAULT '', quantity REAL NULL, line_total REAL NULL, kind TEXT NOT NULL DEFAULT 'item' CHECK (kind IN ('item', 'tax', 'discount', 'deposit', 'fee', 'other')), decision TEXT NOT NULL DEFAULT 'needs_review' CHECK (decision IN ('needs_review', 'include', 'ignore')), product_id INTEGER NULL, confidence REAL NULL, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (receipt_id) REFERENCES grocy_ai_receipts(id), UNIQUE (receipt_id, seq), UNIQUE (receipt_id, id))");
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_receipt_allocations (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, receipt_id INTEGER NOT NULL, receipt_line_id INTEGER NOT NULL, capture_line_id INTEGER NULL, product_id INTEGER NULL, quantity REAL NOT NULL CHECK (quantity > 0), unit_price REAL NOT NULL CHECK (unit_price >= 0), shopping_location_id INTEGER NULL, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id, receipt_id) REFERENCES grocy_ai_receipts(trip_id, id), FOREIGN KEY (receipt_id, receipt_line_id) REFERENCES grocy_ai_receipt_lines(receipt_id, id), FOREIGN KEY (trip_id, capture_line_id) REFERENCES grocy_ai_capture_lines(trip_id, id))");
			$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS grocy_ai_capture_lines_trip_id_unique ON grocy_ai_capture_lines (trip_id, id)');
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_receipt_allocations_receipt_idx ON grocy_ai_receipt_allocations (receipt_id, receipt_line_id)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_receipt_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, receipt_id INTEGER NOT NULL, line_id INTEGER NULL, allocation_id INTEGER NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id, receipt_id) REFERENCES grocy_ai_receipts(trip_id, id), FOREIGN KEY (receipt_id, line_id) REFERENCES grocy_ai_receipt_lines(receipt_id, id), FOREIGN KEY (allocation_id) REFERENCES grocy_ai_receipt_allocations(id))");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_receipt_audit_receipt_idx ON grocy_ai_receipt_audit (receipt_id, id)');
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_receipt_audit_no_update BEFORE UPDATE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_receipt_audit_no_delete BEFORE DELETE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_receipt_migrations (version) VALUES (?)')->execute([self::VERSION]);
			if ($started) $pdo->commit();
		}
		catch (\Throwable $ex)
		{
			if ($started && $pdo->inTransaction()) $pdo->rollBack();
			throw $ex;
		}
	}
}
