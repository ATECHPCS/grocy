<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyService
{
	private PDO $Db;

	public function __construct(?PDO $pdo = null)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		GrocyAiTaxonomyMigration::Bootstrap($this->Db);
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
			'ruleset_version' => GrocyAiTaxonomyMigration::VERSION,
			'provider_category' => $evidence['provider_category'],
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => $evidence['reason_code']
		];
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
			'provider_category' => $providerCategory,
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => 'mapped_provider_category'
		];
	}

	private function Unclassified(string $reasonCode): array
	{
		return [
			'suggested_leaf' => null,
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
