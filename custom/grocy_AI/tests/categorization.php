<?php

declare(strict_types=1);

/**
 * categorization.php — Phase 6 / 06-07 bulk-review SURFACE suite.
 *
 * The bulk-review page must let a MASTER_DATA_EDIT user drive all three Phase 6 passes through the
 * existing generate/review/apply/rollback surface. Task 1 wires the plan-generation endpoint
 * (`POST /api/grocy-ai/bulk/plans`) to dispatch each pass to its OWN explicit server-side generator, and
 * adds a read-only conversion-audit report endpoint (`GET /api/grocy-ai/bulk/conversion-audit`). This
 * suite proves that dispatch at the controller boundary (the UI-facing seam):
 *   - product-group suggestion  -> GenerateGroupPlan          (plan operation_type = product_group_assignment)
 *   - conflict-first classification -> GenerateClassificationPlan (plan operation_type = taxonomy_assignment)
 *   - the legacy confident-only pass -> GeneratePlan          (unchanged, pinned counts)
 *   - the conversion pass         -> a read-only report, never a plan (no apply/rollback)
 *
 * The service-level guarantees (checksums, zero-native-write on the two writing passes, conflict-first
 * ordering, Unclassified retention, DATA-06/07 apply/rollback) are already pinned by the bulk-* and
 * taxonomy-review suites; this suite covers only the new endpoint surface. It reuses the bulk.php fixture
 * helpers and runs in the default (no-arg) run.php suite via the shared check().
 */

foreach ([
	'GrocyAiInventoryScope',
	'GrocyAiTaxonomyMigration',
	'GrocyAiTaxonomyService',
	'GrocyAiConversionMigration',
	'GrocyAiConversionService',
	'GrocyAiBulkMigration',
	'GrocyAiGroupSuggestionService',
	'GrocyAiBulkService',
	'GrocyAiApiController',
	'GrocyAiBulkController'
] as $categorizationClassFile)
{
	$categorizationClassPath = __DIR__ . '/../src/' . $categorizationClassFile . '.php';
	if (is_file($categorizationClassPath))
	{
		require_once $categorizationClassPath;
	}
}

$categorizationBulkTests = __DIR__ . '/bulk.php';
if (is_file($categorizationBulkTests))
{
	require_once $categorizationBulkTests;
}

use GrocyAI\Services\GrocyAiBulkService;
use GrocyAI\Services\GrocyAiTaxonomyService;

/**
 * A products table with the unit columns the conversion audit joins, plus a mix of one global and one
 * expected product-specific package definition, so the read-only report has a real baseline to return.
 */
function categorizationAuditPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, qu_id_stock INTEGER NULL, qu_id_purchase INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name, qu_id_stock, qu_id_purchase) VALUES (10, 'Canned beans', 5, 5)");
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	// One global default and one legitimate per-product package definition (the product's own stock unit
	// -> grams). Both are EXPECTED by the 06-06 integrity rules, so the report is ok with 0 suspicious.
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (1, NULL, 1, 2, 1000)');
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (2, 10, 5, 3, 425)');
	return $pdo;
}

/** Row-value snapshot of the conversions table, for the read-only (zero-write) proof of the audit. */
function categorizationConversionRows(PDO $pdo): array
{
	return $pdo->query('SELECT id, product_id, from_qu_id, to_qu_id, factor FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function runCategorizationSuite(): void
{
	if (!class_exists(GrocyAiBulkService::class)
		|| !method_exists(GrocyAiBulkService::class, 'GenerateGroupPlan')
		|| !method_exists(GrocyAiBulkService::class, 'GenerateClassificationPlan')
		|| !class_exists(GrocyAI\Controllers\Api\GrocyAiApiController::class)
		|| !method_exists(GrocyAI\Controllers\Api\GrocyAiApiController::class, 'BulkConversionAudit'))
	{
		check(false, 'categorization: the three-pass generators + conversion-audit endpoint are available');
		return;
	}
	check(true, 'categorization: the three-pass generators + conversion-audit endpoint are available');

	bulkSelectionRuntime();

	// --- Shared writing-pass fixture: ungrouped in-scope products with mixed evidence, plus two existing
	// product groups so the group-suggestion pass has confident matches to propose. ------------------
	$pdo = bulkGenerationPdo();
	$pdo->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Produce', 1), (2, 'Dairy', 1)");
	$service = new GrocyAiBulkService($pdo);
	bulkSeedGenerationFixture($pdo, $service);
	bulkInstallDatabase($pdo);

	$pdo->exec('CREATE TABLE IF NOT EXISTS user_permissions_resolved (id INTEGER NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, permission_name TEXT NOT NULL)');
	$pdo->exec("DELETE FROM user_permissions_resolved");
	$pdo->exec("INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT')");

	$controller = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiApiController::class))->newInstanceWithoutConstructor();

	// --- Pass 1: product-group suggestion -----------------------------------------------------------
	$before = bulkGenerateEndpointNativeSnapshot($pdo);
	$groupResponse = $controller->GenerateBulkPlan(bulkSelectionRequest('POST', '/x', ['operation_type' => 'product_group_assignment']), bulkSelectionResponse(), []);
	$after = bulkGenerateEndpointNativeSnapshot($pdo);
	check($groupResponse->getStatusCode() === 201, 'categorization: the group-suggestion pass returns 201 Created');
	$groupBody = bulkSelectionBody($groupResponse);
	check(array_keys($groupBody) === ['plan', 'counts', 'items'], 'categorization: the group pass returns the closed plan read shape');
	check((string)$groupBody['plan']['operation_type'] === 'product_group_assignment', 'categorization: the group pass stores operation_type product_group_assignment');
	$groupItemsOk = count($groupBody['items']) >= 1;
	foreach ($groupBody['items'] as $item)
	{
		$groupItemsOk = $groupItemsOk
			&& (string)$item['operation'] === 'suggest_product_group'
			&& array_key_exists('product_group_id', $item['before_image']) && $item['before_image']['product_group_id'] === null
			&& array_key_exists('product_group_id', $item['proposed_value']) && is_int($item['proposed_value']['product_group_id']);
	}
	check($groupItemsOk, 'categorization: every group item is a suggest_product_group proposal writing only product_group_id');
	check($before['rows'] === $after['rows'] && $before['schema'] === $after['schema'], 'categorization: the group pass performs zero native mutation at generation');

	// --- Pass 2: conflict-first classification review -----------------------------------------------
	$classResponse = $controller->GenerateBulkPlan(bulkSelectionRequest('POST', '/x', ['operation_type' => 'classification_review']), bulkSelectionResponse(), []);
	check($classResponse->getStatusCode() === 201, 'categorization: the classification pass returns 201 Created');
	$classBody = bulkSelectionBody($classResponse);
	check((string)$classBody['plan']['operation_type'] === 'taxonomy_assignment', 'categorization: the classification pass stores the byte-identical taxonomy_assignment operation_type');

	// Conflict-first ordering (DATA-02 / Q7): the item band rank must be non-decreasing across the plan
	// (conflicts -> low-confidence -> confident), derived only from the server-supplied reason vocabulary.
	$bandRank = [
		'review_conflict' => 0,
		'below_confidence_threshold' => 1,
		'mapped_grocy_product_group' => 2,
		'mapped_provider_category' => 2
	];
	$ordered = true;
	$previousRank = -1;
	$hasRetainedUnclassified = false;
	foreach ($classBody['items'] as $item)
	{
		$reason = (string)$item['reason'];
		$rank = $bandRank[$reason] ?? 2;
		$ordered = $ordered && $rank >= $previousRank;
		$previousRank = $rank;
		if ((string)$item['operation'] === 'set_unclassified'
			&& $item['proposed_value']['leaf_slug'] === null
			&& $item['selected'] === false)
		{
			// Below-threshold items retain Unclassified (propose null) and start deselected (never forced).
			$hasRetainedUnclassified = true;
		}
	}
	check($ordered, 'categorization: classification items are ordered conflicts -> low-confidence -> confident');
	check($hasRetainedUnclassified, 'categorization: a below-threshold item retains Unclassified (proposes null) and is deselected');

	// --- Pass 3 (legacy regression): the confident-only taxonomy pass is unchanged --------------------
	$legacyResponse = $controller->GenerateBulkPlan(bulkSelectionRequest('POST', '/x', ['operation_type' => 'taxonomy_assignment']), bulkSelectionResponse(), []);
	check($legacyResponse->getStatusCode() === 201, 'categorization: the legacy taxonomy pass still returns 201 Created');
	$legacyBody = bulkSelectionBody($legacyResponse);
	check((string)$legacyBody['plan']['operation_type'] === 'taxonomy_assignment', 'categorization: the legacy pass keeps operation_type taxonomy_assignment');
	check($legacyBody['counts'] === ['included' => 2, 'excluded' => 1, 'skipped' => 3, 'conflicted' => 0, 'changed' => 1, 'unchanged' => 1], 'categorization: the legacy confident-only counts are unchanged');

	// --- Closed-input gate: an unknown selector or any extra key is still refused with a bounded 400 ---
	foreach ([
		['operation_type' => 'conversion_cleanup'],
		['operation_type' => 'product_group_assignment', 'items' => [['object_id' => 1]]],
		['operation_type' => 123],
		[]
	] as $bad)
	{
		$badResponse = $controller->GenerateBulkPlan(bulkSelectionRequest('POST', '/x', $bad), bulkSelectionResponse(), []);
		check($badResponse->getStatusCode() === 400 && bulkSelectionBody($badResponse) === ['error_message' => 'Invalid plan generation request'], 'categorization: a non-closed generation selector is refused with a bounded 400');
	}

	// --- Conversion audit endpoint: a read-only report, never a plan (no apply/rollback) --------------
	$auditPdo = categorizationAuditPdo();
	bulkInstallDatabase($auditPdo);
	$auditPdo->exec('CREATE TABLE IF NOT EXISTS user_permissions_resolved (id INTEGER NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, permission_name TEXT NOT NULL)');
	$auditPdo->exec("INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT')");

	$rowsBefore = categorizationConversionRows($auditPdo);
	$auditResponse = $controller->BulkConversionAudit(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), []);
	$rowsAfter = categorizationConversionRows($auditPdo);
	check($auditResponse->getStatusCode() === 200, 'categorization: the conversion audit endpoint returns 200');
	$auditBody = bulkSelectionBody($auditResponse);
	check(array_keys($auditBody) === ['global_count', 'product_specific_count', 'expected_count', 'expected_product_count', 'suspicious', 'ok'], 'categorization: the conversion audit report is the closed report shape');
	check((int)$auditBody['global_count'] === 1 && (int)$auditBody['product_specific_count'] === 1 && (int)$auditBody['expected_count'] === 1 && (int)$auditBody['expected_product_count'] === 1, 'categorization: the audit baseline classifies the legitimate package definition as expected');
	check($auditBody['suspicious'] === [] && $auditBody['ok'] === true, 'categorization: the audit finds nothing suspicious on legitimate package data');
	check($rowsBefore === $rowsAfter, 'categorization: the conversion audit is read-only (row-value equality before/after)');

	// A permission-less caller is rejected before any read (the surface stays behind MASTER_DATA_EDIT).
	$auditPdo->exec("DELETE FROM user_permissions_resolved WHERE permission_name = 'MASTER_DATA_EDIT'");
	$denied = false;
	try
	{
		$controller->BulkConversionAudit(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), []);
	}
	catch (Grocy\Controllers\Users\PermissionMissingException)
	{
		$denied = true;
	}
	check($denied, 'categorization: the conversion audit endpoint enforces MASTER_DATA_EDIT');

	fwrite(STDOUT, "[categorization] three-pass generation dispatch + read-only conversion audit endpoint verified\n");
}
