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

/**
 * 06-05 — classification-review conflict/ordering suite. Runs in the default (no-arg) run.php suite via
 * the shared check(). Follows the run.php convention of requiring its own src/* deps (guarded by is_file).
 *
 * Asserts:
 *   (Part A) ReviewProductTaxonomy surfaces a deterministic confidence band + the two conflict forms
 *            (group-signal contradiction; candidate tie within the named margin) without changing the
 *            closed ReadProductTaxonomy shape;
 *   (Part B) GenerateClassificationPlan orders items conflicts -> low-confidence -> confident and emits
 *            below-threshold products as DESELECTED set_unclassified (Unclassified retained, never forced);
 *   (Part C) a low-confidence product is promoted to confident once grouped (re-run improvement);
 *   (Part D) REAL-DATA: on the 06-01 prod snapshot (skip if absent, operate on a temp copy) grouping
 *            increases the confident-classification count, and the classification plan applies + rolls
 *            back clean (only taxonomy + bulk tables change; native products byte-identical after rollback).
 */
function classificationReviewFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Produce', 1), (2, 'Seafood', 1), (3, 'Dairy & Eggs', 1), (19, 'Supplements', 1)");
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO products (id, name, product_group_id, active) VALUES
		(1, 'Conflict tie gap0', 2, 1),
		(2, 'Conflict tie gap1', 2, 1),
		(3, 'Conflict gap2',     2, 1),
		(4, 'Confident group',   1, 1),
		(5, 'Low provider',   NULL, 1),
		(6, 'No evidence',    NULL, 1),
		(7, 'Promotable',     NULL, 1),
		(8, 'Reinforced',        1, 1),
		(9, 'Excluded group',   19, 1)");
	// Bootstraps taxonomy + mapping rules (produce/seafood/dairy -> leaves; Supplements -> excluded).
	new GrocyAI\Services\GrocyAiTaxonomyService($pdo);
	$evidence = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
	$evidence->execute([1, 'produce', 'v1', 'high', 'provider_category']);   // group Seafood vs produce, both score 3
	$evidence->execute([2, 'dairy', 'v1', 'medium', 'provider_category']);   // group Seafood(3) vs dairy-eggs(2), gap 1
	$evidence->execute([3, 'dairy', 'v1', 'low', 'provider_category']);      // group Seafood(3) vs dairy-eggs(1), gap 2
	$evidence->execute([5, 'dairy', 'v1', 'low', 'provider_category']);      // ungrouped, weak -> low_confidence
	$evidence->execute([7, 'produce', 'v1', 'low', 'provider_category']);    // ungrouped, weak -> low_confidence
	$evidence->execute([8, 'produce', 'v1', 'high', 'provider_category']);   // group Produce + produce -> same leaf, confident
	return $pdo;
}

function runTaxonomyClassificationReview(): void
{
	(static function (): void {
		$base = __DIR__ . '/..';
		foreach ([
			'src/GrocyAiTaxonomyMigration.php',
			'src/GrocyAiInventoryScope.php',
			'src/GrocyAiTaxonomyService.php',
			'src/GrocyAiGroupSuggestionService.php',
			'src/GrocyAiBulkMigration.php',
			'src/GrocyAiBulkService.php',
			'bin/verify-inventory-diff.php',
		] as $rel)
		{
			$path = $base . '/' . $rel;
			if (is_file($path))
			{
				require_once $path;
			}
		}
	})();

	$taxonomyClass = GrocyAI\Services\GrocyAiTaxonomyService::class;
	$bulkClass = GrocyAI\Services\GrocyAiBulkService::class;

	// RED gate: without the review + classification-plan surface these assertions fail.
	if (!method_exists($taxonomyClass, 'ReviewProductTaxonomy') || !method_exists($bulkClass, 'GenerateClassificationPlan'))
	{
		check(false, 'taxonomy-review: ReviewProductTaxonomy + GenerateClassificationPlan are available');
		return;
	}
	if (!defined($taxonomyClass . '::CANDIDATE_TIE_MARGIN') || !defined($taxonomyClass . '::CONFIDENCE_THRESHOLD'))
	{
		check(false, 'taxonomy-review: the tie-margin + confidence-threshold constants are named on the service');
		return;
	}
	check(is_int($taxonomyClass::CANDIDATE_TIE_MARGIN) && is_int($taxonomyClass::CONFIDENCE_THRESHOLD),
		'taxonomy-review: the tie-margin + confidence-threshold are integer constants');

	// --- Part A: conflict detection + confidence banding on the read path ---------------------------
	$pdo = classificationReviewFixturePdo();
	$svc = new $taxonomyClass($pdo, false);

	$r1 = $svc->ReviewProductTaxonomy(1);
	check($r1['review_band'] === 'conflict' && $r1['conflict'] === true, 'taxonomy-review: group-vs-provider disagreement is a conflict');
	check(in_array('group_signal_contradiction', $r1['conflict_reasons'], true) && in_array('candidate_tie', $r1['conflict_reasons'], true),
		'taxonomy-review: an equal-score disagreement flags BOTH conflict forms');
	check(($r1['suggested_leaf']['slug'] ?? null) === 'meat-seafood', 'taxonomy-review: the group signal wins the tie deterministically');

	$r2 = $svc->ReviewProductTaxonomy(2);
	check($r2['conflict'] === true && in_array('candidate_tie', $r2['conflict_reasons'], true),
		'taxonomy-review: a one-point score gap is within the tie margin');

	$r3 = $svc->ReviewProductTaxonomy(3);
	check($r3['conflict'] === true && in_array('group_signal_contradiction', $r3['conflict_reasons'], true)
		&& !in_array('candidate_tie', $r3['conflict_reasons'], true),
		'taxonomy-review: a two-point score gap contradicts the group signal but is NOT a tie (margin honored)');

	$r4 = $svc->ReviewProductTaxonomy(4);
	check($r4['review_band'] === 'confident' && $r4['conflict'] === false
		&& ($r4['suggested_leaf']['slug'] ?? null) === 'produce' && $r4['evidence_source'] === 'grocy_product_group',
		'taxonomy-review: a lone confident group signal is a clean confident classification');

	$r5 = $svc->ReviewProductTaxonomy(5);
	check($r5['review_band'] === 'low_confidence' && $r5['conflict'] === false
		&& $r5['confidence_band'] === 'low' && ($r5['suggested_leaf']['slug'] ?? null) === 'dairy-eggs',
		'taxonomy-review: a lone below-threshold provider signal is low_confidence, not confident');

	$r6 = $svc->ReviewProductTaxonomy(6);
	check($r6['review_band'] === 'unclassified' && $r6['suggested_leaf'] === null && $r6['reason_code'] === 'no_accepted_evidence',
		'taxonomy-review: no accepted evidence yields an unclassified review');

	$r8 = $svc->ReviewProductTaxonomy(8);
	check($r8['review_band'] === 'confident' && $r8['conflict'] === false,
		'taxonomy-review: agreeing group + provider evidence reinforce (no conflict)');

	// Regression: the closed ReadProductTaxonomy shape is unchanged by the new review path.
	check(array_keys($svc->ReadProductTaxonomy(4)) === ['product_id', 'current_leaf', 'suggested_leaf', 'evidence_source', 'ruleset_version', 'provider_category', 'confidence_band', 'reason_code'],
		'taxonomy-review: ReadProductTaxonomy keeps its closed 8-key shape');

	// --- Part B: the classification plan orders conflicts -> low -> confident, retaining Unclassified ---
	$bulk = new $bulkClass($pdo);
	$plan = $bulk->GenerateClassificationPlan(['actor' => 'reviewer-1']);
	check((string)$plan['operation_type'] === 'taxonomy_assignment' && (string)$plan['status'] === 'draft'
		&& preg_match('/^[0-9a-f]{64}$/D', (string)$plan['checksum']) === 1,
		'taxonomy-review: the classification plan is a draft taxonomy_assignment sealed with a SHA-256 checksum');
	$counts = json_decode((string)$plan['counts_json'], true, 512, JSON_THROW_ON_ERROR);
	check($counts['conflicted'] === 0, 'taxonomy-review: conflicted is reserved 0 at generation');
	check($counts['excluded'] >= 1, 'taxonomy-review: the excluded (out-of-scope Supplements) product is counted excluded');

	$items = $bulk->ReadPlan((int)$plan['id'])['items'];
	$bands = array_map(static function (array $item): string {
		return match ((string)$item['reason'])
		{
			'review_conflict' => 'conflict',
			'below_confidence_threshold' => 'low_confidence',
			default => 'confident'
		};
	}, $items);
	$rank = ['conflict' => 0, 'low_confidence' => 1, 'confident' => 2];
	$ordered = true;
	for ($i = 1; $i < count($bands); $i++)
	{
		if ($rank[$bands[$i]] < $rank[$bands[$i - 1]])
		{
			$ordered = false;
		}
	}
	check($ordered, 'taxonomy-review: plan items are ordered conflicts -> low-confidence -> confident: ' . json_encode($bands));
	check($bands === ['conflict', 'conflict', 'conflict', 'low_confidence', 'low_confidence', 'confident', 'confident'],
		'taxonomy-review: the plan emits exactly the expected review bands in order: ' . json_encode($bands));

	$byObject = [];
	foreach ($items as $item)
	{
		$byObject[(int)$item['object_id']] = $item;
	}
	// A below-threshold product is emitted as a DESELECTED set_unclassified proposing no leaf (Unclassified retained).
	check(($byObject[5]['operation'] ?? null) === 'set_unclassified'
		&& array_key_exists('leaf_slug', $byObject[5]['proposed_value'])
		&& $byObject[5]['proposed_value']['leaf_slug'] === null
		&& $byObject[5]['selected'] === false,
		'taxonomy-review: a below-threshold product is a deselected set_unclassified (no forced leaf)');
	// A conflict item proposes the winning leaf but is DESELECTED for human review.
	check(($byObject[1]['operation'] ?? null) === 'assign_taxonomy_leaf' && $byObject[1]['selected'] === false,
		'taxonomy-review: a conflict item proposes a leaf but is deselected pending review');
	// A confident, changed item is pre-selected.
	check(($byObject[4]['operation'] ?? null) === 'assign_taxonomy_leaf' && $byObject[4]['selected'] === true,
		'taxonomy-review: a confident changed item is pre-selected');
	// Applying leaves the below-threshold product Unclassified (its deselected item never forces a leaf).
	$applied = $bulk->ApplyPlan((int)$plan['id'], 'reviewer-1', (string)$plan['checksum']);
	check(($applied['status'] ?? null) === 'applied', 'taxonomy-review: the classification plan applies (only its selected confident items)');
	check($svc->ReviewProductTaxonomy(5)['current_leaf'] === null, 'taxonomy-review: the below-threshold product is still Unclassified after apply');

	// --- Part C: grouping promotes a low-confidence product to confident on re-run ------------------
	$promotePdo = classificationReviewFixturePdo();
	$promoteSvc = new $taxonomyClass($promotePdo, false);
	check($promoteSvc->ReviewProductTaxonomy(7)['review_band'] === 'low_confidence', 'taxonomy-review: an ungrouped weak-evidence product starts low_confidence');
	$promotePdo->prepare('UPDATE products SET product_group_id = 1 WHERE id = ?')->execute([7]);
	$regrouped = (new $taxonomyClass($promotePdo, false))->ReviewProductTaxonomy(7);
	check($regrouped['review_band'] === 'confident' && ($regrouped['suggested_leaf']['slug'] ?? null) === 'produce',
		'taxonomy-review: grouping the product promotes it from low_confidence to confident');

	// --- Part D: real-data confirmation on the 06-01 prod snapshot (skip gracefully if absent) -------
	runTaxonomyClassificationSnapshotRerun($bulkClass);
}

/**
 * Real-data re-run over the 06-01 prod snapshot: proves grouping increases confident classifications and
 * the classification plan applies + rolls back clean. The raw snapshot predates enrichment capture (0
 * evidence rows), so representative below-threshold provider evidence is seeded on a TEMP COPY to exercise
 * the group-suggestion -> classification chain; the snapshot itself is never mutated.
 */
function runTaxonomyClassificationSnapshotRerun(string $bulkClass): void
{
	$snapshot = __DIR__ . '/../.snapshots/grocy-prod.sqlite';
	if (!is_file($snapshot) || !is_readable($snapshot))
	{
		fwrite(STDOUT, "[taxonomy-review] snapshot absent; skipped the real-data re-run gracefully\n");
		return;
	}

	$temp = tempnam(sys_get_temp_dir(), 'grocy-ai-classify-rerun-');
	if ($temp === false || !copy($snapshot, $temp))
	{
		check(false, 'taxonomy-review: could not stage a temp copy of the snapshot');
		return;
	}
	try
	{
		$db = new PDO('sqlite:' . $temp);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$svc = new $bulkClass($db);

		// Seed representative below-threshold provider evidence for a handful of ungrouped in-scope products
		// whose category equals an existing mapped group (Produce/Seafood/Beverages).
		$ids = $db->query('SELECT id FROM products WHERE product_group_id IS NULL AND active = 1 ORDER BY id LIMIT 6')->fetchAll(PDO::FETCH_COLUMN);
		$seed = $db->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
		$categories = ['Produce', 'Seafood', 'Beverages', 'Produce', 'Seafood', 'Beverages'];
		foreach ($ids as $index => $id)
		{
			$seed->execute([(int)$id, $categories[$index], 'v1', 'low', 'provider_category']);
		}

		$confidentCount = static function (object $svc, string $actor): int {
			$plan = $svc->GenerateClassificationPlan(['actor' => $actor]);
			$count = 0;
			foreach ($svc->ReadPlan((int)$plan['id'])['items'] as $item)
			{
				if ((string)$item['operation'] === 'assign_taxonomy_leaf' && (string)$item['reason'] !== 'review_conflict')
				{
					$count++;
				}
			}
			return $count;
		};

		$before = $confidentCount($svc, 'rerun-before');
		$groupPlan = $svc->GenerateGroupPlan(['actor' => 'rerun-group']);
		$svc->ApplyPlan((int)$groupPlan['id'], 'rerun-group', (string)$groupPlan['checksum']);
		$after = $confidentCount($svc, 'rerun-after');
		check($after > $before, "taxonomy-review: grouping increases confident classifications on the snapshot (before {$before} -> after {$after})");

		// The post-grouping classification plan applies + rolls back clean: only taxonomy + bulk tables change.
		$preApply = inventoryDiffManifest($db);
		$classPlan = $svc->GenerateClassificationPlan(['actor' => 'rerun-apply']);
		$apply = $svc->ApplyPlan((int)$classPlan['id'], 'rerun-apply', (string)$classPlan['checksum']);
		check(($apply['status'] ?? null) === 'applied', 'taxonomy-review: the snapshot classification plan applies');
		$postApply = inventoryDiffManifest($db);
		$applyDiff = inventoryDiffCompare($preApply, $postApply, ['grocy_ai_taxonomy_classifications', 'grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_audit'], []);
		check($applyDiff['violations'] === [], 'taxonomy-review: snapshot classification apply changes ONLY the taxonomy classifications + bulk ledger (native clean)');

		$preview = $svc->PreviewRollback((int)$classPlan['id']);
		$rollback = $svc->RollbackPlan((int)$classPlan['id'], 'rerun-apply', (string)$preview['checksum']);
		check(($rollback['status'] ?? null) === 'rolled_back', 'taxonomy-review: the snapshot classification plan rolls back');
		$postRollback = inventoryDiffManifest($db);
		$productsDiff = inventoryDiffCompare(['products' => $preApply['products']], ['products' => $postRollback['products']]);
		check($productsDiff['changes'] === [], 'taxonomy-review: native products are byte-identical after the classification rollback');

		$db = null;
		fwrite(STDOUT, "[taxonomy-review] snapshot re-run: confident classifications rose {$before} -> {$after} after grouping; classification apply/rollback clean (taxonomy+bulk only)\n");
	}
	finally
	{
		if (is_file($temp))
		{
			unlink($temp);
		}
	}
}
