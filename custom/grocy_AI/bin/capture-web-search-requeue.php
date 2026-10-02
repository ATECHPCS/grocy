<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GrocyAiGtin.php';

function captureWebSearchRequeueDailyLimit(): int
{
	$value = defined('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') ? GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT : (getenv('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') !== false ? getenv('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') : 20);
	if ((!is_int($value) && (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1)) || (int)$value < 0 || (int)$value > 100000) throw new RuntimeException('Invalid daily reservation limit');
	return (int)$value;
}

function captureWebSearchRequeuePreview(PDO $db, int $tripId): array
{
	if ($tripId < 1) throw new InvalidArgumentException('Trip ID must be positive');
	$select = static function(string $sql, array $args = []) use ($db): array
	{
		$q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
	};
	$trip = $select('SELECT * FROM grocy_ai_capture_trips WHERE id = ?', [$tripId]);
	if ($trip === []) throw new RuntimeException('Trip does not exist');
	$cancellations = $select('SELECT * FROM grocy_ai_capture_trip_cancellations WHERE trip_id = ? ORDER BY trip_id', [$tripId]);
	$active = in_array($trip[0]['status'], ['open', 'reviewing'], true) && $cancellations === [];
	$lines = $select('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? ORDER BY id LIMIT 101', [$tripId]);
	if (count($lines) > 100) throw new RuntimeException('Trip exceeds requeue limit of 100 lines');
	$day = (string)$db->query("SELECT date('now')")->fetchColumn();
	$limit = captureWebSearchRequeueDailyLimit();
	$today = $select('SELECT id, job_id, retry_generation, utc_day FROM grocy_ai_capture_web_search_reservations WHERE utc_day = ? ORDER BY id', [$day]);
	$candidates = []; $blockers = []; $state = [];
	foreach ($lines as $line)
	{
		$drafts = $select('SELECT * FROM grocy_ai_capture_research_drafts WHERE line_id = ? AND trip_id = ? ORDER BY id', [$line['id'], $tripId]);
		$draft = $drafts[0] ?? null;
		$jobs = $draft === null ? [] : $select('SELECT * FROM grocy_ai_capture_research_jobs WHERE id = ?', [$draft['job_id']]);
		$job = $jobs[0] ?? null;
		$related = $job === null ? [] : $select('SELECT d.*, t.status AS trip_status, EXISTS(SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = d.trip_id) AS canceled FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.job_id = ? ORDER BY d.id', [$job['id']]);
		$reservations = $job === null ? [] : $select('SELECT id, job_id, retry_generation, utc_day FROM grocy_ai_capture_web_search_reservations WHERE job_id = ? OR canonical_gtin = ? ORDER BY id', [$job['id'], $job['canonical_gtin']]);
		$state[] = [$line, $drafts, $jobs, $related, $reservations];
		$result = $draft === null ? null : json_decode($draft['suggested_json'], true);
		$reason = null;
		if (!$active) $reason = 'inactive_trip';
		elseif ((int)$line['selected'] !== 1) $reason = 'unselected';
		elseif ($line['applied_at'] !== null) $reason = 'applied';
		elseif ($line['status'] !== 'unknown' || $line['resolved_product_id'] !== null) $reason = 'resolved_line';
		elseif (($canonical = GrocyAI\Services\GrocyAiGtin::CanonicalOrNull((string)$line['scanned_barcode'])) === null || $canonical !== $line['canonical_gtin']) $reason = 'invalid_gtin';
		elseif ($draft === null || $job === null) $reason = 'missing_research';
		elseif ($job['canonical_gtin'] !== $canonical || GrocyAI\Services\GrocyAiGtin::CanonicalOrNull($draft['scanned_barcode']) !== $canonical) $reason = 'invalid_gtin';
		elseif ($draft['final_product_id'] !== null || $draft['outcome'] !== 'needs_input') $reason = 'finalized_or_unsettled_draft';
		elseif ($job['state'] !== 'needs_input' || $job['safe_error_code'] !== null || (int)$job['result_revision'] < 1 || (int)$job['retry_generation'] !== 0) $reason = 'unsettled_or_retried_job';
		elseif (!is_array($result) || ($result['contract_version'] ?? null) !== 1 || ($result['canonical_gtin'] ?? null) !== $canonical || ($result['outcome'] ?? null) !== 'miss' || ($result['name_candidates'] ?? null) !== [] || ($result['sources'] ?? null) !== [] || array_key_exists('error_code', $result) || array_diff(['brand', 'package', 'categories'], array_keys($result)) || $result['brand'] !== null || $result['package'] !== null || $result['categories'] !== [] || array_diff(array_keys($result), ['contract_version', 'canonical_gtin', 'outcome', 'name_candidates', 'brand', 'package', 'categories', 'sources', 'name_candidate_sources']) || (array_key_exists('name_candidate_sources', $result) && $result['name_candidate_sources'] !== [])) $reason = 'not_definitive_provider_miss';
		elseif ($reservations !== []) $reason = 'paid_reservation';
		if ($reason === null)
		{
			foreach ($related as $other)
			{
				if ((int)$other['trip_id'] !== $tripId && in_array($other['trip_status'], ['open', 'reviewing'], true) && !(int)$other['canceled'] && $other['final_product_id'] === null && !in_array($other['outcome'], ['approved', 'linked'], true)) { $reason = 'shared_active_job'; break; }
			}
		}
		if ($reason !== null) $blockers[] = ['line_id' => (int)$line['id'], 'reason' => $reason];
		else { $candidates[(int)$job['id']] = (int)$job['id']; }
	}
	ksort($candidates);
	$ids = array_values($candidates);
	$counts = []; foreach ($blockers as $blocker) $counts[$blocker['reason']] = ($counts[$blocker['reason']] ?? 0) + 1; ksort($counts);
	$remaining = max(0, $limit - count($today));
	$checksum = hash('sha256', json_encode(['version' => 1, 'trip' => $trip, 'cancellations' => $cancellations, 'state' => $state, 'today' => $today, 'utc_day' => $day, 'limit' => $limit, 'candidate_ids' => $ids], JSON_THROW_ON_ERROR));
	return ['trip_id' => $tripId, 'trip_status' => $trip[0]['status'], 'active' => $active, 'line_count' => count($lines), 'candidate_ids' => $ids, 'candidate_count' => count($ids), 'blockers' => $blockers, 'blocker_counts' => $counts, 'utc_day' => $day, 'daily_limit' => $limit, 'today_reservation_count' => count($today), 'remaining_slots' => $remaining, 'max_chargeable_calls' => min(count($ids), $remaining), 'checksum' => $checksum];
}

function captureWebSearchRequeueApply(PDO $db, int $tripId, string $checksum): array
{
	if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) throw new InvalidArgumentException('Expected a SHA-256 checksum');
	if ($db->inTransaction()) throw new LogicException('Requeue owns its transaction');
	$db->exec('BEGIN IMMEDIATE');
	try
	{
		$p = captureWebSearchRequeuePreview($db, $tripId);
		if (!$p['active'] || !hash_equals($p['checksum'], $checksum)) throw new RuntimeException('Trip or preview changed; run a new dry run');
		if ($p['candidate_count'] < 1 || $p['candidate_count'] > 20) throw new RuntimeException('Apply requires between 1 and 20 eligible jobs');
		foreach ($p['candidate_ids'] as $id)
		{
			$db->prepare("UPDATE grocy_ai_capture_research_jobs SET state = 'queued', attempts = 0, next_retry_at = NULL, lease_hash = NULL, lease_expires_at = NULL, safe_error_code = NULL, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND state = 'needs_input'")->execute([$id]);
			$q = $db->prepare("SELECT id FROM grocy_ai_capture_research_drafts WHERE trip_id = ? AND job_id = ? AND final_product_id IS NULL AND outcome NOT IN ('approved', 'linked') ORDER BY id");
			$q->execute([$tripId, $id]);
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $draftId)
			{
				$db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, after_json) VALUES (?, ?, 'capture-web-search-requeue-cli', 'grocy_ai:web_search_requeue', ?)")->execute([$tripId, $draftId, json_encode(['job_id' => $id, 'state' => 'queued'], JSON_THROW_ON_ERROR)]);
			}
		}
		$db->commit();
		return ['trip_id' => $tripId, 'requeued_ids' => $p['candidate_ids'], 'requeued_count' => $p['candidate_count'], 'checksum' => $checksum];
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
		$options = getopt('', ['trip:', 'dry-run', 'apply', 'checksum:', 'db:']);
		if (!is_array($options) || !isset($options['trip']) || preg_match('/^[1-9][0-9]*$/D', (string)$options['trip']) !== 1 || isset($options['dry-run']) === isset($options['apply']) || (isset($options['apply']) && !isset($options['checksum'])) || (isset($options['dry-run']) && isset($options['checksum']))) throw new InvalidArgumentException('Usage: --trip=ID (--dry-run | --apply --checksum=SHA256) [--db=/absolute/path]');
		$dataPath = getenv('GROCY_DATAPATH');
		$dbPath = $options['db'] ?? ($dataPath !== false && str_starts_with($dataPath, '/') ? rtrim($dataPath, '/') . '/grocy.db' : null);
		if (!is_string($dbPath) || !str_starts_with($dbPath, '/') || !is_file($dbPath)) throw new RuntimeException('Set an absolute database path');
		$configPath = dirname($dbPath) . '/config.php';
		if (is_file($configPath)) require $configPath;
		$override = dirname($dbPath) . '/settingoverrides/AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT.txt';
		if (!defined('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT') && is_file($override)) define('GROCY_AI_CAPTURE_WEB_SEARCH_DAILY_LIMIT', trim((string)file_get_contents($override)));
		$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$db->exec('PRAGMA foreign_keys = ON');
		if (isset($options['dry-run'])) $db->exec('PRAGMA query_only = ON');
		$result = isset($options['dry-run']) ? captureWebSearchRequeuePreview($db, (int)$options['trip']) : captureWebSearchRequeueApply($db, (int)$options['trip'], (string)$options['checksum']);
		fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	}
	catch (Throwable $error)
	{
		// Database/configuration exceptions can contain private data; expose only controlled errors.
		fwrite(STDERR, $error instanceof PDOException ? "Database operation failed\n" : "Requeue refused; verify arguments, configuration, and a fresh preview\n");
		exit(1);
	}
}
