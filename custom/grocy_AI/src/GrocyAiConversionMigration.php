<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiConversionMigration
{
	public const VERSION = 'v1';
	public const SOURCE_VERSION = 'NIST-SP-811-2008-Appendix-B.9';
	public const INACTIVE_REVISION_ID = 'conversion-catalog-v1';
	public const INACTIVE_PROFILE_REVISION_ID = 'conversion-profiles-v1';
	public const PROFILE_SOURCE_NAME = 'USDA FoodData Central';
	public const PROFILE_SOURCE_VERSION = 'SR Legacy 2018-04; published 2019-04-01';
	public const UNIVERSAL_SOURCE_NAME = 'NIST SP 811';
	/**
	 * The one projection adapter Plan 01 characterization may name. Activation writes universal
	 * `quantity_unit_conversions` rows and lets Grocy's own characterized triggers rebuild the
	 * resolved cache; it never issues cache SQL of its own.
	 */
	public const SELECTED_ADAPTERS = ['universal_native_rows_v1'];

	public static function Bootstrap(PDO $pdo): void
	{
		$startedTransaction = !$pdo->inTransaction();
		if ($startedTransaction)
		{
			$pdo->beginTransaction();
		}

		try
		{
			$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_conversion_migrations (version TEXT NOT NULL PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
			self::CreateSchema($pdo);
			self::Seed($pdo);
			$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_conversion_migrations (version) VALUES (?)')->execute([self::VERSION]);

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
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_catalog_units (unit_key TEXT NOT NULL PRIMARY KEY, dimension TEXT NOT NULL CHECK (dimension IN ('mass', 'volume')), metric_factor TEXT NOT NULL, source_version TEXT NOT NULL)");
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_revisions (id TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, status TEXT NOT NULL CHECK (status = 'inactive'), source_version TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_conversion_rules (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, revision_id TEXT NOT NULL, from_unit_key TEXT NOT NULL, to_unit_key TEXT NOT NULL, factor TEXT NOT NULL, source_version TEXT NOT NULL, FOREIGN KEY (revision_id) REFERENCES grocy_ai_conversion_revisions(id), FOREIGN KEY (from_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key), FOREIGN KEY (to_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key))');
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_conversion_rules_revision_idx ON grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key)');
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_validation_ledger (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, revision_id TEXT NOT NULL, status TEXT NOT NULL CHECK (status IN ('inactive', 'blocked', 'product_native')), blocker TEXT NULL, validated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (revision_id) REFERENCES grocy_ai_conversion_revisions(id))");
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_profile_revisions (id TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, status TEXT NOT NULL CHECK (status = 'inactive'), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_profiles (profile_key TEXT NOT NULL PRIMARY KEY, revision_id TEXT NOT NULL, taxonomy_leaf_id TEXT NOT NULL, from_unit_key TEXT NOT NULL, to_unit_key TEXT NOT NULL, factor TEXT NOT NULL, approximate INTEGER NOT NULL CHECK (approximate = 1), source_name TEXT NOT NULL, source_item_id TEXT NOT NULL, source_version TEXT NOT NULL, source_basis TEXT NOT NULL, status TEXT NOT NULL CHECK (status = 'inactive'), FOREIGN KEY (revision_id) REFERENCES grocy_ai_conversion_profile_revisions(id), FOREIGN KEY (from_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key), FOREIGN KEY (to_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key))");
		$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS grocy_ai_conversion_profiles_leaf_pair_idx ON grocy_ai_conversion_profiles (revision_id, taxonomy_leaf_id, from_unit_key, to_unit_key)');

		// Plan 04-08: the immutable dual-branch evidence ledger and the reusable rule revisions it
		// owns. `grocy_ai_conversion_rule_revisions` is the sole owner of reusable universal and
		// profile source, source version, precise factor, revision hash, and inactive/active
		// lifecycle; native `quantity_unit_conversions` keeps only normal product-scoped rows and
		// the universal rows the activation transaction creates.
		$pdo->exec('CREATE TABLE IF NOT EXISTS grocy_ai_conversion_activation_evidence (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, main_commit TEXT NOT NULL, stable_commit TEXT NOT NULL, characterization_sha256 TEXT NOT NULL, selected_adapter TEXT NOT NULL, cache_key_schema TEXT NOT NULL, query_plan_sha256 TEXT NOT NULL, migration_hashes TEXT NOT NULL, cache_objects TEXT NOT NULL, protected_outputs_sha256 TEXT NOT NULL, evidence_hash TEXT NOT NULL UNIQUE, recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
		$pdo->exec("CREATE TABLE IF NOT EXISTS grocy_ai_conversion_rule_revisions (id TEXT NOT NULL PRIMARY KEY, kind TEXT NOT NULL CHECK (kind IN ('universal', 'profile')), version TEXT NOT NULL, source_name TEXT NOT NULL, source_version TEXT NOT NULL, from_unit_key TEXT NOT NULL, to_unit_key TEXT NOT NULL, factor TEXT NOT NULL, revision_hash TEXT NOT NULL, status TEXT NOT NULL CHECK (status IN ('inactive', 'active')), activation_evidence_id INTEGER NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (activation_evidence_id) REFERENCES grocy_ai_conversion_activation_evidence(id), FOREIGN KEY (from_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key), FOREIGN KEY (to_unit_key) REFERENCES grocy_ai_conversion_catalog_units(unit_key))");
		$pdo->exec('CREATE INDEX IF NOT EXISTS grocy_ai_conversion_rule_revisions_status_idx ON grocy_ai_conversion_rule_revisions (status, kind, from_unit_key, to_unit_key)');
	}

	/**
	 * The deterministic identity and integrity hash of one reusable rule revision. The hash covers
	 * every field the activation gate must prove, so a tampered factor or source version can never
	 * present itself as the revision a maintainer reviewed.
	 */
	public static function RevisionHash(string $kind, string $sourceName, string $sourceVersion, string $fromUnitKey, string $toUnitKey, string $factor): string
	{
		return hash('sha256', json_encode([
			'kind' => $kind,
			'version' => self::VERSION,
			'source_name' => $sourceName,
			'source_version' => $sourceVersion,
			'from_unit_key' => $fromUnitKey,
			'to_unit_key' => $toUnitKey,
			'factor' => $factor
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}

	private static function Seed(PDO $pdo): void
	{
		$units = [
			['mg', 'mass', '0.001'],
			['g', 'mass', '1'],
			['kg', 'mass', '1000'],
			['oz', 'mass', '28.349523125'],
			['lb', 'mass', '453.59237'],
			['ml', 'volume', '0.001'],
			['l', 'volume', '1'],
			['tsp', 'volume', '0.00492892159375'],
			['tbsp', 'volume', '0.01478676478125'],
			['cup', 'volume', '0.2365882365'],
			['fl_oz', 'volume', '0.0295735295625'],
			['pint', 'volume', '0.473176473'],
			['quart', 'volume', '0.946352946'],
			['gallon', 'volume', '3.785411784']
		];
		$unitStatement = $pdo->prepare('INSERT OR IGNORE INTO grocy_ai_conversion_catalog_units (unit_key, dimension, metric_factor, source_version) VALUES (?, ?, ?, ?)');
		foreach ($units as [$key, $dimension, $factor])
		{
			$unitStatement->execute([$key, $dimension, $factor, self::SOURCE_VERSION]);
		}

		$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_conversion_revisions (id, version, status, source_version) VALUES (?, ?, ?, ?)')
			->execute([self::INACTIVE_REVISION_ID, self::VERSION, 'inactive', self::SOURCE_VERSION]);

		$anchors = [
			['mg', 'g', '0.001'], ['kg', 'g', '1000'], ['oz', 'g', '28.349523125'], ['lb', 'g', '453.59237'],
			['ml', 'l', '0.001'], ['tsp', 'l', '0.00492892159375'], ['tbsp', 'l', '0.01478676478125'],
			['cup', 'l', '0.2365882365'], ['fl_oz', 'l', '0.0295735295625'], ['pint', 'l', '0.473176473'],
			['quart', 'l', '0.946352946'], ['gallon', 'l', '3.785411784']
		];
		$ruleStatement = $pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM grocy_ai_conversion_rules WHERE revision_id = ? AND from_unit_key = ? AND to_unit_key = ?)');
		foreach ($anchors as [$from, $to, $factor])
		{
			$ruleStatement->execute([self::INACTIVE_REVISION_ID, $from, $to, $factor, self::SOURCE_VERSION, self::INACTIVE_REVISION_ID, $from, $to]);
		}

		$pdo->prepare('INSERT OR IGNORE INTO grocy_ai_conversion_profile_revisions (id, version, status) VALUES (?, ?, ?)')
			->execute([self::INACTIVE_PROFILE_REVISION_ID, self::VERSION, 'inactive']);
		$profiles = [
			['water-like-beverage', 'leaf-beverages', 'cup', 'g', '237', '174158', '1 cup = 237 g'],
			['whole-milk', 'leaf-dairy-eggs', 'cup', 'g', '244', '171265', '1 cup = 244 g'],
			['olive-oil', 'leaf-oils-vinegars', 'tbsp', 'g', '13.5', '171413', '1 tablespoon = 13.5 g']
		];
		$profileStatement = $pdo->prepare('INSERT OR IGNORE INTO grocy_ai_conversion_profiles (profile_key, revision_id, taxonomy_leaf_id, from_unit_key, to_unit_key, factor, approximate, source_name, source_item_id, source_version, source_basis, status) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)');
		foreach ($profiles as [$profileKey, $taxonomyLeafId, $fromUnitKey, $toUnitKey, $factor, $sourceItemId, $sourceBasis])
		{
			$profileStatement->execute([$profileKey, self::INACTIVE_PROFILE_REVISION_ID, $taxonomyLeafId, $fromUnitKey, $toUnitKey, $factor, self::PROFILE_SOURCE_NAME, $sourceItemId, self::PROFILE_SOURCE_VERSION, $sourceBasis, 'inactive']);
		}

		// Every reusable rule gets an inactive, source/version-owned revision at bootstrap. Nothing
		// here may create an active revision: only `ActivateVerifiedRuleset` transitions one.
		$revisionStatement = $pdo->prepare("INSERT INTO grocy_ai_conversion_rule_revisions (id, kind, version, source_name, source_version, from_unit_key, to_unit_key, factor, revision_hash, status) SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inactive' WHERE NOT EXISTS (SELECT 1 FROM grocy_ai_conversion_rule_revisions WHERE id = ?)");
		foreach ($anchors as [$from, $to, $factor])
		{
			$revisionStatement->execute([
				self::UniversalRevisionId($from, $to), 'universal', self::VERSION, self::UNIVERSAL_SOURCE_NAME, self::SOURCE_VERSION,
				$from, $to, $factor,
				self::RevisionHash('universal', self::UNIVERSAL_SOURCE_NAME, self::SOURCE_VERSION, $from, $to, $factor),
				self::UniversalRevisionId($from, $to)
			]);
		}
		foreach ($profiles as [$profileKey, , $fromUnitKey, $toUnitKey, $factor])
		{
			$revisionStatement->execute([
				self::ProfileRevisionId($profileKey), 'profile', self::VERSION, self::PROFILE_SOURCE_NAME, self::PROFILE_SOURCE_VERSION,
				$fromUnitKey, $toUnitKey, $factor,
				self::RevisionHash('profile', self::PROFILE_SOURCE_NAME, self::PROFILE_SOURCE_VERSION, $fromUnitKey, $toUnitKey, $factor),
				self::ProfileRevisionId($profileKey)
			]);
		}
	}

	public static function UniversalRevisionId(string $fromUnitKey, string $toUnitKey): string
	{
		return 'universal-' . $fromUnitKey . '-' . $toUnitKey;
	}

	public static function ProfileRevisionId(string $profileKey): string
	{
		return 'profile-' . $profileKey;
	}
}
