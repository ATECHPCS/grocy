<?php

namespace GrocyAI\Services;

use PDO;

/**
 * GrocyAiInventoryScope — the SINGLE owner of the in-scope predicate for Phase 6 profilers (06-03).
 *
 * A product is in scope for grouping/classification iff ALL of:
 *   - it is active (Grocy `products.active = 1`; treated active when the column is absent, e.g. module
 *     unit fixtures), and
 *   - its Grocy product group is NOT held out by a seeded exclusion mapping-rule
 *     (`grocy_ai_taxonomy_mapping_rules.disposition = 'excluded'`, keyed on the normalized group name;
 *     the configured non-food groups live in GrocyAiTaxonomyMigration::EXCLUDED_PRODUCT_GROUPS), and
 *   - its per-product exclusion override userfield is NOT set to 'excluded' (default 'included').
 *
 * An ungrouped product is IN scope — grouping is a later reviewed pass (06-04), not an exclusion.
 * The predicate is a pure function of committed database state: the same snapshot yields the same
 * in-scope set on every run (DATA-01, supports DATA-07). No writes are ever issued here. Both the
 * classification profiler (the bulk taxonomy pass) and the future group profiler (06-04) consult this
 * one owner; the predicate is defined nowhere else.
 */
class GrocyAiInventoryScope
{
	private PDO $Db;

	public function __construct(PDO $pdo)
	{
		$this->Db = $pdo;
	}

	/** The single in-scope predicate. Pure read; deterministic for a fixed database state. */
	public function IsInScope(int $productId): bool
	{
		if ($productId < 1 || !$this->ProductExists($productId))
		{
			return false;
		}

		return $this->IsActive($productId)
			&& !$this->IsGroupExcluded($productId)
			&& $this->OverrideState($productId) !== GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_EXCLUDED;
	}

	/**
	 * The ordered list of in-scope product IDs — the bounded object set the profilers act on.
	 *
	 * @return array<int, int>
	 */
	public function InScopeProductIds(): array
	{
		$ids = $this->Db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		$inScope = [];
		foreach ($ids as $id)
		{
			if ($this->IsInScope((int)$id))
			{
				$inScope[] = (int)$id;
			}
		}
		return $inScope;
	}

	private function ProductExists(int $productId): bool
	{
		$statement = $this->Db->prepare('SELECT 1 FROM products WHERE id = ? LIMIT 1');
		$statement->execute([$productId]);
		return $statement->fetchColumn() !== false;
	}

	private function IsActive(int $productId): bool
	{
		if (!$this->ColumnExists('products', 'active'))
		{
			return true;
		}
		$statement = $this->Db->prepare('SELECT active FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$active = $statement->fetchColumn();
		return $active !== false && (int)$active === 1;
	}

	private function IsGroupExcluded(int $productId): bool
	{
		if (!$this->TableExists('product_groups') || !$this->TableExists('grocy_ai_taxonomy_mapping_rules'))
		{
			// No group registry (or no seeded rules) means nothing is group-excluded here.
			return false;
		}

		$statement = $this->Db->prepare('SELECT product_group.name FROM products INNER JOIN product_groups AS product_group ON product_group.id = products.product_group_id WHERE products.id = ?');
		$statement->execute([$productId]);
		$groupName = $statement->fetchColumn();
		if (!is_string($groupName) || trim($groupName) === '')
		{
			// Ungrouped (or no product_groups join) products stay in scope; grouping is a later pass.
			return false;
		}

		$rule = $this->Db->prepare('SELECT disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([GrocyAiTaxonomyMigration::NormalizeCategoryKey($groupName), GrocyAiTaxonomyMigration::VERSION]);
		return $rule->fetchColumn() === 'excluded';
	}

	private function OverrideState(int $productId): string
	{
		if (!$this->TableExists('userfields') || !$this->TableExists('userfield_values'))
		{
			return GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED;
		}

		$field = $this->Db->prepare('SELECT id FROM userfields WHERE entity = \'products\' AND name = ?');
		$field->execute([GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_USERFIELD]);
		$fieldId = $field->fetchColumn();
		if ($fieldId === false)
		{
			return GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED;
		}

		$value = $this->Db->prepare('SELECT value FROM userfield_values WHERE field_id = ? AND object_id = ?');
		$value->execute([(int)$fieldId, (string)$productId]);
		$stored = $value->fetchColumn();
		if (!is_string($stored) || trim($stored) === '')
		{
			return GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED;
		}

		return strtolower(trim($stored)) === GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_EXCLUDED
			? GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_EXCLUDED
			: GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED;
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
