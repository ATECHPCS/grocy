<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiBulkService;
use GrocyAI\Services\GrocyAiBulkMigration;
use GrocyAI\Services\GrocyAiTaxonomyMigration;
use GrocyAI\Services\GrocyAiTaxonomyService;

foreach (['GrocyAiBulkMigration', 'GrocyAiBulkService'] as $bulkClassFile)
{
	$bulkClassPath = __DIR__ . '/../src/' . $bulkClassFile . '.php';
	if (is_file($bulkClassPath))
	{
		require_once $bulkClassPath;
	}
}

/**
 * Phase 5 bulk-engine contract suite.
 *
 * Plan 05-01 fixes the plan/plan-item/audit DTO shapes, the closed count keys, the SHA-256
 * plan-checksum contract, the closed typed-operation registry, and the closed blocker/outcome
 * vocabularies as a failing (RED) suite before any production bulk code exists. Later Phase 5 plans
 * turn the relevant assertions green:
 *   - 05-02 (bulk-schema)   creates the namespaced, idempotent, append-only bulk schema.
 *   - 05-03 (bulk-generate) implements zero-mutation GeneratePlan with exact counts and a checksum.
 *   - 05-04 (bulk-registry) implements the closed named-typed-operation registry and its guard.
 */

const BULK_CONTRACT_MARKER = 'EXPECTED_RED: bulk.contract_shapes';
const BULK_INVARIANTS_MARKER = 'EXPECTED_RED: bulk.engine_invariants';

function bulkPlanCases(): array
{
	$path = __DIR__ . '/fixtures/bulk-plan-cases.json';
	return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function bulkRegistryCases(): array
{
	$path = __DIR__ . '/fixtures/bulk-registry-cases.json';
	return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function bulkAssert(bool $condition, string $marker, string $message): void
{
	if (!$condition)
	{
		expectedRed($marker, $message);
	}
}

/**
 * The full engine surface the invariant gate and later plans depend on. Kept in one place so the
 * RED gates route through a single guard.
 */
function bulkEngineSurfaceComplete(): bool
{
	if (!class_exists(GrocyAiBulkMigration::class) || !class_exists(GrocyAiBulkService::class))
	{
		return false;
	}
	foreach (['GeneratePlan', 'SetItemSelection', 'ApplyPlan', 'PreviewRollback', 'ExportPlan'] as $method)
	{
		if (!method_exists(GrocyAiBulkService::class, $method))
		{
			return false;
		}
	}
	return true;
}

/**
 * Task 1: the plan/plan-item/audit DTO shapes, the closed count keys, the SHA-256 checksum, and the
 * closed registry map. RED until 05-02..05-04 provide the schema, GeneratePlan/checksum, and registry.
 */
function runBulkContract(): never
{
	$plan = bulkPlanCases();
	$registry = bulkRegistryCases();

	// The fixtures themselves must be deterministic and carry the closed contract shapes.
	$expectedPlanKeys = ['id', 'created_at', 'created_by', 'ruleset_version', 'operation_type', 'scope_json', 'counts_json', 'checksum', 'status', 'module_version'];
	$expectedItemKeys = ['id', 'plan_id', 'seq', 'object_type', 'object_id', 'operation', 'before_image_json', 'proposed_value_json', 'reason', 'provenance', 'selected', 'outcome', 'applied_at'];
	$expectedAuditKeys = ['id', 'plan_id', 'plan_item_id', 'actor', 'event', 'event_at', 'module_version', 'before_json', 'after_json', 'outcome'];
	$expectedCountKeys = ['included', 'excluded', 'skipped', 'conflicted', 'changed', 'unchanged'];

	bulkAssert(($plan['dto_shapes']['plan'] ?? null) === $expectedPlanKeys, BULK_CONTRACT_MARKER, 'The plan DTO key set is not the closed contract shape');
	bulkAssert(($plan['dto_shapes']['plan_item'] ?? null) === $expectedItemKeys, BULK_CONTRACT_MARKER, 'The plan-item DTO key set is not the closed contract shape');
	bulkAssert(($plan['dto_shapes']['audit'] ?? null) === $expectedAuditKeys, BULK_CONTRACT_MARKER, 'The audit-record DTO key set is not the closed contract shape');
	bulkAssert(($plan['count_keys'] ?? null) === $expectedCountKeys, BULK_CONTRACT_MARKER, 'The counts_json key set is not the closed contract shape');

	$expectedResolve = ['assign_taxonomy_leaf', 'set_unclassified'];
	$resolveOps = array_map(static fn(array $case): string => (string)$case['operation'], $registry['resolve'] ?? []);
	bulkAssert($resolveOps === $expectedResolve, BULK_CONTRACT_MARKER, 'The registry fixture resolves an operation set other than the two closed operations');
	foreach ($registry['resolve'] ?? [] as $case)
	{
		bulkAssert(($case['delegate_write'] ?? null) === 'AssignProductTaxonomy', BULK_CONTRACT_MARKER, 'A registry operation names a delegate other than the shipped AssignProductTaxonomy write');
		$keys = $case['operation'] === 'assign_taxonomy_leaf' ? ['leaf_slug', 'ruleset_version'] : ['unclassified', 'ruleset_version'];
		bulkAssert(($case['assignment_keys'] ?? null) === $keys, BULK_CONTRACT_MARKER, 'A registry operation produces an assignment key set outside the closed AssignProductTaxonomy shapes');
	}
	foreach ($registry['reject'] ?? [] as $case)
	{
		bulkAssert(($case['blocker'] ?? null) === 'unknown_operation', BULK_CONTRACT_MARKER, 'A free-form entity/field/CRUD/SQL operation is not rejected with the single unknown_operation blocker');
	}

	// The production surface: RED until Plans 05-02..05-04 satisfy every shape.
	if (!class_exists(GrocyAiBulkMigration::class) || !class_exists(GrocyAiBulkService::class)
		|| !method_exists(GrocyAiBulkService::class, 'GeneratePlan')
		|| !method_exists(GrocyAiBulkService::class, 'ChecksumForPlan')
		|| !method_exists(GrocyAiBulkService::class, 'RegisteredOperations'))
	{
		expectedRed(BULK_CONTRACT_MARKER, 'The bulk migration, GeneratePlan/ChecksumForPlan, and closed registry are not implemented');
	}

	// --- Real assertions (green once 05-02..05-04 land) -------------------------------------------

	// Schema columns are exactly the closed DTO shapes (D-02 / D-10).
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	GrocyAiBulkMigration::Bootstrap($pdo);
	foreach (['grocy_ai_bulk_plans' => $expectedPlanKeys, 'grocy_ai_bulk_plan_items' => $expectedItemKeys, 'grocy_ai_bulk_audit' => $expectedAuditKeys] as $table => $expectedColumns)
	{
		$columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
		bulkAssert($columns === $expectedColumns, BULK_CONTRACT_MARKER, "Table {$table} columns are not the closed DTO shape");
	}

	// The plan DTO GeneratePlan returns exposes exactly the closed header keys, and counts_json the
	// closed count keys.
	$generatePdo = bulkGenerationPdo();
	$service = new GrocyAiBulkService($generatePdo);
	bulkSeedGenerationFixture($generatePdo, $service);
	$generated = $service->GeneratePlan([]);
	bulkAssert(array_keys($generated) === $expectedPlanKeys, BULK_CONTRACT_MARKER, 'GeneratePlan returned a plan DTO outside the closed key set');
	$counts = json_decode((string)$generated['counts_json'], true, 512, JSON_THROW_ON_ERROR);
	$sortedCounts = array_keys($counts);
	sort($sortedCounts);
	$sortedExpected = $expectedCountKeys;
	sort($sortedExpected);
	bulkAssert($sortedCounts === $sortedExpected, BULK_CONTRACT_MARKER, 'counts_json exposes keys outside the closed count set');

	// The checksum is a lowercase 64-hex SHA-256 over item identities, before/proposed values,
	// operation types, and ruleset version: reorder-stable and mutation-sensitive (D-03).
	$cases = $plan['checksum_cases'];
	$checksumBase = $service->ChecksumForPlan((string)$cases['operation_type'], (string)$cases['ruleset_version'], $cases['base']);
	$checksumReordered = $service->ChecksumForPlan((string)$cases['operation_type'], (string)$cases['ruleset_version'], $cases['reordered']);
	$checksumMutated = $service->ChecksumForPlan((string)$cases['operation_type'], (string)$cases['ruleset_version'], $cases['mutated']);
	bulkAssert(preg_match('/^[0-9a-f]{64}$/D', $checksumBase) === 1, BULK_CONTRACT_MARKER, 'The plan checksum is not a lowercase 64-hex SHA-256');
	bulkAssert($checksumBase === $checksumReordered, BULK_CONTRACT_MARKER, 'Reordering plan items changed the checksum');
	bulkAssert($checksumBase !== $checksumMutated, BULK_CONTRACT_MARKER, 'Mutating a covered value did not change the checksum');

	// The registry is a closed server-side map whose only members delegate to the shipped write.
	$registered = $service->RegisteredOperations();
	bulkAssert(array_keys($registered) === $expectedResolve, BULK_CONTRACT_MARKER, 'RegisteredOperations exposes an operation set other than the two closed operations');
	foreach ($registered as $operation => $meta)
	{
		bulkAssert(($meta['delegate_write'] ?? null) === 'AssignProductTaxonomy', BULK_CONTRACT_MARKER, "Registered operation {$operation} delegates to a write other than AssignProductTaxonomy");
		$keys = $operation === 'assign_taxonomy_leaf' ? ['leaf_slug', 'ruleset_version'] : ['unclassified', 'ruleset_version'];
		bulkAssert(($meta['assignment_keys'] ?? null) === $keys, BULK_CONTRACT_MARKER, "Registered operation {$operation} produces an assignment shape outside the closed AssignProductTaxonomy key set");
	}

	fwrite(STDOUT, "Bulk contract shapes passed\n");
	exit(0);
}

/**
 * Task 2: the closed blocker/outcome vocabulary and the nine engine invariants stated against the
 * (not-yet-existing) service surface. RED until the full engine — GeneratePlan, SetItemSelection,
 * ApplyPlan, PreviewRollback, ExportPlan — exists (Plans 05-03..05-11).
 */
function runBulkInvariants(): never
{
	$plan = bulkPlanCases();

	$expectedOutcomes = ['pending', 'applied', 'conflict', 'skipped', 'rejected', 'rolled_back'];
	$expectedBlockers = ['unknown_operation', 'before_image_stale', 'plan_checksum_mismatch', 'not_selected', 'already_applied', 'manual_edit_after_apply'];

	$outcomes = $plan['outcome_vocabulary'] ?? [];
	$blockers = $plan['blocker_vocabulary'] ?? [];
	$sortedOutcomes = $outcomes;
	$sortedBlockers = $blockers;
	sort($sortedOutcomes);
	sort($sortedBlockers);
	$sortedExpectedOutcomes = $expectedOutcomes;
	$sortedExpectedBlockers = $expectedBlockers;
	sort($sortedExpectedOutcomes);
	sort($sortedExpectedBlockers);
	bulkAssert($sortedOutcomes === $sortedExpectedOutcomes, BULK_INVARIANTS_MARKER, 'The per-item outcome vocabulary is not the closed set');
	bulkAssert($sortedBlockers === $sortedExpectedBlockers, BULK_INVARIANTS_MARKER, 'The blocker vocabulary is not the closed set');

	// Each engine invariant names the exact method that later exercises it. The claim is a fixed
	// specification the later plans must satisfy; the suite performs no write and touches no native
	// Grocy table.
	$invariantClaims = [
		'D-01 zero-write generation' => 'GeneratePlan',
		'D-02 immutable before-image at generation' => 'GeneratePlan',
		'D-05 named-operation-only apply' => 'ApplyPlan',
		'D-07 optimistic-concurrency refusal' => 'ApplyPlan',
		'D-08 single BEGIN IMMEDIATE with no network under lock' => 'ApplyPlan',
		'D-09 checksum-keyed idempotency' => 'ApplyPlan',
		'D-10 append-only audit with authenticated actor' => 'ApplyPlan',
		'D-11 rollback refuses later manual edits' => 'PreviewRollback',
		'D-12 non-authoritative redacted export' => 'ExportPlan'
	];

	if (!bulkEngineSurfaceComplete())
	{
		expectedRed(BULK_INVARIANTS_MARKER, 'The bulk engine surface (' . implode(', ', array_values(array_unique($invariantClaims))) . ') is not implemented');
	}

	fwrite(STDOUT, "Bulk engine invariants passed\n");
	exit(0);
}

const BULK_SCHEMA_MARKER = 'EXPECTED_RED: bulk.schema';

function bulkNativeFixturePdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL)');
	$pdo->exec("INSERT INTO products (id, name) VALUES (1, 'Fixture product')");
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (1, 1, 2, 3, 4)');
	$pdo->exec('CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, path TEXT NOT NULL)');
	$pdo->exec("INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (1, 2, 3, 4, '1')");
	return $pdo;
}

function bulkSnapshotTables(PDO $pdo, array $tables): array
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
 * Plan 05-02: the idempotent, namespaced, native-safe, append-only bulk schema. Turns the relevant
 * Plan 05-01 DTO-shape assertions green against the real migration.
 */
function runBulkSchema(): never
{
	if (!class_exists(GrocyAiBulkMigration::class))
	{
		expectedRed(BULK_SCHEMA_MARKER, 'The bulk migration is not implemented');
	}

	$plan = bulkPlanCases();
	$expectedTables = ['grocy_ai_bulk_audit', 'grocy_ai_bulk_migrations', 'grocy_ai_bulk_plan_items', 'grocy_ai_bulk_plans'];

	// Test 1: double bootstrap is idempotent and leaves exactly the module tables + one version row.
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	GrocyAiBulkMigration::Bootstrap($pdo);
	GrocyAiBulkMigration::Bootstrap($pdo);
	$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'grocy_ai_bulk_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	bulkAssert($tables === $expectedTables, BULK_SCHEMA_MARKER, 'Bootstrap did not leave exactly the three bulk tables plus the migration ledger');
	bulkAssert((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_bulk_migrations')->fetchColumn() === 1, BULK_SCHEMA_MARKER, 'Bootstrap is not idempotent: the migration ledger holds other than one version row');

	// Test 2: each table carries exactly the shared-contract columns; the ledger is append-only; the
	// plan-item plan_id foreign-keys the plan header.
	foreach ([
		'grocy_ai_bulk_plans' => $plan['dto_shapes']['plan'],
		'grocy_ai_bulk_plan_items' => $plan['dto_shapes']['plan_item'],
		'grocy_ai_bulk_audit' => $plan['dto_shapes']['audit']
	] as $table => $expectedColumns)
	{
		$columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
		bulkAssert($columns === $expectedColumns, BULK_SCHEMA_MARKER, "Table {$table} columns are not the closed DTO shape");
	}
	$migrationSource = (string)file_get_contents(__DIR__ . '/../src/GrocyAiBulkMigration.php');
	bulkAssert(preg_match('/\b(UPDATE|DELETE)\b/', $migrationSource) !== 1, BULK_SCHEMA_MARKER, 'The bulk migration must expose no UPDATE/DELETE path (audit ledger is append-only)');
	$foreignKeys = $pdo->query('PRAGMA foreign_key_list(grocy_ai_bulk_plan_items)')->fetchAll(PDO::FETCH_ASSOC);
	$plansFk = array_filter($foreignKeys, static fn(array $fk): bool => $fk['table'] === 'grocy_ai_bulk_plans' && $fk['from'] === 'plan_id' && $fk['to'] === 'id');
	bulkAssert($plansFk !== [], BULK_SCHEMA_MARKER, 'grocy_ai_bulk_plan_items.plan_id must foreign-key grocy_ai_bulk_plans(id)');

	// Test 3: bootstrapping alongside native tables creates/drops no native table and leaves the
	// resolved cache row-identical.
	$nativePdo = bulkNativeFixturePdo();
	$nativeTables = ['products', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved'];
	$nativeSchemaBefore = $nativePdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'grocy_ai_bulk_%' AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$nativeBefore = bulkSnapshotTables($nativePdo, $nativeTables);
	GrocyAiBulkMigration::Bootstrap($nativePdo);
	$nativeSchemaAfter = $nativePdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'grocy_ai_bulk_%' AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$nativeAfter = bulkSnapshotTables($nativePdo, $nativeTables);
	bulkAssert($nativeSchemaBefore === $nativeSchemaAfter, BULK_SCHEMA_MARKER, 'Bootstrap created, altered, or dropped a native object');
	bulkAssert($nativeBefore === $nativeAfter, BULK_SCHEMA_MARKER, 'Bootstrap mutated native table rows');

	// Task 2: the migration is wired into the module bootstrap order ahead of the bulk service, and
	// routes.php remains parseable and eager-write-free.
	$routes = (string)file_get_contents(__DIR__ . '/../routes.php');
	$migrationRequirePos = strpos($routes, "require_once __DIR__ . '/src/GrocyAiBulkMigration.php';");
	$controllerRequirePos = strpos($routes, "require_once __DIR__ . '/src/GrocyAiApiController.php';");
	bulkAssert($migrationRequirePos !== false, BULK_SCHEMA_MARKER, 'routes.php does not require the bulk migration');
	bulkAssert($controllerRequirePos !== false && $migrationRequirePos < $controllerRequirePos, BULK_SCHEMA_MARKER, 'The bulk migration must be required before the controller requires');

	fwrite(STDOUT, "Bulk schema tests passed\n");
	exit(0);
}

const BULK_GENERATE_MARKER = 'EXPECTED_RED: bulk.generate';

/**
 * A self-contained non-household fixture database for GeneratePlan. Native tables (products,
 * product_groups, quantity_unit_conversions, cache) are created here; the module taxonomy and bulk
 * tables are created by the service constructor's bootstrap.
 */
function bulkGenerationPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name) VALUES (1, 'P1'), (2, 'P2'), (3, 'P3'), (4, 'P4'), (5, 'P5'), (6, 'P6')");
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (1, 1, 2, 3, 4)');
	$pdo->exec('CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, path TEXT NOT NULL)');
	$pdo->exec("INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (1, 2, 3, 4, '1')");
	return $pdo;
}

/**
 * Seed mixed taxonomy states so GeneratePlan exercises every closed count bucket:
 *   P1 mapped/changed (unclassified -> produce), P2 mapped/unchanged (already dairy-eggs),
 *   P3 excluded, P4 low_confidence (skipped), P5 unclassified (skipped),
 *   P6 evidence-level conflicting (skipped, no actionable suggestion).
 */
function bulkSeedGenerationFixture(PDO $pdo, GrocyAiBulkService $service): void
{
	$evidence = $pdo->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (?, ?, ?, ?, ?)');
	$evidence->execute([1, 'produce', 'v1', 'high', 'provider_category']);
	$evidence->execute([2, 'dairy', 'v1', 'high', 'provider_category']);
	$evidence->execute([3, 'baby food', 'v1', 'high', 'provider_category']);
	$evidence->execute([4, 'dairy', 'v1', 'low', 'provider_category']);
	$evidence->execute([6, 'mystery-brand', 'v1', 'medium', 'conflicting_evidence']);

	// P2 already carries its proposed leaf, so it is an included/unchanged item.
	$taxonomy = new GrocyAiTaxonomyService($pdo);
	$taxonomy->AssignProductTaxonomy(2, ['leaf_slug' => 'dairy-eggs', 'ruleset_version' => 'v1']);
}

/**
 * Plan 05-03: GeneratePlan produces a bounded dry-run with exact counts, immutable before-images, and
 * a deterministic checksum, provably without mutating any native Grocy state.
 */
function runBulkGenerate(): never
{
	if (!class_exists(GrocyAiBulkService::class) || !method_exists(GrocyAiBulkService::class, 'GeneratePlan'))
	{
		expectedRed(BULK_GENERATE_MARKER, 'GeneratePlan is not implemented');
	}

	$plan = bulkPlanCases();
	$expectedPlanKeys = $plan['dto_shapes']['plan'];
	$expectedItemKeys = $plan['dto_shapes']['plan_item'];

	$pdo = bulkGenerationPdo();
	$service = new GrocyAiBulkService($pdo);
	bulkSeedGenerationFixture($pdo, $service);

	// Native + module snapshots for the zero-write proof (Task 2).
	$snapshotTables = ['products', 'product_groups', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved', 'grocy_ai_taxonomy_classifications', 'grocy_ai_taxonomy_evidence'];
	$schemaBefore = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$rowsBefore = bulkSnapshotTables($pdo, $snapshotTables);
	$changesBefore = (int)$pdo->query('SELECT total_changes()')->fetchColumn();

	$generated = $service->GeneratePlan([]);

	$changesAfter = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
	$schemaAfter = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$rowsAfter = bulkSnapshotTables($pdo, $snapshotTables);

	// Test 1 / DTO: exact plan header key set and exact closed counts.
	bulkAssert(array_keys($generated) === $expectedPlanKeys, BULK_GENERATE_MARKER, 'GeneratePlan returned a plan DTO outside the closed key set');
	bulkAssert((string)$generated['operation_type'] === 'taxonomy_assignment', BULK_GENERATE_MARKER, 'The plan operation type is not taxonomy_assignment');
	bulkAssert((string)$generated['ruleset_version'] === 'v1', BULK_GENERATE_MARKER, 'The plan ruleset version is not the taxonomy migration version');
	bulkAssert((string)$generated['status'] === 'draft', BULK_GENERATE_MARKER, 'A freshly generated plan is not in draft status');
	$counts = json_decode((string)$generated['counts_json'], true, 512, JSON_THROW_ON_ERROR);
	$expectedCounts = ['included' => 2, 'excluded' => 1, 'skipped' => 3, 'conflicted' => 0, 'changed' => 1, 'unchanged' => 1];
	bulkAssert($counts === $expectedCounts, BULK_GENERATE_MARKER, 'counts_json does not match the fixture bucket-to-count mapping: ' . json_encode($counts));
	bulkAssert($counts['conflicted'] === 0, BULK_GENERATE_MARKER, 'conflicted must be 0 at generation');
	bulkAssert($counts['included'] === $counts['changed'] + $counts['unchanged'], BULK_GENERATE_MARKER, 'included must equal changed + unchanged');
	bulkAssert($counts['included'] + $counts['excluded'] + $counts['skipped'] + $counts['conflicted'] === 6, BULK_GENERATE_MARKER, 'The counts do not reconcile with the bounded scope size');

	// Test 2 / immutable items: identity, before/proposed written-field shape, reason, provenance.
	$planId = (int)$generated['id'];
	$rows = $pdo->query('SELECT * FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId . ' ORDER BY object_id')->fetchAll(PDO::FETCH_ASSOC);
	bulkAssert(count($rows) === 2, BULK_GENERATE_MARKER, 'GeneratePlan persisted an unexpected number of items');
	foreach ($rows as $row)
	{
		bulkAssert(array_keys($row) === $expectedItemKeys, BULK_GENERATE_MARKER, 'A persisted item is outside the closed item key set');
		bulkAssert((string)$row['object_type'] === 'product', BULK_GENERATE_MARKER, 'A persisted item has a non-product object type');
		bulkAssert((string)$row['operation'] === 'assign_taxonomy_leaf', BULK_GENERATE_MARKER, 'A generated proposal is not assign_taxonomy_leaf');
		bulkAssert(in_array((string)$row['reason'], $plan['reason_vocabulary'], true), BULK_GENERATE_MARKER, 'A persisted item reason is outside the closed reason vocabulary');
		bulkAssert((string)$row['outcome'] === 'pending', BULK_GENERATE_MARKER, 'A freshly generated item is not pending');
		// before/proposed images carry ONLY the written field (the leaf slug), never volatile evidence.
		$beforeImage = json_decode((string)$row['before_image_json'], true, 512, JSON_THROW_ON_ERROR);
		$proposedValue = json_decode((string)$row['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
		bulkAssert(array_keys($beforeImage) === ['leaf_slug'], BULK_GENERATE_MARKER, 'before_image_json exposes fields beyond the written leaf slug');
		bulkAssert(array_keys($proposedValue) === ['leaf_slug'], BULK_GENERATE_MARKER, 'proposed_value_json exposes fields beyond the written leaf slug');
	}
	$byId = [];
	foreach ($rows as $row)
	{
		$byId[(int)$row['object_id']] = $row;
	}
	$p1Before = json_decode((string)$byId[1]['before_image_json'], true, 512, JSON_THROW_ON_ERROR);
	$p1Proposed = json_decode((string)$byId[1]['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
	bulkAssert($p1Before['leaf_slug'] === null && $p1Proposed['leaf_slug'] === 'produce' && (int)$byId[1]['selected'] === 1, BULK_GENERATE_MARKER, 'P1 must be a changed, pre-selected proposal from unclassified to produce');
	$p2Before = json_decode((string)$byId[2]['before_image_json'], true, 512, JSON_THROW_ON_ERROR);
	$p2Proposed = json_decode((string)$byId[2]['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
	bulkAssert($p2Before['leaf_slug'] === 'dairy-eggs' && $p2Proposed['leaf_slug'] === 'dairy-eggs' && (int)$byId[2]['selected'] === 0, BULK_GENERATE_MARKER, 'P2 must be an unchanged, deselected proposal already at dairy-eggs');

	// Test 3 / checksum: reorder-stable, mutation-sensitive, and equal to the stored checksum.
	$checksum = (string)$generated['checksum'];
	bulkAssert(preg_match('/^[0-9a-f]{64}$/D', $checksum) === 1, BULK_GENERATE_MARKER, 'The plan checksum is not a lowercase 64-hex SHA-256');
	$items = [
		['object_type' => 'product', 'object_id' => 1, 'operation' => 'assign_taxonomy_leaf', 'before_image' => null, 'proposed_value' => 'produce'],
		['object_type' => 'product', 'object_id' => 2, 'operation' => 'assign_taxonomy_leaf', 'before_image' => 'dairy-eggs', 'proposed_value' => 'dairy-eggs']
	];
	$recomputed = $service->ChecksumForPlan('taxonomy_assignment', 'v1', $items);
	bulkAssert($recomputed === $checksum, BULK_GENERATE_MARKER, 'The stored checksum does not match the recomputed checksum for the same items');
	$reordered = [$items[1], $items[0]];
	bulkAssert($service->ChecksumForPlan('taxonomy_assignment', 'v1', $reordered) === $checksum, BULK_GENERATE_MARKER, 'Reordering items changed the checksum');
	$mutated = $items;
	$mutated[1]['proposed_value'] = 'produce';
	bulkAssert($service->ChecksumForPlan('taxonomy_assignment', 'v1', $mutated) !== $checksum, BULK_GENERATE_MARKER, 'Mutating a proposed value did not change the checksum');

	// Determinism: regenerating the same scope yields the same checksum.
	$secondPdo = bulkGenerationPdo();
	$secondService = new GrocyAiBulkService($secondPdo);
	bulkSeedGenerationFixture($secondPdo, $secondService);
	$second = $secondService->GeneratePlan([]);
	bulkAssert((string)$second['checksum'] === $checksum, BULK_GENERATE_MARKER, 'Regenerating the same scope produced a different checksum');

	// Task 2 zero-write proof: native + module state byte-identical except the two bulk tables, no
	// schema change, and total_changes attributable only to the plan + item inserts.
	bulkAssert($schemaBefore === $schemaAfter, BULK_GENERATE_MARKER, 'Generation created, altered, or dropped a database object');
	bulkAssert($rowsBefore === $rowsAfter, BULK_GENERATE_MARKER, 'Generation mutated a native or read-only module table');
	$itemCount = (int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId)->fetchColumn();
	bulkAssert($changesAfter - $changesBefore === 1 + $itemCount, BULK_GENERATE_MARKER, 'Generation issued row changes beyond the plan and its items: delta ' . ($changesAfter - $changesBefore));

	fwrite(STDOUT, "Bulk generate tests passed\n");
	exit(0);
}

const BULK_REGISTRY_MARKER = 'EXPECTED_RED: bulk.registry';

function bulkRegistryPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE product_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1)');
	$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, product_group_id INTEGER NULL)');
	$pdo->exec("INSERT INTO products (id, name) VALUES (1, 'P1')");
	return $pdo;
}

/**
 * Plan 05-04: the closed named-typed-operation registry. Only the two shipped taxonomy operations
 * resolve, each delegating to AssignProductTaxonomy; every other operation fails closed with the
 * single unknown_operation blocker before any write.
 */
function runBulkRegistry(): never
{
	if (!class_exists(GrocyAiBulkService::class) || !method_exists(GrocyAiBulkService::class, 'RegisteredOperations') || !method_exists(GrocyAiBulkService::class, 'ResolveOperation'))
	{
		expectedRed(BULK_REGISTRY_MARKER, 'The closed operation registry is not implemented');
	}

	$cases = bulkRegistryCases();

	// Task 1 Test 1: the registry is a closed server-side map with exactly the two operations.
	$pdo = bulkRegistryPdo();
	$service = new GrocyAiBulkService($pdo);
	$registered = $service->RegisteredOperations();
	bulkAssert(array_keys($registered) === ['assign_taxonomy_leaf', 'set_unclassified'], BULK_REGISTRY_MARKER, 'RegisteredOperations is not the closed two-operation map');
	foreach ($registered as $operation => $meta)
	{
		bulkAssert(($meta['delegate_write'] ?? null) === 'AssignProductTaxonomy', BULK_REGISTRY_MARKER, "Operation {$operation} delegates to a write other than AssignProductTaxonomy");
		$keys = $operation === 'assign_taxonomy_leaf' ? ['leaf_slug', 'ruleset_version'] : ['unclassified', 'ruleset_version'];
		bulkAssert(($meta['assignment_keys'] ?? null) === $keys, BULK_REGISTRY_MARKER, "Operation {$operation} produces an assignment shape outside the closed key set");
	}

	// Task 1 Test 2: each named operation resolves to a delegate over the shipped taxonomy write and
	// returns the ReadProductTaxonomy DTO.
	$assign = $service->ResolveOperation('assign_taxonomy_leaf');
	bulkAssert(is_callable($assign['delegate']) && $assign['blockers'] === [], BULK_REGISTRY_MARKER, 'assign_taxonomy_leaf did not resolve to a callable');
	$assignResult = ($assign['delegate'])(1, 'produce');
	bulkAssert(($assignResult['current_leaf']['slug'] ?? null) === 'produce', BULK_REGISTRY_MARKER, 'The assign_taxonomy_leaf delegate did not set the leaf via AssignProductTaxonomy');
	$unclassify = $service->ResolveOperation('set_unclassified');
	bulkAssert(is_callable($unclassify['delegate']) && $unclassify['blockers'] === [], BULK_REGISTRY_MARKER, 'set_unclassified did not resolve to a callable');
	$unclassifyResult = ($unclassify['delegate'])(1, null);
	bulkAssert(array_key_exists('current_leaf', $unclassifyResult) && $unclassifyResult['current_leaf'] === null, BULK_REGISTRY_MARKER, 'The set_unclassified delegate did not clear the leaf via AssignProductTaxonomy');

	// Task 1 Test 3: the shipped write keeps its single INSERT ... ON CONFLICT statement; the only edit
	// is the optional nesting guard. The registry adds no new SQL of its own.
	$taxonomySource = (string)file_get_contents(__DIR__ . '/../src/GrocyAiTaxonomyService.php');
	bulkAssert(substr_count($taxonomySource, 'INSERT INTO grocy_ai_taxonomy_classifications') === 1, BULK_REGISTRY_MARKER, 'The taxonomy classification write is no longer a single statement');
	bulkAssert(str_contains($taxonomySource, 'bool $joinExistingTransaction = false'), BULK_REGISTRY_MARKER, 'The transaction-nesting guard parameter is missing');

	// Task 1 Test 4: nesting-awareness. With join=true the delegate performs the upsert but does not
	// own the transaction, so an outer rollback undoes its write; and it does not raise a nested-BEGIN
	// error when invoked inside an outer transaction.
	$nestPdo = bulkRegistryPdo();
	$nestService = new GrocyAiBulkService($nestPdo);
	$nestAssign = $nestService->ResolveOperation('assign_taxonomy_leaf');
	$nestPdo->beginTransaction();
	($nestAssign['delegate'])(1, 'produce');
	$nestPdo->rollBack();
	$countAfterRollback = (int)$nestPdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_classifications WHERE product_id = 1')->fetchColumn();
	bulkAssert($countAfterRollback === 0, BULK_REGISTRY_MARKER, 'A join=true delegate committed independently: the caller does not own the transaction');
	// Default (2-arg) behavior still owns and commits its own transaction, byte-identical for callers.
	$defaultTaxonomy = new GrocyAiTaxonomyService($nestPdo);
	$defaultTaxonomy->AssignProductTaxonomy(1, ['leaf_slug' => 'produce', 'ruleset_version' => 'v1']);
	$countAfterDefault = (int)$nestPdo->query('SELECT COUNT(*) FROM grocy_ai_taxonomy_classifications WHERE product_id = 1')->fetchColumn();
	bulkAssert($countAfterDefault === 1, BULK_REGISTRY_MARKER, 'The default 2-arg AssignProductTaxonomy no longer commits its own transaction');

	// Task 2: every out-of-map, free-form, or SQL-bearing operation fails closed with exactly one
	// unknown_operation blocker and no callable, and causes no mutation (native-write spy via snapshot).
	$rejectPdo = bulkRegistryPdo();
	$rejectService = new GrocyAiBulkService($rejectPdo);
	$rejectService->ResolveOperation('assign_taxonomy_leaf'); // warm the service without writing
	$spyTables = ['products', 'product_groups', 'grocy_ai_taxonomy_classifications', 'grocy_ai_taxonomy_evidence'];
	$rowsBefore = bulkSnapshotTables($rejectPdo, $spyTables);
	$changesBefore = (int)$rejectPdo->query('SELECT total_changes()')->fetchColumn();
	foreach ($cases['reject'] as $case)
	{
		$resolution = $rejectService->ResolveOperation((string)$case['operation']);
		bulkAssert($resolution['delegate'] === null, BULK_REGISTRY_MARKER, 'A rejected operation returned a callable: ' . $case['operation']);
		bulkAssert($resolution['blockers'] === ['unknown_operation'], BULK_REGISTRY_MARKER, 'A rejected operation did not fail closed with the single unknown_operation blocker: ' . $case['operation']);
	}
	$changesAfter = (int)$rejectPdo->query('SELECT total_changes()')->fetchColumn();
	$rowsAfter = bulkSnapshotTables($rejectPdo, $spyTables);
	bulkAssert($rowsBefore === $rowsAfter, BULK_REGISTRY_MARKER, 'Resolving a rejected operation mutated a native or module table');
	bulkAssert($changesBefore === $changesAfter, BULK_REGISTRY_MARKER, 'Resolving a rejected operation issued row changes');

	fwrite(STDOUT, "Bulk registry tests passed\n");
	exit(0);
}
