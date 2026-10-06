<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiCaptureResearchService
{
	private PDO $Db;
	private const MAX_ATTEMPTS = 5;

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
				$completed = $this->Db->prepare("SELECT suggested_json, outcome, result_revision FROM grocy_ai_capture_research_drafts WHERE job_id = ? AND id != ? AND result_revision > 0 ORDER BY result_revision DESC, id DESC LIMIT 1");
				$completed->execute([$jobId, $draft['id']]);
				$source = $completed->fetch(PDO::FETCH_ASSOC);
				if ($source !== false && in_array($source['outcome'], ['ready', 'needs_input'], true))
				{
					$suggestion = json_decode($source['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
					$selected = $source['outcome'] === 'ready' && is_array($suggestion['name_candidates'] ?? null) && isset($suggestion['name_candidates'][0]) ? ['name' => $suggestion['name_candidates'][0]] : [];
					$this->Db->prepare('UPDATE grocy_ai_capture_research_drafts SET suggested_json = ?, selected_json = ?, outcome = ?, result_revision = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$source['suggested_json'], json_encode($selected, JSON_THROW_ON_ERROR), $source['outcome'], $source['result_revision'], $draft['id']]);
					$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, after_json) VALUES (?, ?, 'system', 'research_seed', ?)")->execute([$tripId, $draft['id'], json_encode(['outcome' => $source['outcome'], 'suggested' => $suggestion], JSON_THROW_ON_ERROR)]);
					$draft['outcome'] = $source['outcome'];
					$draft['revision'] = (int)$draft['revision'] + 1;
				}
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

	/** @return array<string, mixed> */
	public function ReviewForTrip(int $tripId): array
	{
		$this->RequireTrip($tripId, false);
		$query = $this->Db->prepare('SELECT d.line_id FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id WHERE d.trip_id = ? ORDER BY l.seq');
		$query->execute([$tripId]);
		$drafts = [];
		foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $lineId) $drafts[] = $this->ReviewDraft($tripId, (int)$lineId);
		return ['contract_version' => 1, 'trip_id' => $tripId, 'drafts' => $drafts];
	}

	/** @return array<string, mixed> */
	public function ReviewOptions(): array
	{
		GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		$groups = $this->Db->query('SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
		$leaves = $this->Db->prepare('SELECT slug, label FROM grocy_ai_taxonomy_nodes WHERE version = ? AND depth = 2 ORDER BY label, slug');
		$leaves->execute([GrocyAiTaxonomyMigration::VERSION]);
		$parents = $this->Db->query('SELECT id, name, qu_id_stock FROM products WHERE active = 1 AND parent_product_id IS NULL ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
		return [
			'contract_version' => 1,
			'taxonomy_version' => GrocyAiTaxonomyMigration::VERSION,
			'product_groups' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name']], $groups),
			'taxonomy_leaves' => $leaves->fetchAll(PDO::FETCH_ASSOC),
			'generic_parents' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name'], 'qu_id_stock' => (int)$row['qu_id_stock']], $parents)
		];
	}

	/** Read-only worker evidence: choices are bounded independently of the full review catalog. */
	public function ClassificationInput(int $draftId): ?array
	{
		$query = $this->Db->prepare("SELECT d.trip_id, d.line_id FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id JOIN grocy_ai_capture_research_jobs j ON j.id = d.job_id WHERE d.id = ? AND d.outcome = 'ready' AND d.result_revision > 0 AND l.scanned_barcode = d.scanned_barcode AND l.canonical_gtin = j.canonical_gtin AND l.applied_at IS NULL AND l.status = 'unknown' AND l.selected = 1 AND l.resolved_product_id IS NULL AND t.status != 'committed' AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id) AND NOT EXISTS (SELECT 1 FROM product_barcodes b WHERE " . GrocyAiGtin::CanonicalSqlExpression('b.barcode') . " = j.canonical_gtin)");
		$query->execute([$draftId]);
		$row = $query->fetch(PDO::FETCH_ASSOC);
		if ($row === false) return null;
		$draft = $this->DraftRow((int)$row['trip_id'], (int)$row['line_id']);
		$suggested = json_decode($draft['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
		$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
		if (($suggested['outcome'] ?? null) !== 'found' || empty($suggested['name_candidates'])) return null;
		$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
		$name = self::ClassificationText($selected['name'] ?? $suggested['name_candidates'][0]);
		if ($name === null) return null;
		GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		$groups = $this->Db->query('SELECT id, name FROM product_groups WHERE active = 1 ORDER BY name, id LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
		$leavesQuery = $this->Db->prepare('SELECT slug, label FROM grocy_ai_taxonomy_nodes WHERE version = ? AND depth = 2 ORDER BY label, slug LIMIT 200');
		$leavesQuery->execute([GrocyAiTaxonomyMigration::VERSION]);
		$leaves = $leavesQuery->fetchAll(PDO::FETCH_ASSOC);
		$parents = $this->Db->query('SELECT id, name, qu_id_stock FROM products WHERE active = 1 AND parent_product_id IS NULL ORDER BY name, id LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($groups as &$group) { $group['id'] = (int)$group['id']; $group['name'] = self::ClassificationText($group['name']); }
		unset($group);
		foreach ($leaves as &$leaf) { $leaf['label'] = self::ClassificationText($leaf['label']); }
		unset($leaf);
		foreach ($parents as &$parent) { $parent['id'] = (int)$parent['id']; $parent['qu_id_stock'] = (int)$parent['qu_id_stock']; $parent['name'] = self::ClassificationText($parent['name']); }
		unset($parent);
		$receiptDescription = null;
		if ($draft['receipt_line_id'] !== null)
		{
			try { $receiptDescription = (new GrocyAiReceiptService($this->Db))->ResearchEvidence((int)$draft['trip_id'], (int)$draft['receipt_line_id'])['description']; }
			catch (\InvalidArgumentException) { /* Removed receipt evidence is optional. */ }
		}
		$candidates = ['product_group_id' => $this->GroupCandidates($suggested), 'taxonomy_leaf_slug' => $this->TaxonomyCandidates($suggested), 'parent_product_id' => $this->ParentCandidates($draft, $selected, $suggested)];
		foreach ($candidates as &$fieldCandidates)
		{
			foreach ($fieldCandidates as &$candidate)
			{
				foreach (['name', 'label', 'provider_category'] as $field)
				{
					if (array_key_exists($field, $candidate)) $candidate[$field] = self::ClassificationText($candidate[$field]);
				}
			}
			unset($candidate);
		}
		unset($fieldCandidates);

		return [
			'contract_version' => 1,
			'draft_id' => $draftId,
			'result_revision' => (int)$draft['result_revision'],
			'identity' => ['name' => $name, 'brand' => self::ClassificationText(!empty($edits['brand']) ? ($selected['brand'] ?? null) : ($selected['brand'] ?? $suggested['brand'] ?? null)), 'package' => self::ClassificationText(!empty($edits['package']) ? ($selected['package'] ?? null) : ($selected['package'] ?? $suggested['package'] ?? null)), 'receipt_description' => self::ClassificationText($receiptDescription)],
			'choices' => ['product_groups' => $groups, 'taxonomy_leaves' => $leaves, 'generic_parents' => $parents] + $this->UnitChoices(),
			'deterministic_candidates' => $candidates
		];
	}

	private static function ClassificationText(mixed $value): ?string
	{
		if (!is_string($value)) return null;
		$value = trim(preg_replace('/\p{C}/u', ' ', $value) ?? '');
		return $value === '' ? null : mb_substr($value, 0, 200);
	}

	private function ParentCandidates(array $draft, array $selected, array $suggested): array
	{
		if ($draft['outcome'] !== 'ready' || $draft['line_status'] !== 'unknown' || ($suggested['outcome'] ?? null) !== 'found') return [];
		$name = $selected['name'] ?? $suggested['name_candidates'][0] ?? null;
		if (!is_string($name) || $name === '') return [];
		$matches = [];
		$query = $this->Db->query('SELECT id, name, qu_id_stock FROM products WHERE active = 1 AND parent_product_id IS NULL ORDER BY id');
		foreach ($query as $parent)
		{
			if (mb_strtolower(trim($parent['name'])) !== mb_strtolower(trim($name))) continue;
			$matches[] = ['id' => (int)$parent['id'], 'name' => $parent['name'], 'qu_id_stock' => (int)$parent['qu_id_stock'], 'source' => 'local_identity', 'compatibility' => 'pending'];
			if (count($matches) > 1) return [];
		}
		if ($matches === [] || !isset($selected['qu_id_stock'])) return $matches;
		$stock = (int)$selected['qu_id_stock'];
		if ($stock !== $matches[0]['qu_id_stock'])
		{
			// Only global conversions apply to a child that has not yet been created.
			$conversion = $this->Db->prepare('SELECT 1 FROM quantity_unit_conversions WHERE product_id IS NULL AND from_qu_id = ? AND to_qu_id = ? AND factor > 0 LIMIT 1');
			$conversion->execute([$matches[0]['qu_id_stock'], $stock]);
			if ($conversion->fetchColumn() === false) return [];
		}
		$matches[0]['compatibility'] = 'compatible';
		return $matches;
	}

	/** @param array<string, mixed> $changes */
	public function UpdateDraft(int $tripId, int $lineId, int $revision, array $changes, string $actor): array
	{
		if ($revision < 1 || $changes === [] || array_diff(array_keys($changes), ['name', 'brand', 'package', 'product_group_id', 'taxonomy_leaf_slug', 'parent_product_id', 'qu_id_purchase', 'qu_id_stock']) !== []) throw new \InvalidArgumentException('Invalid draft changes');
		foreach ($changes as $key => $value)
		{
			if (in_array($key, ['name', 'brand', 'package'], true) && $value !== null && (!is_string($value) || trim($value) !== $value || $value === '' || mb_strlen($value) > 200)) throw new \InvalidArgumentException('Invalid draft field');
			if ($key === 'name' && $value === null) throw new \InvalidArgumentException('Name is required');
			if (in_array($key, ['product_group_id', 'parent_product_id', 'qu_id_purchase', 'qu_id_stock'], true) && $value !== null && (!is_int($value) || $value < 1)) throw new \InvalidArgumentException('Invalid group');
			if ($key === 'taxonomy_leaf_slug' && $value !== null && (!is_string($value) || preg_match('/^[a-z0-9_-]{1,100}$/D', $value) !== 1)) throw new \InvalidArgumentException('Invalid taxonomy leaf');
		}
		foreach (['qu_id_purchase', 'qu_id_stock'] as $field)
		{
			if (!isset($changes[$field])) continue;
			$query = $this->Db->prepare('SELECT 1 FROM quantity_units WHERE id = ? AND active = 1');
			$query->execute([$changes[$field]]);
			if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Inactive quantity unit');
		}
		if (isset($changes['product_group_id']))
		{
			$query = $this->Db->prepare('SELECT 1 FROM product_groups WHERE id = ? AND active = 1');
			$query->execute([$changes['product_group_id']]);
			if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Inactive group');
		}
		if (isset($changes['parent_product_id']))
		{
			$query = $this->Db->prepare('SELECT 1 FROM products WHERE id = ? AND active = 1 AND parent_product_id IS NULL');
			$query->execute([$changes['parent_product_id']]);
			if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Inactive generic parent');
		}
		if (isset($changes['taxonomy_leaf_slug']))
		{
			GrocyAiTaxonomyMigration::Bootstrap($this->Db);
			$query = $this->Db->prepare('SELECT 1 FROM grocy_ai_taxonomy_nodes WHERE slug = ? AND version = ? AND depth = 2');
			$query->execute([$changes['taxonomy_leaf_slug'], GrocyAiTaxonomyMigration::VERSION]);
			if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Unknown taxonomy leaf');
		}
		return $this->MutateDraft($tripId, $lineId, $actor, 'draft_edit', function (array $draft) use ($revision, $changes): array
		{
			if ((int)$draft['revision'] !== $revision) throw new \RuntimeException('Stale research draft');
			$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
			$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
			foreach ($changes as $key => $value)
			{
				if ($value === null && !in_array($key, ['qu_id_purchase', 'qu_id_stock'], true)) unset($selected[$key]); else $selected[$key] = $value;
				$edits[$key] = true;
			}
			return ['selected_json' => json_encode($selected, JSON_THROW_ON_ERROR), 'user_edits_json' => json_encode($edits, JSON_THROW_ON_ERROR)];
		});
	}

	public function SetReceiptEvidence(int $tripId, int $lineId, ?int $receiptLineId, string $actor): array
	{
		if ($receiptLineId !== null && $receiptLineId < 1) throw new \InvalidArgumentException('Invalid receipt line');
		return $this->MutateDraft($tripId, $lineId, $actor, 'receipt_evidence', function (array $draft) use ($tripId, $lineId, $receiptLineId): array
		{
			if ($receiptLineId !== null)
			{
				$claimed = $this->Db->prepare('SELECT 1 FROM grocy_ai_capture_research_drafts WHERE trip_id = ? AND receipt_line_id = ? AND id != ? LIMIT 1');
				$claimed->execute([$tripId, $receiptLineId, $draft['id']]);
				if ($claimed->fetchColumn() !== false) throw new \InvalidArgumentException('Receipt line already paired');
				$allocated = $this->Db->prepare('SELECT 1 FROM grocy_ai_receipt_allocations WHERE trip_id = ? AND receipt_line_id = ? AND active = 1 AND capture_line_id IS NOT NULL AND capture_line_id != ? LIMIT 1');
				$allocated->execute([$tripId, $receiptLineId, $lineId]);
				if ($allocated->fetchColumn() !== false) throw new \InvalidArgumentException('Receipt allocation conflicts with research evidence');
			}
			$evidence = $receiptLineId === null ? null : (new GrocyAiReceiptService($this->Db))->ResearchEvidence($tripId, $receiptLineId);
			$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
			$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
			$suggested = json_decode($draft['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
			if (empty($edits['name']) && ($suggested['name_candidates'] ?? []) === [])
			{
				if ($evidence === null) unset($selected['name']); else $selected['name'] = $evidence['description'];
			}
			return ['receipt_line_id' => $receiptLineId, 'receipt_evidence' => $evidence === null ? null : $evidence['description'], 'selected_json' => json_encode($selected, JSON_THROW_ON_ERROR)];
		});
	}

	public function RetryJob(int $tripId, int $lineId, string $actor): array
	{
		return $this->MutateDraft($tripId, $lineId, $actor, 'retry', function (array $draft): array
		{
			$job = $this->Db->prepare('SELECT state, retry_generation FROM grocy_ai_capture_research_jobs WHERE id = ?');
			$job->execute([$draft['job_id']]);
			$job = $job->fetch(PDO::FETCH_ASSOC);
			if (!in_array($job['state'], ['needs_input', 'retryable_failure'], true)) throw new \InvalidArgumentException('Research is not retryable');
			$this->Db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'queued', retry_generation = MIN(1, retry_generation + 1), attempts = 0, next_retry_at = NULL, lease_hash = NULL, lease_expires_at = NULL, safe_error_code = NULL, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$draft['job_id']]);
			return [];
		});
	}

	private function MutateDraft(int $tripId, int $lineId, string $actor, string $action, callable $change): array
	{
		if ($actor === '' || strlen($actor) > 128) throw new \InvalidArgumentException('Invalid actor');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$this->RequireTrip($tripId, true);
			$draft = $this->DraftRow($tripId, $lineId);
			if (in_array($draft['outcome'], ['approved', 'linked'], true)) throw new \InvalidArgumentException('Finalized research draft');
			$updates = $change($draft);
			$sets = [];
			$params = [];
			foreach ($updates as $key => $value)
			{
				if (!in_array($key, ['selected_json', 'user_edits_json', 'receipt_line_id', 'receipt_evidence'], true)) throw new \LogicException('Invalid draft update');
				$sets[] = $key . ' = ?';
				$params[] = $value;
			}
			$sets[] = 'revision = revision + 1';
			$sets[] = 'updated_at = CURRENT_TIMESTAMP';
			$params[] = $draft['id'];
			$this->Db->prepare('UPDATE grocy_ai_capture_research_drafts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
			$after = $this->DraftRow($tripId, $lineId);
			$this->Db->prepare('INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tripId, $draft['id'], $actor, $action, json_encode($this->AuditState($draft), JSON_THROW_ON_ERROR), json_encode($this->AuditState($after), JSON_THROW_ON_ERROR)]);
			$this->Db->commit();
			return $this->ReviewDraft($tripId, $lineId);
		}
		catch (\Throwable $ex)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	private function RequireTrip(int $tripId, bool $mutable): void
	{
		$query = $this->Db->prepare('SELECT t.status, EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id) AS canceled FROM grocy_ai_capture_trips t WHERE t.id = ?');
		$query->execute([$tripId]);
		$trip = $query->fetch(PDO::FETCH_ASSOC);
		if ($trip === false) throw new \InvalidArgumentException('Unknown trip');
		if ($mutable && ($trip['status'] === 'committed' || (int)$trip['canceled'] === 1)) throw new \InvalidArgumentException('Trip is closed');
	}

	private function DraftRow(int $tripId, int $lineId): array
	{
		$query = $this->Db->prepare('SELECT d.*, l.seq, l.status AS line_status, l.resolved_product_id, p.name AS resolved_product_name FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id LEFT JOIN products p ON p.id = l.resolved_product_id WHERE d.trip_id = ? AND d.line_id = ?');
		$query->execute([$tripId, $lineId]);
		return $query->fetch(PDO::FETCH_ASSOC) ?: throw new \InvalidArgumentException('Unknown research draft');
	}

	private function AuditState(array $draft): array
	{
		return ['revision' => (int)$draft['revision'], 'selected' => json_decode($draft['selected_json'], true), 'user_edits' => json_decode($draft['user_edits_json'], true), 'receipt_line_id' => $draft['receipt_line_id'] === null ? null : (int)$draft['receipt_line_id']];
	}

	public function ReviewDraft(int $tripId, int $lineId): array
	{
		$draft = $this->DraftRow($tripId, $lineId);
		$job = $this->Db->prepare('SELECT canonical_gtin, state, safe_error_code FROM grocy_ai_capture_research_jobs WHERE id = ?');
		$job->execute([$draft['job_id']]);
		$job = $job->fetch(PDO::FETCH_ASSOC);
		$suggested = json_decode($draft['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
		$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
		$names = [];
		foreach ($suggested['name_candidates'] ?? [] as $index => $name)
		{
			$sources = $suggested['name_candidate_sources'][$index] ?? [];
			if (!is_array($sources)) $sources = [];
			$candidate = ['value' => $name, 'sources' => $sources, 'provenance' => $sources === [] ? 'unknown' : 'attributed'];
			foreach ($suggested['web_evidence'] ?? [] as $webEvidence)
			{
				if ($webEvidence['candidate_index'] === $index) $candidate['web_evidence'] = ['exact_gtin_claim' => $webEvidence['exact_gtin_claim'], 'citations' => $webEvidence['citations']];
			}
			$names[] = $candidate;
		}
		$evidence = null;
		if ($draft['receipt_line_id'] !== null)
		{
			try { $evidence = (new GrocyAiReceiptService($this->Db))->ResearchEvidence($tripId, (int)$draft['receipt_line_id']); }
			catch (\InvalidArgumentException) { $evidence = null; }
		}
		if ($evidence !== null) $names[] = ['value' => $evidence['description'], 'sources' => ['receipt_ocr'], 'provenance' => 'attributed'];
		$classificationQuery = $this->Db->prepare('SELECT state, result_revision, result_json FROM grocy_ai_capture_classification_jobs WHERE draft_id = ? AND result_revision = ?');
		$classificationQuery->execute([$draft['id'], $draft['result_revision']]);
		$classification = $classificationQuery->fetch(PDO::FETCH_ASSOC);
		if ($classification !== false) $classification = ['state' => $classification['state'], 'result_revision' => (int)$classification['result_revision'], 'source' => 'openai-classification', 'result' => $classification['result_json'] === null ? null : json_decode($classification['result_json'], true, 512, JSON_THROW_ON_ERROR)];
		elseif ($this->ClassificationInput((int)$draft['id']) !== null) $classification = ['state' => 'pending', 'result_revision' => (int)$draft['result_revision'], 'source' => null, 'result' => null];
		$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
		$unitCandidates = $this->LocalUnitCandidates($selected, $suggested, $edits, $evidence);
		foreach (['qu_id_purchase', 'qu_id_stock'] as $field)
		{
			if (!array_key_exists($field, $selected)) $selected[$field] = !array_key_exists($field, $edits) && count($unitCandidates) === 1 ? $unitCandidates[0]['id'] : null;
		}
		// A saved choice can make the proposed opposite unit incompatible. Leave it blank.
		if ($selected['qu_id_purchase'] !== null && $selected['qu_id_stock'] !== null && !$this->CompatibleUnits((int)$selected['qu_id_purchase'], (int)$selected['qu_id_stock']))
		{
			foreach (['qu_id_purchase', 'qu_id_stock'] as $field) if (!array_key_exists($field, $edits) && !array_key_exists($field, json_decode($draft['selected_json'], true))) $selected[$field] = null;
		}
		$candidates = ['product_group_id' => $this->GroupCandidates($suggested), 'taxonomy_leaf_slug' => $this->TaxonomyCandidates($suggested), 'parent_product_id' => $this->ParentCandidates($draft, $selected, $suggested)];
		$catalog = ($classification['state'] ?? null) === 'suggested' ? $this->ReviewOptions() : ['product_groups' => [], 'taxonomy_leaves' => [], 'generic_parents' => []];
		foreach (['product_group_id' => ['product_groups', 'id'], 'taxonomy_leaf_slug' => ['taxonomy_leaves', 'slug'], 'parent_product_id' => ['generic_parents', 'id']] as $field => [$choices, $key])
		{
			$value = $classification['result'][$field] ?? null;
			if ($candidates[$field] === [] && ($classification['state'] ?? null) === 'suggested' && $value !== null)
			{
				foreach ($catalog[$choices] as $choice) if ($choice[$key] === $value) $candidates[$field][] = $choice + ['source' => 'openai-classification'];
			}
			if (!array_key_exists($field, $edits) && !array_key_exists($field, $selected) && count($candidates[$field]) === 1) $selected[$field] = $candidates[$field][0][$key];
		}
		return ['id' => (int)$draft['id'], 'line_id' => $lineId, 'seq' => (int)$draft['seq'], 'revision' => (int)$draft['revision'], 'outcome' => $draft['outcome'], 'line_status' => $draft['line_status'], 'resolved_product_id' => $draft['resolved_product_id'] === null ? null : (int)$draft['resolved_product_id'], 'resolved_product_name' => $draft['resolved_product_name'], 'job_state' => $job['state'], 'safe_error_code' => $job['safe_error_code'], 'scanned_barcode' => $draft['scanned_barcode'], 'canonical_gtin' => $job['canonical_gtin'], 'selected' => $selected, 'user_edits' => $edits, 'suggested' => $suggested, 'classification' => $classification ?: null, 'name_alternatives' => $names, 'receipt_evidence' => $evidence, 'purchase_unit_candidates' => $unitCandidates, 'stock_unit_candidates' => $unitCandidates, 'group_candidates' => $candidates['product_group_id'], 'taxonomy_candidates' => $candidates['taxonomy_leaf_slug'], 'parent_candidates' => $candidates['parent_product_id'], 'possible_existing_products' => $this->PossibleProducts($selected, $suggested), 'final_product_id' => $draft['final_product_id'] === null ? null : (int)$draft['final_product_id']];
	}

	private function ActiveUnits(?int $limit = null): array
	{
		$columns = $this->Db->query("PRAGMA table_info(quantity_units)")->fetchAll(PDO::FETCH_COLUMN, 1);
		if (!in_array('name', $columns, true)) return [];
		$plural = in_array('name_plural', $columns, true) ? 'name_plural' : 'NULL AS name_plural';
		$rows = $this->Db->query('SELECT id, name, ' . $plural . ' FROM quantity_units WHERE active = 1 ORDER BY name, id' . ($limit === null ? '' : ' LIMIT ' . $limit))->fetchAll(PDO::FETCH_ASSOC);
		return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name'], 'name_plural' => $row['name_plural']], $rows);
	}

	private function UnitChoices(): array
	{
		$units = $this->ActiveUnits(201);
		$exists = $this->Db->query("SELECT 1 FROM sqlite_master WHERE name = 'quantity_unit_conversions'")->fetchColumn();
		$conversions = $exists === false ? [] : $this->Db->query('SELECT c.from_qu_id, c.to_qu_id, c.factor FROM quantity_unit_conversions c JOIN quantity_units f ON f.id = c.from_qu_id AND f.active = 1 JOIN quantity_units t ON t.id = c.to_qu_id AND t.active = 1 WHERE c.product_id IS NULL AND c.factor > 0 ORDER BY c.from_qu_id, c.to_qu_id LIMIT 501')->fetchAll(PDO::FETCH_ASSOC);
		if (count($units) > 200 || count($conversions) > 500) return [];
		return ['quantity_units' => $units, 'global_unit_conversions' => array_map(static fn(array $row): array => ['from_qu_id' => (int)$row['from_qu_id'], 'to_qu_id' => (int)$row['to_qu_id'], 'factor' => (float)$row['factor']], $conversions)];
	}

	private function CompatibleUnits(int $purchase, int $stock): bool
	{
		if ($purchase === $stock) return true;
		if ($this->Db->query("SELECT 1 FROM sqlite_master WHERE name = 'quantity_unit_conversions'")->fetchColumn() === false) return false;
		$query = $this->Db->prepare('SELECT 1 FROM quantity_unit_conversions WHERE product_id IS NULL AND from_qu_id = ? AND to_qu_id = ? AND factor > 0 LIMIT 1');
		$query->execute([$purchase, $stock]);
		return $query->fetchColumn() !== false;
	}

	private function LocalUnitCandidates(array $selected, array $suggested, array $edits, ?array $evidence): array
	{
		$package = !empty($edits['package']) ? ($selected['package'] ?? null) : ($selected['package'] ?? $suggested['package'] ?? null);
		$texts = ['local_package' => $package, 'receipt_ocr' => $evidence['description'] ?? null];
		$aliases = ['kilogram' => ['kg'], 'gram' => ['g'], 'milliliter' => ['ml'], 'millilitre' => ['ml'], 'liter' => ['l'], 'litre' => ['l'], 'ounce' => ['oz'], 'pound' => ['lb', 'lbs']];
		$matches = [];
		foreach ($texts as $source => $text)
		{
			if (!is_string($text) || preg_match('/\d\s*(?:x|×)\s*\d/iu', $text)) continue;
			foreach ($this->ActiveUnits() as $unit)
			{
				$tokens = array_filter([$unit['name'], $unit['name_plural']]);
				$tokens = array_merge($tokens, $aliases[mb_strtolower(trim($unit['name']))] ?? []);
				foreach ($tokens as $token)
				{
					if (trim($token) === '') continue;
					if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote(trim($token), '/') . '(?![\p{L}\p{N}])/iu', $text)) $matches[$unit['id']] ??= ['id' => $unit['id'], 'name' => $unit['name'], 'source' => $source];
				}
			}
		}
		return count($matches) === 1 ? array_values($matches) : [];
	}

	private function GroupCandidates(array $suggested): array
	{
		if (!in_array('openfoodfacts', $suggested['sources'] ?? [], true) || empty($suggested['categories'])) return [];
		if ($this->TaxonomyCandidates($suggested) === []) return [];
		$groups = $this->Db->query('SELECT id, name FROM product_groups WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$matches = [];
		foreach ($suggested['categories'] as $category)
		{
			$key = self::CategoryKey($category);
			foreach ($groups as $group) if (self::CategoryKey($group['name']) === $key) $matches[(int)$group['id']] = ['id' => (int)$group['id'], 'name' => $group['name'], 'source' => 'openfoodfacts', 'provider_category' => $category];
		}
		return count($matches) === 1 ? array_values($matches) : [];
	}

	private function TaxonomyCandidates(array $suggested): array
	{
		if (!in_array('openfoodfacts', $suggested['sources'] ?? [], true) || empty($suggested['categories'])) return [];
		GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		$query = $this->Db->prepare("SELECT n.slug, n.label, r.provider_category FROM grocy_ai_taxonomy_mapping_rules r JOIN grocy_ai_taxonomy_nodes n ON n.slug = r.target_slug AND n.version = r.version AND n.depth = 2 WHERE r.provider_category = ? AND r.version = ? AND r.disposition = 'mapped'");
		$matches = [];
		foreach ($suggested['categories'] as $category)
		{
			$query->execute([self::CategoryKey($category), GrocyAiTaxonomyMigration::VERSION]);
			$row = $query->fetch(PDO::FETCH_ASSOC);
			if ($row === false) return [];
			$matches[$row['slug']] = ['slug' => $row['slug'], 'label' => $row['label'], 'source' => 'openfoodfacts', 'provider_category' => $category, 'ruleset_version' => GrocyAiTaxonomyMigration::VERSION];
		}
		return count($matches) === 1 ? array_values($matches) : [];
	}

	private static function CategoryKey(string $category): string
	{
		return strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', preg_replace('/^en:/i', '', trim($category))));
	}

	private function PossibleProducts(array $selected, array $suggested): array
	{
		$names = array_unique(array_map(static fn(string $name): string => mb_strtolower(trim($name)), array_filter(array_merge([$selected['name'] ?? null], $suggested['name_candidates'] ?? []), 'is_string')));
		if ($names === []) return [];
		$query = $this->Db->prepare('SELECT id, name FROM products WHERE active = 1 AND lower(trim(name)) IN (' . implode(', ', array_fill(0, count($names), '?')) . ') ORDER BY id LIMIT 20');
		$query->execute(array_values($names));
		$matches = [];
		foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $product)
		{
			$matches[] = ['id' => (int)$product['id'], 'name' => $product['name'], 'reason' => 'exact_name'];
		}
		return $matches;
	}

	public function ReserveWebSearch(int $jobId, string $leaseToken, string $actor): array
	{
		if ($actor === '' || strlen($actor) > 128) throw new \InvalidArgumentException('Invalid actor');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$job = $this->JobForLease($jobId, $leaseToken);
			$this->RequireLiveLease($job);
			$decision = ['allowed' => false, 'reason' => 'ineligible', 'reservation_id' => null];
			try { $this->LookupBarcodeForJob($jobId, $job['canonical_gtin']); }
			catch (\PDOException $ex)
			{
				throw $ex;
			}
			catch (\RuntimeException)
			{
				$this->Db->commit();
				return $decision;
			}
			$existing = $this->Db->prepare('SELECT id FROM grocy_ai_capture_web_search_reservations WHERE canonical_gtin = ? AND retry_generation = ?');
			$existing->execute([$job['canonical_gtin'], $job['retry_generation']]);
			$id = $existing->fetchColumn();
			if ($id !== false)
			{
				$decision['reason'] = 'already_reserved';
				$decision['reservation_id'] = (int)$id;
			}
			else
			{
				$limit = defined('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') ? (int)GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT : 20;
				$count = (int)$this->Db->query("SELECT COUNT(*) FROM grocy_ai_capture_web_search_reservations WHERE utc_day = date('now')")->fetchColumn();
				$decision['reason'] = 'daily_limit';
				if ($count < $limit)
				{
					// A granted slot remains spent even if the worker loses the response or fails.
					$this->Db->prepare("INSERT INTO grocy_ai_capture_web_search_reservations (job_id, canonical_gtin, utc_day, actor, retry_generation) VALUES (?, ?, date('now'), ?, ?)")->execute([$jobId, $job['canonical_gtin'], $actor, $job['retry_generation']]);
					$decision = ['allowed' => true, 'reason' => 'reserved', 'reservation_id' => (int)$this->Db->lastInsertId()];
				}
			}
			$this->Db->commit();
			return $decision;
		}
		catch (\Throwable $ex)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	/** @return array<int, array<string, mixed>> */
	public function ClaimJobs(int $limit, string $workerId): array
	{
		if ($limit < 1 || $limit > 5 || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $workerId) !== 1) throw new \InvalidArgumentException('Invalid research claim');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$expired = $this->Db->query("SELECT id FROM grocy_ai_capture_research_jobs WHERE state = 'leased' AND attempts >= 5 AND lease_expires_at <= CURRENT_TIMESTAMP")->fetchAll(PDO::FETCH_COLUMN);
			foreach ($expired as $expiredId)
			{
				$this->Db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'needs_input', safe_error_code = 'worker_unavailable', lease_expires_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$expiredId]);
				$drafts = $this->Db->prepare("SELECT d.id, d.trip_id, d.outcome FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? AND d.outcome NOT IN ('approved', 'linked') AND t.status != 'committed' AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)");
				$drafts->execute([$expiredId]);
				foreach ($drafts->fetchAll(PDO::FETCH_ASSOC) as $draft)
				{
					$this->Db->prepare("UPDATE grocy_ai_capture_research_drafts SET outcome = 'needs_input', revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$draft['id']]);
					$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, 'research-worker', 'research_failure', ?, ?)")->execute([$draft['trip_id'], $draft['id'], json_encode(['outcome' => $draft['outcome']], JSON_THROW_ON_ERROR), json_encode(['outcome' => 'needs_input', 'safe_error_code' => 'worker_unavailable', 'attempts' => 5], JSON_THROW_ON_ERROR)]);
				}
			}
			$select = $this->Db->prepare("SELECT j.id, j.canonical_gtin, j.attempts, j.revision FROM grocy_ai_capture_research_jobs j WHERE j.attempts < ? AND ((j.state = 'queued' AND (j.next_retry_at IS NULL OR j.next_retry_at <= CURRENT_TIMESTAMP)) OR (j.state = 'retryable_failure' AND j.next_retry_at <= CURRENT_TIMESTAMP) OR (j.state = 'leased' AND j.lease_expires_at <= CURRENT_TIMESTAMP)) AND EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = j.id AND d.outcome NOT IN ('approved', 'linked') AND l.status = 'unknown' AND l.selected = 1 AND l.applied_at IS NULL AND l.canonical_gtin = j.canonical_gtin AND l.scanned_barcode = d.scanned_barcode AND t.status IN ('open', 'reviewing') AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)) ORDER BY j.id LIMIT ?");
			$select->bindValue(1, self::MAX_ATTEMPTS, PDO::PARAM_INT);
			$select->bindValue(2, $limit, PDO::PARAM_INT);
			$select->execute();
			$jobs = [];
			foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $job)
			{
				$lookupBarcode = $this->LookupBarcodeForJob((int)$job['id'], $job['canonical_gtin']);
				$token = bin2hex(random_bytes(32));
				$this->Db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'leased', attempts = attempts + 1, lease_hash = ?, lease_expires_at = datetime('now', '+60 seconds'), next_retry_at = NULL, safe_error_code = NULL, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
					->execute([hash('sha256', $token), $job['id']]);
				$jobs[] = ['id' => (int)$job['id'], 'canonical_gtin' => $job['canonical_gtin'], 'lookup_barcode' => $lookupBarcode, 'attempts' => (int)$job['attempts'] + 1, 'revision' => (int)$job['revision'] + 1, 'lease_token' => $token, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 60)];
			}
			$this->Db->commit();
			return $jobs;
		}
		catch (\Throwable $ex)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	private function LookupBarcodeForJob(int $jobId, string $canonical): string
	{
		$query = $this->Db->prepare("SELECT l.scanned_barcode FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? AND d.outcome NOT IN ('approved', 'linked') AND l.status = 'unknown' AND l.selected = 1 AND l.applied_at IS NULL AND l.canonical_gtin = ? AND l.scanned_barcode = d.scanned_barcode AND t.status IN ('open', 'reviewing') AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id) ORDER BY l.id LIMIT 32");
		$query->execute([$jobId, $canonical]);
		foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $barcode)
		{
			if (is_string($barcode) && GrocyAiGtin::CanonicalOrNull($barcode) === $canonical) return $barcode;
		}
		throw new \RuntimeException('Research lookup barcode unavailable');
	}

	/** @param array<string, mixed> $result
	 *  @return array<string, mixed>
	 */
	public function CompleteJob(int $jobId, string $leaseToken, array $result): array
	{
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$job = $this->JobForLease($jobId, $leaseToken);
			$normalized = self::NormalizeResult($result, $job['canonical_gtin']);
			if ($normalized['outcome'] === 'retryable_failure' && in_array($job['state'], ['retryable_failure', 'needs_input'], true) && $job['safe_error_code'] === $normalized['error_code'])
			{
				$this->Db->commit();
				return ['id' => $jobId, 'state' => $job['state'], 'attempts' => (int)$job['attempts'], 'safe_error_code' => $job['safe_error_code']];
			}
			if ($job['state'] === 'ready' || $job['state'] === 'needs_input')
			{
				$stored = $this->Db->prepare('SELECT suggested_json FROM grocy_ai_capture_research_drafts WHERE job_id = ? LIMIT 1');
				$stored->execute([$jobId]);
				if ($stored->fetchColumn() !== json_encode($normalized, JSON_THROW_ON_ERROR)) throw new \RuntimeException('Conflicting research completion');
				$this->Db->commit();
				return self::JobSummary($job);
			}
			$this->RequireLiveLease($job);
			if ($normalized['outcome'] === 'retryable_failure')
			{
				$this->Db->commit();
				return $this->FailJob($jobId, $leaseToken, $normalized['error_code']);
			}
			$state = $normalized['outcome'] === 'found' ? 'ready' : 'needs_input';
			$this->Db->prepare('UPDATE grocy_ai_capture_research_jobs SET state = ?, result_revision = result_revision + 1, lease_expires_at = NULL, safe_error_code = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$state, $jobId]);
			$drafts = $this->Db->prepare("SELECT d.* FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? AND d.outcome NOT IN ('approved', 'linked') AND t.status != 'committed' AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)");
			$drafts->execute([$jobId]);
			$suggested = json_encode($normalized, JSON_THROW_ON_ERROR);
			foreach ($drafts->fetchAll(PDO::FETCH_ASSOC) as $draft)
			{
				$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
				$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($selected) || !is_array($edits)) throw new \RuntimeException('Invalid research draft');
				if (empty($edits['name']))
				{
					if ($normalized['outcome'] === 'found') $selected['name'] = $normalized['name_candidates'][0];
					elseif ($draft['receipt_line_id'] !== null)
					{
						try { $selected['name'] = (new GrocyAiReceiptService($this->Db))->ResearchEvidence((int)$draft['trip_id'], (int)$draft['receipt_line_id'])['description']; }
						catch (\InvalidArgumentException) { unset($selected['name']); }
					}
					else unset($selected['name']);
				}
				$selectedJson = json_encode($selected, JSON_THROW_ON_ERROR);
				$draftOutcome = $normalized['outcome'] === 'found' ? 'ready' : 'needs_input';
				$this->Db->prepare('UPDATE grocy_ai_capture_research_drafts SET suggested_json = ?, selected_json = ?, outcome = ?, revision = revision + 1, result_revision = result_revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$suggested, $selectedJson, $draftOutcome, $draft['id']]);
				$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, 'research-worker', 'research_result', ?, ?)")->execute([$draft['trip_id'], $draft['id'], json_encode(['outcome' => $draft['outcome'], 'suggested' => json_decode($draft['suggested_json'], true)], JSON_THROW_ON_ERROR), json_encode(['outcome' => $draftOutcome, 'suggested' => $normalized], JSON_THROW_ON_ERROR)]);
			}
			$this->Db->commit();
			return ['id' => $jobId, 'state' => $state, 'result_revision' => (int)$job['result_revision'] + 1];
		}
		catch (\Throwable $ex)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	/** @return array<string, mixed> */
	public function FailJob(int $jobId, string $leaseToken, string $safeCode): array
	{
		if (!in_array($safeCode, ['provider_unavailable', 'worker_unavailable', 'web_quota_exhausted'], true)) throw new \InvalidArgumentException('Invalid research failure code');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$job = $this->JobForLease($jobId, $leaseToken);
			if (in_array($job['state'], ['retryable_failure', 'needs_input'], true) && $job['safe_error_code'] === $safeCode)
			{
				$this->Db->commit();
				return ['id' => $jobId, 'state' => $job['state'], 'attempts' => (int)$job['attempts'], 'safe_error_code' => $safeCode];
			}
			$this->RequireLiveLease($job);
			$terminal = $safeCode === 'web_quota_exhausted' || (int)$job['attempts'] >= self::MAX_ATTEMPTS;
			$state = $terminal ? 'needs_input' : 'retryable_failure';
			$delay = min(300, 5 * (2 ** ((int)$job['attempts'] - 1)));
			$this->Db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = ?, safe_error_code = ?, next_retry_at = CASE WHEN ? THEN NULL ELSE datetime('now', '+' || ? || ' seconds') END, lease_expires_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
				->execute([$state, $safeCode, $terminal ? 1 : 0, $delay, $jobId]);
			$drafts = $this->Db->prepare("SELECT d.id, d.trip_id, d.outcome FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? AND d.outcome NOT IN ('approved', 'linked') AND t.status != 'committed' AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)");
			$drafts->execute([$jobId]);
			foreach ($drafts->fetchAll(PDO::FETCH_ASSOC) as $draft)
			{
				if ($terminal) $this->Db->prepare("UPDATE grocy_ai_capture_research_drafts SET outcome = 'needs_input', revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$draft['id']]);
				$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, 'research-worker', 'research_failure', ?, ?)")->execute([$draft['trip_id'], $draft['id'], json_encode(['outcome' => $draft['outcome']], JSON_THROW_ON_ERROR), json_encode(['outcome' => $terminal ? 'needs_input' : $draft['outcome'], 'safe_error_code' => $safeCode, 'attempts' => (int)$job['attempts']], JSON_THROW_ON_ERROR)]);
			}
			$this->Db->commit();
			return ['id' => $jobId, 'state' => $state, 'attempts' => (int)$job['attempts'], 'safe_error_code' => $safeCode];
		}
		catch (\Throwable $ex)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $ex;
		}
	}

	/** Claim an immutable classification input, independently of UPC research leases. */
	public function ClaimClassifications(int $limit, string $workerId): array
	{
		if ($limit < 1 || $limit > 5 || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $workerId) !== 1) throw new \InvalidArgumentException('Invalid classification claim');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			// A lost paid call is terminal. Releasing its lease must never authorize another call.
			$this->Db->exec("UPDATE grocy_ai_capture_classification_jobs SET state = 'unavailable' WHERE state = 'leased' AND lease_expires_at <= CURRENT_TIMESTAMP AND EXISTS (SELECT 1 FROM grocy_ai_capture_classification_reservations r WHERE r.draft_id = grocy_ai_capture_classification_jobs.draft_id AND r.result_revision = grocy_ai_capture_classification_jobs.result_revision)");
			$rows = $this->Db->query("SELECT d.id FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id JOIN grocy_ai_capture_research_jobs j ON j.id = d.job_id LEFT JOIN grocy_ai_capture_classification_jobs c ON c.draft_id = d.id AND c.result_revision = d.result_revision WHERE d.outcome = 'ready' AND d.result_revision > 0 AND l.status = 'unknown' AND l.selected = 1 AND l.resolved_product_id IS NULL AND l.applied_at IS NULL AND l.scanned_barcode = d.scanned_barcode AND l.canonical_gtin = j.canonical_gtin AND t.status != 'committed' AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations x WHERE x.trip_id = t.id) AND NOT EXISTS (SELECT 1 FROM product_barcodes b WHERE " . GrocyAiGtin::CanonicalSqlExpression('b.barcode') . " = j.canonical_gtin) AND (c.id IS NULL OR (c.state = 'leased' AND c.lease_expires_at <= CURRENT_TIMESTAMP)) ORDER BY d.id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
			$jobs = [];
			foreach ($rows as $draftId)
			{
				$input = $this->ClassificationInput((int)$draftId);
				if ($input === null) continue;
				$mapped = count($input['deterministic_candidates']['taxonomy_leaf_slug']) === 1;
				$state = $mapped ? 'not_needed' : 'leased';
				$token = bin2hex(random_bytes(32));
				$this->Db->prepare("INSERT INTO grocy_ai_capture_classification_jobs (draft_id, result_revision, input_json, state, lease_hash, lease_expires_at) VALUES (?, ?, ?, ?, ?, datetime('now', '+60 seconds')) ON CONFLICT(draft_id, result_revision) DO UPDATE SET lease_hash = excluded.lease_hash, lease_expires_at = excluded.lease_expires_at WHERE state = 'leased'")->execute([$draftId, $input['result_revision'], json_encode($input, JSON_THROW_ON_ERROR), $state, hash('sha256', $token)]);
				if ($mapped) continue;
				$query = $this->Db->prepare('SELECT * FROM grocy_ai_capture_classification_jobs WHERE draft_id = ? AND result_revision = ?');
				$query->execute([$draftId, $input['result_revision']]);
				$row = $query->fetch(PDO::FETCH_ASSOC);
				$jobs[] = ['id' => (int)$row['id'], 'input' => json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR), 'lease_token' => $token, 'lease_expires_at' => $row['lease_expires_at']];
				if (count($jobs) === $limit) break;
			}
			$this->Db->commit();
			return $jobs;
		}
		catch (\Throwable $ex) { if ($this->Db->inTransaction()) $this->Db->rollBack(); throw $ex; }
	}

	private function ClassificationLease(int $id, string $token): array
	{
		if ($id < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) throw new \InvalidArgumentException('Invalid classification lease');
		$query = $this->Db->prepare('SELECT * FROM grocy_ai_capture_classification_jobs WHERE id = ?');
		$query->execute([$id]);
		$row = $query->fetch(PDO::FETCH_ASSOC);
		if ($row === false || !hash_equals($row['lease_hash'], hash('sha256', $token))) throw new \RuntimeException('Classification lease conflict');
		return $row;
	}

	public function ReserveClassification(int $id, string $token, string $actor): array
	{
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$row = $this->ClassificationLease($id, $token);
			$this->RequireLiveLease($row);
			$input = $this->ClassificationInput((int)$row['draft_id']);
			$decision = ['allowed' => false, 'reason' => 'stale'];
			if ($input !== null && $input['result_revision'] === (int)$row['result_revision'])
			{
				$existing = $this->Db->prepare('SELECT 1 FROM grocy_ai_capture_classification_reservations WHERE draft_id = ? AND result_revision = ?');
				$existing->execute([$row['draft_id'], $row['result_revision']]);
				$decision['reason'] = 'already_reserved';
				if ($existing->fetchColumn() === false)
				{
					$limit = defined('GROCY_AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT') ? (int)GROCY_AI_CAPTURE_CLASSIFICATION_DAILY_LIMIT : 20;
					$count = (int)$this->Db->query("SELECT COUNT(*) FROM grocy_ai_capture_classification_reservations WHERE utc_day = date('now')")->fetchColumn();
					$decision['reason'] = 'daily_limit';
					if ($count < $limit)
					{
						$this->Db->prepare("INSERT INTO grocy_ai_capture_classification_reservations (draft_id, result_revision, utc_day, actor) VALUES (?, ?, date('now'), ?)")->execute([$row['draft_id'], $row['result_revision'], $actor]);
						$decision = ['allowed' => true, 'reason' => 'reserved'];
					}
					else $this->Db->prepare("UPDATE grocy_ai_capture_classification_jobs SET state = 'daily_limit' WHERE id = ?")->execute([$id]);
				}
			}
			$this->Db->commit();
			return $decision;
		}
		catch (\Throwable $ex) { if ($this->Db->inTransaction()) $this->Db->rollBack(); throw $ex; }
	}

	public function FailClassification(int $id, string $token, string $safeCode): array
	{
		if (!in_array($safeCode, ['unavailable', 'quota_exhausted', 'refused', 'malformed', 'timeout'], true)) throw new \InvalidArgumentException('Invalid classification status');
		return $this->CompleteClassification($id, $token, ['status' => $safeCode, 'product_group_id' => null, 'taxonomy_leaf_slug' => null, 'parent_product_id' => null]);
	}

	public function CompleteClassification(int $id, string $token, array $result): array
	{
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$row = $this->ClassificationLease($id, $token);
			$summary = static fn(array $r): array => ['id' => (int)$r['id'], 'state' => $r['state'], 'result_revision' => (int)$r['result_revision'], 'source' => 'openai-classification', 'result' => $r['result_json'] === null ? null : json_decode($r['result_json'], true, 512, JSON_THROW_ON_ERROR)];
			if ($row['state'] !== 'leased') { $this->Db->commit(); return $summary($row); }
			$live = $this->ClassificationInput((int)$row['draft_id']);
			$state = 'ignored';
			$normalized = null;
			if ($live !== null && $live['result_revision'] === (int)$row['result_revision'] && $row['lease_expires_at'] > gmdate('Y-m-d H:i:s'))
			{
				$fields = ['product_group_id', 'taxonomy_leaf_slug', 'parent_product_id'];
				$status = $result['status'] ?? null;
				if (!in_array($status, ['suggested', 'inconclusive', 'unavailable', 'quota_exhausted', 'refused', 'malformed', 'timeout'], true) || count($result) !== 4 || array_diff($fields, array_keys($result))) $status = 'malformed';
				$reserved = $this->Db->prepare('SELECT 1 FROM grocy_ai_capture_classification_reservations WHERE draft_id = ? AND result_revision = ?');
				$reserved->execute([$row['draft_id'], $row['result_revision']]);
				if ($status === 'suggested' && $reserved->fetchColumn() === false) throw new \RuntimeException('Classification reservation required');
				$normalized = ['status' => $status, 'product_group_id' => null, 'taxonomy_leaf_slug' => null, 'parent_product_id' => null];
				$snapshot = json_decode($row['input_json'], true, 512, JSON_THROW_ON_ERROR);
				foreach (['product_group_id' => ['product_groups', 'id'], 'taxonomy_leaf_slug' => ['taxonomy_leaves', 'slug'], 'parent_product_id' => ['generic_parents', 'id']] as $field => [$choices, $key])
				{
					$value = $result[$field] ?? null;
					if ($status === 'suggested' && $value !== null && in_array($value, array_column($snapshot['choices'][$choices], $key), true) && in_array($value, array_column($live['choices'][$choices], $key), true)) $normalized[$field] = $value;
				}
				$state = $status;
			}
			$this->Db->prepare('UPDATE grocy_ai_capture_classification_jobs SET state = ?, result_json = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$state, $normalized === null ? null : json_encode($normalized, JSON_THROW_ON_ERROR), $id]);
			$row['state'] = $state; $row['result_json'] = $normalized === null ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
			$this->Db->commit();
			return $summary($row);
		}
		catch (\Throwable $ex) { if ($this->Db->inTransaction()) $this->Db->rollBack(); throw $ex; }
	}

	private function JobForLease(int $jobId, string $leaseToken): array
	{
		if ($jobId < 1 || preg_match('/^[a-f0-9]{64}$/D', $leaseToken) !== 1) throw new \InvalidArgumentException('Invalid research lease');
		$query = $this->Db->prepare('SELECT * FROM grocy_ai_capture_research_jobs WHERE id = ?');
		$query->execute([$jobId]);
		$job = $query->fetch(PDO::FETCH_ASSOC);
		if ($job === false || !is_string($job['lease_hash']) || !hash_equals($job['lease_hash'], hash('sha256', $leaseToken))) throw new \RuntimeException('Research lease conflict');
		return $job;
	}

	private function RequireLiveLease(array $job): void
	{
		if ($job['state'] !== 'leased' || $job['lease_expires_at'] === null || $job['lease_expires_at'] <= gmdate('Y-m-d H:i:s')) throw new \RuntimeException('Research lease expired');
	}

	private static function JobSummary(array $job): array
	{
		return ['id' => (int)$job['id'], 'state' => $job['state'], 'result_revision' => (int)$job['result_revision']];
	}

	private static function CitationDomain(mixed $url): string
	{
		// Links only: never fetch citation pages. The companion also validates resolved addresses.
		if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\s\\\\]|\p{C}|%(?![0-9a-f]{2})|%(?:0[0-9a-f]|1[0-9a-f]|7f)/iu', $url)) throw new \InvalidArgumentException('Invalid citation URL');
		$parts = parse_url($url);
		$host = strtolower($parts['host'] ?? '');
		if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && $parts['port'] !== 443 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) || strlen($host) > 253 || preg_match('/\.(?:local|localhost|internal|lan|test|invalid|example|arpa|onion|alt|home|corp|mail)$/D', $host)) throw new \InvalidArgumentException('Invalid citation host');
		return $host;
	}

	private static function NormalizeResult(array $result, string $canonical): array
	{
		$required = ['contract_version', 'canonical_gtin', 'outcome', 'name_candidates', 'brand', 'package', 'categories', 'sources'];
		$v2 = ($result['contract_version'] ?? null) === 2;
		if ($v2) $required[] = 'web_evidence';
		$allowed = array_merge($required, ['error_code', 'name_candidate_sources']);
		$allowedSources = $v2 ? ['bb-federation', 'openfoodfacts', 'openai-web'] : ['bb-federation', 'openfoodfacts'];
		if (array_diff($required, array_keys($result)) || array_diff(array_keys($result), $allowed) || !in_array($result['contract_version'], [1, 2], true) || $result['canonical_gtin'] !== $canonical || !in_array($result['outcome'], ['found', 'miss', 'retryable_failure'], true)) throw new \InvalidArgumentException('Invalid research result');
		foreach (['name_candidates' => [8, 200], 'categories' => [3, 100], 'sources' => [2, 32]] as $field => [$count, $length])
		{
			if (!is_array($result[$field]) || !array_is_list($result[$field]) || count($result[$field]) > $count) throw new \InvalidArgumentException('Invalid research result');
			foreach ($result[$field] as $value) if (!is_string($value) || trim($value) !== $value || $value === '' || mb_strlen($value) > $length || preg_match('/\p{C}/u', $value)) throw new \InvalidArgumentException('Invalid research result');
			if (count(array_unique($result[$field])) !== count($result[$field])) throw new \InvalidArgumentException('Invalid research result');
		}
		foreach (['brand', 'package'] as $field) if ($result[$field] !== null && (!is_string($result[$field]) || trim($result[$field]) !== $result[$field] || $result[$field] === '' || mb_strlen($result[$field]) > 200 || preg_match('/\p{C}/u', $result[$field]))) throw new \InvalidArgumentException('Invalid research result');
		foreach ($result['sources'] as $source) if (!in_array($source, $allowedSources, true)) throw new \InvalidArgumentException('Invalid research result');
		if (array_key_exists('name_candidate_sources', $result))
		{
			$attribution = $result['name_candidate_sources'];
			if (!is_array($attribution) || !array_is_list($attribution) || ($attribution !== [] && count($attribution) !== count($result['name_candidates']))) throw new \InvalidArgumentException('Invalid name candidate sources');
			foreach ($attribution as $candidateSources)
			{
				if (!is_array($candidateSources) || !array_is_list($candidateSources) || count($candidateSources) > 2) throw new \InvalidArgumentException('Invalid name candidate sources');
				foreach ($candidateSources as $source) if (!is_string($source) || !in_array($source, $allowedSources, true) || !in_array($source, $result['sources'], true)) throw new \InvalidArgumentException('Invalid name candidate source');
				if (count(array_unique($candidateSources)) !== count($candidateSources)) throw new \InvalidArgumentException('Duplicate name candidate source');
			}
		}
		if (!in_array('openfoodfacts', $result['sources'], true) && ($result['categories'] !== [] || $result['brand'] !== null || $result['package'] !== null)) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'found' && $result['name_candidates'] === [] || $result['outcome'] !== 'found' && $result['name_candidates'] !== []) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'found' && $result['sources'] === []) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'retryable_failure')
		{
			if (($result['error_code'] ?? null) !== 'provider_unavailable') throw new \InvalidArgumentException('Invalid research result');
		}
		elseif (array_key_exists('error_code', $result)) throw new \InvalidArgumentException('Invalid research result');
		if ($v2)
		{
			$web = $result['web_evidence'];
			if (!is_array($web) || !array_is_list($web) || count($web) > 1) throw new \InvalidArgumentException('Invalid web evidence');
			$isWeb = in_array('openai-web', $result['sources'], true);
			if ($isWeb && ($result['sources'] !== ['openai-web'] || $result['outcome'] !== 'found' || count($result['name_candidates']) !== 1 || ($result['name_candidate_sources'] ?? null) !== [['openai-web']] || count($web) !== 1)) throw new \InvalidArgumentException('Invalid web attribution');
			if (!$isWeb && $web !== []) throw new \InvalidArgumentException('Invalid web attribution');
			foreach ($web as $index => $evidence)
			{
				if (!is_array($evidence) || count($evidence) !== 3 || !isset($evidence['candidate_index'], $evidence['exact_gtin_claim'], $evidence['citations']) || $evidence['candidate_index'] !== 0 || !is_bool($evidence['exact_gtin_claim']) || !is_array($evidence['citations']) || !array_is_list($evidence['citations']) || count($evidence['citations']) < 1 || count($evidence['citations']) > 2) throw new \InvalidArgumentException('Invalid web evidence');
				foreach ($evidence['citations'] as $citationIndex => $citation)
				{
					if (!is_array($citation) || count($citation) !== 2 || !isset($citation['title'], $citation['url']) || !is_string($citation['title']) || trim($citation['title']) !== $citation['title'] || $citation['title'] === '' || mb_strlen($citation['title']) > 200 || preg_match('/\p{C}/u', $citation['title'])) throw new \InvalidArgumentException('Invalid web citation');
					$domain = self::CitationDomain($citation['url']);
					$result['web_evidence'][$index]['citations'][$citationIndex]['domain'] = $domain;
				}
			}
		}
		return $result;
	}
}
