<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiReceiptMigration
{
	public const VERSION = 'v4';

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
			$columns = $pdo->query('PRAGMA table_info(grocy_ai_receipt_allocations)')->fetchAll(PDO::FETCH_ASSOC);
			if (!in_array('active', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE grocy_ai_receipt_allocations ADD COLUMN active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))');
			if (!in_array('shopping_location_inherited', array_column($columns, 'name'), true)) $pdo->exec('ALTER TABLE grocy_ai_receipt_allocations ADD COLUMN shopping_location_inherited INTEGER NOT NULL DEFAULT 0 CHECK (shopping_location_inherited IN (0, 1))');
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_receipt_allocations_receipt_idx ON grocy_ai_receipt_allocations (receipt_id, receipt_line_id)');
			$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS grocy_ai_receipt_allocations_scope_idx ON grocy_ai_receipt_allocations (trip_id, receipt_id, id)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_receipt_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, receipt_id INTEGER NOT NULL, line_id INTEGER NULL, allocation_id INTEGER NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id, receipt_id) REFERENCES grocy_ai_receipts(trip_id, id), FOREIGN KEY (receipt_id, line_id) REFERENCES grocy_ai_receipt_lines(receipt_id, id), FOREIGN KEY (trip_id, receipt_id, allocation_id) REFERENCES grocy_ai_receipt_allocations(trip_id, receipt_id, id))");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_receipt_audit_receipt_idx ON grocy_ai_receipt_audit (receipt_id, id)');
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_receipt_audit_no_update BEFORE UPDATE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_receipt_audit_no_delete BEFORE DELETE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
			self::UpgradeAuditScope($pdo);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_receipt_migrations (version) VALUES (?)')->execute(['v2']);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_receipt_migrations (version) VALUES (?)')->execute(['v3']);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_receipt_migrations (version) VALUES (?)')->execute([self::VERSION]);
			if ($started) $pdo->commit();
		}
		catch (\Throwable $ex)
		{
			if ($started && $pdo->inTransaction()) $pdo->rollBack();
			throw $ex;
		}
	}
	private static function UpgradeAuditScope(PDO $pdo): void
	{
		$keys = $pdo->query('PRAGMA foreign_key_list(grocy_ai_receipt_audit)')->fetchAll(PDO::FETCH_ASSOC);
		$allocationKeys = [];
		foreach ($keys as $key)
		{
			if ($key['table'] === 'grocy_ai_receipt_allocations')
			{
				$allocationKeys[$key['from']] = $key['to'];
			}
		}
		if ($allocationKeys === ['trip_id' => 'trip_id', 'receipt_id' => 'receipt_id', 'allocation_id' => 'id']) return;

		// SQLite cannot alter a foreign key. Copy the append-only rows inside the migration
		// transaction; inconsistent old rows abort without losing audit history.
		$pdo->exec("CREATE TABLE grocy_ai_receipt_audit_upgrade (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, receipt_id INTEGER NOT NULL, line_id INTEGER NULL, allocation_id INTEGER NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id, receipt_id) REFERENCES grocy_ai_receipts(trip_id, id), FOREIGN KEY (receipt_id, line_id) REFERENCES grocy_ai_receipt_lines(receipt_id, id), FOREIGN KEY (trip_id, receipt_id, allocation_id) REFERENCES grocy_ai_receipt_allocations(trip_id, receipt_id, id))");
		$pdo->exec('INSERT INTO grocy_ai_receipt_audit_upgrade (id, trip_id, receipt_id, line_id, allocation_id, actor, action, before_json, after_json, created_at) SELECT id, trip_id, receipt_id, line_id, allocation_id, actor, action, before_json, after_json, created_at FROM grocy_ai_receipt_audit ORDER BY id');
		$pdo->exec('DROP TABLE grocy_ai_receipt_audit');
		$pdo->exec('ALTER TABLE grocy_ai_receipt_audit_upgrade RENAME TO grocy_ai_receipt_audit');
		$pdo->exec('CREATE INDEX grocy_ai_receipt_audit_receipt_idx ON grocy_ai_receipt_audit (receipt_id, id)');
		$pdo->exec("CREATE TRIGGER grocy_ai_receipt_audit_no_update BEFORE UPDATE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
		$pdo->exec("CREATE TRIGGER grocy_ai_receipt_audit_no_delete BEFORE DELETE ON grocy_ai_receipt_audit BEGIN SELECT RAISE(ABORT, 'receipt audit is append-only'); END");
	}

}
