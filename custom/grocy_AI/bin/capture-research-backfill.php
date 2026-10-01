<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiCaptureResearchService;
use GrocyAI\Services\GrocyAiGtin;

require_once __DIR__ . '/../src/GrocyAiGtin.php';
require_once __DIR__ . '/../src/GrocyAiCaptureResearchService.php';

/** @return array<string, mixed> */
function captureResearchBackfillPreview(PDO $db, int $tripId): array
{
	if ($tripId < 1) throw new InvalidArgumentException('Trip ID must be positive');
	$trip = $db->prepare('SELECT id, status FROM grocy_ai_capture_trips WHERE id = ?');
	$trip->execute([$tripId]);
	$header = $trip->fetch(PDO::FETCH_ASSOC);
	if ($header === false) throw new RuntimeException('Trip does not exist');
	$cancel = $db->prepare('SELECT COUNT(*) FROM grocy_ai_capture_trip_cancellations WHERE trip_id = ?');
	$cancel->execute([$tripId]);
	$active = in_array($header['status'], ['open', 'reviewing'], true) && (int)$cancel->fetchColumn() === 0;
	$lines = $db->prepare('SELECT id, seq, scanned_barcode, canonical_gtin, resolved_product_id, status, quantity, selected, applied_at, outcome FROM grocy_ai_capture_lines WHERE trip_id = ? ORDER BY id LIMIT 101');
	$lines->execute([$tripId]);
	$rows = $lines->fetchAll(PDO::FETCH_ASSOC);
	if (count($rows) > 100) throw new RuntimeException('Trip exceeds backfill limit of 100 lines');
	$blockers = [];
	$candidates = [];
	foreach ($rows as $line)
	{
		$reason = null;
		if (!$active) $reason = 'inactive_trip';
		elseif ((int)$line['selected'] !== 1) $reason = 'unselected';
		elseif ($line['applied_at'] !== null) $reason = 'applied';
		elseif ($line['status'] !== 'unknown') $reason = $line['status'];
		elseif ($line['resolved_product_id'] !== null) $reason = 'resolved_product';
		elseif (($canonical = GrocyAiGtin::CanonicalOrNull((string)$line['scanned_barcode'])) === null || $canonical !== $line['canonical_gtin']) $reason = 'invalid_gtin';
		if ($reason === null) $candidates[] = (int)$line['id'];
		else $blockers[] = ['line_id' => (int)$line['id'], 'reason' => $reason];
	}
	$counts = [];
	foreach ($blockers as $blocker) $counts[$blocker['reason']] = ($counts[$blocker['reason']] ?? 0) + 1;
	ksort($counts);
	$checksumData = ['version' => 1, 'trip_id' => $tripId, 'trip_status' => $header['status'], 'active' => $active, 'lines' => $rows, 'candidate_ids' => $candidates];
	return ['trip_id' => $tripId, 'trip_status' => $header['status'], 'active' => $active, 'line_count' => count($rows), 'candidate_ids' => $candidates, 'candidate_count' => count($candidates), 'blockers' => $blockers, 'blocker_counts' => $counts, 'checksum' => hash('sha256', json_encode($checksumData, JSON_THROW_ON_ERROR))];
}

/** @return array<string, mixed> */
function captureResearchBackfillApply(PDO $db, int $tripId, string $checksum): array
{
	if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) throw new InvalidArgumentException('Expected a SHA-256 checksum');
	if ($db->inTransaction()) throw new LogicException('Backfill owns its transaction');
	$db->exec('BEGIN IMMEDIATE');
	try
	{
		$preview = captureResearchBackfillPreview($db, $tripId);
		if (!$preview['active']) throw new RuntimeException('Trip is canceled or committed');
		if (!hash_equals($preview['checksum'], $checksum)) throw new RuntimeException('Candidate checksum changed; run a new dry run');
		$service = new GrocyAiCaptureResearchService($db, false);
		$created = 0;
		foreach ($preview['candidate_ids'] as $lineId)
		{
			$line = $db->prepare('SELECT scanned_barcode FROM grocy_ai_capture_lines WHERE id = ? AND trip_id = ?');
			$line->execute([$lineId, $tripId]);
			$draftBefore = $db->prepare('SELECT COUNT(*) FROM grocy_ai_capture_research_drafts WHERE line_id = ?');
			$draftBefore->execute([$lineId]);
			$existed = (int)$draftBefore->fetchColumn() > 0;
			if ($service->EnqueueUnknown($tripId, $lineId, (string)$line->fetchColumn()) === null) throw new RuntimeException('Candidate became ineligible');
			if (!$existed) $created++;
		}
		$db->commit();
		return ['trip_id' => $tripId, 'candidate_ids' => $preview['candidate_ids'], 'candidate_count' => $preview['candidate_count'], 'created_count' => $created, 'checksum' => $checksum];
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
		if (!is_array($options) || !isset($options['trip']) || preg_match('/^[1-9][0-9]*$/D', (string)$options['trip']) !== 1 || isset($options['dry-run']) === isset($options['apply']) || (isset($options['apply']) && !isset($options['checksum'])) || (isset($options['dry-run']) && isset($options['checksum']))) throw new InvalidArgumentException('Usage: --trip=ID (--dry-run | --apply --checksum=SHA256) [--db=PATH]');
		$dataPath = getenv('GROCY_DATAPATH');
		$dbPath = $options['db'] ?? ($dataPath !== false ? rtrim($dataPath, '/') . '/grocy.db' : null);
		if (!is_string($dbPath) || $dbPath === '' || $dbPath[0] !== '/' || !is_file($dbPath)) throw new RuntimeException('Set GROCY_DATAPATH to an absolute configured data path containing grocy.db');
		$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$db->exec('PRAGMA foreign_keys = ON');
		if (isset($options['dry-run'])) $db->exec('PRAGMA query_only = ON');
		$result = isset($options['dry-run']) ? captureResearchBackfillPreview($db, (int)$options['trip']) : captureResearchBackfillApply($db, (int)$options['trip'], (string)$options['checksum']);
		fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	}
	catch (Throwable $error)
	{
		fwrite(STDERR, $error->getMessage() . "\n");
		exit(1);
	}
}
