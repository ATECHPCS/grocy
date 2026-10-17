<?php

namespace GrocyAI\Services;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Durable collection operations; catalog approval and stock commit remain separate. */
final class GrocyAiShoppingCaptureService
{
	public function __construct(private PDO $Db, bool $bootstrap = true)
	{
		if ($bootstrap)
		{
			GrocyAiShoppingCaptureMigration::Bootstrap($Db);
		}
	}

	private function query(string $sql, array $args = []): \PDOStatement
	{
		$s = $this->Db->prepare($sql);
		$s->execute($args);
		return $s;
	}

	private function transaction(callable $action): mixed
	{
		if ($this->Db->inTransaction())
		{
			throw new \LogicException('Shopping operation requires its own transaction');
		}
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$v = $action();
			$this->Db->commit();
			return $v;
		}
		catch (\Throwable $e)
		{
			if ($this->Db->inTransaction())
			{
				$this->Db->rollBack();
			}
			throw $e;
		}
	}

	private function trip(int $id, string $actor, bool $mutable = false): array
	{
		$r = $this->query('SELECT t.* FROM grocy_ai_capture_trips t WHERE id=? AND created_by=? AND NOT EXISTS(SELECT 1 FROM grocy_ai_capture_trip_cancellations WHERE trip_id=t.id)', [$id, $actor])->fetch(PDO::FETCH_ASSOC);
		if (!$r)
		{
			throw new \OutOfBoundsException('Capture session not found');
		}
		if ($mutable && $r['status'] !== 'open')
		{
			throw new RuntimeException('Collection requires an open capture session');
		}
		return $r;
	}

	private function selected(int $trip): ?int
	{
		$v = $this->query('SELECT list_id FROM grocy_ai_shopping_selection WHERE trip_id=?', [$trip])->fetchColumn();
		return $v === false || $v === null ? null : (int)$v;
	}

	private function row(int $id): array
	{
		$r = $this->query('SELECT s.*,v.version,v.incarnation,p.name,p.parent_product_id,p.active,p.qu_id_purchase,qu.name AS unit FROM shopping_list s JOIN shopping_lists lists ON lists.id=s.shopping_list_id JOIN grocy_ai_shopping_versions v ON v.row_id=s.id LEFT JOIN products p ON p.id=s.product_id LEFT JOIN quantity_units qu ON qu.id=s.qu_id WHERE s.id=?', [$id])->fetch(PDO::FETCH_ASSOC);
		if (!$r)
		{
			throw new \OutOfBoundsException('Shopping entry not found');
		}
		return $r;
	}

	private function shape(array $r): string
	{
		return json_encode([(int)$r['shopping_list_id'], (int)$r['incarnation'], $r['product_id'], (float)$r['amount'], $r['qu_id']], JSON_THROW_ON_ERROR);
	}

	private function total(int $trip, array $row, ?string $except = null): float
	{
		return (float)$this->query("SELECT COALESCE(SUM(amount),0) FROM grocy_ai_shopping_operations WHERE trip_id=? AND row_id=? AND row_shape=? AND state='settled' AND kind='scan' AND operation_id != ?", [$trip, $row['id'], $this->shape($row), $except ?? ''])->fetchColumn();
	}

	public function Read(int $tripId, string $actor): array
	{
		$trip = $this->trip($tripId, $actor);
		$list = $this->selected($tripId);
		$items = [];
		if ($list !== null)
		{
			foreach ($this->query('SELECT id FROM shopping_list WHERE shopping_list_id=? ORDER BY done,id', [$list])->fetchAll(PDO::FETCH_COLUMN) as $id)
			{
				try
				{
					$r = $this->row((int)$id);
				}
				catch (\OutOfBoundsException)
				{
					continue;
				}
				$total = $this->total($tripId, $r);
				$items[] = ['id' => (int)$id, 'list_id' => (int)$r['shopping_list_id'], 'product_id' => $r['product_id'] === null ? null : (int)$r['product_id'], 'name' => $r['name'] ?? $r['note'] ?? 'Item', 'note' => $r['note'], 'amount' => (float)$r['amount'], 'qu_id' => $r['qu_id'] === null ? null : (int)$r['qu_id'], 'unit' => $r['unit'], 'done' => (bool)$r['done'], 'version' => (string)$r['version'], 'collected_amount' => $total, 'remaining_amount' => max(0, (float)$r['amount'] - $total), 'has_children' => (bool)$this->query('SELECT 1 FROM products WHERE parent_product_id=? AND active=1 LIMIT 1', [$r['product_id']])->fetchColumn()];
			}
		}
		return ['contract_version' => 1, 'selected_list_id' => $list, 'lists' => array_map(fn ($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $this->query('SELECT id,name FROM shopping_lists ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC)), 'items' => $items, 'operations' => array_map(fn ($r) => $this->dto($r), $this->query('SELECT * FROM grocy_ai_shopping_operations WHERE trip_id=? ORDER BY rowid', [$tripId])->fetchAll(PDO::FETCH_ASSOC)), 'can_change_list' => $trip['status'] === 'open'];
	}

	public function SelectList(int $tripId, ?int $listId, string $actor, bool $confirmChange = false): array
	{
		$this->transaction(function () use ($tripId, $listId, $actor, $confirmChange)
		{
			$this->trip($tripId, $actor, true);
			if ($listId !== null && ($listId < 1 || !$this->query('SELECT 1 FROM shopping_lists WHERE id=?', [$listId])->fetchColumn()))
			{
				throw new InvalidArgumentException('Shopping list unavailable');
			}
			if ($this->selected($tripId) !== $listId && !$confirmChange && $this->query('SELECT 1 FROM grocy_ai_shopping_operations WHERE trip_id=? LIMIT 1', [$tripId])->fetchColumn())
			{
				throw new RuntimeException('Confirm changing lists; previous collections remain');
			}
			$this->query('INSERT INTO grocy_ai_shopping_selection VALUES(?,?) ON CONFLICT(trip_id) DO UPDATE SET list_id=excluded.list_id', [$tripId, $listId]);
			$this->audit($tripId, null, $actor, 'select_list', ['list_id' => $listId]);
		});
		return $this->Read($tripId, $actor);
	}

	private function id(string $id): void
	{
		if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id))
		{
			throw new InvalidArgumentException('Invalid operation ID');
		}
	}

	private function raw(int $trip, string $id): array
	{
		$r = $this->query('SELECT * FROM grocy_ai_shopping_operations WHERE trip_id=? AND operation_id=?', [$trip, $id])->fetch(PDO::FETCH_ASSOC);
		if (!$r)
		{
			throw new \OutOfBoundsException('Collection operation not found');
		}
		return $r;
	}

	private function dto(array $r): array
	{
		$line = $r['line_id'] === null ? null : $this->query('SELECT * FROM grocy_ai_capture_lines WHERE id=? AND trip_id=?', [$r['line_id'], $r['trip_id']])->fetch(PDO::FETCH_ASSOC);
		if ($line === false)
		{
			$line = null;
		}
		return ['operation_id' => $r['operation_id'], 'state' => $r['state'], 'line_id' => $r['line_id'] === null ? null : (int)$r['line_id'], 'row_id' => $r['row_id'] === null ? null : (int)$r['row_id'], 'product_id' => $line['resolved_product_id'] ?? ($r['product_id'] === null ? null : (int)$r['product_id']), 'needs_amount' => (bool)$r['needs_amount'], 'error_code' => $r['error_code'], 'line' => $line];
	}

	public function Operation(int $tripId, string $actor, string $operationId): array
	{
		$this->trip($tripId, $actor);
		return $this->dto($this->raw($tripId, $operationId));
	}

	private function audit(int $trip, ?string $id, string $actor, string $action, array $result): void
	{
		$this->query('INSERT INTO grocy_ai_shopping_audit(trip_id,operation_id,actor,action,result_json) VALUES(?,?,?,?,?)', [$trip, $id, $actor, $action, json_encode($result, JSON_THROW_ON_ERROR)]);
	}

	private function insert(int $trip, string $id, string $kind, array $request): bool
	{
		$this->id($id);
		$json = json_encode($request, JSON_THROW_ON_ERROR);
		$old = $this->query('SELECT request_json FROM grocy_ai_shopping_operations WHERE trip_id=? AND operation_id=?', [$trip, $id])->fetchColumn();
		if ($old !== false)
		{
			if (!hash_equals($old, $json))
			{
				throw new RuntimeException('Operation ID reused with different input');
			}
			return false;
		}
		$this->query("INSERT INTO grocy_ai_shopping_operations(trip_id,operation_id,request_json,kind,state) VALUES(?,?,?,?,'pending_selection')", [$trip, $id, $json, $kind]);
		return true;
	}

	private function change(int $trip, string $id, array $fields): void
	{
		$allowed = ['state', 'line_id', 'row_id', 'list_id', 'product_id', 'amount', 'row_shape', 'observed_version', 'write_version', 'changed_done', 'needs_amount', 'error_code', 'settlement_json'];
		if (array_diff(array_keys($fields), $allowed))
		{
			throw new \LogicException('Unknown operation field');
		}
		$this->query('UPDATE grocy_ai_shopping_operations SET ' . implode(',', array_map(fn ($k) => $k . '=?', array_keys($fields))) . ' WHERE trip_id=? AND operation_id=?', [...array_values($fields), $trip, $id]);
	}

	private function eligible(int $product, array $r): int
	{
		if (!$r['product_id'] || !(bool)$r['active'])
		{
			return 0;
		}if ((int)$r['product_id'] === $product)
		{
			return 2;
		}
		return (int)$this->query('SELECT parent_product_id FROM products WHERE id=? AND active=1', [$product])->fetchColumn() === (int)$r['product_id'] ? 1 : 0;
	}

	public function Scan(int $tripId, string $actor, string $operationId, string $barcode, ?int $rowId, ?string $expectedVersion): array
	{
		if ($barcode === '' || strlen($barcode) > 128 || trim($barcode) !== $barcode)
		{
			throw new InvalidArgumentException('Invalid barcode');
		}
		$this->transaction(function () use ($tripId, $actor, $operationId, $barcode, $rowId, $expectedVersion)
		{
			$this->trip($tripId, $actor, true);
			if (!$this->insert($tripId, $operationId, 'scan', [$barcode, $rowId, $expectedVersion]))
			{
				return;
			}
			$list = $this->selected($tripId);
			if ($list === null)
			{
				throw new InvalidArgumentException('Select a shopping list first');
			}
			if ($rowId !== null)
			{
				$r = $this->row($rowId);
				if ((int)$r['shopping_list_id'] !== $list)
				{
					throw new InvalidArgumentException('Target is not on selected list');
				}
			}
			$line = (new GrocyAiCaptureService($this->Db, false))->ScanIntoTrip($tripId, $barcode, $actor, $rowId !== null);
			$this->change($tripId, $operationId, ['line_id' => $line['id'], 'list_id' => $list, 'row_id' => $rowId, 'product_id' => $line['resolved_product_id']]);
			if ($line['status'] !== 'known')
			{
				$this->change($tripId, $operationId, ['state' => 'awaiting_curation']);
				return;
			}
			if ($rowId === null)
			{
				$matches = [];
				$priority = 0;
				foreach ($this->query('SELECT id FROM shopping_list WHERE shopping_list_id=?', [$list])->fetchAll(PDO::FETCH_COLUMN) as $candidate)
				{
					$row = $this->row((int)$candidate);
					$p = $this->eligible($line['resolved_product_id'], $row);
					if ($p > $priority)
					{
						$matches = [];
						$priority = $p;
					}if ($p && $p === $priority)
					{
						$matches[] = $row;
					}
				}
				if (count($matches) !== 1)
				{
					$this->change($tripId, $operationId, ['state' => 'pending_selection', 'error_code' => count($matches) ? 'choose_entry' : 'not_on_list']);
					return;
				}
				$r = $matches[0];
				$rowId = (int)$r['id'];
				$expectedVersion = (string)$r['version'];
			}
			$this->settleInternal($tripId, $operationId, $rowId, $expectedVersion ?? '', null);
			$this->audit($tripId, $operationId, $actor, 'scan', $this->raw($tripId, $operationId));
		});
		return $this->Operation($tripId, $actor, $operationId);
	}

	public function Settle(int $tripId, string $actor, string $operationId, int $rowId, string $expectedVersion, ?float $listAmount = null): array
	{
		if ($listAmount !== null && (!is_finite($listAmount) || $listAmount <= 0))
		{
			throw new InvalidArgumentException('Enter a positive collected amount');
		}
		$this->transaction(function () use ($tripId, $actor, $operationId, $rowId, $expectedVersion, $listAmount)
		{
			$this->trip($tripId, $actor, true);
			$op = $this->raw($tripId, $operationId);
			if ($op['kind'] !== 'scan')
			{
				throw new InvalidArgumentException('Not a scan operation');
			}
			if ($op['state'] === 'undone')
			{
				throw new RuntimeException('Collection was undone');
			}
			if ($op['state'] === 'settled')
			{
				if ($op['settlement_json'] !== json_encode([$rowId, $expectedVersion, $listAmount], JSON_THROW_ON_ERROR))
				{
					throw new RuntimeException('Collection already settled differently');
				}
				return;
			}
			$this->settleInternal($tripId, $operationId, $rowId, $expectedVersion, $listAmount);
			$this->audit($tripId, $operationId, $actor, 'settle', $this->raw($tripId, $operationId));
		});
		return $this->Operation($tripId, $actor, $operationId);
	}

	private function factor(int $product, int $from, int $to): ?float
	{
		if ($from === $to && $from > 0)
		{
			return 1.0;
		}if ($from < 1 || $to < 1)
		{
			return null;
		}
		$values = $this->query('SELECT DISTINCT factor FROM quantity_unit_conversions_resolved WHERE product_id=? AND from_qu_id=? AND to_qu_id=?', [$product, $from, $to])->fetchAll(PDO::FETCH_COLUMN);
		if (count($values) !== 1 || !is_numeric($values[0]) || !is_finite((float)$values[0]) || (float)$values[0] <= 0)
		{
			return null;
		}
		return (float)$values[0];
	}

	private function scanAmount(array $line, array $row): ?float
	{
		$product = $this->query('SELECT qu_id_purchase,qu_id_stock FROM products WHERE id=? AND active=1', [$line['resolved_product_id']])->fetch(PDO::FETCH_ASSOC);
		if (!$product)
		{
			return null;
		}
		$canonical = GrocyAiGtin::CanonicalOrNull($line['scanned_barcode']);
		$override = $this->query('SELECT amount FROM product_barcodes WHERE product_id=? AND ' . GrocyAiGtin::CanonicalSqlExpression('barcode') . '=?', [$line['resolved_product_id'], $canonical])->fetchColumn();
		if ($override !== false && $override !== null)
		{
			$factor = $this->factor((int)$line['resolved_product_id'], (int)$product['qu_id_stock'], (int)$row['qu_id']);
			return $factor !== null && is_numeric($override) && is_finite((float)$override) && (float)$override > 0 ? $factor * (float)$override : null;
		}
		return $this->factor((int)$line['resolved_product_id'], (int)$product['qu_id_purchase'], (int)$row['qu_id']);
	}

	private function settleInternal(int $trip, string $id, int $rowId, string $version, ?float $amount): void
	{
		$requestedAmount = $amount;
		$op = $this->raw($trip, $id);
		$r = $this->row($rowId);
		if ((int)$r['shopping_list_id'] !== (int)$op['list_id'])
		{
			throw new InvalidArgumentException('Entry is not on the scan list');
		}
		$line = $this->query('SELECT * FROM grocy_ai_capture_lines WHERE trip_id=? AND id=?', [$trip, $op['line_id']])->fetch(PDO::FETCH_ASSOC);
		if (!$line || !(bool)$line['selected'])
		{
			throw new RuntimeException('Capture line changed');
		}
		// Re-resolve through the service after catalog approval.
		$loaded = (new GrocyAiCaptureService($this->Db, false))->LoadTrip($trip);
		foreach ($loaded['lines'] as $l)
		{
			if ($l['id'] === (int)$op['line_id'])
			{
				$line = $l;
			}
		}
		$product = $line['resolved_product_id'];
		$code = (string)$r['version'] !== $version ? 'entry_changed' : (!$product ? 'needs_curation' : ($r['product_id'] !== null && !$this->eligible($product, $r) ? 'product_mismatch' : null));
		$this->change($trip, $id, ['row_id' => $rowId, 'product_id' => $product, 'needs_amount' => 0]);
		if ($code !== null)
		{
			$this->change($trip, $id, ['state' => $code === 'needs_curation' ? 'awaiting_curation' : 'conflicted', 'error_code' => $code]);
			return;
		}
		$purchase = (int)$this->query('SELECT qu_id_purchase FROM products WHERE id=? AND active=1', [$product])->fetchColumn();
		if ($amount === null)
		{
			$amount = $this->scanAmount($line, $r);
		}
		if ($amount === null || !is_finite($amount) || $amount <= 0 || ($requestedAmount === null && (!is_finite((float)$r['amount']) || (float)$r['amount'] <= 0)))
		{
			$this->change($trip, $id, ['state' => 'pending_amount', 'needs_amount' => 1, 'error_code' => 'confirm_amount']);
			return;
		}
		$total = $this->total($trip, $r) + $amount;
		$changed = !(bool)$r['done'] && ($total + 1e-9 >= (float)$r['amount'] || $requestedAmount !== null && (float)$r['amount'] <= 0);
		if ($changed)
		{
			$this->query('UPDATE shopping_list SET done=1 WHERE id=?', [$rowId]);
		}
		$current = $this->row($rowId);
		$this->change($trip, $id, ['state' => 'settled', 'error_code' => null, 'amount' => $amount, 'row_shape' => $this->shape($r), 'observed_version' => $version, 'write_version' => $changed ? (string)$current['version'] : null, 'changed_done' => (int)$changed, 'settlement_json' => json_encode([$rowId, $version, $requestedAmount], JSON_THROW_ON_ERROR)]);
	}

	public function SetDone(int $tripId, string $actor, string $operationId, int $rowId, string $expectedVersion, bool $done): array
	{
		$this->transaction(function () use ($tripId, $actor, $operationId, $rowId, $expectedVersion, $done)
		{
			$this->trip($tripId, $actor, true);
			if (!$this->insert($tripId, $operationId, 'manual', [$rowId, $expectedVersion, $done]))
			{
				return;
			}
			$r = $this->row($rowId);
			if ((int)$r['shopping_list_id'] !== $this->selected($tripId) || (string)$r['version'] !== $expectedVersion)
			{
				throw new RuntimeException('Shopping entry changed');
			}
			$changed = (bool)$r['done'] !== $done;
			if ($changed)
			{
				$this->query('UPDATE shopping_list SET done=? WHERE id=?', [(int)$done, $rowId]);
			}
			$this->change($tripId, $operationId, ['state' => 'settled', 'row_id' => $rowId, 'list_id' => (int)$r['shopping_list_id'], 'observed_version' => $expectedVersion, 'write_version' => $changed ? (string)$this->row($rowId)['version'] : null, 'changed_done' => (int)$changed, 'amount' => $done ? 1 : 0]);
			$this->audit($tripId, $operationId, $actor, 'manual_done', [$rowId, $done]);
		});
		return $this->Operation($tripId, $actor, $operationId);
	}

	public function Undo(int $tripId, string $actor, string $operationId): array
	{
		$this->transaction(function () use ($tripId, $actor, $operationId)
		{
			$this->trip($tripId, $actor, true);
			$op = $this->raw($tripId, $operationId);
			if ($op['state'] === 'undone')
			{
				return;
			}
			$r = $op['row_id'] === null ? null : $this->row((int)$op['row_id']);
			if ($r && $op['state'] === 'settled')
			{
				if ($op['row_shape'] !== null && $op['row_shape'] !== $this->shape($r))
				{
					throw new RuntimeException('Shopping entry was replaced or its quantity changed');
				}
				if ($op['kind'] === 'manual' && (string)$r['version'] !== (string)($op['write_version'] ?? $op['observed_version']))
				{
					throw new RuntimeException('Shopping entry changed after manual collection');
				}
				$latest = (string)$this->query('SELECT MAX(CAST(COALESCE(write_version,observed_version) AS INTEGER)) FROM grocy_ai_shopping_operations WHERE trip_id=? AND row_id=? AND state IN (\'settled\',\'undone\')', [$tripId, $r['id']])->fetchColumn();
				if ((string)$r['version'] !== $latest)
				{
					throw new RuntimeException('Shopping entry changed after collection; refresh before editing');
				}
			}
			if ($op['kind'] === 'scan' && $op['line_id'] !== null)
			{
				$line = $this->query('SELECT * FROM grocy_ai_capture_lines WHERE id=? AND trip_id=?', [$op['line_id'], $tripId])->fetch(PDO::FETCH_ASSOC);
				$count = (int)$this->query("SELECT COUNT(*) FROM grocy_ai_shopping_operations WHERE trip_id=? AND line_id=? AND kind='scan' AND state!='undone'", [$tripId, $op['line_id']])->fetchColumn();
				if (!$line || (float)$line['quantity'] !== (float)$count || $this->query('SELECT 1 FROM grocy_ai_receipt_allocations WHERE capture_line_id=? LIMIT 1', [$op['line_id']])->fetchColumn())
				{
					throw new RuntimeException('Capture line edited or has receipt history; use review controls');
				}
				(new GrocyAiCaptureService($this->Db, false))->UpdateLine($tripId, (int)$line['seq'], $count === 1 ? ['delete' => true] : ['quantity' => (float)$line['quantity'] - 1], $actor);
			}
			if ($r && (($op['kind'] === 'manual' && (bool)$op['changed_done']) || ($op['kind'] === 'scan' && (bool)$r['done'] && $this->total($tripId, $r, $operationId) + 1e-9 < (float)$r['amount'] && $this->query('SELECT 1 FROM grocy_ai_shopping_operations WHERE trip_id=? AND row_id=? AND changed_done=1 AND write_version=? AND kind=\'scan\' LIMIT 1', [$tripId, $r['id'], (string)$r['version']])->fetchColumn())))
			{
				$this->query('UPDATE shopping_list SET done=? WHERE id=?', [$op['kind'] === 'manual' ? (1 - (int)$op['amount']) : 0, $r['id']]);
				$this->change($tripId, $operationId, ['write_version' => (string)$this->row((int)$r['id'])['version']]);
			}
			$this->change($tripId, $operationId, ['state' => 'undone']);
			$this->audit($tripId, $operationId, $actor, 'undo', ['state' => 'undone']);
		});
		return $this->Operation($tripId, $actor, $operationId);
	}

	public function CurationOptions(int $tripId, string $actor, string $operationId): array
	{
		$this->trip($tripId, $actor, true);
		$op = $this->raw($tripId, $operationId);
		$row = $op['row_id'] === null ? null : $this->row((int)$op['row_id']);
		if (!$row || $op['kind'] !== 'scan' || $op['state'] === 'undone')
		{
			throw new RuntimeException('Choose a target before curating');
		}
		$draft = $this->query('SELECT revision,outcome FROM grocy_ai_capture_research_drafts WHERE trip_id=? AND line_id=?', [$tripId, $op['line_id']])->fetch(PDO::FETCH_ASSOC);
		if (!$draft)
		{
			throw new RuntimeException('This scan has no curatable UPC');
		}
		$products = $this->query('SELECT id,name,parent_product_id,qu_id_purchase,qu_id_stock FROM products WHERE active=1 AND (? IS NULL OR id=? OR parent_product_id=?) ORDER BY name,id', [$row['product_id'], $row['product_id'], $row['product_id']])->fetchAll(PDO::FETCH_ASSOC);
		foreach ($products as &$p)
		{
			foreach (['id', 'parent_product_id', 'qu_id_purchase', 'qu_id_stock'] as $k)
			{
				$p[$k] = $p[$k] === null ? null : (int)$p[$k];
			}
		}unset($p);
		$catalog = (new GrocyAiCaptureResearchService($this->Db, false))->ReviewOptions();
		$pairs = fn ($table) => array_map(fn ($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $this->query('SELECT id,name FROM ' . $table . ' WHERE active=1 ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC));
		return ['revision' => (int)$draft['revision'], 'products' => $products, 'locations' => $pairs('locations'), 'quantity_units' => $pairs('quantity_units'), 'product_groups' => $catalog['product_groups'], 'taxonomy_leaves' => $catalog['taxonomy_leaves']];
	}

	public function Curate(int $tripId, string $actor, string $operationId, string $version, string $mode, int $revision, ?int $productId, ?array $fields): array
	{
		$this->trip($tripId, $actor, true);
		$op = $this->raw($tripId, $operationId);
		if ($op['kind'] !== 'scan' || $op['row_id'] === null || $op['line_id'] === null || $op['state'] === 'undone')
		{
			throw new RuntimeException('Choose a target before curating');
		}
		$guard = function () use ($tripId, $actor, $operationId, $version, $mode, $productId, $fields)
		{
			$this->trip($tripId, $actor, true);
			$current = $this->raw($tripId, $operationId);
			if ($current['state'] === 'undone')
			{
				throw new RuntimeException('Collection was undone');
			}
			$draft = $this->query('SELECT outcome FROM grocy_ai_capture_research_drafts WHERE trip_id=? AND line_id=?', [$tripId, $current['line_id']])->fetchColumn();
			if (in_array($draft, ['linked', 'approved'], true))
			{
				return;
			} // Finalize verifies the exact original confirmation on replay.
			$row = $this->row((int)$current['row_id']);
			if ((string)$row['version'] !== $version || (int)$row['shopping_list_id'] !== (int)$current['list_id'])
			{
				throw new RuntimeException('Shopping entry changed; refresh before approving');
			}
			if ($mode === 'link')
			{
				if (!$productId || ($row['product_id'] !== null && !$this->eligible($productId, $row)))
				{
					throw new InvalidArgumentException('Choose this product or one of its branded children');
				}
				if ($row['product_id'] !== null && (int)$row['product_id'] === $productId && $this->query('SELECT 1 FROM products WHERE parent_product_id=? LIMIT 1', [$productId])->fetchColumn())
				{
					throw new InvalidArgumentException('Choose a branded child of this generic product');
				}
			}
			elseif ($mode === 'create')
			{
				if (!$row['product_id'] || ($fields['parent_product_id'] ?? null) !== (int)$row['product_id'] || ($fields['parent_mode'] ?? null) !== 'existing')
				{
					throw new InvalidArgumentException('New branded product must use the listed parent');
				}
			}
			else
			{
				throw new InvalidArgumentException('Invalid curation mode');
			}
		};
		$service = new GrocyAiCaptureProductService($this->Db, $guard);
		$result = $mode === 'link' ? $service->LinkDraft($tripId, (int)$op['line_id'], $revision, $productId ?? 0, $actor) : $service->ApproveDraft($tripId, (int)$op['line_id'], $revision, $fields ?? [], $actor);
		return ['catalog' => $result, 'operation' => $this->Operation($tripId, $actor, $operationId)];
	}
}
