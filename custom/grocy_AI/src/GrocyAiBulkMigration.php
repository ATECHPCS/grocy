<?php

namespace GrocyAI\Services;

use PDO;

/**
 * Inactive, namespaced, append-only schema for the Phase 5 bulk maintenance & recovery engine.
 *
 * Mirrors the `GrocyAiConversionMigration::Bootstrap` idiom exactly (transaction guard, module
 * migration ledger, `CREATE TABLE IF NOT EXISTS`, `INSERT OR IGNORE` of the version). It creates only
 * the three module tables — the plan header, the immutable plan items, and the append-only audit
 * ledger — and never touches, shadows, alters, or drops a native Grocy table or trigger. The status,
 * selected, and outcome CHECK constraints match the closed vocabularies fixed in Plan 05-01.
 */
class GrocyAiBulkMigration
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
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_bulk_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			self::CreateSchema($pdo);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_bulk_migrations (version) VALUES (?)')->execute([self::VERSION]);

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
		// D-02: the plan header records object scope, exact counts, the immutable checksum, the
		// ruleset version, the operation type, and the module version, none of which are native rows.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_bulk_plans (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by TEXT NULL, ruleset_version TEXT NOT NULL, operation_type TEXT NOT NULL, scope_json TEXT NOT NULL, counts_json TEXT NOT NULL, checksum TEXT NOT NULL, status TEXT NOT NULL CHECK (status IN ('draft', 'applied', 'partially_applied', 'rolled_back')), module_version TEXT NOT NULL)");

		// D-02: each plan item records a stable object identity, an immutable before-image and proposed
		// value, a named reason, provenance, the selection flag, the per-item outcome, and the apply
		// timestamp. before_image_json is captured once at generation and never re-derived.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_bulk_plan_items (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL, seq INTEGER NOT NULL, object_type TEXT NOT NULL, object_id INTEGER NOT NULL, operation TEXT NOT NULL, before_image_json TEXT NOT NULL, proposed_value_json TEXT NOT NULL, reason TEXT NOT NULL, provenance TEXT NOT NULL, selected INTEGER NOT NULL CHECK (selected IN (0, 1)), outcome TEXT NOT NULL CHECK (outcome IN ('pending', 'applied', 'conflict', 'skipped', 'rejected', 'rolled_back')), applied_at TEXT NULL, FOREIGN KEY (plan_id) REFERENCES grocy_ai_bulk_plans(id))");
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_bulk_plan_items_plan_idx ON grocy_ai_bulk_plan_items (plan_id, seq)');

		// D-10: the append-only audit ledger records the actor, the event and its timestamp, the
		// module/version, the exact before/after values, and the per-item outcome. This migration
		// creates rows only; it exposes no row-rewriting or row-removal path, so the ledger is
		// immutable by construction.
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_bulk_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL, plan_item_id INTEGER NULL, actor TEXT NOT NULL, event TEXT NOT NULL, event_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, module_version TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, outcome TEXT NOT NULL CHECK (outcome IN ('pending', 'applied', 'conflict', 'skipped', 'rejected', 'rolled_back')), FOREIGN KEY (plan_id) REFERENCES grocy_ai_bulk_plans(id), FOREIGN KEY (plan_item_id) REFERENCES grocy_ai_bulk_plan_items(id))");
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_bulk_audit_plan_idx ON grocy_ai_bulk_audit (plan_id, plan_item_id)');
	}
}
