<?php

declare(strict_types=1);

require_once __DIR__ . '/capture-web-search-requeue.php';

// One-time recovery for the seven trip #12 searches reserved while OpenAI billing was exhausted.
const CAPTURE_QUOTA_RECOVERY_TRIP = 12;
const CAPTURE_QUOTA_RECOVERY_JOBS = [
	1 => '00020184302530', 2 => '00000002198842', 3 => '04061459264494',
	9 => '00256695212482', 10 => '00209933211518', 13 => '08246160465262',
	15 => '08062461396193'
];

function captureQuotaRecoveryIsMiss(mixed $result, string $canonical): bool
{
	if (!is_array($result)) return false;
	$expected = ['contract_version' => 2, 'canonical_gtin' => $canonical, 'outcome' => 'miss', 'name_candidates' => [], 'brand' => null, 'package' => null, 'categories' => [], 'sources' => [], 'web_evidence' => []];
	if (array_key_exists('name_candidate_sources', $result)) $expected['name_candidate_sources'] = [];
	ksort($result); ksort($expected);
	return $result === $expected;
}

function captureQuotaRecoveryPreview(PDO $db): array
{
	$select = static function (string $sql, array $args = []) use ($db): array
	{
		$query = $db->prepare($sql); $query->execute($args); return $query->fetchAll(PDO::FETCH_ASSOC);
	};
	$trip = $select('SELECT * FROM grocy_ai_capture_trips WHERE id = ?', [CAPTURE_QUOTA_RECOVERY_TRIP]);
	if (count($trip) !== 1) throw new RuntimeException('Trip does not exist');
	$cancellations = $select('SELECT * FROM grocy_ai_capture_trip_cancellations WHERE trip_id = ?', [CAPTURE_QUOTA_RECOVERY_TRIP]);
	$active = in_array($trip[0]['status'], ['open', 'reviewing'], true) && $cancellations === [];
	$day = (string)$db->query("SELECT date('now')")->fetchColumn();
	$limit = captureWebSearchRequeueDailyLimit();
	$today = $select('SELECT * FROM grocy_ai_capture_web_search_reservations WHERE utc_day = ? ORDER BY id', [$day]);
	$state = []; $candidates = []; $blockers = [];
	foreach (CAPTURE_QUOTA_RECOVERY_JOBS as $jobId => $canonical)
	{
		$job = $select('SELECT * FROM grocy_ai_capture_research_jobs WHERE id = ?', [$jobId]);
		$drafts = $select('SELECT * FROM grocy_ai_capture_research_drafts WHERE job_id = ? ORDER BY id', [$jobId]);
		$lines = $select('SELECT l.* FROM grocy_ai_capture_lines l JOIN grocy_ai_capture_research_drafts d ON d.line_id = l.id WHERE d.job_id = ? ORDER BY l.id', [$jobId]);
		$reservations = $select('SELECT * FROM grocy_ai_capture_web_search_reservations WHERE job_id = ? OR canonical_gtin = ? ORDER BY id', [$jobId, $canonical]);
		$related = $select('SELECT d.id, d.trip_id, d.outcome, d.final_product_id, t.status, EXISTS(SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = d.trip_id) AS canceled FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? ORDER BY d.id', [$jobId]);
		$state[] = [$job, $drafts, $lines, $reservations, $related];
		$reason = null;
		if (!$active) $reason = 'inactive_trip';
		elseif (count($job) !== 1 || $job[0]['canonical_gtin'] !== $canonical) $reason = 'unexpected_job';
		elseif ($job[0]['state'] !== 'needs_input' || $job[0]['safe_error_code'] !== null || (int)$job[0]['result_revision'] < 1 || (int)$job[0]['retry_generation'] !== 0) $reason = 'job_changed';
		elseif (count($reservations) !== 1 || (int)$reservations[0]['job_id'] !== $jobId || $reservations[0]['canonical_gtin'] !== $canonical || (int)$reservations[0]['retry_generation'] !== 0) $reason = 'reservation_changed';
		elseif ($drafts === [] || count($drafts) !== count($lines)) $reason = 'missing_line';
		else
		{
			foreach ($drafts as $draft)
			{
				if ((int)$draft['trip_id'] !== CAPTURE_QUOTA_RECOVERY_TRIP) { $reason = 'shared_job'; break; }
				$line = null;
				foreach ($lines as $possible) if ((int)$possible['id'] === (int)$draft['line_id']) { $line = $possible; break; }
				if ($line === null || (int)$line['trip_id'] !== CAPTURE_QUOTA_RECOVERY_TRIP || (int)$line['selected'] !== 1 || $line['applied_at'] !== null || $line['status'] !== 'unknown' || $line['resolved_product_id'] !== null || $line['canonical_gtin'] !== $canonical || $line['scanned_barcode'] !== $draft['scanned_barcode'] || GrocyAI\Services\GrocyAiGtin::CanonicalOrNull((string)$line['scanned_barcode']) !== $canonical || $draft['final_product_id'] !== null || $draft['outcome'] !== 'needs_input' || !captureQuotaRecoveryIsMiss(json_decode($draft['suggested_json'], true), $canonical)) { $reason = 'line_or_draft_changed'; break; }
			}
		}
		if ($reason === null) $candidates[] = $jobId; else $blockers[] = ['job_id' => $jobId, 'reason' => $reason];
	}
	$remaining = max(0, $limit - count($today));
	$checksum = hash('sha256', json_encode(['version' => 1, 'trip' => $trip, 'cancellations' => $cancellations, 'state' => $state, 'today' => $today, 'utc_day' => $day, 'limit' => $limit], JSON_THROW_ON_ERROR));
	return ['trip_id' => CAPTURE_QUOTA_RECOVERY_TRIP, 'trip_status' => $trip[0]['status'], 'candidate_ids' => $candidates, 'candidate_count' => count($candidates), 'blockers' => $blockers, 'utc_day' => $day, 'daily_limit' => $limit, 'today_reservation_count' => count($today), 'remaining_slots' => $remaining, 'max_chargeable_calls' => min(count($candidates), $remaining), 'checksum' => $checksum];
}

function captureQuotaRecoveryApply(PDO $db, string $checksum): array
{
	if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) throw new InvalidArgumentException('Expected a SHA-256 checksum');
	if ($db->inTransaction()) throw new LogicException('Recovery owns its transaction');
	$db->exec('BEGIN IMMEDIATE');
	try
	{
		$preview = captureQuotaRecoveryPreview($db);
		if (!hash_equals($preview['checksum'], $checksum) || $preview['candidate_ids'] !== array_keys(CAPTURE_QUOTA_RECOVERY_JOBS) || $preview['remaining_slots'] < count(CAPTURE_QUOTA_RECOVERY_JOBS)) throw new RuntimeException('Recovery state changed or daily allowance is insufficient');
		foreach ($preview['candidate_ids'] as $jobId)
		{
			$update = $db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'queued', retry_generation = 1, attempts = 0, next_retry_at = NULL, lease_hash = NULL, lease_expires_at = NULL, safe_error_code = NULL, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND state = 'needs_input' AND retry_generation = 0");
			$update->execute([$jobId]);
			if ($update->rowCount() !== 1) throw new RuntimeException('Job changed during recovery');
			$drafts = $db->prepare('SELECT id FROM grocy_ai_capture_research_drafts WHERE job_id = ? AND trip_id = ? ORDER BY id');
			$drafts->execute([$jobId, CAPTURE_QUOTA_RECOVERY_TRIP]);
			foreach ($drafts->fetchAll(PDO::FETCH_COLUMN) as $draftId)
			{
				$db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id,draft_id,actor,action,after_json) VALUES (?,?,'capture-quota-recovery-cli','grocy_ai:quota_recovery',?)")
					->execute([CAPTURE_QUOTA_RECOVERY_TRIP, $draftId, json_encode(['job_id' => $jobId, 'retry_generation' => 1, 'state' => 'queued'], JSON_THROW_ON_ERROR)]);
			}
		}
		$db->commit();
		return ['trip_id' => CAPTURE_QUOTA_RECOVERY_TRIP, 'requeued_ids' => $preview['candidate_ids'], 'requeued_count' => count($preview['candidate_ids']), 'checksum' => $checksum];
	}
	catch (Throwable $error)
	{
		if ($db->inTransaction()) $db->rollBack();
		throw $error;
	}
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
	try
	{
		$options = getopt('', ['dry-run', 'apply', 'checksum:', 'db:']);
		if (!is_array($options) || isset($options['dry-run']) === isset($options['apply']) || (isset($options['apply']) && !isset($options['checksum'])) || (isset($options['dry-run']) && isset($options['checksum']))) throw new InvalidArgumentException('Expected --dry-run or --apply --checksum=SHA256');
		$dataPath = getenv('GROCY_DATAPATH');
		$dbPath = $options['db'] ?? ($dataPath !== false && str_starts_with($dataPath, '/') ? rtrim($dataPath, '/') . '/grocy.db' : null);
		if (!is_string($dbPath) || !str_starts_with($dbPath, '/') || !is_file($dbPath)) throw new RuntimeException('Set an absolute database path');
		$configPath = dirname($dbPath) . '/config.php';
		if (is_file($configPath))
		{
			define('GROCY_DATAPATH', dirname($dbPath));
			require_once __DIR__ . '/../../../helpers/extensions.php';
			require $configPath;
		}
		$override = dirname($dbPath) . '/settingoverrides/AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT.txt';
		if (!defined('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') && is_file($override)) define('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT', trim((string)file_get_contents($override)));
		$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$db->exec('PRAGMA foreign_keys = ON');
		if (isset($options['dry-run'])) $db->exec('PRAGMA query_only = ON');
		$result = isset($options['dry-run']) ? captureQuotaRecoveryPreview($db) : captureQuotaRecoveryApply($db, (string)$options['checksum']);
		fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	}
	catch (Throwable $error)
	{
		fwrite(STDERR, "Quota recovery refused; verify arguments, configuration, and a fresh preview\n");
		exit(1);
	}
}
