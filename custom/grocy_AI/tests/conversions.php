<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiConversionMigration;
use GrocyAI\Services\GrocyAiConversionService;

function conversionRulesPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE quantity_units (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	$units = [
		[1, 'kg'], [2, 'lb'], [3, 'mL'], [4, 'L'], [5, 'pack'], [6, 'count'], [7, 'g'], [8, 'tsp'], [9, 'fl oz'], [10, 'piece']
	];
	$insert = $pdo->prepare('INSERT INTO quantity_units (id, name) VALUES (?, ?)');
	foreach ($units as [$id, $name])
	{
		$insert->execute([$id, $name]);
	}
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (91, 11, 5, 7, 12)');
	return $pdo;
}

function conversionAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
	if ($expected !== $actual)
	{
		throw new RuntimeException($message === '' ? 'conversion_assert_same_failed' : $message);
	}
}

function conversionAssertBlocked(GrocyAiConversionService $service, array $candidate, string $blocker): void
{
	$result = $service->ValidateNativeConversionBeforeWrite($candidate, null);
	conversionAssertSame('blocked', $result['status'], 'invalid conversion must be blocked');
	conversionAssertSame([$blocker], $result['blockers'], 'conversion blocker must be bounded and deterministic');
}

function conversionAssertAllowed(GrocyAiConversionService $service, array $candidate, string $status): void
{
	$result = $service->ValidateNativeConversionBeforeWrite($candidate, null);
	conversionAssertSame($status, $result['status'], 'eligible conversion must retain its scope status');
	conversionAssertSame([], $result['blockers'], 'eligible conversion must have no blockers');
}

function crossDimensionCandidate(): array
{
	return ['product_id' => null, 'from_qu_id' => 7, 'to_qu_id' => 3, 'factor' => '1'];
}

function reusablePackageCandidate(): array
{
	return ['product_id' => null, 'from_qu_id' => 5, 'to_qu_id' => 7, 'factor' => '12'];
}

function productScopedPackageCandidate(): array
{
	return ['product_id' => 11, 'from_qu_id' => 5, 'to_qu_id' => 7, 'factor' => '12'];
}

function productScopedDensityCandidate(): array
{
	return ['product_id' => 11, 'from_qu_id' => 7, 'to_qu_id' => 3, 'factor' => '0.9'];
}

function runConversionRules(): never
{
	if (!class_exists(GrocyAiConversionMigration::class) || !class_exists(GrocyAiConversionService::class))
	{
		expectedRed('EXPECTED_RED: conversion-rules', 'The inactive conversion migration and service are not implemented');
	}

	$pdo = conversionRulesPdo();
	$nativeBefore = $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	GrocyAiConversionMigration::Bootstrap($pdo);
	$service = new GrocyAiConversionService($pdo);

	$catalog = $pdo->query('SELECT unit_key, dimension, metric_factor, source_version FROM grocy_ai_conversion_catalog_units ORDER BY unit_key')->fetchAll(PDO::FETCH_ASSOC);
	conversionAssertSame([
		['unit_key' => 'cup', 'dimension' => 'volume', 'metric_factor' => '0.2365882365', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'fl_oz', 'dimension' => 'volume', 'metric_factor' => '0.0295735295625', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'g', 'dimension' => 'mass', 'metric_factor' => '1', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'gallon', 'dimension' => 'volume', 'metric_factor' => '3.785411784', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'kg', 'dimension' => 'mass', 'metric_factor' => '1000', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'l', 'dimension' => 'volume', 'metric_factor' => '1', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'lb', 'dimension' => 'mass', 'metric_factor' => '453.59237', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'mg', 'dimension' => 'mass', 'metric_factor' => '0.001', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'ml', 'dimension' => 'volume', 'metric_factor' => '0.001', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'oz', 'dimension' => 'mass', 'metric_factor' => '28.349523125', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'pint', 'dimension' => 'volume', 'metric_factor' => '0.473176473', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'quart', 'dimension' => 'volume', 'metric_factor' => '0.946352946', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'tbsp', 'dimension' => 'volume', 'metric_factor' => '0.01478676478125', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'tsp', 'dimension' => 'volume', 'metric_factor' => '0.00492892159375', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9']
	], $catalog, 'catalog must contain exactly the source-versioned D-01 identities and factors');
	conversionAssertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name LIKE 'cache__quantity_unit_conversions_resolved%'")->fetchAll(PDO::FETCH_COLUMN), 'inactive bootstrap must not project cache objects');

	$result = $service->ValidateNativeConversionBeforeWrite([
		'product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'
	], null);
	conversionAssertSame('inactive', $result['status'], 'valid reusable mass candidate must remain inactive');
	conversionAssertSame(['status', 'scope', 'blockers', 'factor', 'dimension', 'source_version', 'inactive_revision_id'], array_keys($result), 'conversion validation DTO keys must stay fixed');
	conversionAssertSame('mass', $result['dimension'], 'same-dimension catalog candidate must disclose its dimension');
	conversionAssertSame('2.2046226218487757', $result['factor'], 'candidate factor must retain precision without display rounding');
	conversionAssertSame('conversion-catalog-v1', $result['inactive_revision_id'], 'valid reusable candidate must identify its inactive revision');

	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '0'], 'factor_non_positive');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => 'NAN'], 'factor_not_finite');
	conversionAssertBlocked($service, crossDimensionCandidate(), 'dimension_mismatch');
	conversionAssertBlocked($service, reusablePackageCandidate(), 'reusable_count_scope');
	conversionAssertAllowed($service, productScopedPackageCandidate(), 'product_native');
	conversionAssertAllowed($service, productScopedDensityCandidate(), 'product_native');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.1'], 'factor_tolerance');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757', 'inactive_revision_id' => 'stale'], 'stale_revision_identity');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET source_version = 'tampered-catalog-source' WHERE unit_key = 'lb'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE unit_key = 'lb'");
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET metric_factor = 'NAN', source_version = 'tampered-unrelated-source' WHERE unit_key = 'gallon'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET metric_factor = '3.785411784', source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE unit_key = 'gallon'");
	$pdo->exec("UPDATE grocy_ai_conversion_revisions SET source_version = 'tampered-revision-source' WHERE id = 'conversion-catalog-v1'");
	$revisionSource = $service->ValidateNativeConversionBeforeWrite(['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], null);
	conversionAssertSame('blocked', $revisionSource['status'], 'tampered revision provenance must block reusable validation');
	conversionAssertSame(['revision_source_version_invalid'], $revisionSource['blockers'], 'tampered revision provenance must return a bounded blocker');
	conversionAssertSame('tampered-revision-source', $revisionSource['source_version'], 'validation DTO must disclose stored, not hard-coded, revision provenance');
	$pdo->exec("UPDATE grocy_ai_conversion_revisions SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE id = 'conversion-catalog-v1'");
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET source_version = 'tampered-rule-source' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_rule_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET factor = '1001' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_rule_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET factor = '1000' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'g', 'kg', '0.0011', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'reciprocal_mismatch');
	$pdo->exec("DELETE FROM grocy_ai_conversion_rules WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'g' AND to_unit_key = 'kg'");
	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'kg', 'g', '1001', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'competing_path');
	$pdo->exec("DELETE FROM grocy_ai_conversion_rules WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g' AND factor = '1001'");

	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'kg', 'lb', '2.2046226218487757', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'cycle_detected');

	if ($nativeBefore !== $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
	{
		throw new RuntimeException('inactive_catalog_mutated_native_conversions');
	}
	if ((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_conversion_revisions WHERE status = \'inactive\'')->fetchColumn() !== 1)
	{
		throw new RuntimeException('inactive_revision_missing');
	}

	fwrite(STDOUT, "Conversion rule tests passed\n");
	exit(0);
}

function runConversionCharacterization(string $mainRoot, string $stableRoot, string $fixtureRoot, string $blockedDataPath): array
{
	$openedPaths = [];
	$blockedDataPath = conversionCharacterizationNormalizePath($blockedDataPath);
	$fixtureRoot = conversionCharacterizationNormalizePath($fixtureRoot);
	if (conversionCharacterizationIsAtOrBelow($fixtureRoot, $blockedDataPath))
	{
		throw new RuntimeException('fixture_path_inside_grocy_datapath');
	}

	$mainManifest = conversionCharacterizationLoadManifest($fixtureRoot . '/conversion-characterization-main.json', 'main', $openedPaths);
	$stableManifest = conversionCharacterizationLoadManifest($fixtureRoot . '/conversion-characterization-stable.json', 'stable', $openedPaths);
	$mainRoot = conversionCharacterizationNormalizePath($mainRoot);
	$stableRoot = conversionCharacterizationNormalizePath($stableRoot);
	if ($mainRoot === $stableRoot)
	{
		throw new RuntimeException('identical_branch_roots');
	}
	conversionCharacterizationAssertBranchIdentity($mainRoot, $mainManifest);
	conversionCharacterizationAssertBranchIdentity($stableRoot, $stableManifest);
	$main = CharacterizeBranch('main', $mainRoot, $mainManifest, $blockedDataPath);
	$stable = CharacterizeBranch('stable', $stableRoot, $stableManifest, $blockedDataPath);
	if ($main['commit'] !== $mainManifest['expected_commit'] || $stable['commit'] !== $stableManifest['expected_commit'])
	{
		throw new RuntimeException('branch_commit_mismatch');
	}

	$parity = conversionCharacterizationAssertBranchParity($main, $stable);
	$manifest = conversionCharacterizationRedactedManifest($main, $stable);

	return [
		'main' => $main,
		'stable' => $stable,
		'manifest' => $manifest,
		'manifest_json' => conversionCharacterizationRedactedManifestJson($manifest),
		'opened_paths' => array_values(array_unique(array_merge($openedPaths, $main['opened_paths'], $stable['opened_paths']))),
		'protected_outputs' => [
			'equal' => $parity['protected_outputs_equal'],
			'categories' => $main['protected_outputs']['categories'],
			'schema_equal' => $parity['schema_equal'],
			'cache_equal' => $parity['cache_equal'],
			'query_plan_equal' => $parity['query_plan_equal']
		]
	];
}

function CharacterizeBranch(string $branchName, string $root, array $manifest, string $blockedDataPath): array
{
	$root = conversionCharacterizationNormalizePath($root);
	$openedPaths = [];
	conversionCharacterizationValidateBranchRoot($root, $blockedDataPath, $openedPaths);
	$temporaryDatabase = conversionCharacterizationCreateTemporaryDatabase($blockedDataPath, $openedPaths);
	$report = [];
	$database = null;
	try
	{
		$database = new PDO('sqlite:' . $temporaryDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		conversionCharacterizationBuildFixtureSchema($database, $root, $openedPaths);
		conversionCharacterizationAssertNativeTriggers($database);
		conversionCharacterizationSeedFixture($database, $manifest);
		$baseline = conversionCharacterizationProtectedOutputs($database, $manifest['protected_categories']);
		$baselineRows = conversionCharacterizationCacheRows($database);
		conversionCharacterizationExerciseNativeWrites($database, $manifest);
		$probe = conversionCharacterizationProtectedOutputs($database, $manifest['protected_categories']);
		$probeRows = conversionCharacterizationCacheRows($database);
		if ($baseline !== $probe)
		{
			throw new RuntimeException('protected_outputs_changed');
		}
		$cache = conversionCharacterizationCacheDelta($baselineRows, $probeRows);
		conversionCharacterizationAssertProbeCacheParity($cache['baseline'], $cache['probe']);

		$report = [
			'branch' => $branchName,
			'commit' => conversionCharacterizationImmutableCommit($root),
			'schema' => array_merge(conversionCharacterizationSchemaManifest($root, $openedPaths), [
				'fixture_sqlite_master' => RedactedSqliteMaster($database)
			]),
			'cache' => $cache,
			'protected_outputs' => [
				'categories' => $manifest['protected_categories'],
				'baseline' => $baseline,
				'probe' => $probe
			],
			'query_plan' => conversionCharacterizationQueryPlan($database),
			'opened_paths' => $openedPaths,
			'fixture_deleted' => false
		];
	}
	finally
	{
		$database = null;
		clearstatcache(true, $temporaryDatabase);
		if (is_file($temporaryDatabase))
		{
			if (!unlink($temporaryDatabase))
			{
				throw new RuntimeException('fixture_cleanup_failed');
			}
		}
		clearstatcache(true, $temporaryDatabase);
		if (file_exists($temporaryDatabase))
		{
			throw new RuntimeException('fixture_cleanup_failed');
		}
		if ($report !== [])
		{
			$report['fixture_deleted'] = !file_exists($temporaryDatabase);
		}
	}
	return $report;
}

function conversionCharacterizationNormalizePath(string $value): string
{
	$resolved = realpath($value);
	return rtrim(str_replace('\\', '/', $resolved === false ? $value : $resolved), '/');
}

function conversionCharacterizationConfiguredDataPath(string $checkoutRoot): string
{
	$configured = getenv('GROCY_DATAPATH');
	if (!is_string($configured) || $configured === '')
	{
		$configured = $checkoutRoot . '/data';
	}
	if ($configured[0] !== '/')
	{
		$configured = $checkoutRoot . '/' . $configured;
	}
	return conversionCharacterizationNormalizePath($configured);
}

function conversionCharacterizationAssertBranchParity(array $main, array $stable): array
{
	$schemaEqual = $main['schema']['migration_hashes'] === $stable['schema']['migration_hashes']
		&& $main['schema']['cache_objects'] === $stable['schema']['cache_objects']
		&& $main['schema']['fixture_sqlite_master'] === $stable['schema']['fixture_sqlite_master'];
	$cacheEqual = $main['cache']['baseline'] === $stable['cache']['baseline']
		&& $main['cache']['probe'] === $stable['cache']['probe'];
	$queryPlanEqual = $main['query_plan'] === $stable['query_plan'];
	$protectedOutputsEqual = $main['protected_outputs']['baseline'] === $stable['protected_outputs']['baseline']
		&& $main['protected_outputs']['probe'] === $stable['protected_outputs']['probe'];
	if (!$schemaEqual || !$cacheEqual || !$queryPlanEqual || !$protectedOutputsEqual)
	{
		throw new RuntimeException('branch_characterization_mismatch');
	}
	return [
		'schema_equal' => $schemaEqual,
		'cache_equal' => $cacheEqual,
		'query_plan_equal' => $queryPlanEqual,
		'protected_outputs_equal' => $protectedOutputsEqual
	];
}

function conversionCharacterizationRedactedManifest(array $main, array $stable): array
{
	$branchManifest = static fn(array $branch): array => [
		'commit' => $branch['commit'],
		'migration_hashes' => $branch['schema']['migration_hashes'],
		'cache_aggregate' => $branch['cache'],
		'query_plan' => $branch['query_plan'],
		'query_plan_sha256' => hash('sha256', json_encode($branch['query_plan'], JSON_THROW_ON_ERROR))
	];
	return ['main' => $branchManifest($main), 'stable' => $branchManifest($stable)];
}

function conversionCharacterizationRedactedManifestJson(array $manifest): string
{
	if (isset($manifest['manifest']) && is_array($manifest['manifest']))
	{
		$manifest = $manifest['manifest'];
	}
	return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function conversionCharacterizationVerifyEvidenceDocument(string $evidencePath, array $manifest): void
{
	if (!is_file($evidencePath))
	{
		throw new RuntimeException('characterization_evidence_missing');
	}
	$evidence = (string)file_get_contents($evidencePath);
	foreach (['main', 'stable'] as $branchName)
	{
		$branch = $manifest[$branchName];
		$required = [
			$branch['commit'],
			$branch['migration_hashes']['migrations/0208.sql'],
			$branch['migration_hashes']['migrations/0225.sql'],
			(string)$branch['cache_aggregate']['baseline']['row_count'],
			$branch['cache_aggregate']['baseline']['row_key_factor_path_sha256'],
			$branch['query_plan_sha256']
		];
		foreach ($required as $value)
		{
			if (!str_contains($evidence, $value))
			{
				throw new RuntimeException('characterization_evidence_mismatch');
			}
		}
	}
}

function conversionCharacterizationIsAtOrBelow(string $candidate, string $parent): bool
{
	return $candidate === $parent || str_starts_with($candidate . '/', $parent . '/');
}

function conversionCharacterizationLoadManifest(string $manifestPath, string $branchName, array &$openedPaths): array
{
	if (!is_file($manifestPath))
	{
		throw new RuntimeException('missing_branch_manifest');
	}
	$openedPaths[] = conversionCharacterizationNormalizePath($manifestPath);
	try
	{
		$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
	}
	catch (JsonException)
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	if (!is_array($manifest)
		|| ($manifest['branch'] ?? null) !== $branchName
		|| !is_string($manifest['expected_commit'] ?? null)
		|| preg_match('/^[a-f0-9]{40}$/', $manifest['expected_commit']) !== 1
		|| !is_array($manifest['native_default'] ?? null)
		|| !is_array($manifest['product_override'] ?? null)
		|| !is_array($manifest['protected_categories'] ?? null))
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	$requiredCategories = ['stock', 'recipe', 'purchase', 'consumption', 'price', 'transfer', 'meal-plan', 'quantity-display'];
	if ($manifest['protected_categories'] !== $requiredCategories)
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	return $manifest;
}

function conversionCharacterizationAssertBranchIdentity(string $root, array $manifest): void
{
	$commit = conversionCharacterizationImmutableCommit($root);
	if ($commit !== $manifest['expected_commit'])
	{
		throw new RuntimeException('branch_commit_mismatch');
	}
	$status = conversionCharacterizationGitOutput($root, ['status', '--porcelain', '--', 'migrations/0208.sql', 'migrations/0225.sql']);
	if (trim($status) !== '')
	{
		throw new RuntimeException('dirty_branch_source');
	}
}

function conversionCharacterizationValidateBranchRoot(string $root, string $blockedDataPath, array &$openedPaths): void
{
	if (conversionCharacterizationIsAtOrBelow($root, $blockedDataPath)
		|| (!is_dir($root . '/.git') && !is_file($root . '/.git')))
	{
		throw new RuntimeException('invalid_branch_root');
	}
	foreach (['migrations/0208.sql', 'migrations/0225.sql'] as $relativePath)
	{
		$sourcePath = $root . '/' . $relativePath;
		if (!is_file($sourcePath))
		{
			throw new RuntimeException('missing_cache_definitions');
		}
		$openedPaths[] = $sourcePath;
	}
}

function conversionCharacterizationCreateTemporaryDatabase(string $blockedDataPath, array &$openedPaths): string
{
	$temporaryDirectory = conversionCharacterizationNormalizePath(sys_get_temp_dir());
	if (conversionCharacterizationIsAtOrBelow($temporaryDirectory, $blockedDataPath))
	{
		throw new RuntimeException('temporary_fixture_inside_grocy_datapath');
	}
	$temporaryDatabase = tempnam($temporaryDirectory, 'grocy-conversion-characterization-');
	if ($temporaryDatabase === false)
	{
		throw new RuntimeException('temporary_fixture_creation_failed');
	}
	$temporaryDatabase = conversionCharacterizationNormalizePath($temporaryDatabase);
	if (conversionCharacterizationIsAtOrBelow($temporaryDatabase, $blockedDataPath))
	{
		unlink($temporaryDatabase);
		throw new RuntimeException('temporary_fixture_inside_grocy_datapath');
	}
	$openedPaths[] = $temporaryDatabase;
	return $temporaryDatabase;
}

function conversionCharacterizationBuildFixtureSchema(PDO $database, string $root, array &$openedPaths): void
{
	$database->exec(<<<'SQL'
CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_plural TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY, qu_id_stock INTEGER NOT NULL, qu_id_purchase INTEGER NOT NULL, qu_id_consume INTEGER NOT NULL, qu_id_price INTEGER NOT NULL);
CREATE TABLE quantity_unit_conversions (from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, product_id INTEGER, UNIQUE(product_id, from_qu_id, to_qu_id));
CREATE VIEW quantity_unit_conversions_resolved AS SELECT NULL AS id, NULL AS product_id, NULL AS from_qu_id, NULL AS from_qu_name, NULL AS from_qu_name_plural, NULL AS to_qu_id, NULL AS to_qu_name, NULL AS to_qu_name_plural, NULL AS factor, NULL AS path WHERE 0;
CREATE TRIGGER qu_conversions_inverse_INS AFTER INSERT ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_UPD AFTER UPDATE ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_DEL AFTER DELETE ON quantity_unit_conversions BEGIN SELECT 1; END;
SQL);

	$resolverSql = conversionCharacterizationGitOutput($root, ['show', 'HEAD:migrations/0208.sql']);
	$cacheSql = conversionCharacterizationGitOutput($root, ['show', 'HEAD:migrations/0225.sql']);
	$cachePrefix = strstr($cacheSql, 'DROP VIEW recipes_pos_resolved;', true);
	if ($resolverSql === '' || $cachePrefix === false)
	{
		throw new RuntimeException('missing_cache_definitions');
	}

	$database->exec($resolverSql);
	$database->exec($cachePrefix);
	$database->exec(<<<'SQL'
CREATE TABLE fixture_stock_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, on_hand REAL NOT NULL, adjustment REAL NOT NULL);
CREATE TABLE fixture_recipe_positions (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, ingredient_amount REAL NOT NULL, servings REAL NOT NULL);
CREATE TABLE fixture_purchase_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, package_count REAL NOT NULL, package_amount REAL NOT NULL);
CREATE TABLE fixture_consumption_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, consumed_amount REAL NOT NULL);
CREATE TABLE fixture_price_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, priced_amount REAL NOT NULL, unit_price REAL NOT NULL);
CREATE TABLE fixture_transfer_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, sent_amount REAL NOT NULL, returned_amount REAL NOT NULL);
CREATE TABLE fixture_meal_plan_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, portion_amount REAL NOT NULL, servings REAL NOT NULL);
CREATE TABLE fixture_quantity_display_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, display_amount REAL NOT NULL);
SQL);
}

function conversionCharacterizationSeedFixture(PDO $database, array $manifest): void
{
	$database->exec("INSERT INTO quantity_units VALUES (1, 'stock', 'stocks'), (2, 'purchase', 'purchases'), (3, 'display', 'displays')");
	$database->exec('INSERT INTO products VALUES (1, 1, 2, 3, 3)');
	conversionCharacterizationInsertConversion($database, $manifest['native_default']);
	conversionCharacterizationInsertConversion($database, $manifest['product_override']);
	$database->exec(<<<'SQL'
INSERT INTO fixture_stock_entries VALUES (1, 1, 3, 3, 1);
INSERT INTO fixture_recipe_positions VALUES (1, 3, 1, 7, 1);
INSERT INTO fixture_purchase_entries VALUES (1, 2, 1, 2, 500);
INSERT INTO fixture_consumption_entries VALUES (1, 3, 1, 9);
INSERT INTO fixture_price_entries VALUES (1, 3, 1, 11, 1);
INSERT INTO fixture_transfer_entries VALUES (1, 1, 3, 5, 2);
INSERT INTO fixture_meal_plan_entries VALUES (1, 3, 1, 13, 1);
INSERT INTO fixture_quantity_display_entries VALUES (1, 2, 1, 1500);
SQL);
}

function conversionCharacterizationAssertNativeTriggers(PDO $database): void
{
	$triggers = $database->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name IN ('quantity_unit_conversions_INS', 'quantity_unit_conversions_UPD', 'quantity_unit_conversions_DEL') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	if ($triggers !== ['quantity_unit_conversions_DEL', 'quantity_unit_conversions_INS', 'quantity_unit_conversions_UPD'])
	{
		throw new RuntimeException('missing_cache_definitions');
	}
}

function conversionCharacterizationExerciseNativeWrites(PDO $database, array $manifest): void
{
	conversionCharacterizationUpdateConversion($database, $manifest['native_default']);
	conversionCharacterizationUpdateConversion($database, $manifest['product_override']);
	conversionCharacterizationReplaceConversion($database, $manifest['native_default']);
	conversionCharacterizationReplaceConversion($database, $manifest['product_override']);
}

function conversionCharacterizationInsertConversion(PDO $database, array $conversion): void
{
	$statement = $database->prepare('INSERT INTO quantity_unit_conversions (product_id, from_qu_id, to_qu_id, factor) VALUES (:product_id, :from_qu_id, :to_qu_id, :factor)');
	$statement->execute([
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null,
		':factor' => $conversion['factor'] ?? null
	]);
}

function conversionCharacterizationReplaceConversion(PDO $database, array $conversion): void
{
	$delete = $database->prepare('DELETE FROM quantity_unit_conversions WHERE product_id IS :product_id AND from_qu_id = :from_qu_id AND to_qu_id = :to_qu_id');
	$delete->execute([
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null
	]);
	conversionCharacterizationInsertConversion($database, $conversion);
}

function conversionCharacterizationUpdateConversion(PDO $database, array $conversion): void
{
	$update = $database->prepare('UPDATE quantity_unit_conversions SET factor = :factor WHERE product_id IS :product_id AND from_qu_id = :from_qu_id AND to_qu_id = :to_qu_id');
	$update->execute([
		':factor' => $conversion['factor'] ?? null,
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null
	]);
	if ($update->rowCount() !== 1)
	{
		throw new RuntimeException('native_trigger_not_exercised');
	}
}

function conversionCharacterizationCacheRows(PDO $database): array
{
	return $database->query('SELECT product_id, from_qu_id, to_qu_id, factor, path FROM cache__quantity_unit_conversions_resolved ORDER BY product_id, from_qu_id, to_qu_id')->fetchAll(PDO::FETCH_ASSOC);
}

function conversionCharacterizationCacheDelta(array $baseline, array $probe): array
{
	$aggregate = static fn(array $rows): array => [
		'row_count' => count($rows),
		'row_key_factor_path_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))
	];
	return ['baseline' => $aggregate($baseline), 'probe' => $aggregate($probe), 'equal' => $baseline === $probe];
}

function conversionCharacterizationAssertProbeCacheParity(array $baseline, array $probe): void
{
	if ($baseline !== $probe)
	{
		throw new RuntimeException('cache_aggregate_changed');
	}
}

function conversionCharacterizationProtectedOutputs(PDO $database, array $categories): array
{
	$queries = conversionCharacterizationProtectedOutputQueries();
	$outputs = [];
	foreach ($categories as $category)
	{
		if (!isset($queries[$category]))
		{
			throw new RuntimeException('invalid_branch_manifest');
		}
		$fixture = $queries[$category];
		$row = $database->query($fixture['sql'])->fetch(PDO::FETCH_ASSOC);
		if ($row === false)
		{
			throw new RuntimeException('protected_output_missing');
		}
		$outputs[$category] = [
			'operation' => $fixture['operation'],
			'factor' => (float)$row['factor'],
			'path' => (string)$row['path'],
			'converted_amount' => (float)$row['converted_amount']
		];
	}
	return $outputs;
}

function conversionCharacterizationProtectedOutputQueries(): array
{
	return [
		'stock' => ['operation' => 'stock-adjustment', 'sql' => 'SELECT (e.on_hand + e.adjustment) * c.factor AS converted_amount, c.factor, c.path FROM fixture_stock_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'recipe' => ['operation' => 'recipe-ingredient', 'sql' => 'SELECT e.ingredient_amount * e.servings * c.factor AS converted_amount, c.factor, c.path FROM fixture_recipe_positions e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'purchase' => ['operation' => 'purchase-entry', 'sql' => 'SELECT e.package_count * e.package_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_purchase_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'consumption' => ['operation' => 'consumption-entry', 'sql' => 'SELECT e.consumed_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_consumption_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'price' => ['operation' => 'price-entry', 'sql' => 'SELECT e.priced_amount * e.unit_price * c.factor AS converted_amount, c.factor, c.path FROM fixture_price_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'transfer' => ['operation' => 'transfer-entry', 'sql' => 'SELECT (e.sent_amount - e.returned_amount) * c.factor AS converted_amount, c.factor, c.path FROM fixture_transfer_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'meal-plan' => ['operation' => 'meal-plan-entry', 'sql' => 'SELECT e.portion_amount * e.servings * c.factor AS converted_amount, c.factor, c.path FROM fixture_meal_plan_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'quantity-display' => ['operation' => 'quantity-display', 'sql' => 'SELECT e.display_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_quantity_display_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id']
	];
}

function conversionCharacterizationSchemaManifest(string $root, array &$openedPaths): array
{
	$objects = [];
	$hashes = [];
	foreach (['migrations/0208.sql', 'migrations/0225.sql'] as $relativePath)
	{
		$source = conversionCharacterizationGitOutput($root, ['show', 'HEAD:' . $relativePath]);
		$containsResolver = str_contains($source, 'quantity_unit_conversions_resolved');
		$containsCache = str_contains($source, 'cache__quantity_unit_conversions_resolved');
		$containsTrigger = str_contains($source, 'quantity_unit_conversions_INS');
		if (!$containsResolver || ($relativePath === 'migrations/0225.sql' && (!$containsCache || !$containsTrigger)))
		{
			throw new RuntimeException('missing_cache_definitions');
		}
		$hashes[$relativePath] = hash('sha256', $source);
		preg_match_all('/CREATE (?:TABLE|TRIGGER|INDEX)\s+([a-zA-Z0-9_]+)/', $source, $matches);
		$objects[$relativePath] = array_values(array_filter($matches[1], static fn(string $name): bool => str_contains($name, 'quantity_unit_conversions')));
	}
	return ['migration_hashes' => $hashes, 'cache_objects' => $objects];
}

function RedactedSqliteMaster(PDO $database): array
{
	$statement = $database->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE 'cache__quantity_unit_conversions_resolved%' OR name LIKE 'quantity_unit_conversions_%' ORDER BY type, name");
	$objects = [];
	foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$objects[] = [
			'type' => (string)$row['type'],
			'name' => (string)$row['name'],
			'sql_sha256' => hash('sha256', (string)$row['sql'])
		];
	}
	return $objects;
}

function conversionCharacterizationQueryPlan(PDO $database): array
{
	$plans = [];
	foreach (conversionCharacterizationProtectedOutputQueries() as $category => $fixture)
	{
		$statement = $database->query('EXPLAIN QUERY PLAN ' . $fixture['sql']);
		$plans[$category] = array_map(static fn(array $row): string => (string)$row['detail'], $statement->fetchAll(PDO::FETCH_ASSOC));
	}
	return $plans;
}

function conversionCharacterizationGitOutput(string $root, array $arguments): string
{
	$process = proc_open(array_merge(['git', '-C', $root], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process))
	{
		throw new RuntimeException('invalid_branch_root');
	}
	$stdout = stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0)
	{
		throw new RuntimeException('invalid_branch_root');
	}
	return $stdout;
}

function conversionCharacterizationImmutableCommit(string $root): string
{
	$commit = trim(conversionCharacterizationGitOutput($root, ['rev-parse', 'HEAD']));
	if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1)
	{
		throw new RuntimeException('invalid_branch_root');
	}
	return $commit;
}
