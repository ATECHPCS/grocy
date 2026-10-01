<?php

namespace GrocyAI\Services;

use LessQL\Database;
use PDO;

class GrocyAiCaptureProductService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		GrocyAiCaptureResearchMigration::Bootstrap($this->Db);
	}

	public function ApproveDraft(int $tripId, int $lineId, int $revision, array $fields, string $actor): array
	{
		$required = ['name', 'location_id', 'qu_id_purchase', 'qu_id_stock'];
		$allowed = [...$required, 'product_group_id', 'taxonomy_leaf_slug', 'parent_product_id'];
		if (array_diff($required, array_keys($fields)) !== [] || array_diff(array_keys($fields), $allowed) !== []) throw new \InvalidArgumentException('Invalid approval fields');
		$name = $fields['name'];
		if (!is_string($name) || trim($name) !== $name || $name === '' || mb_strlen($name) > 200) throw new \InvalidArgumentException('Invalid product name');
		foreach (['location_id', 'qu_id_purchase', 'qu_id_stock'] as $key) if (!is_int($fields[$key]) || $fields[$key] < 1) throw new \InvalidArgumentException('Invalid product reference');
		foreach (['product_group_id', 'parent_product_id'] as $key) if (array_key_exists($key, $fields) && $fields[$key] !== null && (!is_int($fields[$key]) || $fields[$key] < 1)) throw new \InvalidArgumentException('Invalid product reference');
		$leaf = $fields['taxonomy_leaf_slug'] ?? null;
		if ($leaf !== null && (!is_string($leaf) || preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $leaf) !== 1)) throw new \InvalidArgumentException('Invalid taxonomy leaf');
		return $this->Finalize($tripId, $lineId, $revision, $actor, 'approved', function (array $line) use ($fields, $name, $leaf): int
		{
			if ($leaf !== null) GrocyAiTaxonomyMigration::Bootstrap($this->Db);
			$this->RequireActive('locations', $fields['location_id']);
			$this->RequireActive('quantity_units', $fields['qu_id_purchase']);
			$this->RequireActive('quantity_units', $fields['qu_id_stock']);
			if (isset($fields['product_group_id'])) $this->RequireActive('product_groups', $fields['product_group_id']);
			$parentStockUnit = null;
			if (isset($fields['parent_product_id']))
			{
				$parent = $this->Db->prepare('SELECT 1 FROM products WHERE id = ? AND active = 1 AND parent_product_id IS NULL');
				$parent->execute([$fields['parent_product_id']]);
				if ($parent->fetchColumn() === false) throw new \InvalidArgumentException('Invalid parent product');
				$parentUnit = $this->Db->prepare('SELECT qu_id_stock FROM products WHERE id = ?');
				$parentUnit->execute([$fields['parent_product_id']]);
				$parentStockUnit = (int)$parentUnit->fetchColumn();
			}
			$nameQuery = $this->Db->prepare('SELECT id FROM products WHERE name = ? COLLATE NOCASE LIMIT 1');
			$nameQuery->execute([$name]);
			if ($nameQuery->fetchColumn() !== false) throw new \RuntimeException('Product name already exists');
			$factor = $this->UnitFactor($fields['qu_id_purchase'], $fields['qu_id_stock']);
			$row = (new Database($this->Db))->products()->createRow([
				'name' => $name,
				'location_id' => $fields['location_id'],
				'qu_id_purchase' => $fields['qu_id_purchase'],
				'qu_id_stock' => $fields['qu_id_stock'],
				'product_group_id' => $fields['product_group_id'] ?? null,
				'parent_product_id' => $fields['parent_product_id'] ?? null
			]);
			$row->save();
			$productId = (int)$row->id;
			if ($fields['qu_id_purchase'] !== $fields['qu_id_stock'])
			{
				(new Database($this->Db))->quantity_unit_conversions()->createRow(['product_id' => $productId, 'from_qu_id' => $fields['qu_id_purchase'], 'to_qu_id' => $fields['qu_id_stock'], 'factor' => $factor])->save();
			}
			if ($parentStockUnit !== null && !$this->UnitsCompatible($productId, $parentStockUnit, $fields['qu_id_stock'])) throw new \InvalidArgumentException('Incompatible parent unit');
			$this->AttachBarcode($productId, (string)$line['scanned_barcode']);
			if ($leaf !== null)
			{
				(new GrocyAiTaxonomyService($this->Db, false))->AssignProductTaxonomy($productId, ['leaf_slug' => $leaf, 'ruleset_version' => GrocyAiTaxonomyMigration::VERSION], true);
			}
			return $productId;
		}, $fields);
	}

	public function LinkDraft(int $tripId, int $lineId, int $revision, int $productId, string $actor): array
	{
		if ($productId < 1) throw new \InvalidArgumentException('Explicit product ID required');
		return $this->Finalize($tripId, $lineId, $revision, $actor, 'linked', function (array $line) use ($productId): int
		{
			$this->RequireActive('products', $productId);
			$this->AttachBarcode($productId, (string)$line['scanned_barcode']);
			return $productId;
		}, ['product_id' => $productId]);
	}

	private function Finalize(int $tripId, int $lineId, int $revision, string $actor, string $outcome, callable $persist, array $confirmed): array
	{
		if ($tripId < 1 || $lineId < 1 || $revision < 1 || $actor === '' || $this->Db->inTransaction()) throw new \InvalidArgumentException('Invalid approval request');
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$query = $this->Db->prepare('SELECT d.*, l.scanned_barcode AS original_barcode, l.canonical_gtin, l.status AS line_status, l.selected, t.status AS trip_status FROM grocy_ai_capture_research_drafts d JOIN grocy_ai_capture_lines l ON l.id = d.line_id AND l.trip_id = d.trip_id JOIN grocy_ai_capture_trips t ON t.id = d.trip_id WHERE d.trip_id = ? AND d.line_id = ? AND NOT EXISTS (SELECT 1 FROM grocy_ai_capture_trip_cancellations c WHERE c.trip_id = t.id)');
			$query->execute([$tripId, $lineId]);
			$draft = $query->fetch(PDO::FETCH_ASSOC);
			if ($draft === false || $draft['trip_status'] === 'committed') throw new \InvalidArgumentException('Inactive capture trip');
			if (in_array($draft['outcome'], ['approved', 'linked'], true))
			{
				if ($draft['outcome'] !== $outcome || ($outcome === 'linked' && (int)$draft['final_product_id'] !== $confirmed['product_id'])) throw new \RuntimeException('Draft already finalized differently');
				$audit = $this->Db->prepare('SELECT after_json FROM grocy_ai_capture_research_audit WHERE draft_id = ? AND action = ? ORDER BY id DESC LIMIT 1');
				$audit->execute([$draft['id'], $outcome]);
				$after = json_decode((string)$audit->fetchColumn(), true);
				$original = is_array($after) ? ($after['confirmed'] ?? null) : null;
				if (!is_array($original)) throw new \RuntimeException('Approval audit unavailable');
				ksort($original);
				ksort($confirmed);
				if ($original !== $confirmed) throw new \RuntimeException('Confirmation changed');
				$result = ['product_id' => (int)$draft['final_product_id'], 'outcome' => $outcome, 'revision' => (int)$draft['revision']];
				$this->Db->commit();
				return $result;
			}
			if ((int)$draft['revision'] !== $revision || (int)$draft['selected'] !== 1 || $draft['line_status'] !== 'unknown') throw new \RuntimeException('Research draft changed');
			$barcode = (string)$draft['original_barcode'];
			$canonical = GrocyAiGtin::CanonicalOrNull($barcode);
			if ($canonical === null || $canonical !== $draft['canonical_gtin'] || $barcode !== $draft['scanned_barcode']) throw new \RuntimeException('Scanned barcode changed');
			$owner = $this->BarcodeOwner($canonical);
			if ($owner !== null && ($outcome === 'approved' || $owner !== ($confirmed['product_id'] ?? null))) throw new \RuntimeException('Barcode already owned');
			$line = ['scanned_barcode' => $barcode, 'canonical_gtin' => $canonical];
			$productId = $persist($line);
			$this->Db->prepare('UPDATE grocy_ai_capture_research_drafts SET outcome = ?, final_product_id = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$outcome, $productId, $draft['id']]);
			$this->Db->prepare('INSERT INTO grocy_ai_capture_research_audit (trip_id, draft_id, actor, action, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tripId, $draft['id'], $actor, $outcome, json_encode(['revision' => $revision, 'outcome' => $draft['outcome']], JSON_THROW_ON_ERROR), json_encode(['product_id' => $productId, 'confirmed' => $confirmed], JSON_THROW_ON_ERROR)]);
			(new GrocyAiCaptureService($this->Db, false))->ReresolveBarcode($canonical, $actor);
			$this->Db->commit();
			return ['product_id' => $productId, 'outcome' => $outcome, 'revision' => $revision + 1];
		}
		catch (\Throwable $error)
		{
			if ($this->Db->inTransaction()) $this->Db->rollBack();
			throw $error;
		}
	}

	private function RequireActive(string $table, int $id): void
	{
		$query = $this->Db->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? AND active = 1');
		$query->execute([$id]);
		if ($query->fetchColumn() === false) throw new \InvalidArgumentException('Inactive or missing product reference');
	}

	private function UnitFactor(int $purchase, int $stock): float
	{
		if ($purchase === $stock) return 1.0;
		$query = $this->Db->prepare('SELECT factor FROM quantity_unit_conversions WHERE from_qu_id = ? AND to_qu_id = ? AND product_id IS NULL AND factor > 0 LIMIT 1');
		$query->execute([$purchase, $stock]);
		$factor = $query->fetchColumn();
		if ($factor === false) throw new \InvalidArgumentException('Units need a conversion');
		return (float)$factor;
	}

	private function UnitsCompatible(int $childId, int $parentStock, int $childStock): bool
	{
		if ($parentStock === $childStock) return true;
		// Stock substitution resolves conversions against the child product.
		$query = $this->Db->prepare('SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ? AND factor > 0 LIMIT 1');
		$query->execute([$childId, $parentStock, $childStock]);
		return $query->fetchColumn() !== false;
	}

	private function BarcodeOwner(string $canonical): ?int
	{
		$query = $this->Db->prepare('SELECT product_id FROM product_barcodes WHERE ' . GrocyAiGtin::CanonicalSqlExpression('barcode') . ' = ? LIMIT 2');
		$query->execute([$canonical]);
		$rows = $query->fetchAll(PDO::FETCH_COLUMN);
		if (count($rows) > 1) throw new \RuntimeException('Canonical barcode ownership conflict');
		return $rows === [] ? null : (int)$rows[0];
	}

	private function AttachBarcode(int $productId, string $barcode): void
	{
		$canonical = GrocyAiGtin::CanonicalOrNull($barcode);
		if ($canonical === null) throw new \InvalidArgumentException('Invalid scanned barcode');
		$query = $this->Db->prepare('SELECT id, product_id, barcode FROM product_barcodes WHERE ' . GrocyAiGtin::CanonicalSqlExpression('barcode') . ' = ? LIMIT 2');
		$query->execute([$canonical]);
		$owners = $query->fetchAll(PDO::FETCH_ASSOC);
		if (count($owners) > 1 || ($owners !== [] && (int)$owners[0]['product_id'] !== $productId)) throw new \RuntimeException('Barcode already owned');
		if ($owners !== [])
		{
			// Canonical uniqueness keeps one stored spelling; native lookup resolves equivalent GTIN scans.
			return;
		}
		(new Database($this->Db))->product_barcodes()->createRow(['product_id' => $productId, 'barcode' => $barcode])->save();
	}
}
