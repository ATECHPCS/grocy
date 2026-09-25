<?php

namespace GrocyAI\Services;

use PDO;

/**
 * Inactive, namespaced, append-only schema for the Phase 8 purchase-capture & deferred stock-intake
 * engine.
 *
 * Mirrors the `GrocyAiBulkMigration::Bootstrap` idiom exactly (transaction guard, module migration
 * ledger, `CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE` of the version). It creates only the module
 * capture tables — the trip header, the coalescing trip lines, and the append-only audit ledger — and
 * never touches a native Grocy table or trigger. The trip-status, line-status, selected, and line-outcome
 * CHECK constraints match the closed vocabularies fixed in the Phase 8 design. The coalescing invariant
 * (Q6: one incrementing line per canonical/barcode) is enforced by a UNIQUE index on
 * `(trip_id, COALESCE(canonical_gtin, scanned_barcode))`.
 */
class GrocyAiCaptureMigration
{
	public const VERSION = 'v1';

	public static function Bootstrap(PDO $pdo): void
	{
		$startedTransaction = !$pdo->inTransaction();
		if ($startedTransaction)
		{
			$pdo->beginTransaction();
		}

		try
		{
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_capture_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			self::CreateSchema($pdo);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_capture_migrations (version) VALUES (?)')->execute([self::VERSION]);

			if ($startedTransaction)
			{
				$pdo->commit();
			}
		}
		catch (\Throwable $ex)
		{
			if ($startedTransaction && $pdo->inTransaction())
			{
				$pdo->rollBack();
			}
			throw $ex;
		}
	}

	private static function CreateSchema(PDO $pdo): void
	{
		// Q5: a trip is a discrete purchase batch moving through the closed open -> reviewing -> committed
		// lifecycle. It carries the trip-level location/store defaults (Q11), and once committed records the
		// native purchase transaction id, the commit time, and the reviewed checksum (Q12/Q14). None of these
		// are native Grocy rows.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_trips (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by TEXT NULL, status TEXT NOT NULL CHECK (status IN ('open', 'reviewing', 'committed')), default_location_id INTEGER NULL, default_shopping_location_id INTEGER NULL, transaction_id TEXT NULL, committed_at TEXT NULL, checksum TEXT NULL, module_version TEXT NOT NULL)");

		// Q6/Q7: each line is one coalesced scan bucket. It records the raw scanned barcode, its canonical
		// GTIN and resolved owner (or null when unknown), the known/unknown/conflict status, the scan count as
		// purchase units, the optional per-line price and best-before override (Q11), the selection flag (Q9),
		// and the per-item apply timestamp + outcome (Q12). The UNIQUE index below coalesces same-barcode
		// scans into one incrementing line.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_lines (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, seq INTEGER NOT NULL, scanned_barcode TEXT NOT NULL, canonical_gtin TEXT NULL, resolved_product_id INTEGER NULL, status TEXT NOT NULL CHECK (status IN ('known', 'unknown', 'conflict')), quantity REAL NOT NULL DEFAULT 1, price REAL NULL, best_before_override TEXT NULL, selected INTEGER NOT NULL CHECK (selected IN (0, 1)) DEFAULT 1, applied_at TEXT NULL, outcome TEXT NULL CHECK (outcome IS NULL OR outcome IN ('applied', 'conflict', 'skipped')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id))");
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_capture_lines_trip_idx ON grocy_ai_capture_lines (trip_id, seq)');
		// Q6 coalescing invariant: one line per trip per canonical GTIN (falling back to the raw barcode when
		// the scan has no checksum-valid canonical form).
		$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS grocy_ai_capture_lines_coalesce_idx ON grocy_ai_capture_lines (trip_id, COALESCE(canonical_gtin, scanned_barcode))');

		// Q12/Q14: the append-only audit ledger records the actor, the action and its timestamp, the exact
		// before/after values, and the native purchase transaction id once a commit groups its stock batch.
		// This migration creates rows only; it exposes no row-rewriting or row-removal path, so the ledger is
		// immutable by construction.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, line_id INTEGER NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, transaction_id TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id), FOREIGN KEY (line_id) REFERENCES grocy_ai_capture_lines(id))");
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_capture_audit_trip_idx ON grocy_ai_capture_audit (trip_id, line_id)');
	}
}
