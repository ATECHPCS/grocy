<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyMigration
{
	public const VERSION = 'v1';

	/**
	 * The reviewable, source-controlled set of Grocy product groups held out of scope (06-03).
	 * On current prod this is only "Supplements" (~2 items); it is the config tripwire if the
	 * non-food footprint ever grows. Exclusion is applied through the taxonomy mapping-rule seeded
	 * from this list (disposition 'excluded'), never ad-hoc SQL, so it reruns identically.
	 *
	 * @var array<int, string>
	 */
	public const EXCLUDED_PRODUCT_GROUPS = ['Supplements'];

	/** Per-product exclusion override userfield (Grocy `userfields` entity=products). */
	public const SCOPE_OVERRIDE_USERFIELD = 'grocy_ai_scope_override';
	public const SCOPE_OVERRIDE_INCLUDED = 'included';
	public const SCOPE_OVERRIDE_EXCLUDED = 'excluded';

	public static function Bootstrap(PDO $pdo): void
	{
		$startedTransaction = !$pdo->inTransaction();
		if ($startedTransaction)
		{
			$pdo->beginTransaction();
		}

		try
		{
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_taxonomy_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			$applied = $pdo->prepare('SELECT COUNT(*) FROM grocy_ai_taxonomy_migrations WHERE version = ?');
			$applied->execute([self::VERSION]);
			if ((int)$applied->fetchColumn() === 0)
			{
				self::CreateSchema($pdo);
				self::Seed($pdo);
				$pdo->prepare('INSERT INTO grocy_ai_taxonomy_migrations (version) VALUES (?)')->execute([self::VERSION]);
			}
			else
			{
				// Mapping additions are idempotent source-controlled rules, not a new taxonomy version.
				self::SeedMappings($pdo);
			}

			// Additive, idempotent scope surface (06-03): the per-product override userfield definition
			// and the excluded-group mapping-rules are re-asserted on every bootstrap (IF NOT EXISTS /
			// INSERT OR IGNORE), so they land whether the base schema is fresh or already applied and
			// never disturb the recorded ruleset VERSION or any native product/stock data.
			self::SeedScopeExclusions($pdo);
			self::SeedScopeOverrideUserfield($pdo);

			if ($startedTransaction)
			{
				$pdo->commit();
			}
		}
		catch (\Throwable $ex)
		{
			if ($startedTransaction && $pdo->inTransaction())
			{
				$pdo->rollBack();
			}
			throw $ex;
		}
	}

	private static function CreateSchema(PDO $pdo): void
	{
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_taxonomy_nodes (id TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, parent_id TEXT NULL, slug TEXT NOT NULL UNIQUE, label TEXT NOT NULL, depth INTEGER NOT NULL CHECK (depth IN (1, 2)), FOREIGN KEY (parent_id) REFERENCES grocy_ai_taxonomy_nodes(id))');
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_taxonomy_nodes_parent_idx ON grocy_ai_taxonomy_nodes (parent_id, slug)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_taxonomy_mapping_rules (provider_category TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, target_slug TEXT NULL, disposition TEXT NOT NULL CHECK (disposition IN (\'mapped\', \'excluded\')), CHECK ((disposition = \'mapped\' AND target_slug IS NOT NULL) OR (disposition = \'excluded\' AND target_slug IS NULL)))');
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_taxonomy_evidence (product_id INTEGER NOT NULL PRIMARY KEY, provider_category TEXT NOT NULL, mapping_version TEXT NOT NULL, confidence_band TEXT NOT NULL CHECK (confidence_band IN (\'high\', \'medium\', \'low\', \'unverified\')), reason_code TEXT NOT NULL, recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_taxonomy_classifications (product_id INTEGER NOT NULL PRIMARY KEY, leaf_id TEXT NULL, ruleset_version TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (leaf_id) REFERENCES grocy_ai_taxonomy_nodes(id))');
	}

	private static function Seed(PDO $pdo): void
	{
		$nodes = [
			['group-pantry', null, 'pantry', 'Pantry', 1],
			['leaf-baking', 'group-pantry', 'baking', 'Baking', 2],
			['leaf-beverages', 'group-pantry', 'beverages', 'Beverages', 2],
			['leaf-grains-pasta', 'group-pantry', 'grains-pasta', 'Grains & pasta', 2],
			['leaf-snacks', 'group-pantry', 'snacks', 'Snacks', 2],
			['group-fresh', null, 'fresh-food', 'Fresh food', 1],
			['leaf-dairy-eggs', 'group-fresh', 'dairy-eggs', 'Dairy & eggs', 2],
			['leaf-meat-seafood', 'group-fresh', 'meat-seafood', 'Meat & seafood', 2],
			['leaf-produce', 'group-fresh', 'produce', 'Produce', 2],
			['group-cooking', null, 'cooking-basics', 'Cooking basics', 1],
			['leaf-condiments', 'group-cooking', 'condiments', 'Condiments', 2],
			['leaf-oils-vinegars', 'group-cooking', 'oils-vinegars', 'Oils & vinegars', 2]
		];
		$nodeStatement = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_nodes (id, version, parent_id, slug, label, depth) VALUES (?, ?, ?, ?, ?, ?)');
		foreach ($nodes as [$id, $parentId, $slug, $label, $depth])
		{
			self::AssertSeedNode($id, $parentId, $slug, $label, $depth);
			$nodeStatement->execute([$id, self::VERSION, $parentId, $slug, $label, $depth]);
		}

		self::SeedMappings($pdo);
	}

	private static function SeedMappings(PDO $pdo): void
	{
		$rules = [
			['dairy', 'dairy-eggs', 'mapped'],
			['eggs', 'dairy-eggs', 'mapped'],
			['produce', 'produce', 'mapped'],
			['pasta', 'grains-pasta', 'mapped'],
			['seafood', 'meat-seafood', 'mapped'],
			['fish', 'meat-seafood', 'mapped'],
			['meat', 'meat-seafood', 'mapped'],
			['bakery', 'baking', 'mapped'],
			['beverages', 'beverages', 'mapped'],
			['baby_food', null, 'excluded'],
			['pet_food', null, 'excluded']
		];
		$ruleStatement = $pdo->prepare('INSERT OR IGNORE INTO grocy_ai_taxonomy_mapping_rules (provider_category, version, target_slug, disposition) VALUES (?, ?, ?, ?)');
		foreach ($rules as [$category, $targetSlug, $disposition])
		{
			$ruleStatement->execute([$category, self::VERSION, $targetSlug, $disposition]);
		}
	}

	/**
	 * Seed one closed exclusion mapping-rule per configured non-food group (06-03), keyed on the
	 * same normalized group name the scope owner and the taxonomy scorer use. Idempotent: a rule that
	 * already exists is left untouched (INSERT OR IGNORE), so exclusion reruns identically.
	 */
	private static function SeedScopeExclusions(PDO $pdo): void
	{
		$ruleStatement = $pdo->prepare('INSERT OR IGNORE INTO grocy_ai_taxonomy_mapping_rules (provider_category, version, target_slug, disposition) VALUES (?, ?, NULL, \'excluded\')');
		foreach (self::EXCLUDED_PRODUCT_GROUPS as $groupName)
		{
			$ruleStatement->execute([self::NormalizeCategoryKey($groupName), self::VERSION]);
		}
	}

	/**
	 * Create the per-product exclusion override userfield definition (Grocy `userfields`, entity
	 * products) with a closed value set {included, excluded} defaulting to included. Additive and
	 * idempotent (INSERT OR IGNORE on the UNIQUE(entity, name) key); skipped entirely when the native
	 * `userfields` table is absent (module unit fixtures) so it never fabricates native schema.
	 */
	private static function SeedScopeOverrideUserfield(PDO $pdo): void
	{
		if (!self::TableExists($pdo, 'userfields'))
		{
			return;
		}

		$statement = $pdo->prepare('INSERT OR IGNORE INTO userfields (entity, name, caption, type, config, default_value) VALUES (?, ?, ?, ?, ?, ?)');
		$statement->execute([
			'products',
			self::SCOPE_OVERRIDE_USERFIELD,
			'Grocy AI inventory scope',
			'preset-list',
			self::SCOPE_OVERRIDE_INCLUDED . "\n" . self::SCOPE_OVERRIDE_EXCLUDED,
			self::SCOPE_OVERRIDE_INCLUDED
		]);
	}

	/**
	 * Normalize a provider category or product-group label to the mapping-rule key. Must stay in lock
	 * step with GrocyAiTaxonomyService::ProviderCategoryKey and GrocyAiInventoryScope so a group name
	 * resolves to the same seeded exclusion rule from every caller.
	 */
	public static function NormalizeCategoryKey(string $category): string
	{
		return strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($category)));
	}

	private static function TableExists(PDO $pdo, string $table): bool
	{
		$statement = $pdo->prepare('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = ? LIMIT 1');
		$statement->execute([$table]);
		return $statement->fetchColumn() !== false;
	}

	private static function AssertSeedNode(string $id, ?string $parentId, string $slug, string $label, int $depth): void
	{
		if ($id === '' || $slug === '' || $label === '' || !preg_match('/^[a-z][a-z0-9-]*$/D', $slug)
			|| !in_array($depth, [1, 2], true) || ($depth === 1) !== ($parentId === null)
			|| preg_match('/baby|pet|frozen|preserved/i', $slug) === 1)
		{
			throw new \LogicException('Invalid closed taxonomy seed node');
		}
	}
}
