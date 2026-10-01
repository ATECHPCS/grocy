<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiCaptureResearchService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap) GrocyAiCaptureResearchMigration::Bootstrap($this->Db);
	}

	/** @return array<string, mixed>|null */
	public function EnqueueUnknown(int $tripId, int $lineId, string $scannedBarcode): ?array
	{
		$canonical = GrocyAiGtin::CanonicalOrNull($scannedBarcode);
		if ($canonical === null) return null;
		$started = !$this->Db->inTransaction();
		if ($started) $this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$lineQuery = $this->Db->prepare("SELECT l.*, t.status AS trip_status FROM grocy_ai_capture_lines l JOIN grocy_ai_capture_trips t ON t.id = l.trip_id WHERE l.id = ? AND l.trip_id = ? AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)");
			$lineQuery->execute([$lineId, $tripId]);
			$line = $lineQuery->fetch(PDO::FETCH_ASSOC);
			if ($line === false || $line['trip_status'] === 'committed' || $line['status'] !== 'unknown' || (int)$line['selected'] !== 1 || $line['canonical_gtin'] !== $canonical || GrocyAiGtin::CanonicalOrNull((string)$line['scanned_barcode']) !== $canonical)
			{
				if ($started) $this->Db->commit();
				return null;
			}

			$this->Db->prepare('INSERT OR IGNORE INTO grocy_ai_capture_research_jobs (canonical_gtin) VALUES (?)')->execute([$canonical]);
			$jobQuery = $this->Db->prepare('SELECT id FROM grocy_ai_capture_research_jobs WHERE canonical_gtin = ?');
			$jobQuery->execute([$canonical]);
			$jobId = (int)$jobQuery->fetchColumn();
			$insert = $this->Db->prepare('INSERT OR IGNORE INTO grocy_ai_capture_research_drafts (job_id, trip_id, line_id, scanned_barcode) VALUES (?, ?, ?, ?)');
			$insert->execute([$jobId, $tripId, $lineId, $scannedBarcode]);
			$draftQuery = $this->Db->prepare('SELECT id, job_id, trip_id, line_id, revision, outcome FROM grocy_ai_capture_research_drafts WHERE line_id = ?');
			$draftQuery->execute([$lineId]);
			$draft = $draftQuery->fetch(PDO::FETCH_ASSOC);
			if ($draft === false) throw new \RuntimeException('Unable to load research draft');
			if ($insert->rowCount() === 1)
			{
				$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, after_json) VALUES (?, ?, 'system', 'enqueue', ?)")->execute([$tripId, $draft['id'], json_encode(['canonical_gtin' => $canonical], JSON_THROW_ON_ERROR)]);
			}
			if ($started) $this->Db->commit();
			return $draft;
		}
		catch (\Throwable $ex)
		{
			if ($started && $this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	/** @return array<int, array<string, mixed>> */
	public function DraftsForTrip(int $tripId): array
	{
		$query = $this->Db->prepare('SELECT d.id, d.job_id, d.trip_id, d.line_id, d.scanned_barcode, j.canonical_gtin, j.state AS job_state, j.safe_error_code, d.suggested_json, d.selected_json, d.user_edits_json, d.receipt_line_id, d.receipt_evidence, d.revision, d.result_revision, d.outcome, d.final_product_id, d.created_at, d.updated_at FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_research_jobs j ON j.id = d.job_id JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id WHERE d.trip_id = ? ORDER BY l.seq');
		$query->execute([$tripId]);
		return $query->fetchAll(PDO::FETCH_ASSOC);
	}
}
