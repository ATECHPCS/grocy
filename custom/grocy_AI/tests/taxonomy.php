<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiTaxonomyMigration;
use GrocyAI\Services\GrocyAiTaxonomyService;

function taxonomyPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name) VALUES (1, 'Fixture product')");
	return $pdo;
}

function runTaxonomySchema(): never
{
	if (!class_exists(GrocyAiTaxonomyMigration::class) || !class_exists(GrocyAiTaxonomyService::class))
	{
		expectedRed('EXPECTED_RED: taxonomy-schema', 'The taxonomy migration and service are not implemented');
	}

	$pdo = taxonomyPdo();
	GrocyAiTaxonomyMigration::Bootstrap($pdo);
	$objects = $pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'index') AND name LIKE 'grocy_ai_taxonomy_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	foreach ($objects as $object)
	{
		if (!str_starts_with($object, 'grocy_ai_taxonomy_'))
		{
			expectedRed('EXPECTED_RED: taxonomy-schema', 'Taxonomy object names must be namespaced');
		}
	}
	if (!in_array('grocy_ai_taxonomy_migrations', $objects, true))
	{
		expectedRed('EXPECTED_RED: taxonomy-schema', 'The module migration ledger is missing');
	}

	$before = $pdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_nodes')->fetchColumn();
	GrocyAiTaxonomyMigration::Bootstrap($pdo);
	$after = $pdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_nodes')->fetchColumn();
	if ($before !== $after || $pdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_migrations')->fetchColumn() !== 1)
	{
		expectedRed('EXPECTED_RED: taxonomy-schema', 'Bootstrap must be idempotent');
	}

	$leaves = $pdo->query('SELECT slug FROM grocy_ai_taxonomy_nodes WHERE parent_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
	foreach ($leaves as $slug)
	{
		if (preg_match('/baby|pet|frozen|preserved/i', (string)$slug) === 1)
		{
			expectedRed('EXPECTED_RED: taxonomy-schema', 'Excluded handling or domains became taxonomy leaves');
		}
	}
	if ($pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() !== 1)
	{
		expectedRed('EXPECTED_RED: taxonomy-schema', 'Bootstrap must not mutate upstream products');
	}

	$service = new GrocyAiTaxonomyService($pdo);
	foreach (['baby-food', 'pet-food', 'frozen-food', 'preserved-food', 'provider-created-leaf'] as $slug)
	{
		try
		{
			$service->LeafBySlug($slug);
			expectedRed('EXPECTED_RED: taxonomy-schema', "Dynamic or excluded taxonomy leaf {$slug} was accepted");
		}
		catch (InvalidArgumentException)
		{
			// Closed local identities reject dynamic/provider-derived and excluded labels.
		}
	}

	fwrite(STDOUT, "Taxonomy schema tests passed\n");
	exit(0);
}

function runTaxonomyApi(): never
{
	if (!class_exists(GrocyAiTaxonomyService::class))
	{
		expectedRed('EXPECTED_RED: taxonomy-api', 'The taxonomy service is not implemented');
	}

	$pdo = taxonomyPdo();
	$service = new GrocyAiTaxonomyService($pdo);
	$result = $service->ReadProductTaxonomy(1);
	$allowed = ['product_id', 'current_leaf', 'suggested_leaf', 'evidence_source', 'ruleset_version', 'provider_category', 'confidence_band', 'reason_code'];
	if (array_keys($result) !== $allowed || $result['suggested_leaf'] !== null || $result['reason_code'] !== 'no_accepted_evidence')
	{
		expectedRed('EXPECTED_RED: taxonomy-api', 'Unknown evidence must return the closed Unclassified DTO');
	}

	$pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)')
		->execute([1, 'baby food', 'v1', 'high', 'provider_category']);
	$result = $service->ReadProductTaxonomy(1);
	if ($result['suggested_leaf'] !== null || $result['reason_code'] !== 'excluded_mapping')
	{
		expectedRed('EXPECTED_RED: taxonomy-api', 'Excluded provider evidence must fail closed');
	}

	$pdo->prepare('INSERT INTO product_groups (id, name, active) VALUES (?, ?, ?)')->execute([1, 'Seafood', 1]);
	$pdo->prepare('UPDATE products SET product_group_id = ? WHERE id = ?')->execute([1, 1]);
	$pdo->prepare('UPDATE grocy_ai_taxonomy_evidence SET provider_category = ?, confidence_band = ?, reason_code = ? WHERE product_id = ?')
		->execute(['produce', 'high', 'provider_category', 1]);
	$result = $service->ReadProductTaxonomy(1);
	if (($result['suggested_leaf']['slug'] ?? null) !== 'meat-seafood'
		|| $result['evidence_source'] !== 'grocy_product_group'
		|| $result['provider_category'] !== 'Seafood'
		|| $result['reason_code'] !== 'mapped_grocy_product_group'
		|| (int)$pdo->query('SELECT product_group_id FROM products WHERE id = 1')->fetchColumn() !== 1)
	{
		expectedRed('EXPECTED_RED: taxonomy-api', 'An active Grocy product group must provide read-only local taxonomy evidence ahead of provider evidence');
	}

	foreach ([0, -1, 2] as $productId)
	{
		try
		{
			$service->ReadProductTaxonomy($productId);
			expectedRed('EXPECTED_RED: taxonomy-api', 'Invalid or unavailable products must be rejected');
		}
		catch (InvalidArgumentException|RuntimeException)
		{
			// The controller maps these bounded failures to API responses.
		}
	}

	$routeSource = file_get_contents(__DIR__ . '/../routes.php');
	$controllerSource = file_get_contents(__DIR__ . '/../src/GrocyAiApiController.php');
	if (!str_contains($routeSource, '/products/{productId}/taxonomy')
		|| !str_contains($controllerSource, 'User::PERMISSION_MASTER_DATA_EDIT')
		|| !str_contains($controllerSource, 'ReadProductTaxonomy'))
	{
		expectedRed('EXPECTED_RED: taxonomy-api', 'The taxonomy API route must enforce master-data edit permission');
	}

	fwrite(STDOUT, "Taxonomy API tests passed\n");
	exit(0);
}

function runTaxonomyAssignment(): never
{
	$pdo = taxonomyPdo();
	foreach (['stock', 'recipes', 'prices', 'history', 'locations', 'units', 'conversions'] as $table)
	{
		$pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, value TEXT NOT NULL)");
		$pdo->exec("INSERT INTO {$table} (id, value) VALUES (1, 'unchanged')");
	}

	$service = new GrocyAiTaxonomyService($pdo);
	$before = [];
	foreach (['products', 'stock', 'recipes', 'prices', 'history', 'locations', 'units', 'conversions'] as $table)
	{
		$before[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	}

	$first = $service->AssignProductTaxonomy(1, ['leaf_slug' => 'produce', 'ruleset_version' => 'v1']);
	if (($first['current_leaf']['slug'] ?? null) !== 'produce')
	{
		expectedRed('EXPECTED_RED: taxonomy-assignment', 'A permitted explicit leaf assignment must become current');
	}
	$second = $service->AssignProductTaxonomy(1, ['leaf_slug' => 'dairy-eggs', 'ruleset_version' => 'v1']);
	if (($second['current_leaf']['slug'] ?? null) !== 'dairy-eggs'
		|| (int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_classifications WHERE product_id = 1')->fetchColumn() !== 1)
	{
		expectedRed('EXPECTED_RED: taxonomy-assignment', 'Replacement must leave exactly one current leaf');
	}
	$unclassified = $service->AssignProductTaxonomy(1, ['unclassified' => true, 'ruleset_version' => 'v1']);
	if (!array_key_exists('current_leaf', $unclassified) || $unclassified['current_leaf'] !== null)
	{
		expectedRed('EXPECTED_RED: taxonomy-assignment', 'Explicit Unclassified must clear the current leaf without deleting the module record');
	}

	foreach ([
		['leaf_slug' => 'baby-food', 'ruleset_version' => 'v1'],
		['leaf_slug' => 'produce', 'ruleset_version' => 'stale'],
		['leaf_slug' => 'produce', 'unclassified' => true, 'ruleset_version' => 'v1']
	] as $invalid)
	{
		$classificationBefore = $pdo->query('SELECT product_id, leaf_id, ruleset_version FROM grocy_ai_taxonomy_classifications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
		try
		{
			$service->AssignProductTaxonomy(1, $invalid);
			expectedRed('EXPECTED_RED: taxonomy-assignment', 'Stale, excluded, or ambiguous input must be rejected');
		}
		catch (InvalidArgumentException)
		{
			if ($classificationBefore !== $pdo->query('SELECT product_id, leaf_id, ruleset_version FROM grocy_ai_taxonomy_classifications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC))
			{
				expectedRed('EXPECTED_RED: taxonomy-assignment', 'Invalid input must not mutate classification data');
			}
		}
	}

	foreach ($before as $table => $snapshot)
	{
		if ($snapshot !== $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC))
		{
			expectedRed('EXPECTED_RED: taxonomy-assignment', "Assignment changed unrelated {$table} data");
		}
	}

	fwrite(STDOUT, "Taxonomy assignment tests passed\n");
	exit(0);
}

function runTaxonomyValidation(): never
{
	$pdo = taxonomyPdo();
	$pdo->exec("INSERT INTO products (id, name) VALUES (2, 'Mapped fixture'), (3, 'Excluded fixture'), (4, 'Low confidence fixture'), (5, 'Conflict fixture')");
	$service = new GrocyAiTaxonomyService($pdo);
	$pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)')->execute([2, 'produce', 'v1', 'high', 'provider_category']);
	$pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)')->execute([3, 'baby food', 'v1', 'high', 'provider_category']);
	$pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)')->execute([4, 'dairy', 'v1', 'low', 'provider_category']);
	$pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)')->execute([5, 'produce', 'v1', 'medium', 'conflicting_evidence']);

	$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	$before = taxonomySnapshots($pdo, $tables);
	$report = $service->ValidateInventoryTaxonomy();
	$after = taxonomySnapshots($pdo, $tables);
	$expectedKeys = ['ruleset_version', 'frozen_preserved_boundary', 'in_scope_products', 'mapped', 'unclassified', 'excluded', 'conflicting', 'low_confidence'];
	if (array_keys($report) !== $expectedKeys
		|| $report['ruleset_version'] !== 'v1'
		|| $report['frozen_preserved_boundary'] !== 'Frozen and preserved are handling/location concerns, not taxonomy identities.'
		|| $report['in_scope_products'] !== 5
		|| $report['mapped'] !== 1
		|| $report['unclassified'] !== 1
		|| $report['excluded'] !== 1
		|| $report['conflicting'] !== 1
		|| $report['low_confidence'] !== 1)
	{
		expectedRed('EXPECTED_RED: taxonomy-validation', 'The validation report must contain only the required redacted aggregate outcomes');
	}
	if ($before !== $after)
	{
		expectedRed('EXPECTED_RED: taxonomy-validation', 'Inventory validation must not write any fixture table');
	}
	if (str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'fixture'))
	{
		expectedRed('EXPECTED_RED: taxonomy-validation', 'Validation output must not disclose product fixture values');
	}

	fwrite(STDOUT, "Taxonomy validation tests passed\n");
	exit(0);
}

function runTaxonomyProductionPaths(): never
{
	$pdo = taxonomyPdo();
	$service = new GrocyAiTaxonomyService($pdo);
	$beforeProducts = $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$enrichment = [
		'suggestions' => [[
			'field' => 'food_type',
			'value' => 'produce',
			'confidence_band' => 'high',
			'reason_code' => 'inferred_provider_data'
		]]
	];
	if (!$service->ReconcileEnrichmentEvidence(1, $enrichment))
	{
		expectedRed('EXPECTED_RED: taxonomy-production-paths', 'Server-validated food-type evidence must record for an existing local product');
	}
	$evidence = $pdo->query('SELECT provider_category, mapping_version, confidence_band, reason_code FROM grocy_ai_taxonomy_evidence WHERE product_id = 1')->fetch(PDO::FETCH_ASSOC);
	if ($evidence !== ['provider_category' => 'produce', 'mapping_version' => 'v1', 'confidence_band' => 'high', 'reason_code' => 'inferred_provider_data']
		|| ($service->ReadProductTaxonomy(1)['suggested_leaf']['slug'] ?? null) !== 'produce'
		|| $beforeProducts !== $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
	{
		expectedRed('EXPECTED_RED: taxonomy-production-paths', 'Evidence reconciliation must create a local suggestion without changing the Grocy product');
	}
	$service->ReconcileEnrichmentEvidence(1, ['suggestions' => []]);
	if ((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_evidence WHERE product_id = 1')->fetchColumn() !== 0)
	{
		expectedRed('EXPECTED_RED: taxonomy-production-paths', 'A validated enrichment without food-type evidence must clear the stale module snapshot');
	}
	if ($service->ReconcileEnrichmentEvidence(99, $enrichment) !== false)
	{
		expectedRed('EXPECTED_RED: taxonomy-production-paths', 'Browser-selected unavailable products must not receive evidence');
	}
	$controllerSource = file_get_contents(__DIR__ . '/../src/GrocyAiApiController.php');
	if (!str_contains($controllerSource, 'ReconcileEnrichmentEvidence($currentProductId, $result)')
		|| !str_contains($controllerSource, "provider_result'] === null"))
	{
		expectedRed('EXPECTED_RED: taxonomy-production-paths', 'Only a server-returned provider result may reconcile taxonomy evidence');
	}

	$tempDirectory = sys_get_temp_dir() . '/grocy-ai-taxonomy-' . bin2hex(random_bytes(8));
	if (!mkdir($tempDirectory, 0700))
	{
		throw new RuntimeException('Could not create taxonomy CLI fixture');
	}
	$databasePath = $tempDirectory . '/grocy.db';
	try
	{
		$database = new PDO('sqlite:' . $databasePath);
		$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$database->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
		$database->exec("INSERT INTO products (id, name) VALUES (1, 'Private fixture product')");
		$databaseService = new GrocyAiTaxonomyService($database);
		$databaseService->ReconcileEnrichmentEvidence(1, $enrichment);
		$tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
		$before = taxonomySnapshots($database, $tables);
		$database = null;

		$pipes = [];
		$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/validate-inventory-taxonomy.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['GROCY_DATAPATH' => $tempDirectory]);
		if (!is_resource($process))
		{
			throw new RuntimeException('Could not execute taxonomy maintainer command');
		}
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$afterPdo = new PDO('sqlite:' . $databasePath);
		$after = taxonomySnapshots($afterPdo, $tables);
		$report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		if ($status !== 0 || $error !== '' || !is_array($report) || $report['mapped'] !== 1
			|| str_contains($output, 'Private fixture product') || $before !== $after)
		{
			expectedRed('EXPECTED_RED: taxonomy-production-paths', 'The configured-database maintainer command must emit only redacted aggregates without writes');
		}
	}
	finally
	{
		if (is_file($databasePath))
		{
			unlink($databasePath);
		}
		rmdir($tempDirectory);
	}

	fwrite(STDOUT, "Taxonomy production-path tests passed\n");
	exit(0);
}

function taxonomySnapshots(PDO $pdo, array $tables): array
{
	$snapshots = [];
	foreach ($tables as $table)
	{
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string)$table) !== 1)
		{
			throw new RuntimeException('Unsafe fixture table name');
		}
		$snapshots[$table] = $pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
	}
	return $snapshots;
}
