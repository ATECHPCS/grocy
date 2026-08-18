<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiTaxonomyMigration;
use GrocyAI\Services\GrocyAiTaxonomyService;

function taxonomyPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
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
	$allowed = ['product_id', 'current_leaf', 'suggested_leaf', 'ruleset_version', 'provider_category', 'confidence_band', 'reason_code'];
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
	if (($unclassified['current_leaf'] ?? 'missing') !== null)
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
