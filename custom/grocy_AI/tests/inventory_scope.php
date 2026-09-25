<?php

declare(strict_types=1);

/**
 * inventory_scope.php — RED-first unit proof of the Phase 6 in-scope predicate (06-03).
 *
 * Asserts, on a production-shaped in-memory fixture, that GrocyAiInventoryScope is the single owner of
 * the in-scope predicate: a product is in scope iff it is active AND its product group is not in the
 * configured excluded set AND its per-product override userfield is not 'excluded'. Also proves an
 * ungrouped product stays in scope (grouping is a later pass, not an exclusion) and that the predicate
 * is pure/deterministic (same database state -> same result on every run, supporting DATA-01/DATA-07).
 *
 * Registered from tests/run.php; its checks run in the default (no-arg) suite via the shared check().
 * Follows the run.php convention of requiring its own src/* dependencies (guarded by is_file).
 */

(static function (): void {
	$base = __DIR__ . '/..';
	foreach ([
		'src/GrocyAiTaxonomyMigration.php',
		'src/GrocyAiInventoryScope.php',
	] as $rel)
	{
		$path = $base . '/' . $rel;
		if (is_file($path))
		{
			require_once $path;
		}
	}
})();

use GrocyAI\Services\GrocyAiInventoryScope;
use GrocyAI\Services\GrocyAiTaxonomyMigration;

/**
 * A production-shaped fixture: native products (with active + product_group_id), product_groups, and
 * the native Grocy userfield tables. The migration then seeds the excluded mapping-rule + the override
 * userfield definition exactly as it would on prod.
 */
function inventoryScopeFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Dairy & Eggs', 1), (2, 'Supplements', 1)");
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO products (id, name, product_group_id, active) VALUES
		(1, 'Active dairy', 1, 1),
		(2, 'Supplement', 2, 1),
		(3, 'Ungrouped', NULL, 1),
		(4, 'Override excluded', 1, 1),
		(5, 'Inactive dairy', 1, 0),
		(6, 'Override included', 1, 1)");
	$pdo->exec('CREATE TABLE userfields (id INTEGER PRIMARY KEY AUTOINCREMENT, entity TEXT NOT NULL, name TEXT NOT NULL, caption TEXT NOT NULL, type TEXT NOT NULL, config TEXT, default_value TEXT, UNIQUE(entity, name))');
	$pdo->exec('CREATE TABLE userfield_values (id INTEGER PRIMARY KEY AUTOINCREMENT, field_id INTEGER NOT NULL, object_id TEXT NOT NULL, value TEXT NOT NULL, UNIQUE(field_id, object_id))');

	// Seed the exclusion mapping-rule + the override userfield definition through the real migration.
	GrocyAiTaxonomyMigration::Bootstrap($pdo);

	// Explicit per-product overrides against the seeded userfield definition.
	$fieldId = (int)$pdo->query("SELECT id FROM userfields WHERE entity = 'products' AND name = '" . GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_USERFIELD . "'")->fetchColumn();
	$override = $pdo->prepare('INSERT INTO userfield_values (field_id, object_id, value) VALUES (?, ?, ?)');
	$override->execute([$fieldId, '4', GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_EXCLUDED]);
	$override->execute([$fieldId, '6', GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED]);
	return $pdo;
}

function runInventoryScopeSuite(): void
{
	// RED gate: without the predicate owner these assertions fail (Task 1 is RED until Task 3 lands it).
	if (!class_exists(GrocyAiInventoryScope::class) || !method_exists(GrocyAiInventoryScope::class, 'IsInScope'))
	{
		check(false, 'inventory_scope: the single in-scope predicate owner GrocyAiInventoryScope is available');
		return;
	}

	$pdo = inventoryScopeFixturePdo();
	$scope = new GrocyAiInventoryScope($pdo);

	// The migration seeded the config-driven exclusion rule and the override userfield definition.
	$supplementRule = $pdo->query("SELECT disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = '" . GrocyAiTaxonomyMigration::NormalizeCategoryKey('Supplements') . "' AND version = '" . GrocyAiTaxonomyMigration::VERSION . "'")->fetchColumn();
	check($supplementRule === 'excluded', 'inventory_scope: Supplements is seeded as a closed exclusion mapping-rule');
	$userfieldDef = $pdo->query("SELECT default_value FROM userfields WHERE entity = 'products' AND name = '" . GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_USERFIELD . "'")->fetchColumn();
	check($userfieldDef === GrocyAiTaxonomyMigration::SCOPE_OVERRIDE_INCLUDED, 'inventory_scope: the per-product override userfield is seeded defaulting to included');

	// The predicate itself, dimension by dimension.
	check($scope->IsInScope(1) === true, 'inventory_scope: an active, non-excluded-group product is in scope');
	check($scope->IsInScope(2) === false, 'inventory_scope: a product in the excluded Supplements group is out of scope');
	check($scope->IsInScope(3) === true, 'inventory_scope: an ungrouped product stays in scope (grouping is a later pass, not an exclusion)');
	check($scope->IsInScope(4) === false, 'inventory_scope: an override=excluded product is out of scope even though its group is fine');
	check($scope->IsInScope(5) === false, 'inventory_scope: an inactive product is out of scope');
	check($scope->IsInScope(6) === true, 'inventory_scope: an explicit override=included product is in scope');
	check($scope->IsInScope(999) === false, 'inventory_scope: a non-existent product id is out of scope');

	// The bounded in-scope set is exactly the products that pass all three dimensions.
	check($scope->InScopeProductIds() === [1, 3, 6], 'inventory_scope: InScopeProductIds returns exactly the in-scope products in id order');

	// Determinism / reproducibility: the same database state yields the same result on every run.
	$firstPass = [];
	$secondPass = [];
	foreach ([1, 2, 3, 4, 5, 6] as $productId)
	{
		$firstPass[$productId] = $scope->IsInScope($productId);
	}
	$freshScope = new GrocyAiInventoryScope($pdo);
	foreach ([1, 2, 3, 4, 5, 6] as $productId)
	{
		$secondPass[$productId] = $freshScope->IsInScope($productId);
	}
	check($firstPass === $secondPass, 'inventory_scope: the predicate is pure/deterministic across repeated evaluations');
	check($scope->InScopeProductIds() === $freshScope->InScopeProductIds(), 'inventory_scope: the excluded set is reproducible across runs on the same snapshot');

	fwrite(STDOUT, "[inventory_scope] in-scope predicate (active AND group-not-excluded AND override!=excluded) verified: config-driven, ungrouped-in, deterministic\n");
}
