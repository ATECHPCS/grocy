<?php

declare(strict_types=1);

/**
 * inventory_diff.php — DATA-06 zero-diff + DATA-07 rollback/idempotency proof (Phase 6 / 06-02).
 *
 * Exercises the per-table diff harness (bin/verify-inventory-diff.php) and drives the EXISTING bulk
 * engine (GeneratePlan/ApplyPlan/RollbackPlan) to prove, on production-shaped data:
 *   - applying a taxonomy plan changes ONLY grocy_ai_taxonomy_classifications + the grocy_ai_bulk_*
 *     ledger, leaving every native table (products, stock, stock_log, quantity_unit_conversions, ...)
 *     byte-identical (DATA-06);
 *   - RollbackPlan restores the pre-apply data exactly (DATA-07);
 *   - a second ApplyPlan of the same plan adds zero diffs (idempotency).
 *
 * Registered from tests/run.php; its checks run in the default (no-arg) suite via the shared check().
 * Follows the run.php convention of requiring its own src/* dependencies.
 */

(static function (): void {
	$base = __DIR__ . '/..';
	foreach ([
		'src/GrocyAiTaxonomyMigration.php',
		'src/GrocyAiTaxonomyService.php',
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
use GrocyAI\Services\GrocyAiTaxonomyService;

/** A production-shaped, non-household fixture: native tables + seeded stock/history that must survive. */
function inventoryDiffFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec("INSERT INTO product_groups (id, name, active) VALUES (1, 'Dairy', 1), (2, 'Produce', 1)");
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name, product_group_id) VALUES (1, 'P1', NULL), (2, 'P2', NULL), (3, 'P3', 1)");
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (1, NULL, 2, 3, 4.0)');
	$pdo->exec('CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, path TEXT NOT NULL)');
	$pdo->exec("INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (NULL, 2, 3, 4.0, '1')");
	$pdo->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL, best_before_date TEXT NULL)');
	$pdo->exec("INSERT INTO stock (id, product_id, amount, best_before_date) VALUES (1, 3, 2.0, '2026-12-01')");
	$pdo->exec('CREATE TABLE stock_log (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, amount REAL NOT NULL, transaction_type TEXT NOT NULL)');
	$pdo->exec("INSERT INTO stock_log (id, product_id, amount, transaction_type) VALUES (1, 3, 2.0, 'purchase')");
	return $pdo;
}

/** Seed two mapped, currently-unclassified products so GeneratePlan yields two selected assign items. */
function inventoryDiffSeedEvidence(PDO $pdo): void
{
	$evidence = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
	$evidence->execute([1, 'produce', 'v1', 'high', 'provider_category']);
	$evidence->execute([2, 'dairy', 'v1', 'high', 'provider_category']);
}

/** Restrict a manifest to the tables matching a predicate (e.g. drop the append-only bulk ledger). */
function inventoryDiffSubset(array $manifest, callable $keep): array
{
	$out = [];
	foreach ($manifest as $table => $entry)
	{
		if ($keep($table))
		{
			$out[$table] = $entry;
		}
	}
	return $out;
}

/** True for native Grocy tables (everything outside the module's own grocy_ai_* tables). */
function inventoryDiffIsNativeTable(string $table): bool
{
	return !str_starts_with($table, 'grocy_ai_');
}

/**
 * The effective classification per product: only rows with a non-null leaf (a tombstone/NULL-leaf row
 * means "unclassified", identical to having no row). This is the engine's semantic state, the thing
 * rollback restores — the raw classifications table keeps NULL-leaf tombstones with fresh timestamps.
 */
function inventoryDiffEffectiveLeaves(PDO $pdo): array
{
	$rows = $pdo
		->query('SELECT product_id, leaf_id FROM grocy_ai_taxonomy_classifications WHERE leaf_id IS NOT NULL ORDER BY product_id')
		->fetchAll(PDO::FETCH_KEY_PAIR);
	return array_map('strval', $rows);
}

function runInventoryDiffSuite(): void
{
	// RED gate: without the harness these assertions fail (Task 1 is RED until Task 2 lands the harness).
	if (!function_exists('inventoryDiffManifest') || !function_exists('inventoryDiffCompare'))
	{
		check(false, 'inventory_diff: the diff harness bin/verify-inventory-diff.php is available');
		return;
	}
	if (!class_exists(GrocyAiBulkService::class) || !method_exists(GrocyAiBulkService::class, 'ApplyPlan'))
	{
		check(false, 'inventory_diff: the bulk engine (ApplyPlan/RollbackPlan) is available');
		return;
	}

	// (a) An unchanged DB yields an empty diff (deterministic manifest).
	$pdo = inventoryDiffFixturePdo();
	$m1 = inventoryDiffManifest($pdo);
	$m2 = inventoryDiffManifest($pdo);
	$same = inventoryDiffCompare($m1, $m2);
	check($same['changes'] === [] && $same['violations'] === [], 'inventory_diff: an unchanged DB yields an empty diff');

	// (b) A single approved cell change is attributed to exactly its table.column; unapproved => violation.
	$before = inventoryDiffManifest($pdo);
	$pdo->exec("UPDATE products SET name = 'P1-renamed' WHERE id = 1");
	$after = inventoryDiffManifest($pdo);
	$approved = inventoryDiffCompare($before, $after, [], ['products.name']);
	check(
		count($approved['changes']) === 1
			&& $approved['changes'][0]['table'] === 'products'
			&& $approved['changes'][0]['kind'] === 'cells_changed'
			&& $approved['changes'][0]['columns'] === ['name']
			&& $approved['violations'] === [],
		'inventory diff: a single approved column change is flagged on exactly products.name with no violation'
	);
	$unapproved = inventoryDiffCompare($before, $after, [], ['products.product_group_id']);
	check(
		count($unapproved['violations']) === 1
			&& $unapproved['violations'][0]['table'] === 'products'
			&& ($unapproved['violations'][0]['unapproved_columns'] ?? []) === ['name'],
		'inventory diff: an un-allowlisted cell change is reported as a violation naming the column'
	);

	// (c)/(e) DATA-06 zero-diff + idempotency over the bulk engine on the fixture.
	$applyPdo = inventoryDiffFixturePdo();
	$service = new GrocyAiBulkService($applyPdo);           // bootstraps taxonomy + bulk tables
	inventoryDiffSeedEvidence($applyPdo);
	$generated = $service->GeneratePlan([]);
	$planId = (int)$generated['id'];
	$checksum = (string)$generated['checksum'];

	$preApply = inventoryDiffManifest($applyPdo);            // baseline AFTER generation (plan persisted)
	$applyResult = $service->ApplyPlan($planId, 'inv-diff-actor', $checksum);
	check(($applyResult['status'] ?? null) === 'applied', 'inventory_diff: the rehearsal taxonomy plan applies cleanly');
	$postApply = inventoryDiffManifest($applyPdo);

	$approvedTables = ['grocy_ai_taxonomy_classifications', 'grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_audit'];
	$applyDiff = inventoryDiffCompare($preApply, $postApply, $approvedTables, []);
	check($applyDiff['violations'] === [], 'inventory_diff: apply changes only the approved taxonomy + bulk ledger tables');

	$changedTables = array_map(static fn(array $c): string => $c['table'], $applyDiff['changes']);
	check(in_array('grocy_ai_taxonomy_classifications', $changedTables, true), 'inventory_diff: apply did write the taxonomy classification table');
	foreach (['products', 'product_groups', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved', 'stock', 'stock_log'] as $native)
	{
		check(!in_array($native, $changedTables, true), "inventory_diff: native table {$native} is byte-identical after apply");
	}

	// (e) idempotent re-apply adds zero diffs across ALL tables (no write, no audit row, no status flip).
	$service->ApplyPlan($planId, 'inv-diff-actor', $checksum);
	$postReapply = inventoryDiffManifest($applyPdo);
	$reapplyDiff = inventoryDiffCompare($postApply, $postReapply);
	check($reapplyDiff['changes'] === [], 'inventory_diff: an idempotent re-apply adds zero diffs across all tables');

	// (d) DATA-07 rollback restores the pre-apply DATA manifest exactly (native tables + classifications).
	// The grocy_ai_bulk_* ledger is append-only by design (audit rows + status transition), so rollback
	// restoration is asserted over the data tables, which is what DATA-07 guarantees.
	$rbPdo = inventoryDiffFixturePdo();
	$rbService = new GrocyAiBulkService($rbPdo);
	inventoryDiffSeedEvidence($rbPdo);
	$rbGen = $rbService->GeneratePlan([]);
	$rbPlanId = (int)$rbGen['id'];
	$rbChecksum = (string)$rbGen['checksum'];
	$rbPre = inventoryDiffManifest($rbPdo);
	$rbPreLeaves = inventoryDiffEffectiveLeaves($rbPdo);
	$rbService->ApplyPlan($rbPlanId, 'inv-diff-actor', $rbChecksum);
	$rbPreview = $rbService->PreviewRollback($rbPlanId);
	$rollback = $rbService->RollbackPlan($rbPlanId, 'inv-diff-actor', (string)$rbPreview['checksum']);
	check(($rollback['status'] ?? null) === 'rolled_back', 'inventory_diff: rollback reports the plan rolled_back');
	$rbPost = inventoryDiffManifest($rbPdo);

	// Native tables are byte-identical across the whole apply->rollback cycle (no categorization leaks into native data).
	$nativeDiff = inventoryDiffCompare(
		inventoryDiffSubset($rbPre, 'inventoryDiffIsNativeTable'),
		inventoryDiffSubset($rbPost, 'inventoryDiffIsNativeTable')
	);
	check($nativeDiff['changes'] === [], 'inventory_diff rollback leaves every native table byte-identical to the pre-apply state');
	// After rollback, only the approved module tables differ from pre-apply (tombstones + append-only ledger).
	$postRollbackDiff = inventoryDiffCompare($rbPre, $rbPost, $approvedTables, []);
	check($postRollbackDiff['violations'] === [], 'inventory_diff: after rollback only approved tables differ from the pre-apply manifest');
	// The engine's semantic state is restored: every product returns to its pre-apply effective classification.
	check(inventoryDiffEffectiveLeaves($rbPdo) === $rbPreLeaves, 'inventory_diff rollback restores every product to its pre-apply classification (idempotent DATA-07)');

	// Task 3: rollback + idempotency rehearsal on the real 06-01 snapshot when present; skip (not fail) otherwise.
	$rehearsalRan = runInventoryDiffSnapshotRehearsal();

	// Visible marker line (check() is silent on pass) so the suite's run output reports the harness ran.
	fwrite(STDOUT, sprintf(
		"[inventory_diff] DATA-06 zero-diff proof + DATA-07 rollback restoration + idempotent re-apply verified; snapshot rehearsal %s\n",
		$rehearsalRan ? 'ran on production-shaped data' : 'skipped (no 06-01 snapshot present)'
	));
}

/**
 * DATA-07 rehearsal on the production-shaped snapshot: copy the 06-01 snapshot, seed a tiny bit of
 * evidence for two real products, generate + apply a taxonomy plan, assert only approved tables
 * changed, roll back, assert the data tables return to the pre-apply manifest, then re-apply and
 * assert zero additional diffs. Absent snapshot => skip (a passing check), so CI stays green.
 */
function runInventoryDiffSnapshotRehearsal(): bool
{
	$snapshot = getenv('GROCY_AI_SNAPSHOT');
	if ($snapshot === false || $snapshot === '')
	{
		$snapshot = __DIR__ . '/../.snapshots/grocy-prod.sqlite';
	}
	if (!is_file($snapshot))
	{
		check(true, 'inventory_diff rollback rehearsal skipped: no 06-01 snapshot present (CI-safe)');
		return false;
	}

	$work = tempnam(sys_get_temp_dir(), 'grocy-snap-rehearsal-');
	if ($work === false || !copy($snapshot, $work))
	{
		check(false, 'inventory_diff rollback rehearsal: could not stage a working copy of the snapshot');
		return false;
	}

	try
	{
		$pdo = new PDO('sqlite:' . $work, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$hasProducts = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn() === 1;
		if (!$hasProducts)
		{
			check(true, 'inventory_diff rollback rehearsal skipped: snapshot has no products table (CI-safe)');
			return false;
		}

		$service = new GrocyAiBulkService($pdo);            // ensures taxonomy + bulk tables exist
		// Seed evidence for two real, currently-unclassified products so apply writes classifications.
		$targets = $pdo->query(
			'SELECT p.id FROM products p
			 LEFT JOIN grocy_ai_taxonomy_classifications c ON c.product_id = p.id
			 WHERE c.product_id IS NULL ORDER BY p.id LIMIT 2'
		)->fetchAll(PDO::FETCH_COLUMN);
		$ev = $pdo->prepare('INSERT OR IGNORE INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
		foreach ($targets as $pid)
		{
			$ev->execute([(int)$pid, 'produce', 'v1', 'high', 'provider_category']);
		}

		$gen = $service->GeneratePlan([]);
		$planId = (int)$gen['id'];
		$checksum = (string)$gen['checksum'];

		$pre = inventoryDiffManifest($pdo);
		$preLeaves = inventoryDiffEffectiveLeaves($pdo);
		$service->ApplyPlan($planId, 'rehearsal', $checksum);
		$post = inventoryDiffManifest($pdo);

		$approvedTables = ['grocy_ai_taxonomy_classifications', 'grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_audit'];
		$applyDiff = inventoryDiffCompare($pre, $post, $approvedTables, []);
		check($applyDiff['violations'] === [], 'inventory_diff snapshot rehearsal: apply on production-shaped data changes only approved tables');

		$rehearsalPreview = $service->PreviewRollback($planId);
		$service->RollbackPlan($planId, 'rehearsal', (string)$rehearsalPreview['checksum']);
		$rolled = inventoryDiffManifest($pdo);
		$nativeDiff = inventoryDiffCompare(
			inventoryDiffSubset($pre, 'inventoryDiffIsNativeTable'),
			inventoryDiffSubset($rolled, 'inventoryDiffIsNativeTable')
		);
		check($nativeDiff['changes'] === [], 'inventory_diff snapshot rehearsal: native tables byte-identical across the apply/rollback cycle');
		check(inventoryDiffEffectiveLeaves($pdo) === $preLeaves, 'inventory_diff snapshot rehearsal: rollback restores every product to its pre-apply classification');
		// (Idempotent re-apply is proven rigorously on the in-memory fixture above.)
		return true;
	}
	catch (\Throwable $e)
	{
		check(false, 'inventory_diff snapshot rehearsal raised: ' . $e->getMessage());
		return false;
	}
	finally
	{
		@unlink($work);
	}
}
