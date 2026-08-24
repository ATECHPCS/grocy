<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiConversionService
{
	private const RELATIVE_TOLERANCE = 0.000000000001;
	private PDO $Db;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiConversionMigration::Bootstrap($this->Db);
		}
	}

	public function ValidateNativeConversionBeforeWrite(array $candidate, ?int $objectId): array
	{
		$productId = $this->PositiveInteger($candidate['product_id'] ?? null);
		$scope = $productId === null ? 'reusable' : 'product';
		$factor = $this->Factor($candidate['factor'] ?? null);
		if ($factor === null)
		{
			return $this->Blocked($scope, 'factor_not_finite');
		}
		if ($factor <= 0)
		{
			return $this->Blocked($scope, 'factor_non_positive');
		}

		$units = $this->CandidateUnits($candidate);
		if ($scope === 'product')
		{
			return $this->Dto('product_native', 'product', [], $this->CandidateFactor($candidate), 'product_scoped');
		}

		if ($units === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}
		if ($this->IsCountUnit($units['from_name']) || $this->IsCountUnit($units['to_name']))
		{
			return $this->Blocked('reusable', 'reusable_count_scope');
		}
		if ($units['from_key'] === null || $units['to_key'] === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}

		$catalog = $this->Catalog();
		$from = $catalog[$units['from_key']] ?? null;
		$to = $catalog[$units['to_key']] ?? null;
		if ($from === null || $to === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}
		if ($from['dimension'] !== $to['dimension'])
		{
			return $this->Blocked('reusable', 'dimension_mismatch');
		}
		if (($candidate['inactive_revision_id'] ?? GrocyAiConversionMigration::INACTIVE_REVISION_ID) !== GrocyAiConversionMigration::INACTIVE_REVISION_ID)
		{
			return $this->Blocked('reusable', 'stale_revision_identity');
		}
		if (($candidate['source_version'] ?? GrocyAiConversionMigration::SOURCE_VERSION) !== GrocyAiConversionMigration::SOURCE_VERSION)
		{
			return $this->Blocked('reusable', 'stale_source_version');
		}

		$graphBlocker = $this->ValidateCatalogGraph($catalog);
		if ($graphBlocker !== null)
		{
			return $this->Blocked('reusable', $graphBlocker);
		}
		$expected = $from['metric_factor'] / $to['metric_factor'];
		if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
		{
			return $this->Blocked('reusable', 'factor_tolerance');
		}

		return $this->Dto('inactive', 'reusable', [], $this->CandidateFactor($candidate), $from['dimension']);
	}

	private function CandidateUnits(array $candidate): ?array
	{
		$fromId = $this->PositiveInteger($candidate['from_qu_id'] ?? null);
		$toId = $this->PositiveInteger($candidate['to_qu_id'] ?? null);
		if ($fromId === null || $toId === null)
		{
			return null;
		}
		$statement = $this->Db->prepare('SELECT id, name FROM quantity_units WHERE id IN (?, ?)');
		$statement->execute([$fromId, $toId]);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (count($rows) !== 2)
		{
			return null;
		}
		$byId = [];
		foreach ($rows as $row)
		{
			$byId[(int)$row['id']] = (string)$row['name'];
		}
		if (!isset($byId[$fromId], $byId[$toId]))
		{
			return null;
		}
		return [
			'from_name' => $byId[$fromId],
			'to_name' => $byId[$toId],
			'from_key' => $this->UnitKey($byId[$fromId]),
			'to_key' => $this->UnitKey($byId[$toId])
		];
	}

	private function Catalog(): array
	{
		$rows = $this->Db->query('SELECT unit_key, dimension, metric_factor FROM grocy_ai_conversion_catalog_units')->fetchAll(PDO::FETCH_ASSOC);
		$catalog = [];
		foreach ($rows as $row)
		{
			$factor = $this->Factor($row['metric_factor']);
			if ($factor === null || $factor <= 0)
			{
				continue;
			}
			$catalog[(string)$row['unit_key']] = ['dimension' => (string)$row['dimension'], 'metric_factor' => $factor];
		}
		return $catalog;
	}

	private function ValidateCatalogGraph(array $catalog): ?string
	{
		$statement = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$edges = [];
		$direct = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rule)
		{
			$from = (string)$rule['from_unit_key'];
			$to = (string)$rule['to_unit_key'];
			$factor = $this->Factor($rule['factor']);
			if ($factor === null || $factor <= 0 || !isset($catalog[$from], $catalog[$to]) || $catalog[$from]['dimension'] !== $catalog[$to]['dimension']
				|| $this->RelativeDifference($factor, $catalog[$from]['metric_factor'] / $catalog[$to]['metric_factor']) > self::RELATIVE_TOLERANCE)
			{
				return 'catalog_rule_invalid';
			}
			$key = $from . '>' . $to;
			if (isset($direct[$key]) && $this->RelativeDifference($direct[$key], $factor) > self::RELATIVE_TOLERANCE)
			{
				return 'competing_path';
			}
			$reverseKey = $to . '>' . $from;
			if (isset($direct[$reverseKey]) && $this->RelativeDifference($direct[$reverseKey] * $factor, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return 'reciprocal_mismatch';
			}
			$direct[$key] = $factor;
			$edges[$from][] = $to;
			$edges[$to][] = $from;
		}

		$visited = [];
		foreach (array_keys($edges) as $node)
		{
			if ($this->HasCycle($node, null, $edges, $visited))
			{
				return 'cycle_detected';
			}
		}
		return null;
	}

	private function HasCycle(string $node, ?string $parent, array $edges, array &$visited): bool
	{
		if (isset($visited[$node]))
		{
			return false;
		}
		$visited[$node] = true;
		foreach ($edges[$node] ?? [] as $neighbor)
		{
			if ($neighbor === $parent)
			{
				continue;
			}
			if (isset($visited[$neighbor]) || $this->HasCycle($neighbor, $node, $edges, $visited))
			{
				return true;
			}
		}
		return false;
	}

	private function Dto(string $status, string $scope, array $blockers, ?string $factor, ?string $dimension): array
	{
		return [
			'status' => $status,
			'scope' => $scope,
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'source_version' => GrocyAiConversionMigration::SOURCE_VERSION,
			'inactive_revision_id' => GrocyAiConversionMigration::INACTIVE_REVISION_ID
		];
	}

	private function Blocked(string $scope, string $blocker): array
	{
		return $this->Dto('blocked', $scope, [$blocker], null, null);
	}

	private function Factor(mixed $value): ?float
	{
		if (!is_string($value) && !is_int($value) && !is_float($value))
		{
			return null;
		}
		$raw = trim((string)$value);
		if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $raw) !== 1)
		{
			return null;
		}
		$factor = (float)$raw;
		return is_finite($factor) ? $factor : null;
	}

	private function CandidateFactor(array $candidate): string
	{
		return trim((string)$candidate['factor']);
	}

	private function PositiveInteger(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}
		if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1)
		{
			return (int)$value;
		}
		return null;
	}

	private function UnitKey(string $name): ?string
	{
		$normalized = strtolower(trim($name));
		$normalized = str_replace(['-', '_'], ' ', $normalized);
		$normalized = (string)preg_replace('/\s+/', ' ', $normalized);
		return [
			'mg' => 'mg', 'milligram' => 'mg', 'milligrams' => 'mg',
			'g' => 'g', 'gram' => 'g', 'grams' => 'g',
			'kg' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
			'oz' => 'oz', 'ounce' => 'oz', 'ounces' => 'oz',
			'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
			'ml' => 'ml', 'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
			'l' => 'l', 'liter' => 'l', 'liters' => 'l', 'litre' => 'l', 'litres' => 'l',
			'tsp' => 'tsp', 'teaspoon' => 'tsp', 'teaspoons' => 'tsp',
			'tbsp' => 'tbsp', 'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp',
			'cup' => 'cup', 'cups' => 'cup',
			'fl oz' => 'fl_oz', 'fluid ounce' => 'fl_oz', 'fluid ounces' => 'fl_oz',
			'pint' => 'pint', 'pints' => 'pint', 'quart' => 'quart', 'quarts' => 'quart', 'gallon' => 'gallon', 'gallons' => 'gallon'
		][$normalized] ?? null;
	}

	private function IsCountUnit(string $name): bool
	{
		$normalized = strtolower(trim($name));
		return preg_match('/^(?:pack|packs|can|cans|bottle|bottles|piece|pieces|count|counts)$/D', $normalized) === 1;
	}

	private function RelativeDifference(float $actual, float $expected): float
	{
		return abs($actual - $expected) / max(abs($expected), 1.0);
	}
}
