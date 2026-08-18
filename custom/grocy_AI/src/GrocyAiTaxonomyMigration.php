<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyMigration
{
	public const VERSION = 'v1';

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

		$rules = [
			['dairy', 'dairy-eggs', 'mapped'],
			['eggs', 'dairy-eggs', 'mapped'],
			['produce', 'produce', 'mapped'],
			['pasta', 'grains-pasta', 'mapped'],
			['baby_food', null, 'excluded'],
			['pet_food', null, 'excluded']
		];
		$ruleStatement = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_mapping_rules (provider_category, version, target_slug, disposition) VALUES (?, ?, ?, ?)');
		foreach ($rules as [$category, $targetSlug, $disposition])
		{
			$ruleStatement->execute([$category, self::VERSION, $targetSlug, $disposition]);
		}
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
