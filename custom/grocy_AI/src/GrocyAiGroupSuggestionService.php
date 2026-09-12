<?php

namespace GrocyAI\Services;

use PDO;

/**
 * GrocyAiGroupSuggestionService — evidence-based product-group suggestions for the ~214 ungrouped,
 * in-scope products (Phase 6 / 06-04), plus the audited native write that sets
 * `products.product_group_id`.
 *
 * A suggestion maps a product to an EXISTING `product_groups` row only — it never invents or creates a
 * group. Only in-scope products (per the single owner GrocyAiInventoryScope) that are currently
 * ungrouped receive a suggestion. Evidence comes from the product's enrichment provider category
 * (`grocy_ai_taxonomy_evidence`) and its name, matched against the active `product_groups` by a
 * normalized key. A confident match — a normalized provider category, or the product name being exactly
 * an existing group — is emitted `high` and pre-selected; a weaker name-contains match, or an ambiguous
 * tie between two candidate groups (Q7 conflict), is emitted `low` and left deselected; no match yields
 * no suggestion.
 *
 * The delegate write (`AssignProductGroup`) sets ONLY `products.product_group_id` and joins the bulk
 * engine's outer transaction when asked, so `ApplyPlan`/`RollbackPlan` own the single write lock. Pure
 * reads elsewhere; the same committed database state always yields the same suggestion set.
 */
class GrocyAiGroupSuggestionService
{
	public const CONFIDENCE_HIGH = 'high';
	public const CONFIDENCE_LOW = 'low';

	private PDO $Db;
	private GrocyAiInventoryScope $Scope;

	public function __construct(PDO $pdo)
	{
		$this->Db = $pdo;
		$this->Scope = new GrocyAiInventoryScope($pdo);
	}

	/** The native product group id for a product (null when ungrouped, deleted, or absent). Pure read. */
	public function CurrentProductGroupId(int $productId): ?int
	{
		$statement = $this->Db->prepare('SELECT product_group_id FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$value = $statement->fetchColumn();
		return ($value === false || $value === null) ? null : (int)$value;
	}

	/**
	 * The evidence-based suggestion for one product, or null when the product is out of scope, already
	 * grouped, or has no confident/ambiguous match to an existing group. Deterministic and zero-write.
	 *
	 * @return array{product_group_id: int, group_name: string, confidence: string, reason: string, provenance: string, evidence: array<int, array{source: string, value: string}>}|null
	 */
	public function Suggest(int $productId): ?array
	{
		// Only in-scope products are candidates; the single scope owner holds out inactive / excluded-group
		// / override-excluded products. An ungrouped product is in scope — grouping is this reviewed pass.
		if (!$this->Scope->IsInScope($productId))
		{
			return null;
		}
		// Grouping targets ONLY the ungrouped set; an already-grouped product needs no suggestion.
		if ($this->CurrentProductGroupId($productId) !== null)
		{
			return null;
		}

		$groups = $this->ActiveGroupsByKey();
		if ($groups === [])
		{
			return null;
		}

		// Evidence 1 (confident): the enrichment provider category equals an existing group name.
		$providerCategory = $this->ProviderCategory($productId);
		if ($providerCategory !== null)
		{
			$key = self::NormalizeKey($providerCategory);
			if ($key !== '' && isset($groups[$key]))
			{
				$group = $groups[$key];
				return $this->Suggestion($group, self::CONFIDENCE_HIGH, 'provider_category_matches_group', 'provider_category', [
					['source' => 'provider_category', 'value' => $providerCategory],
					['source' => 'grocy_product_group', 'value' => $group['name']]
				]);
			}
		}

		// Evidence 2 (name): an existing group name appears as a whole word/phrase in the product name.
		$name = $this->ProductName($productId);
		$nameMatches = $this->NameGroupMatches($name, $groups);
		if (count($nameMatches) === 1)
		{
			$group = $nameMatches[0];
			// Confident only when the name IS the group; a mere contains-match is reviewed low.
			$confidence = $group['exact'] ? self::CONFIDENCE_HIGH : self::CONFIDENCE_LOW;
			$reason = $group['exact'] ? 'name_matches_group' : 'name_contains_group';
			return $this->Suggestion($group, $confidence, $reason, 'product_name', [
				['source' => 'product_name', 'value' => $name],
				['source' => 'grocy_product_group', 'value' => $group['name']]
			]);
		}
		if (count($nameMatches) > 1)
		{
			// Two+ candidate groups tie on the name signal (Q7 conflict): review low against the first
			// candidate by group id, left deselected so a human resolves the ambiguity.
			usort($nameMatches, static fn(array $left, array $right): int => $left['product_group_id'] <=> $right['product_group_id']);
			$group = $nameMatches[0];
			return $this->Suggestion($group, self::CONFIDENCE_LOW, 'ambiguous_group_signal', 'product_name', [
				['source' => 'product_name', 'value' => $name],
				['source' => 'conflict', 'value' => 'multiple_candidate_groups']
			]);
		}

		return null;
	}

	/**
	 * The audited native write: set ONLY `products.product_group_id` for one product. A non-null target
	 * MUST be an existing `product_groups` row — a suggestion never invents a group; null restores the
	 * ungrouped state (rollback). Joins the caller's outer transaction when `$joinExistingTransaction` is
	 * true (the bulk engine owns the single write lock) and never opens its own in that case.
	 *
	 * @return array{product_id: int, product_group_id: ?int}
	 */
	public function AssignProductGroup(int $productId, ?int $groupId, bool $joinExistingTransaction = false): array
	{
		if ($productId < 1)
		{
			throw new \InvalidArgumentException('Invalid product for group assignment');
		}

		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			throw new \RuntimeException('Product unavailable');
		}
		if ($groupId !== null)
		{
			// Never invent a group: a non-null target must already exist in the native registry.
			$group = $this->Db->prepare('SELECT id FROM product_groups WHERE id = ?');
			$group->execute([$groupId]);
			if ($group->fetchColumn() === false)
			{
				throw new \RuntimeException('Unknown product group');
			}
		}

		$ownsTransaction = !$joinExistingTransaction;
		if ($ownsTransaction)
		{
			$this->Db->beginTransaction();
		}
		try
		{
			// The single native field write; no other column or table is touched.
			$write = $this->Db->prepare('UPDATE products SET product_group_id = ? WHERE id = ?');
			$write->execute([$groupId, $productId]);
			if ($ownsTransaction)
			{
				$this->Db->commit();
			}
		}
		catch (\Throwable $exception)
		{
			if ($ownsTransaction && $this->Db->inTransaction())
			{
				$this->Db->rollBack();
			}
			throw $exception;
		}

		return ['product_id' => $productId, 'product_group_id' => $this->CurrentProductGroupId($productId)];
	}

	/**
	 * @param array{product_group_id: int, name: string, key: string} $group
	 * @param array<int, array{source: string, value: string}> $evidence
	 * @return array{product_group_id: int, group_name: string, confidence: string, reason: string, provenance: string, evidence: array<int, array{source: string, value: string}>}
	 */
	private function Suggestion(array $group, string $confidence, string $reason, string $provenance, array $evidence): array
	{
		return [
			'product_group_id' => $group['product_group_id'],
			'group_name' => $group['name'],
			'confidence' => $confidence,
			'reason' => $reason,
			'provenance' => $provenance,
			'evidence' => $evidence
		];
	}

	/**
	 * The active product groups keyed by normalized name. A normalized-name collision resolves
	 * deterministically to the lowest group id, so the same registry always yields the same key map.
	 *
	 * @return array<string, array{product_group_id: int, name: string, key: string}>
	 */
	private function ActiveGroupsByKey(): array
	{
		if (!$this->TableExists('product_groups'))
		{
			return [];
		}
		$activeClause = $this->ColumnExists('product_groups', 'active') ? ' WHERE active = 1' : '';
		$rows = $this->Db->query('SELECT id, name FROM product_groups' . $activeClause)->fetchAll(PDO::FETCH_ASSOC);

		$byKey = [];
		foreach ($rows as $row)
		{
			$name = (string)$row['name'];
			$key = self::NormalizeKey($name);
			if ($key === '')
			{
				continue;
			}
			if (!isset($byKey[$key]) || (int)$row['id'] < $byKey[$key]['product_group_id'])
			{
				$byKey[$key] = ['product_group_id' => (int)$row['id'], 'name' => $name, 'key' => $key];
			}
		}
		return $byKey;
	}

	/** The enrichment provider category for a product (null when absent/empty). */
	private function ProviderCategory(int $productId): ?string
	{
		if (!$this->TableExists('grocy_ai_taxonomy_evidence'))
		{
			return null;
		}
		$statement = $this->Db->prepare('SELECT provider_category FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$value = $statement->fetchColumn();
		return (is_string($value) && trim($value) !== '') ? $value : null;
	}

	private function ProductName(int $productId): string
	{
		$statement = $this->Db->prepare('SELECT name FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$value = $statement->fetchColumn();
		return is_string($value) ? $value : '';
	}

	/**
	 * The existing groups whose normalized name appears as a whole word/phrase in the product name.
	 * Each match carries `exact` = the product name normalizes exactly to the group name.
	 *
	 * @param array<string, array{product_group_id: int, name: string, key: string}> $groups
	 * @return array<int, array{product_group_id: int, name: string, key: string, exact: bool}>
	 */
	private function NameGroupMatches(string $name, array $groups): array
	{
		$nameText = self::NormalizeText($name);
		if ($nameText === '')
		{
			return [];
		}
		$padded = ' ' . $nameText . ' ';

		$matches = [];
		foreach ($groups as $group)
		{
			$groupText = self::NormalizeText($group['name']);
			if ($groupText !== '' && str_contains($padded, ' ' . $groupText . ' '))
			{
				$matches[] = $group + ['exact' => $nameText === $groupText];
			}
		}
		return $matches;
	}

	/** A compact alphanumeric key: lowercased, with every non-alphanumeric run removed. */
	private static function NormalizeKey(string $value): string
	{
		return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($value));
	}

	/** A word-separated normalized text: lowercased, non-alphanumeric runs collapsed to single spaces. */
	private static function NormalizeText(string $value): string
	{
		return trim((string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
	}

	private function ColumnExists(string $table, string $column): bool
	{
		foreach ($this->Db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $columnInfo)
		{
			if (($columnInfo['name'] ?? null) === $column)
			{
				return true;
			}
		}
		return false;
	}

	private function TableExists(string $table): bool
	{
		$statement = $this->Db->prepare('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = ? LIMIT 1');
		$statement->execute([$table]);
		return $statement->fetchColumn() !== false;
	}
}
