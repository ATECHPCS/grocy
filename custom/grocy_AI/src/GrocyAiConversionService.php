<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiConversionService
{
	private const RELATIVE_TOLERANCE = 0.000000000001;
	private const SOURCED_PROFILE_RECORDS = [
		'water-like-beverage' => ['leaf-beverages', 'cup', 'g', '237', '174158', '1 cup = 237 g'],
		'whole-milk' => ['leaf-dairy-eggs', 'cup', 'g', '244', '171265', '1 cup = 244 g'],
		'olive-oil' => ['leaf-oils-vinegars', 'tbsp', 'g', '13.5', '171413', '1 tablespoon = 13.5 g']
	];
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

		$revision = $this->InactiveRevision();
		if ($revision === null)
		{
			return $this->Blocked('reusable', 'inactive_revision_unavailable', null);
		}
		$sourceVersion = (string)$revision['source_version'];
		if ($sourceVersion !== GrocyAiConversionMigration::SOURCE_VERSION)
		{
			return $this->Blocked('reusable', 'revision_source_version_invalid', $sourceVersion);
		}

		$catalog = $this->Catalog();
		foreach ($catalog as $unit)
		{
			if ($unit['source_version'] !== $sourceVersion)
			{
				return $this->Blocked('reusable', 'catalog_source_version_invalid', $sourceVersion);
			}
		}
		$from = $catalog[$units['from_key']] ?? null;
		$to = $catalog[$units['to_key']] ?? null;
		if ($from === null || $to === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}
		if ($from['metric_factor'] === null || $from['metric_factor'] <= 0 || $to['metric_factor'] === null || $to['metric_factor'] <= 0)
		{
			return $this->Blocked('reusable', 'catalog_unit_invalid', $sourceVersion);
		}
		if ($from['dimension'] !== $to['dimension'])
		{
			return $this->Blocked('reusable', 'dimension_mismatch');
		}
		if (($candidate['inactive_revision_id'] ?? GrocyAiConversionMigration::INACTIVE_REVISION_ID) !== GrocyAiConversionMigration::INACTIVE_REVISION_ID)
		{
			return $this->Blocked('reusable', 'stale_revision_identity');
		}
		if (($candidate['source_version'] ?? $sourceVersion) !== $sourceVersion)
		{
			return $this->Blocked('reusable', 'stale_source_version', $sourceVersion);
		}

		$graphBlocker = $this->ValidateCatalogGraph($catalog, $sourceVersion);
		if ($graphBlocker !== null)
		{
			return $this->Blocked('reusable', $graphBlocker, $sourceVersion);
		}
		$expected = $from['metric_factor'] / $to['metric_factor'];
		if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
		{
			return $this->Blocked('reusable', 'factor_tolerance', $sourceVersion);
		}

		return $this->Dto('inactive', 'reusable', [], $this->CandidateFactor($candidate), $from['dimension'], $sourceVersion);
	}

	public function InspectSourcedProfile(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		if ($productId < 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $fromUnitKey) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $toUnitKey) !== 1)
		{
			return $this->ProfileUnavailable('profile_request_invalid');
		}

		if (!$this->TaxonomyClassificationsRelationAvailable())
		{
			return $this->ProfileUnavailable('taxonomy_unavailable');
		}
		$classification = $this->Db->prepare('SELECT node.id, node.slug FROM grocy_ai_taxonomy_classifications AS classification INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND classification.leaf_id IS NOT NULL AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2');
		$classification->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $classification->fetch(PDO::FETCH_ASSOC);
		if (!is_array($leaf))
		{
			return $this->ProfileUnavailable('explicit_taxonomy_required');
		}
		$leafSlug = (string)$leaf['slug'];
		if (preg_match('/baby|pet|frozen|preserved/i', $leafSlug) === 1)
		{
			return $this->ProfileUnavailable('taxonomy_leaf_excluded');
		}

		$profileStatement = $this->Db->prepare('SELECT profile.profile_key, profile.factor, profile.approximate, profile.source_name, profile.source_item_id, profile.source_version, profile.source_basis, profile.status, revision.status AS revision_status, revision.version AS revision_version FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_conversion_profile_revisions AS revision ON revision.id = profile.revision_id WHERE profile.revision_id = ? AND profile.taxonomy_leaf_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$profileStatement->execute([GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, (string)$leaf['id'], $fromUnitKey, $toUnitKey]);
		$profile = $profileStatement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($profile))
		{
			return $this->ProfileUnavailable('profile_unavailable');
		}

		$expected = self::SOURCED_PROFILE_RECORDS[(string)$profile['profile_key']] ?? null;
		$factor = $this->Factor($profile['factor']);
		if ($expected === null
			|| $expected !== [(string)$leaf['id'], $fromUnitKey, $toUnitKey, (string)$profile['factor'], (string)$profile['source_item_id'], (string)$profile['source_basis']]
			|| $factor === null || $factor <= 0 || (int)$profile['approximate'] !== 1 || $profile['status'] !== 'inactive'
			|| $profile['revision_status'] !== 'inactive' || $profile['revision_version'] !== GrocyAiConversionMigration::VERSION
			|| $profile['source_name'] !== GrocyAiConversionMigration::PROFILE_SOURCE_NAME
			|| $profile['source_version'] !== GrocyAiConversionMigration::PROFILE_SOURCE_VERSION
			|| preg_match('/^[1-9][0-9]{0,9}$/D', (string)$profile['source_item_id']) !== 1
			|| trim((string)$profile['source_basis']) === '')
		{
			return $this->ProfileUnavailable('profile_invalid');
		}

		return $this->ProfileDto(
			'inactive', [], trim((string)$profile['factor']), 'mass_volume', (string)$profile['profile_key'], $leafSlug,
			(string)$profile['source_name'], (string)$profile['source_item_id'], (string)$profile['source_version'], (string)$profile['source_basis']
		);
	}

	public function InspectConversionResolution(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		if ($productId < 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $fromUnitKey) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $toUnitKey) !== 1)
		{
			return $this->InspectionUnavailable('resolution_request_invalid');
		}

		$productGraph = $this->InspectProductGraph($productId, $fromUnitKey, $toUnitKey);
		if ($productGraph['blocker'] !== null)
		{
			return $this->InspectionBlocked($productGraph['blocker']);
		}
		if ($productGraph['candidate'] !== null)
		{
			$candidate = $productGraph['candidate'];
			$fromDimension = $this->UnitDimension($fromUnitKey);
			$toDimension = $this->UnitDimension($toUnitKey);
			$dimension = $fromDimension !== null && $fromDimension === $toDimension ? $fromDimension : 'product_scoped';
			return $this->InspectionDto(
				'product_native', [], $candidate['factor_raw'], $dimension, false, 'product_override',
				'Grocy native product conversion', null, 'native', null, null, null, null
			);
		}

		if (!$this->TaxonomyClassificationsRelationAvailable())
		{
			return $this->InspectionUnavailable('taxonomy_unavailable');
		}
		if ($this->EligibleProfileCount($productId, $fromUnitKey, $toUnitKey) > 1)
		{
			return $this->InspectionBlocked('same_rank_collision');
		}
		$profile = $this->InspectSourcedProfile($productId, $fromUnitKey, $toUnitKey);
		if ($profile['status'] === 'inactive')
		{
			return $this->InspectionDto(
				'inactive', [], $profile['factor'], $profile['dimension'], true, 'food_profile',
				$profile['source_name'], $profile['source_version'], 'inactive', $profile['source_item_id'],
				$profile['profile_key'], $profile['taxonomy_leaf'], $profile['inactive_revision_id']
			);
		}
		if (($profile['blockers'][0] ?? null) === 'profile_invalid')
		{
			return $this->InspectionBlocked($this->InvalidProfileBlocker($productId, $fromUnitKey, $toUnitKey));
		}

		$catalog = $this->Catalog();
		$from = $catalog[$fromUnitKey] ?? null;
		$to = $catalog[$toUnitKey] ?? null;
		if ($from !== null && $to !== null && $from['dimension'] === $to['dimension'])
		{
			$universalBlocker = $this->ValidateInspectionUniversalGraph($catalog);
			if ($universalBlocker !== null)
			{
				return $this->InspectionBlocked($universalBlocker);
			}
			$factor = $from['metric_factor'] / $to['metric_factor'];
			return $this->InspectionDto(
				'inactive', [], (string)$factor, $from['dimension'], false, 'universal', 'NIST SP 811',
				GrocyAiConversionMigration::SOURCE_VERSION, 'inactive', null, null, null, GrocyAiConversionMigration::INACTIVE_REVISION_ID
			);
		}

		return $this->InspectionUnavailable((string)($profile['blockers'][0] ?? 'conversion_unavailable'));
	}

	private function ValidateInspectionUniversalGraph(array $catalog): ?string
	{
		$revision = $this->InactiveRevision();
		if ($revision === null || (string)$revision['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
		{
			return 'provenance_mismatch';
		}
		foreach ($catalog as $unit)
		{
			if ($unit['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
			{
				return 'provenance_mismatch';
			}
			if ($unit['metric_factor'] === null || $unit['metric_factor'] <= 0)
			{
				return 'malformed_factor';
			}
			if (!in_array($unit['dimension'], ['mass', 'volume'], true))
			{
				return 'dimension_mismatch';
			}
		}

		$statement = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$rules = $statement->fetchAll(PDO::FETCH_ASSOC);
		usort($rules, static function(array $left, array $right): int
		{
			return [(string)$left['from_unit_key'], (string)$left['to_unit_key'], (string)$left['factor']]
				<=> [(string)$right['from_unit_key'], (string)$right['to_unit_key'], (string)$right['factor']];
		});
		$edges = [];
		$pairs = [];
		foreach ($rules as $rule)
		{
			$from = (string)$rule['from_unit_key'];
			$to = (string)$rule['to_unit_key'];
			$factor = $this->Factor($rule['factor']);
			if ($factor === null || $factor <= 0)
			{
				return 'malformed_factor';
			}
			if ((string)$rule['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
			{
				return 'provenance_mismatch';
			}
			if (!isset($catalog[$from], $catalog[$to]) || $catalog[$from]['dimension'] !== $catalog[$to]['dimension'])
			{
				return 'dimension_mismatch';
			}
			$key = $from . '>' . $to;
			if (isset($pairs[$key]))
			{
				return 'same_rank_collision';
			}
			$expected = $catalog[$from]['metric_factor'] / $catalog[$to]['metric_factor'];
			if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
			{
				return 'tolerance_drift';
			}
			$pairs[$key] = $factor;
			$edges[] = ['from' => $from, 'to' => $to, 'factor' => $factor];
		}
		foreach ($pairs as $key => $factor)
		{
			[$from, $to] = explode('>', $key, 2);
			$reverse = $pairs[$to . '>' . $from] ?? null;
			if ($reverse !== null && $this->RelativeDifference($factor * $reverse, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return 'reciprocal_inconsistency';
			}
		}
		return $this->InspectionGraphBlocker($edges, $pairs);
	}

	private function InspectProductGraph(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		$statement = $this->Db->prepare('SELECT conversion.id, conversion.factor, from_unit.name AS from_name, to_unit.name AS to_name FROM quantity_unit_conversions AS conversion INNER JOIN quantity_units AS from_unit ON from_unit.id = conversion.from_qu_id INNER JOIN quantity_units AS to_unit ON to_unit.id = conversion.to_qu_id WHERE conversion.product_id = ?');
		$statement->execute([$productId]);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		usort($rows, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
		$edges = [];
		$pairs = [];
		$candidates = [];
		foreach ($rows as $row)
		{
			$from = $this->UnitKey((string)$row['from_name']);
			$to = $this->UnitKey((string)$row['to_name']);
			if ($from === null || $to === null)
			{
				continue;
			}
			$factor = $this->Factor($row['factor']);
			if ($factor === null || $factor <= 0)
			{
				return ['blocker' => 'malformed_factor', 'candidate' => null];
			}
			$key = $from . '>' . $to;
			if (isset($pairs[$key]))
			{
				return ['blocker' => 'same_rank_collision', 'candidate' => null];
			}
			$pairs[$key] = $factor;
			$edge = ['from' => $from, 'to' => $to, 'factor' => $factor, 'factor_raw' => trim((string)$row['factor'])];
			$edges[] = $edge;
			if ($from === $fromUnitKey && $to === $toUnitKey)
			{
				$candidates[] = $edge;
			}
		}
		foreach ($pairs as $key => $factor)
		{
			[$from, $to] = explode('>', $key, 2);
			$reverse = $pairs[$to . '>' . $from] ?? null;
			if ($reverse !== null && $this->RelativeDifference($factor * $reverse, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return ['blocker' => 'reciprocal_inconsistency', 'candidate' => null];
			}
		}
		$blocker = $this->InspectionGraphBlocker($edges, $pairs);
		if ($blocker !== null)
		{
			return ['blocker' => $blocker, 'candidate' => null];
		}
		if (count($candidates) === 1)
		{
			return ['blocker' => null, 'candidate' => $candidates[0]];
		}
		$adjacency = $this->InspectionAdjacency($edges);
		$paths = [];
		$this->CollectInspectionPaths($fromUnitKey, 1.0, [$fromUnitKey => true], $adjacency, $paths);
		if (isset($paths[$toUnitKey][0]))
		{
			return ['blocker' => null, 'candidate' => ['factor_raw' => (string)$paths[$toUnitKey][0]]];
		}
		return ['blocker' => null, 'candidate' => null];
	}

	private function InspectionGraphBlocker(array $edges, array $pairs): ?string
	{
		$adjacency = $this->InspectionAdjacency($edges);
		$state = [];
		foreach (array_keys($adjacency) as $node)
		{
			if ($this->HasInspectionCycle($node, null, $adjacency, $pairs, $state))
			{
				return 'cycle_detected';
			}
		}

		foreach (array_keys($adjacency) as $source)
		{
			$paths = [];
			$this->CollectInspectionPaths($source, 1.0, [$source => true], $adjacency, $paths);
			foreach ($paths as $factors)
			{
				if (count($factors) < 2)
				{
					continue;
				}
				$expected = $factors[0];
				foreach (array_slice($factors, 1) as $factor)
				{
					if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
					{
						return 'competing_paths';
					}
				}
			}
		}
		return null;
	}

	private function InspectionAdjacency(array $edges): array
	{
		$adjacency = [];
		foreach ($edges as $edge)
		{
			$adjacency[$edge['from']][] = $edge;
		}
		foreach ($adjacency as &$neighbors)
		{
			usort($neighbors, static fn(array $left, array $right): int => [$left['to'], $left['factor']] <=> [$right['to'], $right['factor']]);
		}
		unset($neighbors);
		return $adjacency;
	}

	private function HasInspectionCycle(string $node, ?string $parent, array $adjacency, array $pairs, array &$state): bool
	{
		if (($state[$node] ?? 0) === 1)
		{
			return true;
		}
		if (($state[$node] ?? 0) === 2)
		{
			return false;
		}
		$state[$node] = 1;
		foreach ($adjacency[$node] ?? [] as $edge)
		{
			$neighbor = $edge['to'];
			if ($neighbor === $parent && isset($pairs[$neighbor . '>' . $node]))
			{
				continue;
			}
			if ($this->HasInspectionCycle($neighbor, $node, $adjacency, $pairs, $state))
			{
				return true;
			}
		}
		$state[$node] = 2;
		return false;
	}

	private function CollectInspectionPaths(string $node, float $factor, array $visited, array $adjacency, array &$paths): void
	{
		foreach ($adjacency[$node] ?? [] as $edge)
		{
			$target = $edge['to'];
			if (isset($visited[$target]))
			{
				continue;
			}
			$pathFactor = $factor * $edge['factor'];
			$paths[$target][] = $pathFactor;
			if (count($paths[$target]) > 64)
			{
				continue;
			}
			$nextVisited = $visited;
			$nextVisited[$target] = true;
			$this->CollectInspectionPaths($target, $pathFactor, $nextVisited, $adjacency, $paths);
		}
	}

	private function EligibleProfileCount(int $productId, string $fromUnitKey, string $toUnitKey): int
	{
		$statement = $this->Db->prepare('SELECT COUNT(*) FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_taxonomy_classifications AS classification ON classification.leaf_id = profile.taxonomy_leaf_id INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2 AND profile.revision_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION, GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, $fromUnitKey, $toUnitKey]);
		return (int)$statement->fetchColumn();
	}

	private function InvalidProfileBlocker(int $productId, string $fromUnitKey, string $toUnitKey): string
	{
		$statement = $this->Db->prepare('SELECT profile.profile_key, profile.factor FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_taxonomy_classifications AS classification ON classification.leaf_id = profile.taxonomy_leaf_id INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2 AND profile.revision_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION, GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, $fromUnitKey, $toUnitKey]);
		$profile = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($profile))
		{
			return 'profile_invalid';
		}
		$factor = $this->Factor($profile['factor']);
		if ($factor === null || $factor <= 0)
		{
			return 'malformed_factor';
		}
		$expected = self::SOURCED_PROFILE_RECORDS[(string)$profile['profile_key']] ?? null;
		$expectedFactor = is_array($expected) ? $this->Factor($expected[3] ?? null) : null;
		if ($expectedFactor !== null && $this->RelativeDifference($factor, $expectedFactor) > self::RELATIVE_TOLERANCE)
		{
			return 'tolerance_drift';
		}
		return 'profile_invalid';
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
		$rows = $this->Db->query('SELECT unit_key, dimension, metric_factor, source_version FROM grocy_ai_conversion_catalog_units')->fetchAll(PDO::FETCH_ASSOC);
		$catalog = [];
		foreach ($rows as $row)
		{
			$catalog[(string)$row['unit_key']] = [
				'dimension' => (string)$row['dimension'],
				'metric_factor' => $this->Factor($row['metric_factor']),
				'source_version' => (string)$row['source_version']
			];
		}
		return $catalog;
	}

	private function InactiveRevision(): ?array
	{
		$statement = $this->Db->prepare('SELECT id, status, source_version FROM grocy_ai_conversion_revisions WHERE id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$revision = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($revision) || $revision['status'] !== 'inactive')
		{
			return null;
		}
		return $revision;
	}

	private function ValidateCatalogGraph(array $catalog, string $sourceVersion): ?string
	{
		$statement = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$edges = [];
		$direct = [];
		$rules = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rule)
		{
			$from = (string)$rule['from_unit_key'];
			$to = (string)$rule['to_unit_key'];
			$factor = $this->Factor($rule['factor']);
			if ((string)$rule['source_version'] !== $sourceVersion)
			{
				return 'catalog_rule_source_version_invalid';
			}
			if ($factor === null || $factor <= 0 || !isset($catalog[$from], $catalog[$to]) || $catalog[$from]['dimension'] !== $catalog[$to]['dimension']
				|| $catalog[$from]['metric_factor'] === null || $catalog[$from]['metric_factor'] <= 0 || $catalog[$to]['metric_factor'] === null || $catalog[$to]['metric_factor'] <= 0)
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
			$rules[] = ['from' => $from, 'to' => $to, 'factor' => $factor];
		}

		foreach ($rules as $rule)
		{
			$from = $rule['from'];
			$to = $rule['to'];
			$factor = $rule['factor'];
			if ($this->RelativeDifference($factor, $catalog[$from]['metric_factor'] / $catalog[$to]['metric_factor']) > self::RELATIVE_TOLERANCE)
			{
				return 'catalog_rule_invalid';
			}
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

	private function Dto(string $status, string $scope, array $blockers, ?string $factor, ?string $dimension, ?string $sourceVersion = null): array
	{
		return [
			'status' => $status,
			'scope' => $scope,
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'source_version' => $sourceVersion ?? GrocyAiConversionMigration::SOURCE_VERSION,
			'inactive_revision_id' => GrocyAiConversionMigration::INACTIVE_REVISION_ID
		];
	}

	private function Blocked(string $scope, string $blocker, ?string $sourceVersion = null): array
	{
		return $this->Dto('blocked', $scope, [$blocker], null, null, $sourceVersion);
	}

	private function ProfileUnavailable(string $blocker): array
	{
		return $this->ProfileDto('unavailable', [$blocker], null, null, null, null, null, null, null, null);
	}

	private function TaxonomyClassificationsRelationAvailable(): bool
	{
		$statement = $this->Db->prepare('SELECT 1 FROM sqlite_master WHERE name = ? LIMIT 1');
		$statement->execute(['grocy_ai_taxonomy_classifications']);
		return $statement->fetchColumn() !== false;
	}

	private function ProfileDto(string $status, array $blockers, ?string $factor, ?string $dimension, ?string $profileKey, ?string $taxonomyLeaf, ?string $sourceName, ?string $sourceItemId, ?string $sourceVersion, ?string $sourceBasis): array
	{
		return [
			'status' => $status,
			'scope' => 'food_profile',
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'approximate' => true,
			'profile_key' => $profileKey,
			'taxonomy_leaf' => $taxonomyLeaf,
			'source_name' => $sourceName,
			'source_item_id' => $sourceItemId,
			'source_version' => $sourceVersion,
			'source_basis' => $sourceBasis,
			'inactive_revision_id' => GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID
		];
	}

	private function InspectionBlocked(string $blocker): array
	{
		return $this->InspectionDto('blocked', [$blocker], null, null, null, null, null, null, null, null, null, null, null);
	}

	private function InspectionUnavailable(string $blocker): array
	{
		return $this->InspectionDto('unavailable', [$blocker], null, null, null, null, null, null, null, null, null, null, null);
	}

	private function InspectionDto(string $status, array $blockers, ?string $factor, ?string $dimension, ?bool $approximate, ?string $winnerSource, ?string $sourceName, ?string $sourceVersion, ?string $sourceStatus, ?string $sourceItemId, ?string $profileKey, ?string $taxonomyLeaf, ?string $inactiveRevisionId): array
	{
		return [
			'status' => $status,
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'approximate' => $approximate,
			'winner_source' => $winnerSource,
			'source_name' => $sourceName,
			'source_version' => $sourceVersion,
			'source_status' => $sourceStatus,
			'source_item_id' => $sourceItemId,
			'profile_key' => $profileKey,
			'taxonomy_leaf' => $taxonomyLeaf,
			'precedence' => 'product_override>food_profile>universal',
			'inactive_revision_id' => $inactiveRevisionId
		];
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
		return self::UnitKeyForName($name);
	}

	/**
	 * Single owner of the Grocy quantity-unit-name to reusable catalog-key mapping.
	 * Inspection callers outside this service must use this predicate rather than
	 * restating the table, so the two cannot drift.
	 */
	public static function UnitKeyForName(string $name): ?string
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

	private function UnitDimension(string $unitKey): ?string
	{
		if (in_array($unitKey, ['mg', 'g', 'kg', 'oz', 'lb'], true))
		{
			return 'mass';
		}
		if (in_array($unitKey, ['ml', 'l', 'tsp', 'tbsp', 'cup', 'fl_oz', 'pint', 'quart', 'gallon'], true))
		{
			return 'volume';
		}
		return null;
	}

	private function RelativeDifference(float $actual, float $expected): float
	{
		return abs($actual - $expected) / max(abs($expected), 1.0);
	}
}
