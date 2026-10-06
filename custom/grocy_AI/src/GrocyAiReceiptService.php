<?php

namespace GrocyAI\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

class GrocyAiReceiptService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		GrocyAiReceiptMigration::Bootstrap($this->Db);
	}

	public function ListForTrip(int $tripId): array
	{
		$this->Trip($tripId);
		$rows = $this->Rows('SELECT * FROM grocy_ai_receipts WHERE trip_id = ? ORDER BY id', [$tripId]);
		return array_map(fn(array $row) => $this->View((int)$row['id']), $rows);
	}

	/** A receipt description is evidence only after an explicit, same-trip line choice. */
	public function ResearchEvidence(int $tripId, int $receiptLineId): array
	{
		$query = $this->Db->prepare("SELECT l.id, l.description, l.kind, l.decision, r.id AS receipt_id FROM grocy_ai_receipt_lines l JOIN grocy_ai_receipts r ON r.id = l.receipt_id WHERE l.id = ? AND r.trip_id = ?");
		$query->execute([$receiptLineId, $tripId]);
		$line = $query->fetch(PDO::FETCH_ASSOC);
		if ($line === false || $line['kind'] !== 'item' || $line['decision'] === 'ignore' || trim((string)$line['description']) === '') throw new InvalidArgumentException('Receipt line is not usable research evidence');
		return ['receipt_line_id' => (int)$line['id'], 'receipt_id' => (int)$line['receipt_id'], 'description' => mb_substr(trim((string)$line['description']), 0, 200), 'source' => 'receipt_ocr'];
	}

	public function SuggestMatches(int $receiptId, int $lineId): array
	{
		$receipt = $this->Receipt($receiptId);
		$line = $this->Line($receiptId, $lineId);
		$reviewMatch = $this->CaptureCandidates((int)$receipt['trip_id'], $line);
		$description = mb_strtolower(trim((string)$line['description']));
		$captures = $this->Rows('SELECT c.id, c.seq, c.scanned_barcode, c.resolved_product_id AS product_id, c.quantity, p.name AS product_name FROM grocy_ai_capture_lines c LEFT JOIN products p ON p.id = c.resolved_product_id WHERE c.trip_id = ? AND c.selected = 1 ORDER BY c.seq', [(int)$receipt['trip_id']]);
		$score = static function (string $name) use ($description): int
		{
			$name = mb_strtolower(trim($name));
			if ($description === '' || $name === '') return 0;
			if ($description === $name) return 2;
			return str_contains($description, $name) || str_contains($name, $description) ? 1 : 0;
		};
		foreach ($captures as &$capture) $capture['match_score'] = $score((string)($capture['product_name'] ?? ''));
		unset($capture);
		usort($captures, static fn(array $a, array $b) => $b['match_score'] <=> $a['match_score'] ?: $a['seq'] <=> $b['seq']);
		$products = $this->Rows('SELECT id, name FROM products ORDER BY id', []);
		foreach ($products as &$product) $product['match_score'] = $score((string)$product['name']);
		unset($product);
		$products = array_values(array_filter($products, static fn(array $product) => $product['match_score'] > 0));
		usort($products, static fn(array $a, array $b) => $b['match_score'] <=> $a['match_score'] ?: $a['id'] <=> $b['id']);
		return ['capture_lines' => array_slice($captures, 0, 20), 'products' => array_slice($products, 0, 20), 'capture_candidates' => $reviewMatch['capture_candidates'], 'capture_match_status' => $reviewMatch['capture_match_status']];
	}

	private function CaptureCandidates(int $tripId, array $line): array
	{
		$hasResearch = $this->Scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'grocy_ai_capture_research_drafts'", []) !== false;
		$paired = [];
		if ($hasResearch) $paired = $this->Rows('SELECT d.line_id FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines c ON c.id = d.line_id AND c.trip_id = d.trip_id WHERE d.trip_id = ? AND d.receipt_line_id = ? AND c.selected = 1 LIMIT 2', [$tripId, (int)$line['id']]);
		$pairedId = count($paired) === 1 ? (int)$paired[0]['line_id'] : null;
		if ($line['kind'] !== 'item' || $line['decision'] === 'ignore') return ['capture_candidates' => [], 'capture_options' => [], 'capture_match_status' => 'none', 'paired_capture_line_id' => $pairedId];
		// Store item numbers printed before a receipt name are not package barcodes.
		$receiptName = preg_replace('/^\s*[0-9]{6}(?=\s|$)/u', '', (string)$line['description'], 1);
		$needle = $this->MatchWords($receiptName ?? (string)$line['description']);
		$draftJoin = $hasResearch ? 'LEFT JOIN grocy_ai_capture_research_drafts d ON d.line_id = c.id AND d.trip_id = c.trip_id' : '';
		$draftFields = $hasResearch ? 'd.selected_json, d.suggested_json, d.receipt_line_id' : 'NULL AS selected_json, NULL AS suggested_json, NULL AS receipt_line_id';
		$captures = $this->Rows('SELECT c.id, c.seq, c.scanned_barcode, c.status, p.name AS product_name, ' . $draftFields . ' FROM grocy_ai_capture_lines c LEFT JOIN products p ON p.id = c.resolved_product_id ' . $draftJoin . ' WHERE c.trip_id = ? AND c.selected = 1 ORDER BY c.seq', [$tripId]);
		$candidates = [];
		$options = [];
		foreach ($captures as $capture)
		{
			$claimed = $capture['receipt_line_id'] === null ? null : (int)$capture['receipt_line_id'];
			if ($claimed !== null && $claimed !== (int)$line['id']) continue;
			$names = [];
			if (is_string($capture['product_name'] ?? null)) $names[] = ['name' => $capture['product_name'], 'source' => 'grocy_product'];
			foreach (['selected_json' => 'research_selected', 'suggested_json' => 'research_provider'] as $field => $source)
			{
				$data = json_decode((string)($capture[$field] ?? '{}'), true);
				if (!is_array($data)) continue;
				if ($field === 'selected_json' && is_string($data['name'] ?? null)) $names[] = ['name' => $data['name'], 'source' => $source];
				if ($field === 'suggested_json') foreach ($data['name_candidates'] ?? [] as $name) if (is_string($name)) $names[] = ['name' => $name, 'source' => $source];
			}
			if ($capture['status'] === 'unknown')
			{
				$display = $names[0] ?? ['name' => 'UPC ' . $capture['scanned_barcode'], 'source' => 'barcode'];
				$options[] = ['capture_line_id' => (int)$capture['id'], 'seq' => (int)$capture['seq'], 'scanned_barcode' => $capture['scanned_barcode'], 'display_name' => $display['name'], 'source' => $display['source'], 'paired_receipt_line_id' => $claimed];
			}
			$best = null;
			if ($needle === []) continue;
			foreach ($names as $name)
			{
				$words = $this->MatchWords($name['name']);
				if (count($words) < 2) continue;
				$score = $words === $needle ? 100 : 0;
				if ($score === 0 && count($needle) >= 2 && count(array_intersect($needle, $words)) >= 2 && count(array_diff($needle, $words)) === 0) $score = 80;
				if ($score > 0 && ($best === null || $score > $best['score'])) $best = ['capture_line_id' => (int)$capture['id'], 'seq' => (int)$capture['seq'], 'scanned_barcode' => $capture['scanned_barcode'], 'display_name' => $name['name'], 'source' => $name['source'], 'score' => $score, 'reason' => $score === 100 ? 'same_product_words' : 'receipt_words_in_product_name'];
			}
			if ($best !== null) $candidates[] = $best;
		}
		usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['seq'] <=> $b['seq']);
		return ['capture_candidates' => array_slice($candidates, 0, 20), 'capture_options' => $options, 'capture_match_status' => count($candidates) === 0 ? 'none' : (count($candidates) === 1 ? 'unique' : 'ambiguous'), 'paired_capture_line_id' => $pairedId];
	}

	private function MatchWords(string $text): array
	{
		$text = mb_strtolower($text);
		$words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		return array_values(array_filter($words, static fn(string $word): bool => mb_strlen($word) >= 3));
	}

	public function ImportExtraction(int $receiptId, array $suggestions, ?string $actor = null, ?int $expectedRevision = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($suggestions, $actor, $expectedRevision): void
		{
			if ($expectedRevision !== null && (int)$receipt['revision'] !== $expectedRevision) throw new InvalidArgumentException('Receipt changed during OCR');
			if ($this->Scalar("SELECT 1 FROM grocy_ai_receipt_audit WHERE receipt_id = ? AND action = 'update_receipt' LIMIT 1", [(int)$receipt['id']]) !== false) throw new InvalidArgumentException('Receipt has manual header edits');
			if (!isset($suggestions['lines']) || !is_array($suggestions['lines']) || !is_array($suggestions['adjustments'] ?? []) || count($suggestions['lines']) + count($suggestions['adjustments'] ?? []) > 250)
			{
				throw new InvalidArgumentException('Invalid receipt extraction lines');
			}
			$existing = $this->Rows('SELECT id FROM grocy_ai_receipt_lines WHERE receipt_id = ?', [(int)$receipt['id']]);
			if ($existing !== [])
			{
				if ($receipt['extraction_json'] === json_encode($suggestions, JSON_THROW_ON_ERROR)) return;
				throw new InvalidArgumentException('Receipt already has lines; extraction cannot overwrite review');
			}
			$before = $receipt;
			$header = $this->HeaderChange($suggestions);
			if ($receipt['shopping_location_id'] === null && !array_key_exists('shopping_location_id', $header) && is_string($header['merchant'] ?? null))
			{
				$store = $this->UniqueMerchantStore($header['merchant']);
				if ($store !== null) $header['shopping_location_id'] = $store;
			}
			$this->Apply('grocy_ai_receipts', (int)$receipt['id'], $header + ['extraction_json' => json_encode($suggestions, JSON_THROW_ON_ERROR), 'status' => 'needs_review']);
			$seq = 0;
			foreach (array_merge($suggestions['lines'], $suggestions['adjustments'] ?? []) as $candidate)
			{
				if (!is_array($candidate)) throw new InvalidArgumentException('Invalid extraction line');
				$line = $this->LineChange($candidate);
				$line['description'] ??= '';
				$line['kind'] ??= 'item';
				$line['decision'] = $line['kind'] === 'item' ? 'needs_review' : 'ignore';
				$columns = array_keys($line);
				$sql = 'INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, ' . implode(', ', $columns) . ') VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')';
				$this->Db->prepare($sql)->execute(array_merge([(int)$receipt['id'], ++$seq], array_values($line)));
				$this->Audit($receipt, (int)$this->Db->lastInsertId(), null, $actor, 'import_line', null, $line);
			}
			$this->Revision((int)$receipt['id']);
			$this->Audit($receipt, null, null, $actor, 'import_extraction', $before, $this->Receipt((int)$receipt['id']));
		});
	}

	public function UpdateReceipt(int $receiptId, array $change, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($change, $actor): void
		{
			$before = $receipt;
			if ($change === ['accept_difference' => true])
			{
				$totals = $this->Totals($receipt);
				if ($totals['difference'] === null || abs($totals['difference']) < 0.005) throw new InvalidArgumentException('No receipt difference to accept');
				$this->Apply('grocy_ai_receipts', (int)$receipt['id'], ['difference_accepted_amount' => $totals['difference'], 'difference_accepted_by' => $this->Actor($actor), 'difference_accepted_at' => gmdate('Y-m-d H:i:s')]);
				$action = 'accept_difference';
			}
			else
			{
				$fields = $this->HeaderChange($change);
				if ($fields === [] || count($fields) !== count($change)) throw new InvalidArgumentException('Unsupported receipt change');
				if (array_key_exists('shopping_location_id', $fields) && $fields['shopping_location_id'] != $receipt['shopping_location_id'])
				{
					$inheritedAllocations = $this->Rows('SELECT * FROM grocy_ai_receipt_allocations WHERE receipt_id = ? AND active = 1 AND shopping_location_inherited = 1', [(int)$receipt['id']]);
					foreach ($inheritedAllocations as $allocation)
					{
						if ($this->Scalar("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE allocation_id = ? AND action = 'commit_allocation'", [(int)$allocation['id']]) > 0) throw new InvalidArgumentException('Applied allocation shopping location is read only');
						$this->Apply('grocy_ai_receipt_allocations', (int)$allocation['id'], ['shopping_location_id' => $fields['shopping_location_id'], 'revision' => (int)$allocation['revision'] + 1]);
						$this->Audit($receipt, (int)$allocation['receipt_line_id'], (int)$allocation['id'], $actor, 'inherit_shopping_location', $allocation, $this->Allocation((int)$receipt['id'], (int)$allocation['receipt_line_id'], (int)$allocation['id']));
					}
				}
				$this->Apply('grocy_ai_receipts', (int)$receipt['id'], $fields + $this->Invalidation());
				$action = 'update_receipt';
			}
			$this->Revision((int)$receipt['id']);
			$this->Audit($receipt, null, null, $actor, $action, $before, $this->Receipt((int)$receipt['id']));
		});
	}

	public function AddLine(int $receiptId, array $change, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($change, $actor): void
		{
			$line = $this->LineChange($change);
			if ($line === [] || count($line) !== count($change)) throw new InvalidArgumentException('Unsupported line change');
			$line['description'] ??= '';
			$seq = (int)$this->Scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM grocy_ai_receipt_lines WHERE receipt_id = ?', [(int)$receipt['id']]);
			$columns = array_keys($line);
			$this->Db->prepare('INSERT INTO grocy_ai_receipt_lines (receipt_id, seq, ' . implode(', ', $columns) . ') VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_merge([(int)$receipt['id'], $seq], array_values($line)));
			$this->Apply('grocy_ai_receipts', (int)$receipt['id'], $this->Invalidation());
			$this->Revision((int)$receipt['id']);
			$this->Audit($receipt, (int)$this->Db->lastInsertId(), null, $actor, 'add_line', null, $line);
		});
	}

	public function UpdateLine(int $receiptId, int $lineId, array $change, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($receiptId, $lineId, $change, $actor): void
		{
			$before = $this->Line($receiptId, $lineId);
			$fields = $this->LineChange($change);
			if ($fields === [] || count($fields) !== count($change)) throw new InvalidArgumentException('Unsupported line change');
			if (($fields['decision'] ?? null) === 'ignore' && $this->Rows('SELECT id FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ? AND active = 1', [$lineId]) !== [])
			{
				throw new InvalidArgumentException('Remove allocations before ignoring a line');
			}
			$this->ValidateLineAllocations($before, array_merge($before, $fields));
			$this->Apply('grocy_ai_receipt_lines', $lineId, $fields + ['revision' => (int)$before['revision'] + 1]);
			$this->SyncResearchEvidence($receipt, $lineId);
			$this->Apply('grocy_ai_receipts', $receiptId, $this->Invalidation());
			$this->Revision($receiptId);
			$this->Audit($receipt, $lineId, null, $actor, 'update_line', $before, $this->Line($receiptId, $lineId));
		});
	}

	private function SyncResearchEvidence(array $receipt, int $lineId): void
	{
		if ($this->Scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'grocy_ai_capture_research_drafts'", []) === false) return;
		$line = $this->Line((int)$receipt['id'], $lineId);
		$usable = $line['kind'] === 'item' && $line['decision'] !== 'ignore' && trim((string)$line['description']) !== '';
		$name = $usable ? mb_substr(trim((string)$line['description']), 0, 200) : null;
		$drafts = $this->Rows("SELECT * FROM grocy_ai_capture_research_drafts WHERE trip_id = ? AND receipt_line_id = ? AND outcome NOT IN ('approved', 'linked')", [(int)$receipt['trip_id'], $lineId]);
		foreach ($drafts as $draft)
		{
			$selected = json_decode($draft['selected_json'], true, 512, JSON_THROW_ON_ERROR);
			$edits = json_decode($draft['user_edits_json'], true, 512, JSON_THROW_ON_ERROR);
			$suggested = json_decode($draft['suggested_json'], true, 512, JSON_THROW_ON_ERROR);
			if (empty($edits['name']) && ($suggested['name_candidates'] ?? []) === [])
			{
				if ($name === null) unset($selected['name']); else $selected['name'] = $name;
			}
			$this->Db->prepare('UPDATE grocy_ai_capture_research_drafts SET receipt_evidence = ?, selected_json = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$name, json_encode($selected, JSON_THROW_ON_ERROR), $draft['id']]);
			$this->Db->prepare("INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, 'receipt-review', 'receipt_correction', ?, ?)")->execute([$receipt['trip_id'], $draft['id'], json_encode(['receipt_evidence' => $draft['receipt_evidence'], 'selected' => json_decode($draft['selected_json'], true)], JSON_THROW_ON_ERROR), json_encode(['receipt_evidence' => $name, 'selected' => $selected], JSON_THROW_ON_ERROR)]);
		}
	}

	public function UpdateAllocation(int $receiptId, int $lineId, array $change, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($receiptId, $lineId, $change, $actor): void
		{
			$line = $this->Line($receiptId, $lineId);
			if ($line['decision'] !== 'include') throw new InvalidArgumentException('Only included lines accept allocations');
			$id = $change['id'] ?? null;
			foreach (['capture_line_id', 'product_id', 'shopping_location_id'] as $key) if (array_key_exists($key, $change) && $change[$key] !== null) $change[$key] = $this->PositiveId($change[$key], $key);
			if ($id !== null && (!is_int($id) || $id < 1)) throw new InvalidArgumentException('Invalid allocation ID');
			$before = $id === null ? null : $this->Allocation($receiptId, $lineId, $id);
			if ($id !== null && $this->Scalar("SELECT COUNT(*) FROM grocy_ai_receipt_audit WHERE allocation_id = ? AND action = 'commit_allocation'", [$id]) > 0) throw new InvalidArgumentException('Applied allocation is read only');
			if (($change['delete'] ?? false) === true)
			{
				if ($before === null || count($change) !== 2) throw new InvalidArgumentException('Invalid allocation delete');
				$this->Apply('grocy_ai_receipt_allocations', $id, ['active' => 0, 'revision' => (int)$before['revision'] + 1]);
				$after = null;
				$action = 'delete_allocation';
			}
			else
			{
				$fields = array_diff_key($change, array_flip(['id']));
				if ($fields === [] || array_diff(array_keys($fields), ['capture_line_id', 'product_id', 'quantity', 'unit_price', 'shopping_location_id']) !== []) throw new InvalidArgumentException('Unsupported allocation change');
				$merged = array_merge($before ?? [], $fields);
				$this->AssertResearchAllocation((int)$receipt['trip_id'], $lineId, isset($merged['capture_line_id']) ? (int)$merged['capture_line_id'] : null);
				$resolvedProductId = $this->ValidateAllocation($receipt, $line, $merged, $id);
				$fields['product_id'] = $resolvedProductId;
				$inherited = !array_key_exists('shopping_location_id', $change) ? (int)($before['shopping_location_inherited'] ?? 1) : 0;
				if ($inherited === 1) $fields['shopping_location_id'] = $receipt['shopping_location_id'];
				$fields['shopping_location_inherited'] = $inherited;
				if ($id === null)
				{
					$this->Db->prepare('INSERT INTO grocy_ai_receipt_allocations (trip_id, receipt_id, receipt_line_id, capture_line_id, product_id, quantity, unit_price, shopping_location_id, shopping_location_inherited) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([(int)$receipt['trip_id'], $receiptId, $lineId, $merged['capture_line_id'] ?? null, $resolvedProductId, $merged['quantity'], $merged['unit_price'], $fields['shopping_location_id'], $inherited]);
					$id = (int)$this->Db->lastInsertId();
				}
				else $this->Apply('grocy_ai_receipt_allocations', $id, $fields + ['revision' => (int)$before['revision'] + 1]);
				$after = $this->Allocation($receiptId, $lineId, $id);
				$action = $before === null ? 'add_allocation' : 'update_allocation';
			}
			$this->Apply('grocy_ai_receipts', $receiptId, $this->Invalidation());
			$this->Revision($receiptId);
			$this->Audit($receipt, $lineId, $action === 'delete_allocation' ? null : $id, $actor, $action, $before, $after);
		});
	}

	private function AssertResearchAllocation(int $tripId, int $receiptLineId, ?int $captureLineId): void
	{
		if ($captureLineId === null || $this->Scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'grocy_ai_capture_research_drafts'", []) === false) return;
		$conflict = $this->Scalar('SELECT 1 FROM grocy_ai_capture_research_drafts WHERE trip_id = ? AND receipt_line_id = ? AND line_id IS NOT NULL AND line_id != ? LIMIT 1', [$tripId, $receiptLineId, $captureLineId]);
		if ($conflict !== false) throw new InvalidArgumentException('Allocation conflicts with paired research evidence');
	}

	public function Finish(int $receiptId, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($receiptId, $actor): void
		{
			$issues = $this->ReceiptIssues($receipt);
			if ($issues !== []) throw new InvalidArgumentException('Receipt cannot be finished: ' . implode(', ', $issues));
			$this->Apply('grocy_ai_receipts', $receiptId, ['status' => 'finished']);
			$this->Revision($receiptId);
			$this->Audit($receipt, null, null, $actor, 'finish', $receipt, $this->Receipt($receiptId));
		});
	}

	public function Reopen(int $receiptId, ?string $actor = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($receiptId, $actor): void
		{
			if ($receipt['status'] !== 'finished') throw new InvalidArgumentException('Receipt is not finished');
			$this->Apply('grocy_ai_receipts', $receiptId, $this->Invalidation());
			$this->Revision($receiptId);
			$this->Audit($receipt, null, null, $actor, 'reopen', $receipt, $this->Receipt($receiptId));
		});
	}

	public function Readiness(int $tripId): array
	{
		$this->Trip($tripId);
		$receipts = $this->ListForTrip($tripId);
		$issues = [];
		if ($receipts === []) $issues[] = 'no_receipts';
		foreach ($receipts as $view)
		{
			$id = (int)$view['receipt']['id'];
			if ($view['receipt']['status'] !== 'finished') $issues[] = 'receipt_' . $id . '_unfinished';
			foreach ($this->ReceiptIssues($view['receipt']) as $issue) $issues[] = 'receipt_' . $id . '_' . $issue;
		}
		$captures = $this->Rows('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? ORDER BY seq', [$tripId]);
		$hasResearchDrafts = $this->Scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'grocy_ai_capture_research_drafts'", []) !== false;
		foreach ($captures as $capture)
		{
			if ((int)$capture['selected'] === 1)
			{
				$draft = $hasResearchDrafts ? $this->One('SELECT outcome, final_product_id FROM grocy_ai_capture_research_drafts WHERE trip_id = ? AND line_id = ?', [$tripId, (int)$capture['id']]) : null;
				if ($capture['status'] === 'unknown' || $capture['resolved_product_id'] === null || ($draft !== null && (!in_array($draft['outcome'], ['approved', 'linked'], true) || (int)$draft['final_product_id'] !== (int)$capture['resolved_product_id'])))
				{
					$issues[] = 'capture_line_' . $capture['id'] . '_product_review_required';
				}
			}
			$total = (float)$this->Scalar('SELECT COALESCE(SUM(quantity), 0) FROM grocy_ai_receipt_allocations WHERE active = 1 AND capture_line_id = ?', [(int)$capture['id']]);
			if ((int)$capture['selected'] === 1 && abs($total - (float)$capture['quantity']) > 0.000001) $issues[] = 'capture_line_' . $capture['id'] . '_quantity_unmatched';
			if ((int)$capture['selected'] === 0 && $total > 0) $issues[] = 'capture_line_' . $capture['id'] . '_unselected_allocated';
		}
		return ['ready' => $issues === [], 'reasons' => array_values(array_unique($issues)), 'receipts' => $receipts];
	}

	private function ReceiptIssues(array $receipt): array
	{
		$issues = [];
		$lines = $this->Rows('SELECT * FROM grocy_ai_receipt_lines WHERE receipt_id = ? ORDER BY seq', [(int)$receipt['id']]);
		if ($lines === []) $issues[] = 'no_lines';
		foreach ($lines as $line)
		{
			$id = (int)$line['id'];
			if ($line['decision'] === 'needs_review') $issues[] = 'line_' . $id . '_needs_review';
			if ($line['decision'] !== 'include') continue;
			$allocations = $this->Rows('SELECT * FROM grocy_ai_receipt_allocations WHERE active = 1 AND receipt_line_id = ?', [$id]);
			if ($allocations === []) $issues[] = 'line_' . $id . '_unallocated';
			foreach ($allocations as $allocation)
			{
				if (!$this->KnownProduct((int)($allocation['product_id'] ?: $line['product_id'] ?: 0))) $issues[] = 'line_' . $id . '_unknown_product';
				if ($allocation['capture_line_id'] !== null)
				{
					$capture = $this->One('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? AND id = ?', [(int)$receipt['trip_id'], (int)$allocation['capture_line_id']]);
					if ($capture === null || (int)$capture['selected'] !== 1 || (int)$capture['resolved_product_id'] !== (int)$allocation['product_id']) $issues[] = 'line_' . $id . '_capture_conflict';
				}
				if ((float)$allocation['quantity'] <= 0 || (float)$allocation['unit_price'] < 0) $issues[] = 'line_' . $id . '_invalid_amount';
				if ($line['product_id'] !== null && (int)$line['product_id'] !== (int)$allocation['product_id']) $issues[] = 'line_' . $id . '_product_conflict';
				if ((int)$allocation['shopping_location_inherited'] === 1 && $allocation['shopping_location_id'] != $receipt['shopping_location_id']) $issues[] = 'line_' . $id . '_store_conflict';
			}
			if ($line['quantity'] !== null && array_sum(array_map(static fn(array $allocation) => (float)$allocation['quantity'], $allocations)) > (float)$line['quantity'] + 0.000001) $issues[] = 'line_' . $id . '_quantity_overallocated';
		}
		$totals = $this->Totals($receipt);
		if ($totals['difference'] === null) $issues[] = 'printed_total_missing';
		elseif (abs($totals['difference']) >= 0.005 && ($receipt['difference_accepted_amount'] === null || abs((float)$receipt['difference_accepted_amount'] - $totals['difference']) >= 0.005)) $issues[] = 'total_difference';
		return $issues;
	}

	private function ValidateAllocation(array $receipt, array $line, array $allocation, ?int $id): int
	{
		foreach (['quantity', 'unit_price'] as $key) if (!isset($allocation[$key]) || !is_numeric($allocation[$key]) || !is_finite((float)$allocation[$key])) throw new InvalidArgumentException('Allocation amount is required');
		if ((float)$allocation['quantity'] <= 0 || (float)$allocation['unit_price'] < 0) throw new InvalidArgumentException('Allocation amount is invalid');
		$captureId = $allocation['capture_line_id'] ?? null;
		if ($captureId !== null) $captureId = $this->PositiveId($captureId, 'capture_line_id');
		$productId = $allocation['product_id'] ?? $line['product_id'];
		if ($productId !== null) $productId = $this->PositiveId($productId, 'product_id');
		if ($captureId !== null)
		{
			$capture = $this->One('SELECT * FROM grocy_ai_capture_lines WHERE trip_id = ? AND id = ?', [(int)$receipt['trip_id'], $captureId]);
			if ($capture === null || (int)$capture['selected'] !== 1) throw new InvalidArgumentException('Unknown selected capture line');
			if ($productId === null) $productId = $capture['resolved_product_id'];
			if ((int)$productId !== (int)$capture['resolved_product_id']) throw new InvalidArgumentException('Allocation product conflicts with capture line');
			$total = (float)$this->Scalar('SELECT COALESCE(SUM(quantity), 0) FROM grocy_ai_receipt_allocations WHERE active = 1 AND capture_line_id = ? AND id != ?', [$captureId, $id ?? 0]);
			if ($total + (float)$allocation['quantity'] > (float)$capture['quantity'] + 0.000001) throw new InvalidArgumentException('Allocation exceeds captured quantity');
		}
		if (!$this->KnownProduct((int)$productId)) throw new InvalidArgumentException('Allocation requires a known product');
		if ($line['product_id'] !== null && (int)$line['product_id'] !== (int)$productId) throw new InvalidArgumentException('Allocation product conflicts with receipt line');
		$lineTotal = (float)$this->Scalar('SELECT COALESCE(SUM(quantity), 0) FROM grocy_ai_receipt_allocations WHERE active = 1 AND receipt_line_id = ? AND id != ?', [(int)$line['id'], $id ?? 0]);
		if ($line['quantity'] !== null && $lineTotal + (float)$allocation['quantity'] > (float)$line['quantity'] + 0.000001) throw new InvalidArgumentException('Allocation exceeds receipt line quantity');
		return (int)$productId;
	}

	private function PositiveId(mixed $value, string $field): int
	{
		if (!is_int($value) || $value < 1) throw new InvalidArgumentException('Invalid ' . $field);
		return $value;
	}

	private function ValidateLineAllocations(array $before, array $after): void
	{
		$allocations = $this->Rows('SELECT * FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ? AND active = 1', [(int)$before['id']]);
		if ($allocations === []) return;
		if ($after['decision'] !== 'include') throw new InvalidArgumentException('Remove allocations before changing the line decision');
		$total = 0.0;
		foreach ($allocations as $allocation)
		{
			$total += (float)$allocation['quantity'];
			if ($after['product_id'] !== null && (int)$after['product_id'] !== (int)$allocation['product_id']) throw new InvalidArgumentException('Line product conflicts with allocation');
		}
		if ($after['quantity'] !== null && $total > (float)$after['quantity'] + 0.000001) throw new InvalidArgumentException('Line quantity is less than allocated quantity');
	}

	private function HeaderChange(array $input): array
	{
		$allowed = ['merchant', 'purchase_date', 'printed_total', 'currency', 'shopping_location_id'];
		$fields = array_intersect_key($input, array_flip($allowed));
		foreach ($fields as $key => $value)
		{
			if ($key === 'shopping_location_id' && $value !== null) $fields[$key] = $this->PositiveId($value, $key);
			if (in_array($key, ['printed_total', 'shopping_location_id'], true))
			{
				if ($value !== null && (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0)) throw new InvalidArgumentException('Invalid receipt amount or location');
			}
			elseif ($value !== null && (!is_string($value) || mb_strlen($value, 'UTF-8') > 255)) throw new InvalidArgumentException('Invalid receipt text');
		}
		return $fields;
	}

	private function UniqueMerchantStore(string $merchant): ?int
	{
		$name = mb_strtolower(trim($merchant), 'UTF-8');
		if ($name === '') return null;
		$matches = [];
		foreach ($this->Rows('SELECT id, name FROM shopping_locations', []) as $store)
		{
			if (mb_strtolower(trim((string)$store['name']), 'UTF-8') === $name) $matches[] = (int)$store['id'];
		}
		return count($matches) === 1 ? $matches[0] : null;
	}

	private function LineChange(array $input): array
	{
		$fields = array_intersect_key($input, array_flip(['raw_text', 'description', 'quantity', 'line_total', 'kind', 'decision', 'product_id', 'confidence']));
		foreach ($fields as $key => $value)
		{
			if ($key === 'decision' && !in_array($value, ['needs_review', 'include', 'ignore'], true)) throw new InvalidArgumentException('Invalid line decision');
			if ($key === 'kind' && !in_array($value, ['item', 'tax', 'discount', 'deposit', 'fee', 'other'], true)) throw new InvalidArgumentException('Invalid line kind');
			if (in_array($key, ['quantity', 'line_total', 'confidence'], true) && $value !== null && (!is_numeric($value) || !is_finite((float)$value) || ($key === 'quantity' && (float)$value <= 0))) throw new InvalidArgumentException('Invalid line amount');
			if ($key === 'product_id' && $value !== null && !$this->KnownProduct($this->PositiveId($value, $key))) throw new InvalidArgumentException('Unknown product');
			if ($key === 'description' && $value === null) throw new InvalidArgumentException('Description is required');
			if (in_array($key, ['description', 'raw_text'], true) && $value !== null && (!is_string($value) || strlen($value) > 1000)) throw new InvalidArgumentException('Invalid line text');
		}
		return $fields;
	}

	private function Totals(array $receipt): array
	{
		$sum = (float)$this->Scalar('SELECT COALESCE(SUM(line_total), 0) FROM grocy_ai_receipt_lines WHERE receipt_id = ?', [(int)$receipt['id']]);
		return ['entered_total' => round($sum, 2), 'printed_total' => $receipt['printed_total'] === null ? null : (float)$receipt['printed_total'], 'difference' => $receipt['printed_total'] === null ? null : round((float)$receipt['printed_total'] - $sum, 2)];
	}

	private function Mutate(int $receiptId, callable $action): array
	{
		if ($this->Db->inTransaction()) throw new RuntimeException('Receipt mutation requires its own transaction');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$receipt = $this->Receipt($receiptId);
			if ($this->Trip((int)$receipt['trip_id'])['status'] === 'committed' || $this->Scalar('SELECT 1 FROM grocy_ai_capture_trip_cancellations WHERE trip_id = ?', [(int)$receipt['trip_id']]) !== false) throw new InvalidArgumentException('Closed trip is read-only');
			$action($receipt);
			$view = $this->View($receiptId);
			$this->Db->commit();
			return $view;
		}
		catch (\Throwable $error)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $error;
		}
	}

	private function View(int $receiptId): array
	{
		$receipt = $this->Receipt($receiptId);
		$lines = $this->Rows('SELECT * FROM grocy_ai_receipt_lines WHERE receipt_id = ? ORDER BY seq', [$receiptId]);
		foreach ($lines as &$line)
		{
			$line['allocations'] = $this->Rows('SELECT * FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ? ORDER BY id', [(int)$line['id']]);
			$line += $this->CaptureCandidates((int)$receipt['trip_id'], $line);
		}
		unset($line);
		return ['receipt' => $receipt, 'lines' => $lines, 'totals' => $this->Totals($receipt), 'issues' => $this->ReceiptIssues($receipt)];
	}

	private function Invalidation(): array { return ['status' => 'needs_review', 'difference_accepted_amount' => null, 'difference_accepted_by' => null, 'difference_accepted_at' => null]; }
	private function Revision(int $id): void { $this->Db->prepare('UPDATE grocy_ai_receipts SET revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]); }
	private function Actor(?string $actor): string { return $actor === null || $actor === '' ? 'unknown' : substr($actor, 0, 255); }
	private function KnownProduct(int $id): bool { return $id > 0 && $this->Scalar('SELECT 1 FROM products WHERE id = ?', [$id]) !== false; }
	private function Receipt(int $id): array { return $this->One('SELECT * FROM grocy_ai_receipts WHERE id = ?', [$id]) ?? throw new InvalidArgumentException('Unknown receipt'); }
	private function Trip(int $id): array { return $this->One('SELECT * FROM grocy_ai_capture_trips WHERE id = ?', [$id]) ?? throw new InvalidArgumentException('Unknown trip'); }
	private function Line(int $receiptId, int $lineId): array { return $this->One('SELECT * FROM grocy_ai_receipt_lines WHERE receipt_id = ? AND id = ?', [$receiptId, $lineId]) ?? throw new InvalidArgumentException('Unknown receipt line'); }
	private function Allocation(int $receiptId, int $lineId, int $id): array { return $this->One('SELECT * FROM grocy_ai_receipt_allocations WHERE receipt_id = ? AND receipt_line_id = ? AND id = ? AND active = 1', [$receiptId, $lineId, $id]) ?? throw new InvalidArgumentException('Unknown allocation'); }
	private function One(string $sql, array $args): ?array { $rows = $this->Rows($sql, $args); return $rows[0] ?? null; }
	private function Rows(string $sql, array $args): array { $statement = $this->Db->prepare($sql); $statement->execute($args); return $statement->fetchAll(PDO::FETCH_ASSOC); }
	private function Scalar(string $sql, array $args): mixed { $statement = $this->Db->prepare($sql); $statement->execute($args); return $statement->fetchColumn(); }
	private function Apply(string $table, int $id, array $fields): void
	{
		$set = implode(', ', array_map(fn(string $key) => $key . ' = ?', array_keys($fields)));
		$this->Db->prepare('UPDATE ' . $table . ' SET ' . $set . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute(array_merge(array_values($fields), [$id]));
	}
	private function Audit(array $receipt, ?int $lineId, ?int $allocationId, ?string $actor, string $action, ?array $before, ?array $after): void
	{
		$this->Db->prepare('INSERT INTO grocy_ai_receipt_audit (trip_id, receipt_id, line_id, allocation_id, actor, action, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([(int)$receipt['trip_id'], (int)$receipt['id'], $lineId, $allocationId, $this->Actor($actor), $action, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)]);
	}
}
