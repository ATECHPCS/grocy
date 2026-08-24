<?php

declare(strict_types=1);

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
	$main = CharacterizeBranch('main', $mainRoot, $mainManifest, $blockedDataPath);
	$stable = CharacterizeBranch('stable', $stableRoot, $stableManifest, $blockedDataPath);

	$parity = conversionCharacterizationAssertBranchParity($main, $stable);

	return [
		'main' => $main,
		'stable' => $stable,
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

		$report = [
			'branch' => $branchName,
			'commit' => conversionCharacterizationImmutableCommit($root),
			'schema' => array_merge(conversionCharacterizationSchemaManifest($root, $openedPaths), [
				'fixture_sqlite_master' => RedactedSqliteMaster($database)
			]),
			'cache' => conversionCharacterizationCacheDelta($baselineRows, $probeRows),
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
		if (is_file($temporaryDatabase))
		{
			unlink($temporaryDatabase);
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

	$resolverPath = $root . '/migrations/0208.sql';
	$cachePath = $root . '/migrations/0225.sql';
	$resolverSql = (string)file_get_contents($resolverPath);
	$cacheSql = (string)file_get_contents($cachePath);
	$openedPaths[] = $resolverPath;
	$openedPaths[] = $cachePath;
	$cachePrefix = strstr($cacheSql, 'DROP VIEW recipes_pos_resolved;', true);
	if ($resolverSql === '' || $cachePrefix === false)
	{
		throw new RuntimeException('missing_cache_definitions');
	}

	$database->exec($resolverSql);
	$database->exec($cachePrefix);
}

function conversionCharacterizationSeedFixture(PDO $database, array $manifest): void
{
	$database->exec("INSERT INTO quantity_units VALUES (1, 'stock', 'stocks'), (2, 'purchase', 'purchases'), (3, 'display', 'displays')");
	$database->exec('INSERT INTO products VALUES (1, 1, 2, 3, 3)');
	conversionCharacterizationInsertConversion($database, $manifest['native_default']);
	conversionCharacterizationInsertConversion($database, $manifest['product_override']);
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

function conversionCharacterizationProtectedOutputs(PDO $database, array $categories): array
{
	$pairs = [
		'stock' => ['operation' => 'stock-adjustment', 'from' => 1, 'to' => 3, 'amount' => 4],
		'recipe' => ['operation' => 'recipe-ingredient', 'from' => 3, 'to' => 1, 'amount' => 7],
		'purchase' => ['operation' => 'purchase-entry', 'from' => 2, 'to' => 1, 'amount' => 1000],
		'consumption' => ['operation' => 'consumption-entry', 'from' => 3, 'to' => 1, 'amount' => 9],
		'price' => ['operation' => 'price-entry', 'from' => 3, 'to' => 1, 'amount' => 11],
		'transfer' => ['operation' => 'transfer-entry', 'from' => 1, 'to' => 3, 'amount' => 3],
		'meal-plan' => ['operation' => 'meal-plan-entry', 'from' => 3, 'to' => 1, 'amount' => 13],
		'quantity-display' => ['operation' => 'quantity-display', 'from' => 2, 'to' => 1, 'amount' => 1500]
	];
	$statement = $database->prepare('SELECT factor, path FROM cache__quantity_unit_conversions_resolved WHERE product_id = 1 AND from_qu_id = :from_qu_id AND to_qu_id = :to_qu_id');
	$outputs = [];
	foreach ($categories as $category)
	{
		if (!isset($pairs[$category]))
		{
			throw new RuntimeException('invalid_branch_manifest');
		}
		$fixture = $pairs[$category];
		$statement->execute([':from_qu_id' => $fixture['from'], ':to_qu_id' => $fixture['to']]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		if ($row === false)
		{
			throw new RuntimeException('protected_output_missing');
		}
		$outputs[$category] = [
			'operation' => $fixture['operation'],
			'input_amount' => $fixture['amount'],
			'factor' => (float)$row['factor'],
			'path' => (string)$row['path'],
			'converted_amount' => $fixture['amount'] * (float)$row['factor']
		];
	}
	return $outputs;
}

function conversionCharacterizationSchemaManifest(string $root, array &$openedPaths): array
{
	$objects = [];
	$hashes = [];
	foreach (['migrations/0208.sql', 'migrations/0225.sql'] as $relativePath)
	{
		$sourcePath = $root . '/' . $relativePath;
		$source = (string)file_get_contents($sourcePath);
		$openedPaths[] = $sourcePath;
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
	$statement = $database->query('EXPLAIN QUERY PLAN SELECT factor, path FROM cache__quantity_unit_conversions_resolved WHERE product_id = 1 AND from_qu_id = 3 AND to_qu_id = 1');
	return array_map(static fn(array $row): string => (string)$row['detail'], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function conversionCharacterizationImmutableCommit(string $root): string
{
	$process = proc_open(['git', '-C', $root, 'rev-parse', 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process))
	{
		throw new RuntimeException('invalid_branch_root');
	}
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0 || preg_match('/^[a-f0-9]{40}$/', trim($stdout)) !== 1)
	{
		throw new RuntimeException('invalid_branch_root');
	}
	return trim($stdout);
}
