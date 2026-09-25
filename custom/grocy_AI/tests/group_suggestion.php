<?php

declare(strict_types=1);

/**
 * group_suggestion.php — RED-first proof of the `suggest_product_group` bulk operation (06-04).
 *
 * Asserts, on production-shaped in-memory fixtures:
 *   (a) an evidence-based group suggestion is produced ONLY for in-scope, ungrouped products (via the
 *       single scope owner GrocyAiInventoryScope); grouped and out-of-scope products yield no suggestion;
 *   (b) a suggestion carries evidence/provenance and a confidence band, and maps ONLY to an existing
 *       product_groups row (never inventing a group);
 *   (c) GenerateGroupPlan emits the standard bulk plan/item schema with operation `suggest_product_group`,
 *       before_image = the prior group id (nullable) and proposed = the suggested group id, pre-selecting
 *       only confident suggestions;
 *   (d) ApplyPlan writes ONLY products.product_group_id for the selected items and RollbackPlan restores
 *       the prior group id exactly (including NULL) — proven with bin/verify-inventory-diff.php;
 *   (e) REGRESSION: an existing assign_taxonomy_leaf plan still applies + rolls back byte-identically
 *       after the delegate-dispatch generalization (native tables clean; audit before/after JSON keeps the
 *       {"leaf_slug":…} bytes).
 *
 * Registered from tests/run.php; its checks run in the default (no-arg) suite via the shared check().
 * Follows the run.php convention of requiring its own src/* dependencies (guarded by is_file).
 */

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

use GrocyAI\Services\GrocyAiBulkService;
use GrocyAI\Services\GrocyAiGroupSuggestionService;
use GrocyAI\Services\GrocyAiTaxonomyService;

/**
 * A production-shaped fixture for the group pass: native product_groups + products (active +
 * product_group_id), taxonomy + bulk module tables bootstrapped, and enrichment evidence for one product.
 *   P1 Whole Milk       ungrouped, active, provider_category 'dairy'  -> HIGH  -> group 1 (selected)
 *   P2 Fresh Produce Bag ungrouped, active, name contains 'Produce'   -> LOW   -> group 2 (deselected)
 *   P3 Cheddar          grouped (Dairy), active                        -> no suggestion (already grouped)
 *   P4 Inactive Yogurt  ungrouped, INACTIVE                            -> no suggestion (out of scope)
 *   P5 Mystery Widget   ungrouped, active, no evidence / no name match -> no suggestion (skipped)
 */
function groupSuggestionFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Dairy', 1), (2, 'Produce', 1), (3, 'Beverages', 1)");
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO products (id, name, product_group_id, active) VALUES
		(1, 'Whole Milk', NULL, 1),
		(2, 'Fresh Produce Bag', NULL, 1),
		(3, 'Cheddar', 1, 1),
		(4, 'Inactive Yogurt', NULL, 0),
		(5, 'Mystery Widget', NULL, 1)");
	// Native tables the diff harness / rollback exercise must leave byte-identical.
	$pdo->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL)');
	$pdo->exec("INSERT INTO stock (id, product_id, amount) VALUES (1, 3, 2.0)");

	// Bootstraps taxonomy + bulk module tables (and the Supplements exclusion rule); no userfields table
	// present, so the scope override resolves to 'included' by default.
	new GrocyAiBulkService($pdo);
	$evidence = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
	$evidence->execute([1, 'dairy', 'v1', 'high', 'provider_category']);
	return $pdo;
}

/** A taxonomy fixture for the byte-identical regression: two ungrouped, mapped, unclassified products. */
function groupSuggestionTaxonomyFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name, product_group_id) VALUES (1, 'P1', NULL), (2, 'P2', NULL)");
	$pdo->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL)');
	$pdo->exec("INSERT INTO stock (id, product_id, amount) VALUES (1, 1, 5.0)");
	$service = new GrocyAiBulkService($pdo);
	$evidence = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
	$evidence->execute([1, 'produce', 'v1', 'high', 'provider_category']);
	$evidence->execute([2, 'dairy', 'v1', 'high', 'provider_category']);
	return $pdo;
}

function groupSuggestionCurrentGroupId(PDO $pdo, int $productId): ?int
{
	$statement = $pdo->prepare('SELECT product_group_id FROM products WHERE id = ?');
	$statement->execute([$productId]);
	$value = $statement->fetchColumn();
	return ($value === false || $value === null) ? null : (int)$value;
}

function runGroupSuggestionSuite(): void
{
	// RED gate: without the group service + generator these assertions fail (RED until Task 3 lands them).
	if (!class_exists(GrocyAiGroupSuggestionService::class)
		|| !method_exists(GrocyAiGroupSuggestionService::class, 'Suggest')
		|| !method_exists(GrocyAiBulkService::class, 'GenerateGroupPlan'))
	{
		check(false, 'group_suggestion: the group suggestion service + GenerateGroupPlan are available');
		return;
	}
	if (!function_exists('inventoryDiffManifest') || !function_exists('inventoryDiffCompare'))
	{
		check(false, 'group_suggestion: the diff harness bin/verify-inventory-diff.php is available');
		return;
	}

	$pdo = groupSuggestionFixturePdo();
	$groups = new GrocyAiGroupSuggestionService($pdo);

	// (a) Suggestions only for in-scope, ungrouped products.
	$s1 = $groups->Suggest(1);
	$s2 = $groups->Suggest(2);
	check($groups->Suggest(3) === null, 'group_suggestion: an already-grouped product yields no suggestion');
	check($groups->Suggest(4) === null, 'group_suggestion: an out-of-scope (inactive) product yields no suggestion');
	check($groups->Suggest(5) === null, 'group_suggestion: an ungrouped product with no matching group yields no suggestion');

	// (b) A suggestion carries evidence/provenance + confidence and maps only to an existing group.
	check(is_array($s1) && $s1['product_group_id'] === 1 && $s1['confidence'] === 'high'
		&& $s1['provenance'] === 'provider_category' && $s1['reason'] === 'provider_category_matches_group',
		'group_suggestion: a provider-category match is a confident suggestion to the existing group');
	check(is_array($s1) && is_array($s1['evidence']) && $s1['evidence'] !== [] && ($s1['evidence'][0]['source'] ?? null) === 'provider_category',
		'group_suggestion: a suggestion carries its evidence/provenance trail');
	check(is_array($s2) && $s2['product_group_id'] === 2 && $s2['confidence'] === 'low' && $s2['provenance'] === 'product_name',
		'group_suggestion: a name-contains match is a low-confidence suggestion to the existing group');
	$existingGroupIds = $pdo->query('SELECT id FROM product_groups')->fetchAll(PDO::FETCH_COLUMN);
	check(in_array((string)$s1['product_group_id'], array_map('strval', $existingGroupIds), true),
		'group_suggestion: the suggested group id is an existing product_groups row (never invented)');

	// (c) The generated plan uses the standard bulk schema with operation suggest_product_group.
	$service = new GrocyAiBulkService($pdo);
	$generated = $service->GenerateGroupPlan(['actor' => 'grouper-1']);
	$planId = (int)$generated['id'];
	$checksum = (string)$generated['checksum'];
	check((string)$generated['operation_type'] === 'product_group_assignment', 'group_suggestion: the plan operation type is product_group_assignment');
	check((string)$generated['status'] === 'draft', 'group_suggestion: a freshly generated group plan is draft');
	check(preg_match('/^[0-9a-f]{64}$/D', $checksum) === 1, 'group_suggestion: the group plan checksum is a lowercase 64-hex SHA-256');
	$counts = json_decode((string)$generated['counts_json'], true, 512, JSON_THROW_ON_ERROR);
	check($counts === ['included' => 2, 'excluded' => 1, 'skipped' => 2, 'conflicted' => 0, 'changed' => 2, 'unchanged' => 0],
		'group_suggestion: the closed counts reconcile the scope (2 included, 1 excluded, 2 skipped): ' . json_encode($counts));

	$read = $service->ReadPlan($planId);
	$items = $read['items'];
	check(count($items) === 2, 'group_suggestion: the plan persisted one item per confident/low suggestion');
	$byObject = [];
	foreach ($items as $item)
	{
		$byObject[(int)$item['object_id']] = $item;
	}
	check(($byObject[1]['operation'] ?? null) === 'suggest_product_group'
		&& is_array($byObject[1]['before_image']) && array_key_exists('product_group_id', $byObject[1]['before_image'])
		&& $byObject[1]['before_image']['product_group_id'] === null
		&& (int)($byObject[1]['proposed_value']['product_group_id'] ?? 0) === 1
		&& $byObject[1]['selected'] === true,
		'group_suggestion: the confident item proposes the group over a null before-image and is pre-selected');
	check(is_array($byObject[2]['before_image']) && array_key_exists('product_group_id', $byObject[2]['before_image'])
		&& $byObject[2]['before_image']['product_group_id'] === null
		&& (int)($byObject[2]['proposed_value']['product_group_id'] ?? 0) === 2
		&& $byObject[2]['selected'] === false,
		'group_suggestion: the low-confidence item is emitted deselected');

	// (d) Apply writes ONLY products.product_group_id for selected items; rollback restores prior (incl. NULL).
	$preApply = inventoryDiffManifest($pdo);
	check(groupSuggestionCurrentGroupId($pdo, 1) === null && groupSuggestionCurrentGroupId($pdo, 2) === null,
		'group_suggestion: both candidate products are ungrouped before apply');
	$applyResult = $service->ApplyPlan($planId, 'grouper-1', $checksum);
	check(($applyResult['status'] ?? null) === 'applied' && ($applyResult['outcomes']['applied'] ?? null) === 1,
		'group_suggestion: apply commits exactly the one selected item');
	$postApply = inventoryDiffManifest($pdo);
	check(groupSuggestionCurrentGroupId($pdo, 1) === 1, 'group_suggestion: apply set the selected product to its suggested group');
	check(groupSuggestionCurrentGroupId($pdo, 2) === null, 'group_suggestion: the deselected product was left ungrouped');

	$approvedTables = ['grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_audit'];
	$applyDiff = inventoryDiffCompare($preApply, $postApply, $approvedTables, ['products.product_group_id']);
	check($applyDiff['violations'] === [], 'group_suggestion: apply changes ONLY products.product_group_id plus the bulk ledger (DATA-06)');
	$changedTables = [];
	foreach ($applyDiff['changes'] as $change)
	{
		$changedTables[$change['table']] = $change;
	}
	check(isset($changedTables['products']) && $changedTables['products']['columns'] === ['product_group_id'],
		'group_suggestion: the only native column changed by apply is products.product_group_id');
	foreach (['product_groups', 'stock'] as $native)
	{
		check(!isset($changedTables[$native]), "group_suggestion: native table {$native} is byte-identical after apply");
	}

	$preview = $service->PreviewRollback($planId);
	$rollback = $service->RollbackPlan($planId, 'grouper-1', (string)$preview['checksum']);
	check(($rollback['status'] ?? null) === 'rolled_back', 'group_suggestion: rollback reports the plan rolled_back');
	check(groupSuggestionCurrentGroupId($pdo, 1) === null, 'group_suggestion: rollback restored the prior (NULL) group id exactly');
	$postRollback = inventoryDiffManifest($pdo);
	$productsRestored = inventoryDiffCompare(
		['products' => $preApply['products']],
		['products' => $postRollback['products']]
	);
	check($productsRestored['changes'] === [], 'group_suggestion: after rollback the products table is byte-identical to pre-apply (DATA-07)');

	// The audit ledger records the native group id in the closed written-field shape.
	$audit = $service->ReadPlanAudit($planId)['records'];
	$appliedItemRows = array_values(array_filter($audit, static fn(array $r): bool => $r['plan_item_id'] !== null && $r['event'] === 'applied' && $r['outcome'] === 'applied'));
	check(count($appliedItemRows) === 1 && (string)$appliedItemRows[0]['after_json'] === '{"product_group_id":1}',
		'group_suggestion: the applied audit row records {"product_group_id":1} as the written value');

	// (e) REGRESSION: taxonomy apply/rollback stays byte-identical after the dispatch generalization.
	$txPdo = groupSuggestionTaxonomyFixturePdo();
	$txService = new GrocyAiBulkService($txPdo);
	$txPlan = $txService->GeneratePlan(['actor' => 'tax-1']);
	$txPlanId = (int)$txPlan['id'];
	$txChecksum = (string)$txPlan['checksum'];
	$txPre = inventoryDiffManifest($txPdo);
	$txApply = $txService->ApplyPlan($txPlanId, 'tax-1', $txChecksum);
	check(($txApply['status'] ?? null) === 'applied', 'group_suggestion regression: an assign_taxonomy_leaf plan still applies cleanly');
	$txPost = inventoryDiffManifest($txPdo);
	$txApprovedTables = ['grocy_ai_taxonomy_classifications', 'grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_audit'];
	$txApplyDiff = inventoryDiffCompare($txPre, $txPost, $txApprovedTables, []);
	check($txApplyDiff['violations'] === [], 'group_suggestion regression: taxonomy apply changes only the taxonomy + bulk tables (native clean)');

	$txAudit = $txService->ReadPlanAudit($txPlanId)['records'];
	$txAppliedRows = array_values(array_filter($txAudit, static fn(array $r): bool => $r['plan_item_id'] !== null && $r['event'] === 'applied' && $r['outcome'] === 'applied'));
	check(count($txAppliedRows) === 2, 'group_suggestion regression: both taxonomy items applied');
	$txAfterJson = array_map(static fn(array $r): string => (string)$r['after_json'], $txAppliedRows);
	sort($txAfterJson);
	check($txAfterJson === ['{"leaf_slug":"dairy-eggs"}', '{"leaf_slug":"produce"}'],
		'group_suggestion regression: taxonomy applied audit keeps the byte-identical {"leaf_slug":…} after-image');

	$txPreview = $txService->PreviewRollback($txPlanId);
	$txRollback = $txService->RollbackPlan($txPlanId, 'tax-1', (string)$txPreview['checksum']);
	check(($txRollback['status'] ?? null) === 'rolled_back', 'group_suggestion regression: the taxonomy plan rolls back');
	$txRolled = inventoryDiffManifest($txPdo);
	$txNativeDiff = inventoryDiffCompare(
		['products' => $txPre['products'], 'stock' => $txPre['stock'], 'product_groups' => $txPre['product_groups']],
		['products' => $txRolled['products'], 'stock' => $txRolled['stock'], 'product_groups' => $txRolled['product_groups']]
	);
	check($txNativeDiff['changes'] === [], 'group_suggestion regression: taxonomy rollback leaves native tables byte-identical');
	$txRolledBackRows = array_values(array_filter($txService->ReadPlanAudit($txPlanId)['records'], static fn(array $r): bool => $r['plan_item_id'] !== null && $r['event'] === 'rolled_back' && $r['outcome'] === 'rolled_back'));
	check(count($txRolledBackRows) === 2, 'group_suggestion regression: taxonomy rollback appended one rolled_back row per item');
	foreach ($txRolledBackRows as $record)
	{
		check((string)$record['after_json'] === '{"leaf_slug":null}', 'group_suggestion regression: rolled_back after-image keeps the byte-identical {"leaf_slug":null}');
	}

	fwrite(STDOUT, "[group_suggestion] suggest_product_group: scope-limited evidence suggestions, group_id-only apply, NULL-restoring rollback (DATA-06/07), and byte-identical taxonomy regression verified\n");
}
