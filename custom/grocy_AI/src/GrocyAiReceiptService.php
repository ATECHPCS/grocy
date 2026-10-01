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

	public function SuggestMatches(int $receiptId, int $lineId): array
	{
		$receipt = $this->Receipt($receiptId);
		$line = $this->Line($receiptId, $lineId);
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
		return ['capture_lines' => array_slice($captures, 0, 20), 'products' => array_slice($products, 0, 20)];
	}

	public function ImportExtraction(int $receiptId, array $suggestions, ?string $actor = null, ?int $expectedRevision = null): array
	{
		return $this->Mutate($receiptId, function (array $receipt) use ($suggestions, $actor, $expectedRevision): void
		{
			if ($expectedRevision !== null && (int)$receipt['revision'] !== $expectedRevision) throw new InvalidArgumentException('Receipt changed during OCR');
			if ($this->Scalar("SELECT 1 FROM grocy_ai_receipt_audit WHERE receipt_id = ? AND action = 'update_receipt' LIMIT 1", [(int)$receipt['id']]) !== false) throw new InvalidArgumentException('Receipt has manual header edits');
			if (!isset($suggestions['lines']) || !is_array($suggestions['lines']) || !is_array($suggestions['adjustments'] ?? []) || count($suggestions['lines']) + count($suggestions['adjustments'] ?? []) > 200)
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
			$this->Apply('grocy_ai_receipts', $receiptId, $this->Invalidation());
			$this->Revision($receiptId);
			$this->Audit($receipt, $lineId, null, $actor, 'update_line', $before, $this->Line($receiptId, $lineId));
		});
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
		foreach ($captures as $capture)
		{
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
			if ($this->Trip((int)$receipt['trip_id'])['status'] === 'committed') throw new InvalidArgumentException('Committed trip is read-only');
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
		foreach ($lines as &$line) $line['allocations'] = $this->Rows('SELECT * FROM grocy_ai_receipt_allocations WHERE receipt_line_id = ? ORDER BY id', [(int)$line['id']]);
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
