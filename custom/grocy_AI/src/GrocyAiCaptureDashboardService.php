<?php

namespace GrocyAI\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Personal read model over already bootstrapped capture tables. */
class GrocyAiCaptureDashboardService
{
	public function __construct(private PDO $Db) {}

	public function ReadForUser(string $actor, ?int $activeBefore = null, ?int $completedBefore = null, ?DateTimeImmutable $now = null): array
	{
		$now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
		$end = $now->format('Y-m-d\TH:i:s\Z');
		$start = $now->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
		$counts = $this->Db->prepare("SELECT SUM(t.status = 'open') AS open_sessions, SUM(t.status = 'reviewing') AS reviewing_sessions FROM grocy_ai_capture_trips t WHERE t.created_by = ? AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)");
		$counts->execute([$actor]);
		$counts = $counts->fetch(PDO::FETCH_ASSOC);
		// Separate trip and receipt aggregates avoid multiplying either by capture lines or allocations.
		// julianday normalizes SQLite legacy timestamps and ISO offsets to the same UTC timeline.
		$totals = $this->Db->prepare("WITH completed AS (
			SELECT t.id FROM grocy_ai_capture_trips t WHERE t.created_by = ? AND t.status = 'committed'
			AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)
			AND julianday(t.committed_at) BETWEEN julianday(?) AND julianday(?)
		), receipt_totals AS (
			SELECT r.printed_total, (typeof(r.printed_total) IN ('integer', 'real') AND r.printed_total BETWEEN 0 AND 1.7976931348623157e308) AS has_total
			FROM grocy_ai_receipts r JOIN completed t ON t.id = r.trip_id
		)
		SELECT (SELECT COUNT(*) FROM completed) AS completed_sessions_30d,
		(SELECT SUM(CASE WHEN has_total THEN printed_total END) FROM receipt_totals) AS receipt_printed_total_30d,
		(SELECT COUNT(*) FROM receipt_totals WHERE has_total) AS receipts_with_total_30d,
		(SELECT COUNT(*) FROM receipt_totals WHERE COALESCE(has_total, 0) = 0) AS receipts_missing_total_30d,
		(SELECT COUNT(*) FROM completed t WHERE NOT EXISTS (SELECT 1 FROM grocy_ai_receipts r WHERE r.trip_id = t.id)) AS completed_without_receipts_30d");
		$totals->execute([$actor, $start, $end]);
		$totals = $totals->fetch(PDO::FETCH_ASSOC);
		$stats = ['open_sessions' => (int)$counts['open_sessions'], 'reviewing_sessions' => (int)$counts['reviewing_sessions']];
		foreach ($totals as $key => $value)
		{
			$stats[$key] = $key === 'receipt_printed_total_30d' ? ($value === null || !is_finite((float)$value) ? null : round((float)$value, 10)) : (int)$value;
		}
		return [
			'contract_version' => 1, 'period_start' => $start, 'period_end' => $end, 'stats' => $stats,
			'active' => $this->Page($actor, $activeBefore, false),
			'completed' => $this->Page($actor, $completedBefore, true)
		];
	}

	private function Page(string $actor, ?int $before, bool $completed): array
	{
		$statement = $this->Db->prepare("SELECT t.id, t.status,
			strftime('%Y-%m-%dT%H:%M:%SZ', t.created_at) AS created_at,
			strftime('%Y-%m-%dT%H:%M:%SZ', t.committed_at) AS committed_at,
			(SELECT COUNT(*) FROM grocy_ai_capture_lines l WHERE l.trip_id = t.id) AS item_count,
			(SELECT COUNT(*) FROM grocy_ai_receipts r WHERE r.trip_id = t.id) AS receipt_count
			FROM grocy_ai_capture_trips t WHERE t.created_by = :actor
			AND ((:completed = 1 AND t.status = 'committed') OR (:completed = 0 AND t.status IN ('open', 'reviewing')))
			AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)
			AND (:before IS NULL OR t.id < :before) ORDER BY t.id DESC LIMIT 26");
		$statement->bindValue(':actor', $actor, PDO::PARAM_STR);
		$statement->bindValue(':completed', $completed ? 1 : 0, PDO::PARAM_INT);
		$statement->bindValue(':before', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
		$statement->execute();
		$sessions = $statement->fetchAll(PDO::FETCH_ASSOC);
		$hasMore = count($sessions) > 25;
		if ($hasMore) array_pop($sessions);
		foreach ($sessions as &$session)
		{
			foreach (['id', 'item_count', 'receipt_count'] as $key) $session[$key] = (int)$session[$key];
		}
		unset($session);
		return ['sessions' => $sessions, 'next_before_id' => $hasMore ? $sessions[24]['id'] : null];
	}
}
