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
		$allowed = array_merge($required, ['error_code']);
		if (array_diff($required, array_keys($result)) || array_diff(array_keys($result), $allowed) || $result['contract_version'] !== 1 || $result['canonical_gtin'] !== $canonical || !in_array($result['outcome'], ['found', 'miss', 'retryable_failure'], true)) throw new \InvalidArgumentException('Invalid research result');
		foreach (['name_candidates' => [8, 200], 'categories' => [3, 100], 'sources' => [2, 32]] as $field => [$count, $length])
		{
			if (!is_array($result[$field]) || !array_is_list($result[$field]) || count($result[$field]) > $count) throw new \InvalidArgumentException('Invalid research result');
			foreach ($result[$field] as $value) if (!is_string($value) || trim($value) !== $value || $value === '' || mb_strlen($value) > $length) throw new \InvalidArgumentException('Invalid research result');
			if (count(array_unique($result[$field])) !== count($result[$field])) throw new \InvalidArgumentException('Invalid research result');
		}
		foreach (['brand', 'package'] as $field) if ($result[$field] !== null && (!is_string($result[$field]) || trim($result[$field]) !== $result[$field] || $result[$field] === '' || mb_strlen($result[$field]) > 200)) throw new \InvalidArgumentException('Invalid research result');
		foreach ($result['sources'] as $source) if (!in_array($source, ['bb-federation', 'openfoodfacts'], true)) throw new \InvalidArgumentException('Invalid research result');
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
