<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		}
	}

	/**
	 * Store only the server-validated Phase 2 food-type suggestion for the current
	 * local product. Browser payloads never supply this evidence or its metadata.
	 */
	public function ReconcileEnrichmentEvidence(?int $productId, array $enrichment): bool
	{
		if ($productId === null || $productId < 1)
		{
			return false;
		}

		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			return false;
		}

		$foodType = null;
		$suggestions = $enrichment['suggestions'] ?? null;
		if (!is_array($suggestions) || !array_is_list($suggestions))
		{
			throw new \InvalidArgumentException('Invalid enrichment taxonomy evidence');
		}
		foreach ($suggestions as $suggestion)
		{
			if (is_array($suggestion) && ($suggestion['field'] ?? null) === 'food_type')
			{
				$foodType = $suggestion;
				break;
			}
		}

		if ($foodType === null)
		{
			$delete = $this->Db->prepare('DELETE FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
			$delete->execute([$productId]);
			return true;
		}

		$providerCategory = $foodType['value'] ?? null;
		$confidenceBand = $foodType['confidence_band'] ?? null;
		$reasonCode = $foodType['reason_code'] ?? null;
		if (!is_string($providerCategory) || trim($providerCategory) === '' || strlen($providerCategory) > 500
			|| !is_string($confidenceBand) || !in_array($confidenceBand, ['high', 'medium', 'low', 'unverified'], true)
			|| !is_string($reasonCode) || trim($reasonCode) === '' || strlen($reasonCode) > 500)
		{
			throw new \InvalidArgumentException('Invalid enrichment taxonomy evidence');
		}

		$write = $this->Db->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code, recorded_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(product_id) DO UPDATE SET provider_category = excluded.provider_category, mapping_version = excluded.mapping_version, confidence_band = excluded.confidence_band, reason_code = excluded.reason_code, recorded_at = CURRENT_TIMESTAMP');
		$write->execute([$productId, $providerCategory, GrocyAiTaxonomyMigration::VERSION, $confidenceBand, $reasonCode]);
		return true;
	}

	public function LeafBySlug(string $slug): array
	{
		if (preg_match('/^[a-z][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/baby|pet|frozen|preserved/i', $slug) === 1)
		{
			throw new \InvalidArgumentException('Unknown local taxonomy leaf');
		}

		$statement = $this->Db->prepare('SELECT id, slug, label FROM grocy_ai_taxonomy_nodes WHERE slug = ? AND parent_id IS NOT NULL AND version = ?');
		$statement->execute([$slug, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($leaf))
		{
			throw new \InvalidArgumentException('Unknown local taxonomy leaf');
		}

		return $this->LeafDto($leaf);
	}

	public function ReadProductTaxonomy(int $productId): array
	{
		if ($productId < 1)
		{
			throw new \InvalidArgumentException('Invalid product ID');
		}
		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			throw new \RuntimeException('Product unavailable');
		}

		$currentLeaf = $this->CurrentLeaf($productId);
		$evidence = $this->Evidence($productId);
		return [
			'product_id' => $productId,
			'current_leaf' => $currentLeaf,
			'suggested_leaf' => $evidence['suggested_leaf'],
			'evidence_source' => $evidence['evidence_source'],
			'ruleset_version' => GrocyAiTaxonomyMigration::VERSION,
			'provider_category' => $evidence['provider_category'],
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => $evidence['reason_code']
		];
	}

	/**
	 * @param bool $joinExistingTransaction When false (the default, unchanged for the controller
	 *   endpoint and the taxonomy test callers) this method opens and commits its own transaction.
	 *   When true it runs the identical validation and the same single `INSERT ... ON CONFLICT` upsert
	 *   but issues no `beginTransaction()`/`commit()`/`rollBack()` of its own, so a caller such as the
	 *   bulk engine's `ApplyPlan` can own one outer `BEGIN IMMEDIATE` and nest this delegate inside it.
	 *   The write statement itself is unchanged either way.
	 */
	public function AssignProductTaxonomy(int $productId, array $assignment, bool $joinExistingTransaction = false): array
	{
		if ($productId < 1 || array_keys($assignment) !== ['leaf_slug', 'ruleset_version'] && array_keys($assignment) !== ['unclassified', 'ruleset_version'])
		{
			throw new \InvalidArgumentException('Invalid taxonomy assignment');
		}
		if (($assignment['ruleset_version'] ?? null) !== GrocyAiTaxonomyMigration::VERSION)
		{
			throw new \InvalidArgumentException('Stale taxonomy ruleset');
		}

		$isUnclassified = ($assignment['unclassified'] ?? null) === true;
		if (!$isUnclassified && (!is_string($assignment['leaf_slug'] ?? null) || !isset($assignment['leaf_slug'])))
		{
			throw new \InvalidArgumentException('Invalid taxonomy assignment');
		}

		$ownsTransaction = !$joinExistingTransaction;
		if ($ownsTransaction)
		{
			$this->Db->beginTransaction();
		}
		try
		{
			$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
			$product->execute([$productId]);
			if ($product->fetchColumn() === false)
			{
				throw new \RuntimeException('Product unavailable');
			}

			$this->Evidence($productId);
			$leafId = null;
			if (!$isUnclassified)
			{
				$leaf = $this->LeafBySlug($assignment['leaf_slug']);
				$leafId = $leaf['id'];
			}
			$write = $this->Db->prepare('INSERT INTO grocy_ai_taxonomy_classifications (product_id, leaf_id, ruleset_version, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(product_id) DO UPDATE SET leaf_id = excluded.leaf_id, ruleset_version = excluded.ruleset_version, updated_at = CURRENT_TIMESTAMP');
			$write->execute([$productId, $leafId, GrocyAiTaxonomyMigration::VERSION]);
			if ($ownsTransaction)
			{
				$this->Db->commit();
			}
		}
		catch (\Throwable $ex)
		{
			if ($ownsTransaction && $this->Db->inTransaction())
			{
				$this->Db->rollBack();
			}
			throw $ex;
		}

		return $this->ReadProductTaxonomy($productId);
	}

	public function ValidateInventoryTaxonomy(): array
	{
		$products = $this->Db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		$report = [
			'ruleset_version' => GrocyAiTaxonomyMigration::VERSION,
			'frozen_preserved_boundary' => 'Frozen and preserved are handling/location concerns, not taxonomy identities.',
			'in_scope_products' => count($products),
			'mapped' => 0,
			'unclassified' => 0,
			'excluded' => 0,
			'conflicting' => 0,
			'low_confidence' => 0
		];

		foreach ($products as $productId)
		{
			$outcome = $this->ValidationOutcome((int)$productId);
			$report[$outcome]++;
		}

		return $report;
	}

	private function CurrentLeaf(int $productId): ?array
	{
		$statement = $this->Db->prepare('SELECT node.id, node.slug, node.label FROM grocy_ai_taxonomy_classifications AS classification INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $statement->fetch(PDO::FETCH_ASSOC);
		return is_array($leaf) ? $this->LeafDto($leaf) : null;
	}

	private function Evidence(int $productId): array
	{
		$productGroup = $this->ProductGroupEvidence($productId);
		if ($productGroup !== null)
		{
			return $productGroup;
		}

		$statement = $this->Db->prepare('SELECT provider_category, mapping_version, confidence_band, reason_code FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$evidence = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($evidence) || $evidence['mapping_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			return $this->Unclassified('no_accepted_evidence');
		}
		$providerCategory = self::ProviderCategoryKey((string)$evidence['provider_category']);
		$rule = $this->Db->prepare('SELECT target_slug, disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([$providerCategory, GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (!is_array($mapping))
		{
			return $this->Unclassified('unknown_mapping');
		}
		if ($mapping['disposition'] === 'excluded')
		{
			return $this->Unclassified('excluded_mapping');
		}
		if ($mapping['disposition'] !== 'mapped' || !is_string($mapping['target_slug'])
			|| !in_array($evidence['confidence_band'], ['high', 'medium'], true))
		{
			return $this->Unclassified('unknown_mapping');
		}

		return [
			'suggested_leaf' => $this->LeafBySlug($mapping['target_slug']),
			'evidence_source' => 'provider_food_type',
			'provider_category' => $providerCategory,
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => 'mapped_provider_category'
		];
	}

	private function ProductGroupEvidence(int $productId): ?array
	{
		$statement = $this->Db->prepare('SELECT product_group.name FROM products INNER JOIN product_groups AS product_group ON product_group.id = products.product_group_id WHERE products.id = ? AND product_group.active = 1');
		$statement->execute([$productId]);
		$productGroup = $statement->fetchColumn();
		if (!is_string($productGroup) || trim($productGroup) === '')
		{
			return null;
		}

		$key = self::ProviderCategoryKey($productGroup);
		$rule = $this->Db->prepare('SELECT target_slug, disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([$key, GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (!is_array($mapping) || $mapping['disposition'] !== 'mapped' || !is_string($mapping['target_slug']))
		{
			return null;
		}

		return [
			'suggested_leaf' => $this->LeafBySlug($mapping['target_slug']),
			'evidence_source' => 'grocy_product_group',
			'provider_category' => $productGroup,
			'confidence_band' => 'high',
			'reason_code' => 'mapped_grocy_product_group'
		];
	}

	private function ValidationOutcome(int $productId): string
	{
		$statement = $this->Db->prepare('SELECT provider_category, mapping_version, confidence_band, reason_code FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$evidence = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($evidence) || $evidence['mapping_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			return 'unclassified';
		}
		if (str_contains(strtolower((string)$evidence['reason_code']), 'conflict'))
		{
			return 'conflicting';
		}

		$rule = $this->Db->prepare('SELECT disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([self::ProviderCategoryKey((string)$evidence['provider_category']), GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (is_array($mapping) && $mapping['disposition'] === 'excluded')
		{
			return 'excluded';
		}
		if (is_array($mapping) && $mapping['disposition'] === 'mapped' && !in_array($evidence['confidence_band'], ['high', 'medium'], true))
		{
			return 'low_confidence';
		}
		if (is_array($mapping) && $mapping['disposition'] === 'mapped')
		{
			return 'mapped';
		}

		return 'unclassified';
	}

	private function Unclassified(string $reasonCode): array
	{
		return [
			'suggested_leaf' => null,
			'evidence_source' => null,
			'provider_category' => null,
			'confidence_band' => null,
			'reason_code' => $reasonCode
		];
	}

	private static function ProviderCategoryKey(string $providerCategory): string
	{
		return strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($providerCategory)));
	}

	private function LeafDto(array $leaf): array
	{
		return [
			'id' => (string)$leaf['id'],
			'slug' => (string)$leaf['slug'],
			'label' => (string)$leaf['label']
		];
	}
}
