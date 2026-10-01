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

	/** @param array<string, mixed> $changes */
	public function UpdateDraft(int $tripId, int $lineId, int $revision, array $changes, string $actor): array
	{
		if ($revision < 1 || $changes === [] || array_diff(array_keys($changes), ['name', 'brand', 'package', 'product_group_id', 'taxonomy_leaf_slug']) !== []) throw new \InvalidArgumentException('Invalid draft changes');
		foreach ($changes as $key => $value)
		{
			if (in_array($key, ['name', 'brand', 'package'], true) && $value !== null && (!is_string($value) || trim($value) !== $value || $value === '' || mb_strlen($value) > 200)) throw new \InvalidArgumentException('Invalid draft field');
			if ($key === 'name' && $value === null) throw new \InvalidArgumentException('Name is required');
			if ($key === 'product_group_id' && $value !== null && (!is_int($value) || $value < 1)) throw new \InvalidArgumentException('Invalid group');
			if ($key === 'taxonomy_leaf_slug' && $value !== null && (!is_string($value) || preg_match('/^[a-z0-9_-]{1,100}$/D', $value) !== 1)) throw new \InvalidArgumentException('Invalid taxonomy leaf');
		}
		if (isset($changes['product_group_id']))
		{
			$query = $this->Db->prepare('SELECT 1 FROM product_groups WHERE id = ? AND active = 1');
			$query->execute([$changes['product_group_id']]);
			if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Inactive group');
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
				if ($value === null) unset($selected[$key]); else $selected[$key] = $value;
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
			$job = $this->Db->prepare('SELECT state FROM grocy_ai_capture_research_jobs WHERE id = ?');
			$job->execute([$draft['job_id']]);
			if (!in_array($job->fetchColumn(), ['needs_input', 'retryable_failure'], true)) throw new \InvalidArgumentException('Research is not retryable');
			$this->Db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'queued', attempts = 0, next_retry_at = NULL, lease_hash = NULL, lease_expires_at = NULL, safe_error_code = NULL, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$draft['job_id']]);
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
		$query = $this->Db->prepare('SELECT d.*, l.seq FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id WHERE d.trip_id = ? AND d.line_id = ?');
		$query->execute([$tripId, $lineId]);
		return $query->fetch(PDO::FETCH_ASSOC) ?: throw new \InvalidArgumentException('Unknown research draft');
	}

	private function AuditState(array $draft): array
	{
		return ['revision' => (int)$draft['revision'], 'selected' => json_decode($draft['selected_json'], true), 'user_edits' => json_decode($draft['user_edits_json'], true), 'receipt_line_id' => $draft['receipt_line_id'] === null ? null : (int)$draft['receipt_line_id']];
	}

	private function ReviewDraft(int $tripId, int $lineId): array
	{
		$draft = $this->DraftRow($tripId, $lineId);
		$job = $this->Db->prepare('SELECT canonical_gtin, state, safe_error_code FROM grocy_ai_capture_research_jobs WHERE id = ?');
		$job->execute([$draft['job_id']]);
		$job = $job->fetch(PDO::FETCH_ASSOC);
		$suggested = json_decode($draft['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
		$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
		$names = [];
		foreach ($suggested['name_candidates'] ?? [] as $index => $name) $names[] = ['value' => $name, 'source' => $suggested['name_candidate_sources'][$index] ?? 'unknown'];
		$evidence = null;
		if ($draft['receipt_line_id'] !== null)
		{
			try { $evidence = (new GrocyAiReceiptService($this->Db))->ResearchEvidence($tripId, (int)$draft['receipt_line_id']); }
			catch (\InvalidArgumentException) { $evidence = null; }
		}
		if ($evidence !== null) $names[] = ['value' => $evidence['description'], 'source' => 'receipt_ocr'];
		return ['id' => (int)$draft['id'], 'line_id' => $lineId, 'seq' => (int)$draft['seq'], 'revision' => (int)$draft['revision'], 'outcome' => $draft['outcome'], 'job_state' => $job['state'], 'safe_error_code' => $job['safe_error_code'], 'scanned_barcode' => $draft['scanned_barcode'], 'canonical_gtin' => $job['canonical_gtin'], 'selected' => $selected, 'suggested' => $suggested, 'name_alternatives' => $names, 'receipt_evidence' => $evidence, 'group_candidates' => $this->GroupCandidates($suggested), 'taxonomy_candidates' => $this->TaxonomyCandidates($suggested), 'possible_existing_products' => $this->PossibleProducts($selected, $suggested), 'final_product_id' => $draft['final_product_id'] === null ? null : (int)$draft['final_product_id']];
	}

	private function GroupCandidates(array $suggested): array
	{
		if (!in_array('openfoodfacts', $suggested['sources'] ?? [], true) || empty($suggested['categories'])) return [];
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
			$select = $this->Db->prepare("SELECT j.id, j.canonical_gtin, j.attempts, j.revision FROM grocy_ai_capture_research_jobs j WHERE j.attempts < ? AND ((j.state = 'queued' AND (j.next_retry_at IS NULL OR j.next_retry_at <= CURRENT_TIMESTAMP)) OR (j.state = 'retryable_failure' AND j.next_retry_at <= CURRENT_TIMESTAMP) OR (j.state = 'leased' AND j.lease_expires_at <= CURRENT_TIMESTAMP)) AND EXISTS (SELECT 1 FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = j.id AND l.status = 'unknown' AND l.selected = 1 AND l.applied_at IS NULL AND l.canonical_gtin = j.canonical_gtin AND l.scanned_barcode = d.scanned_barcode AND t.status IN ('open', 'reviewing') AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)) ORDER BY j.id LIMIT ?");
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
		$query = $this->Db->prepare("SELECT l.scanned_barcode FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? AND l.status = 'unknown' AND l.selected = 1 AND l.applied_at IS NULL AND l.canonical_gtin = ? AND l.scanned_barcode = d.scanned_barcode AND t.status IN ('open', 'reviewing') AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id) ORDER BY l.id LIMIT 32");
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
		if (!in_array($safeCode, ['provider_unavailable', 'worker_unavailable'], true)) throw new \InvalidArgumentException('Invalid research failure code');
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
			$terminal = (int)$job['attempts'] >= self::MAX_ATTEMPTS;
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

	private static function NormalizeResult(array $result, string $canonical): array
	{
		$required = ['contract_version', 'canonical_gtin', 'outcome', 'name_candidates', 'brand', 'package', 'categories', 'sources'];
		$allowed = array_merge($required, ['error_code', 'name_candidate_sources']);
		if (array_diff($required, array_keys($result)) || array_diff(array_keys($result), $allowed) || $result['contract_version'] !== 1 || $result['canonical_gtin'] !== $canonical || !in_array($result['outcome'], ['found', 'miss', 'retryable_failure'], true)) throw new \InvalidArgumentException('Invalid research result');
		foreach (['name_candidates' => [8, 200], 'categories' => [3, 100], 'sources' => [2, 32]] as $field => [$count, $length])
		{
			if (!is_array($result[$field]) || !array_is_list($result[$field]) || count($result[$field]) > $count) throw new \InvalidArgumentException('Invalid research result');
			foreach ($result[$field] as $value) if (!is_string($value) || trim($value) !== $value || $value === '' || mb_strlen($value) > $length) throw new \InvalidArgumentException('Invalid research result');
			if (count(array_unique($result[$field])) !== count($result[$field])) throw new \InvalidArgumentException('Invalid research result');
		}
		foreach (['brand', 'package'] as $field) if ($result[$field] !== null && (!is_string($result[$field]) || trim($result[$field]) !== $result[$field] || $result[$field] === '' || mb_strlen($result[$field]) > 200)) throw new \InvalidArgumentException('Invalid research result');
		foreach ($result['sources'] as $source) if (!in_array($source, ['bb-federation', 'openfoodfacts'], true)) throw new \InvalidArgumentException('Invalid research result');
		if (array_key_exists('name_candidate_sources', $result))
		{
			if (!is_array($result['name_candidate_sources']) || !array_is_list($result['name_candidate_sources']) || count($result['name_candidate_sources']) !== count($result['name_candidates'])) throw new \InvalidArgumentException('Invalid name candidate sources');
			foreach ($result['name_candidate_sources'] as $source) if (!is_string($source) || !in_array($source, ['bb-federation', 'openfoodfacts'], true) || !in_array($source, $result['sources'], true)) throw new \InvalidArgumentException('Invalid name candidate source');
		}
		if (!in_array('openfoodfacts', $result['sources'], true) && ($result['categories'] !== [] || $result['brand'] !== null || $result['package'] !== null)) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'found' && $result['name_candidates'] === [] || $result['outcome'] !== 'found' && $result['name_candidates'] !== []) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'found' && $result['sources'] === []) throw new \InvalidArgumentException('Invalid research result');
		if ($result['outcome'] === 'retryable_failure')
		{
			if (($result['error_code'] ?? null) !== 'provider_unavailable') throw new \InvalidArgumentException('Invalid research result');
		}
		elseif (array_key_exists('error_code', $result)) throw new \InvalidArgumentException('Invalid research result');
		return $result;
	}
}
