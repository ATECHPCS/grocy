<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiCaptureResearchMigration
{
	public const VERSION = 'v2';

	public static function Bootstrap(PDO $pdo): void
	{
		GrocyAiReceiptMigration::Bootstrap($pdo);
		if ((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1)
		{
			if ($pdo->inTransaction()) throw new \LogicException('Research migration requires SQLite foreign keys before a transaction');
			$pdo->exec('PRAGMA foreign_keys = ON');
		}
		$started = !$pdo->inTransaction();
		if ($started) $pdo->beginTransaction();
		try
		{
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_capture_research_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_research_jobs (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, canonical_gtin TEXT NOT NULL UNIQUE CHECK (length(canonical_gtin) = 14 AND canonical_gtin NOT GLOB '*[^0-9]*'), state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'leased', 'ready', 'retryable_failure', 'needs_input')), attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5), next_retry_at TEXT NULL, lease_hash TEXT NULL, lease_expires_at TEXT NULL, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), result_revision INTEGER NOT NULL DEFAULT 0 CHECK (result_revision >= 0), safe_error_code TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_capture_research_jobs_claim_idx ON grocy_ai_capture_research_jobs (state, next_retry_at, lease_expires_at, id)');
			// Drafts survive capture-line deletion for audit; only their live line association is cleared.
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_research_drafts (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, trip_id INTEGER NOT NULL, line_id INTEGER NULL UNIQUE, scanned_barcode TEXT NOT NULL, suggested_json TEXT NOT NULL DEFAULT '{}', selected_json TEXT NOT NULL DEFAULT '{}', user_edits_json TEXT NOT NULL DEFAULT '{}', receipt_line_id INTEGER NULL, receipt_evidence TEXT NULL, revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0), result_revision INTEGER NOT NULL DEFAULT 0 CHECK (result_revision >= 0), outcome TEXT NOT NULL DEFAULT 'pending' CHECK (outcome IN ('pending', 'ready', 'needs_input', 'approved', 'linked')), final_product_id INTEGER NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (job_id) REFERENCES grocy_ai_capture_research_jobs(id), FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id), FOREIGN KEY (line_id) REFERENCES grocy_ai_capture_lines(id) ON DELETE SET NULL, FOREIGN KEY (receipt_line_id) REFERENCES grocy_ai_receipt_lines(id) ON DELETE SET NULL)");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_capture_research_drafts_trip_idx ON grocy_ai_capture_research_drafts (trip_id, line_id)');
			$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_capture_research_audit (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, trip_id INTEGER NOT NULL, draft_id INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (trip_id) REFERENCES grocy_ai_capture_trips(id), FOREIGN KEY (draft_id) REFERENCES grocy_ai_capture_research_drafts(id))");
			$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_capture_research_audit_draft_idx ON grocy_ai_capture_research_audit (draft_id, id)');
			// Keep the trip column when ON DELETE SET NULL clears a live line/evidence reference.
			// These triggers add trip scope to the individual foreign keys without rebuilding stored drafts.
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_drafts_scope_insert BEFORE INSERT ON grocy_ai_capture_research_drafts WHEN (NEW.line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_lines l WHERE l.id = NEW.line_id AND l.trip_id = NEW.trip_id)) OR (NEW.receipt_line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_receipt_lines rl JOIN grocy_ai_receipts r ON r.id = rl.receipt_id WHERE rl.id = NEW.receipt_line_id AND r.trip_id = NEW.trip_id)) BEGIN SELECT RAISE(ABORT, 'research draft trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_drafts_scope_update BEFORE UPDATE OF trip_id, line_id, receipt_line_id ON grocy_ai_capture_research_drafts WHEN (NEW.line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_lines l WHERE l.id = NEW.line_id AND l.trip_id = NEW.trip_id)) OR (NEW.receipt_line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_receipt_lines rl JOIN grocy_ai_receipts r ON r.id = rl.receipt_id WHERE rl.id = NEW.receipt_line_id AND r.trip_id = NEW.trip_id)) BEGIN SELECT RAISE(ABORT, 'research draft trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_drafts_audit_scope_update BEFORE UPDATE OF trip_id ON grocy_ai_capture_research_drafts WHEN EXISTS (SELECT 1 FROM grocy_ai_capture_research_audit a WHERE a.draft_id = OLD.id AND a.trip_id != NEW.trip_id) BEGIN SELECT RAISE(ABORT, 'research draft audit trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_capture_scope_update BEFORE UPDATE OF trip_id ON grocy_ai_capture_lines WHEN EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d WHERE d.line_id = OLD.id AND d.trip_id != NEW.trip_id) BEGIN SELECT RAISE(ABORT, 'research capture trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_receipt_line_scope_update BEFORE UPDATE OF receipt_id ON grocy_ai_receipt_lines WHEN EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_receipts r ON r.id = NEW.receipt_id WHERE d.receipt_line_id = OLD.id AND d.trip_id != r.trip_id) BEGIN SELECT RAISE(ABORT, 'research receipt line trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_receipt_scope_update BEFORE UPDATE OF trip_id ON grocy_ai_receipts WHEN EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_receipt_lines rl ON rl.id = d.receipt_line_id WHERE rl.receipt_id = OLD.id AND d.trip_id != NEW.trip_id) BEGIN SELECT RAISE(ABORT, 'research receipt trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_audit_scope_insert BEFORE INSERT ON grocy_ai_capture_research_audit WHEN NOT EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d WHERE d.id = NEW.draft_id AND d.trip_id = NEW.trip_id) BEGIN SELECT RAISE(ABORT, 'research audit trip mismatch'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_audit_no_update BEFORE UPDATE ON grocy_ai_capture_research_audit BEGIN SELECT RAISE(ABORT, 'research audit is append-only'); END");
			$pdo->exec("CREATE TRIGGER IF NOT EXISTS grocy_ai_capture_research_audit_no_delete BEFORE DELETE ON grocy_ai_capture_research_audit BEGIN SELECT RAISE(ABORT, 'research audit is append-only'); END");
			$invalidDrafts = (int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_capture_research_drafts d WHERE (d.line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_lines l WHERE l.id = d.line_id AND l.trip_id = d.trip_id)) OR (d.receipt_line_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM grocy_ai_receipt_lines rl JOIN grocy_ai_receipts r ON r.id = rl.receipt_id WHERE rl.id = d.receipt_line_id AND r.trip_id = d.trip_id))")->fetchColumn();
			$invalidAudit = (int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_capture_research_audit a WHERE NOT EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d WHERE d.id = a.draft_id AND d.trip_id = a.trip_id)')->fetchColumn();
			if ($invalidDrafts !== 0 || $invalidAudit !== 0) throw new \RuntimeException('Existing research rows have mismatched trip references');
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_capture_research_migrations (version) VALUES (?)')->execute(['v1']);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_capture_research_migrations (version) VALUES (?)')->execute([self::VERSION]);
			if ($started) $pdo->commit();
		}
		catch (\Throwable $ex)
		{
			if ($started && $pdo->inTransaction()) $pdo->rollBack();
			throw $ex;
		}
	}
}
