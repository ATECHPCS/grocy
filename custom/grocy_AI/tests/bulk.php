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

const BULK_SELECTION_MARKER = 'EXPECTED_RED: bulk.selection';

/**
 * Bind an in-memory PDO to the Grocy DatabaseService singleton so the controller's `new
 * GrocyAiBulkService(...)` and `User::CheckPermission` resolve against the fixture database.
 */
function bulkInstallDatabase(PDO $pdo): void
{
	$serviceReflection = new ReflectionClass(Grocy\Services\DatabaseService::class);
	foreach (['DbConnectionRaw' => $pdo, 'DbConnection' => new LessQL\Database($pdo), 'instance' => $serviceReflection->newInstance()] as $propertyName => $value)
	{
		$property = $serviceReflection->getProperty($propertyName);
		$property->setValue(null, $value);
	}
}

function bulkSelectionRuntime(): void
{
	if (!defined('GROCY_MODE'))
	{
		define('GROCY_MODE', 'production');
	}
	if (!defined('GROCY_DATAPATH'))
	{
		define('GROCY_DATAPATH', sys_get_temp_dir());
	}
	if (!defined('GROCY_USER_ID'))
	{
		define('GROCY_USER_ID', 1);
	}
	require_once dirname(__DIR__, 3) . '/packages/autoload.php';
	require_once dirname(__DIR__) . '/src/GrocyAiBulkService.php';
	require_once dirname(__DIR__) . '/src/GrocyAiApiController.php';
	require_once dirname(__DIR__) . '/src/GrocyAiBulkController.php';
}

function bulkSelectionRequest(string $method, string $path, ?array $parsedBody = null): Psr\Http\Message\ServerRequestInterface
{
	$request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, $path);
	if ($parsedBody !== null)
	{
		$request = $request->withHeader('Content-Type', 'application/json')->withParsedBody($parsedBody);
	}
	return $request;
}

function bulkSelectionResponse(): Psr\Http\Message\ResponseInterface
{
	return (new Slim\Psr7\Factory\ResponseFactory())->createResponse();
}

function bulkSelectionBody(Psr\Http\Message\ResponseInterface $response): array
{
	return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A full native + resolved-cache + taxonomy snapshot plus `total_changes()`, for the same zero-write
 * rigor the 05-03 generation proof uses. Selection and rendering may touch only the module-owned
 * `selected` flag; nothing here may change on a read or an invalid request.
 */
function bulkSelectionStateSnapshot(PDO $pdo): array
{
	$tables = ['products', 'product_groups', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved', 'grocy_ai_taxonomy_classifications', 'grocy_ai_taxonomy_evidence'];
	return [
		'rows' => bulkSnapshotTables($pdo, $tables),
		'schema' => $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC),
		'total_changes' => (int)$pdo->query('SELECT total_changes()')->fetchColumn()
	];
}

function bulkSelectionAssertRoutesRegistered(): void
{
	$container = new DI\Container();
	$app = Slim\Factory\AppFactory::createFromContainer($container);
	require dirname(__DIR__) . '/routes.php';
	$found = [];
	foreach ($app->getRouteCollector()->getRoutes() as $route)
	{
		$found[$route->getPattern()] = $route->getMethods();
	}
	$expected = [
		'/api/grocy-ai/bulk/plans/{planId}' => ['GET'],
		'/api/grocy-ai/bulk/plans/{planId}/items/{seq}/selection' => ['PUT'],
		'/api/grocy-ai/bulk/plans/{planId}/selected-diff' => ['GET'],
		'/grocyai/bulkreview' => ['GET']
	];
	foreach ($expected as $pattern => $methods)
	{
		bulkAssert(($found[$pattern] ?? null) === $methods, BULK_SELECTION_MARKER, "Route {$pattern} is not registered exactly as " . implode(',', $methods));
	}
}

/**
 * Plan 05-05: per-item selection persists with the plan and never touches the checksum; the selected
 * diff is complete and rejected-free; selection and rendering are provably write-free beyond the single
 * module-owned `selected` flag; and the reads/toggle are only reachable by an authenticated
 * MASTER_DATA_EDIT user through closed endpoints.
 */
function runBulkSelection(): never
{
	if (!class_exists(GrocyAiBulkService::class)
		|| !method_exists(GrocyAiBulkService::class, 'SetItemSelection')
		|| !method_exists(GrocyAiBulkService::class, 'SelectedDiff')
		|| !method_exists(GrocyAiBulkService::class, 'ReadPlan'))
	{
		expectedRed(BULK_SELECTION_MARKER, 'SetItemSelection / SelectedDiff / ReadPlan are not implemented');
	}

	$expectedItemKeys = ['seq', 'object_type', 'object_id', 'operation', 'before_image', 'proposed_value', 'reason', 'provenance', 'selected', 'outcome'];

	// ---- Task 1: service-level selection + selected diff -----------------------------------------
	$pdo = bulkGenerationPdo();
	$service = new GrocyAiBulkService($pdo);
	bulkSeedGenerationFixture($pdo, $service);
	$generated = $service->GeneratePlan([]);
	$planId = (int)$generated['id'];
	$storedChecksum = (string)$generated['checksum'];

	// Baseline: seq 0 = P1 (changed, pre-selected); seq 1 = P2 (unchanged, deselected).
	$plan = $service->ReadPlan($planId);
	bulkAssert(array_keys($plan) === ['plan', 'counts', 'items'], BULK_SELECTION_MARKER, 'ReadPlan DTO is not the closed shape');
	bulkAssert(count($plan['items']) === 2, BULK_SELECTION_MARKER, 'ReadPlan returned an unexpected item count');
	$bySeq = [];
	foreach ($plan['items'] as $item)
	{
		$bySeq[$item['seq']] = $item;
	}
	bulkAssert(array_keys($bySeq[0]) === $expectedItemKeys, BULK_SELECTION_MARKER, 'A plan item DTO is outside the closed review shape');
	bulkAssert($bySeq[0]['object_id'] === 1 && $bySeq[0]['selected'] === true, BULK_SELECTION_MARKER, 'P1 must start selected');
	bulkAssert($bySeq[1]['object_id'] === 2 && $bySeq[1]['selected'] === false, BULK_SELECTION_MARKER, 'P2 must start deselected');

	// Test 1: selecting P2 flips exactly one flag; re-read reflects it; a second identical call writes nothing.
	$afterSelect = $service->SetItemSelection($planId, 1, true);
	$reBySeq = [];
	foreach ($afterSelect['items'] as $item)
	{
		$reBySeq[$item['seq']] = $item;
	}
	bulkAssert($reBySeq[1]['selected'] === true, BULK_SELECTION_MARKER, 'Selecting P2 did not persist');
	bulkAssert($reBySeq[0]['selected'] === true, BULK_SELECTION_MARKER, 'Selecting P2 disturbed P1');
	bulkAssert((int)$pdo->query('SELECT selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId . ' AND seq = 1')->fetchColumn() === 1, BULK_SELECTION_MARKER, 'The selected column was not written');
	$changesBeforeIdem = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
	$service->SetItemSelection($planId, 1, true);
	bulkAssert($changesBeforeIdem === (int)$pdo->query('SELECT total_changes()')->fetchColumn(), BULK_SELECTION_MARKER, 'An idempotent identical selection issued a write');

	// Test 2: the selected diff is exactly the selected items, verbatim, rejected omitted; checksum unchanged.
	$service->SetItemSelection($planId, 0, false); // reject P1, leaving only P2 selected
	$diff = $service->SelectedDiff($planId);
	bulkAssert(array_keys($diff) === ['plan_id', 'checksum', 'operation_type', 'ruleset_version', 'included', 'items'], BULK_SELECTION_MARKER, 'SelectedDiff DTO is outside the closed shape');
	bulkAssert(count($diff['items']) === 1 && $diff['items'][0]['object_id'] === 2, BULK_SELECTION_MARKER, 'SelectedDiff must contain exactly the selected item');
	bulkAssert($diff['included'] === 1, BULK_SELECTION_MARKER, 'The apply-set count must equal the number of selected items');
	bulkAssert(array_keys($diff['items'][0]) === $expectedItemKeys, BULK_SELECTION_MARKER, 'A selected-diff item is outside the closed review shape');
	bulkAssert($diff['items'][0]['before_image'] === ['leaf_slug' => 'dairy-eggs'] && $diff['items'][0]['proposed_value'] === ['leaf_slug' => 'dairy-eggs'], BULK_SELECTION_MARKER, 'The selected diff must expose the immutable before/proposed images verbatim');
	bulkAssert($diff['checksum'] === $storedChecksum, BULK_SELECTION_MARKER, 'Selection changed the returned plan checksum');
	$recompute = $service->ChecksumForPlan('taxonomy_assignment', 'v1', [
		['object_type' => 'product', 'object_id' => 1, 'operation' => 'assign_taxonomy_leaf', 'before_image' => null, 'proposed_value' => 'produce'],
		['object_type' => 'product', 'object_id' => 2, 'operation' => 'assign_taxonomy_leaf', 'before_image' => 'dairy-eggs', 'proposed_value' => 'dairy-eggs']
	]);
	bulkAssert($recompute === $storedChecksum, BULK_SELECTION_MARKER, 'The plan checksum is not reproducible after selection');
	bulkAssert((string)$pdo->query('SELECT checksum FROM grocy_ai_bulk_plans WHERE id = ' . $planId)->fetchColumn() === $storedChecksum, BULK_SELECTION_MARKER, 'The stored checksum row changed after selection');

	// Test 3: a selection change writes only the selected column; native/cache/taxonomy state is untouched.
	$before = bulkSelectionStateSnapshot($pdo);
	$service->SetItemSelection($planId, 1, false);
	$after = bulkSelectionStateSnapshot($pdo);
	bulkAssert($before['schema'] === $after['schema'], BULK_SELECTION_MARKER, 'Selection created, altered, or dropped a database object');
	bulkAssert($before['rows'] === $after['rows'], BULK_SELECTION_MARKER, 'Selection mutated native, cache, or taxonomy state');
	bulkAssert($after['total_changes'] - $before['total_changes'] === 1, BULK_SELECTION_MARKER, 'Selection issued row changes beyond the single selected flag');

	// Invalid requests fail closed with no write.
	$itemsBefore = $pdo->query('SELECT seq, selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
	$invalidBefore = bulkSelectionStateSnapshot($pdo);
	try
	{
		$service->SetItemSelection($planId, 99, true);
		bulkAssert(false, BULK_SELECTION_MARKER, 'An unknown seq was accepted');
	}
	catch (InvalidArgumentException)
	{
	}
	try
	{
		$service->SetItemSelection($planId, 1, 'yes');
		bulkAssert(false, BULK_SELECTION_MARKER, 'A non-boolean flag was accepted');
	}
	catch (InvalidArgumentException)
	{
	}
	try
	{
		$service->SetItemSelection(999999, 0, true);
		bulkAssert(false, BULK_SELECTION_MARKER, 'An unknown plan was accepted');
	}
	catch (RuntimeException)
	{
	}
	$invalidAfter = bulkSelectionStateSnapshot($pdo);
	bulkAssert($invalidBefore['rows'] === $invalidAfter['rows'] && $invalidBefore['total_changes'] === $invalidAfter['total_changes'], BULK_SELECTION_MARKER, 'An invalid selection request issued a write');
	bulkAssert($itemsBefore === $pdo->query('SELECT seq, selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC), BULK_SELECTION_MARKER, 'An invalid selection request changed a selection flag');

	// A stale ruleset version freezes selection with no write.
	$pdo->exec("UPDATE grocy_ai_bulk_plans SET ruleset_version = 'stale' WHERE id = " . $planId);
	$staleBefore = bulkSelectionStateSnapshot($pdo);
	try
	{
		$service->SetItemSelection($planId, 1, true);
		bulkAssert(false, BULK_SELECTION_MARKER, 'A stale-ruleset plan accepted a selection');
	}
	catch (RuntimeException)
	{
	}
	bulkAssert($staleBefore['total_changes'] === (int)$pdo->query('SELECT total_changes()')->fetchColumn(), BULK_SELECTION_MARKER, 'A stale-ruleset selection issued a write');
	$pdo->exec("UPDATE grocy_ai_bulk_plans SET ruleset_version = 'v1' WHERE id = " . $planId);

	// An already-applied (non-reviewable) plan freezes selection.
	$pdo->exec("UPDATE grocy_ai_bulk_plans SET status = 'applied' WHERE id = " . $planId);
	try
	{
		$service->SetItemSelection($planId, 1, true);
		bulkAssert(false, BULK_SELECTION_MARKER, 'A non-reviewable plan accepted a selection');
	}
	catch (RuntimeException)
	{
	}
	$pdo->exec("UPDATE grocy_ai_bulk_plans SET status = 'draft' WHERE id = " . $planId);

	// ---- Task 2: MASTER_DATA_EDIT-gated read/selection endpoints ---------------------------------
	bulkSelectionRuntime();
	bulkSelectionAssertRoutesRegistered();

	$apiPdo = bulkGenerationPdo();
	$apiPdo->exec('CREATE TABLE user_permissions_resolved (id INTEGER NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, permission_name TEXT NOT NULL)');
	$apiPdo->exec("INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT')");
	$apiService = new GrocyAiBulkService($apiPdo);
	bulkSeedGenerationFixture($apiPdo, $apiService);
	$apiPlanId = (int)$apiService->GeneratePlan([])['id'];
	bulkInstallDatabase($apiPdo);

	$controller = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiApiController::class))->newInstanceWithoutConstructor();

	// Test 1: GET plan returns header + counts + items; permission enforced before any read.
	$planResponse = $controller->BulkPlan(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);
	bulkAssert($planResponse->getStatusCode() === 200, BULK_SELECTION_MARKER, 'BulkPlan GET did not succeed');
	$planBody = bulkSelectionBody($planResponse);
	bulkAssert(array_keys($planBody) === ['plan', 'counts', 'items'] && count($planBody['items']) === 2, BULK_SELECTION_MARKER, 'BulkPlan response is not the closed plan read shape');
	$controller->BulkPlan(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);

	$apiPdo->exec("DELETE FROM user_permissions_resolved WHERE permission_name = 'MASTER_DATA_EDIT'");
	$permBefore = bulkSelectionStateSnapshot($apiPdo);
	try
	{
		$controller->BulkPlan(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);
		bulkAssert(false, BULK_SELECTION_MARKER, 'BulkPlan did not enforce MASTER_DATA_EDIT');
	}
	catch (Grocy\Controllers\Users\PermissionMissingException)
	{
	}
	bulkAssert($permBefore['total_changes'] === (int)$apiPdo->query('SELECT total_changes()')->fetchColumn(), BULK_SELECTION_MARKER, 'An unauthorized plan read issued a write');
	$apiPdo->exec("INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT')");

	// Test 2: PUT selection toggles one item via the closed body; anything else is a bounded 400 with no write.
	$selResponse = $controller->BulkPlanSetItemSelection(bulkSelectionRequest('PUT', '/x', ['selected' => true]), bulkSelectionResponse(), ['planId' => (string)$apiPlanId, 'seq' => '1']);
	bulkAssert($selResponse->getStatusCode() === 200, BULK_SELECTION_MARKER, 'Selection PUT did not succeed');
	bulkAssert((int)$apiPdo->query('SELECT selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $apiPlanId . ' AND seq = 1')->fetchColumn() === 1, BULK_SELECTION_MARKER, 'Selection PUT did not persist the flag');

	$badBodies = [
		['selected' => true, 'entity' => 'products'],
		['entity' => 'products', 'field' => 'name', 'value' => 'x'],
		['selected' => 'true'],
		['selected' => 1],
		[]
	];
	foreach ($badBodies as $bad)
	{
		$flagBefore = $apiPdo->query('SELECT seq, selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $apiPlanId . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
		$changesBefore = (int)$apiPdo->query('SELECT total_changes()')->fetchColumn();
		$badResponse = $controller->BulkPlanSetItemSelection(bulkSelectionRequest('PUT', '/x', $bad), bulkSelectionResponse(), ['planId' => (string)$apiPlanId, 'seq' => '1']);
		bulkAssert($badResponse->getStatusCode() === 400, BULK_SELECTION_MARKER, 'A non-closed selection body was not rejected with 400');
		bulkAssert(bulkSelectionBody($badResponse) === ['error_message' => 'Invalid selection request'], BULK_SELECTION_MARKER, 'A rejected selection body did not return the bounded error');
		bulkAssert($flagBefore === $apiPdo->query('SELECT seq, selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $apiPlanId . ' ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC), BULK_SELECTION_MARKER, 'A rejected selection body changed a flag');
		bulkAssert($changesBefore === (int)$apiPdo->query('SELECT total_changes()')->fetchColumn(), BULK_SELECTION_MARKER, 'A rejected selection body issued a write');
	}
	$badIdResponse = $controller->BulkPlanSetItemSelection(bulkSelectionRequest('PUT', '/x', ['selected' => true]), bulkSelectionResponse(), ['planId' => 'abc', 'seq' => '1']);
	bulkAssert($badIdResponse->getStatusCode() === 400, BULK_SELECTION_MARKER, 'A non-integer plan id was not rejected');

	// Test 3: GET selected-diff returns exactly the selected items and declares no apply/write; unknown plan → 404.
	$controller->BulkPlanSetItemSelection(bulkSelectionRequest('PUT', '/x', ['selected' => true]), bulkSelectionResponse(), ['planId' => (string)$apiPlanId, 'seq' => '1']);
	$controller->BulkPlanSetItemSelection(bulkSelectionRequest('PUT', '/x', ['selected' => false]), bulkSelectionResponse(), ['planId' => (string)$apiPlanId, 'seq' => '0']);
	$diffResponse = $controller->BulkPlanSelectedDiff(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);
	bulkAssert($diffResponse->getStatusCode() === 200, BULK_SELECTION_MARKER, 'Selected-diff GET did not succeed');
	$diffBody = bulkSelectionBody($diffResponse);
	bulkAssert(array_keys($diffBody) === ['plan_id', 'checksum', 'operation_type', 'ruleset_version', 'included', 'items'], BULK_SELECTION_MARKER, 'Selected-diff endpoint exposes a field beyond the closed read shape');
	bulkAssert(count($diffBody['items']) === 1 && $diffBody['items'][0]['object_id'] === 2 && $diffBody['included'] === 1, BULK_SELECTION_MARKER, 'Selected-diff endpoint did not return exactly the selected item');

	$missingResponse = $controller->BulkPlanSelectedDiff(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => '987654321']);
	bulkAssert($missingResponse->getStatusCode() === 404, BULK_SELECTION_MARKER, 'An unknown plan id did not return 404 from selected-diff');

	// Reading through the endpoints performs no write (query_only forbids any accidental mutation).
	$readBefore = bulkSelectionStateSnapshot($apiPdo);
	$apiPdo->exec('PRAGMA query_only = ON');
	try
	{
		$controller->BulkPlan(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);
		$controller->BulkPlanSelectedDiff(bulkSelectionRequest('GET', '/x'), bulkSelectionResponse(), ['planId' => (string)$apiPlanId]);
	}
	finally
	{
		$apiPdo->exec('PRAGMA query_only = OFF');
	}
	$readAfter = bulkSelectionStateSnapshot($apiPdo);
	bulkAssert($readBefore['rows'] === $readAfter['rows'] && $readBefore['total_changes'] === $readAfter['total_changes'], BULK_SELECTION_MARKER, 'A read endpoint issued a write');

	fwrite(STDOUT, "Bulk selection tests passed\n");
	exit(0);
}

const BULK_CONFLICT_MARKER = 'EXPECTED_RED: bulk.conflict';

/**
 * A full persistent-state snapshot (native + resolved-cache + module taxonomy + the two bulk tables)
 * plus `total_changes()`, for the same zero-write rigor the 05-03 generation proof uses. Conflict
 * detection may touch NONE of these — it is a pure read.
 */
function bulkConflictStateSnapshot(PDO $pdo): array
{
	$tables = ['products', 'product_groups', 'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved', 'grocy_ai_taxonomy_classifications', 'grocy_ai_taxonomy_evidence', 'grocy_ai_bulk_plans', 'grocy_ai_bulk_plan_items'];
	return [
		'rows' => bulkSnapshotTables($pdo, $tables),
		'schema' => $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC),
		'total_changes' => (int)$pdo->query('SELECT total_changes()')->fetchColumn()
	];
}

/** Index a conflict-detection item list by its plan-item seq. */
function bulkConflictBySeq(array $items): array
{
	$bySeq = [];
	foreach ($items as $item)
	{
		$bySeq[$item['seq']] = $item;
	}
	return $bySeq;
}

/**
 * Plan 05-06: per-item optimistic-concurrency conflict detection. `DetectApplyConflicts` re-reads each
 * selected item's current WRITTEN field through the shipped taxonomy read path and refuses any item whose
 * value has drifted from the reviewed before-image — marking it `conflict`, dropping it from the apply
 * set while valid siblings remain, comparing only the written field (never volatile evidence), never
 * trusting the stored plan as reality, and never writing.
 */
function runBulkConflict(): never
{
	if (!class_exists(GrocyAiBulkService::class) || !method_exists(GrocyAiBulkService::class, 'DetectApplyConflicts'))
	{
		expectedRed(BULK_CONFLICT_MARKER, 'DetectApplyConflicts is not implemented');
	}

	$expectedResultKeys = ['plan_id', 'checksum', 'items', 'apply_set'];
	$expectedItemKeys = ['seq', 'object_type', 'object_id', 'operation', 'before_image', 'current_value', 'conflict', 'annotation'];

	// ---- Task 1: matching stays eligible, drift becomes conflict, re-read is live, zero-write --------
	$pdo = bulkGenerationPdo();
	$service = new GrocyAiBulkService($pdo);
	bulkSeedGenerationFixture($pdo, $service);
	$generated = $service->GeneratePlan([]);
	$planId = (int)$generated['id'];
	// Select both items: seq 0 = P1 (before null / unclassified), seq 1 = P2 (before dairy-eggs).
	$service->SetItemSelection($planId, 1, true);
	$taxonomy = new GrocyAiTaxonomyService($pdo);

	// Test 1: every selected item still matches its reviewed before-image -> no conflict, all eligible.
	$clean = $service->DetectApplyConflicts($planId);
	bulkAssert(array_keys($clean) === $expectedResultKeys, BULK_CONFLICT_MARKER, 'DetectApplyConflicts result is outside the closed shape');
	bulkAssert((int)$clean['plan_id'] === $planId && $clean['checksum'] === (string)$generated['checksum'], BULK_CONFLICT_MARKER, 'DetectApplyConflicts did not echo the stored plan identity and checksum');
	bulkAssert(count($clean['items']) === 2, BULK_CONFLICT_MARKER, 'DetectApplyConflicts evaluated other than the two selected items');
	$cleanBySeq = bulkConflictBySeq($clean['items']);
	bulkAssert(array_keys($cleanBySeq[0]) === $expectedItemKeys, BULK_CONFLICT_MARKER, 'A conflict-detection item is outside the closed shape');
	bulkAssert($cleanBySeq[0]['annotation'] === 'no_conflict' && $cleanBySeq[0]['conflict'] === false, BULK_CONFLICT_MARKER, 'A matching unclassified item was not eligible');
	bulkAssert($cleanBySeq[0]['before_image'] === null && $cleanBySeq[0]['current_value'] === null, BULK_CONFLICT_MARKER, 'P1 written-field before/current are not both null (unclassified)');
	bulkAssert($cleanBySeq[1]['annotation'] === 'no_conflict' && $cleanBySeq[1]['before_image'] === 'dairy-eggs' && $cleanBySeq[1]['current_value'] === 'dairy-eggs', BULK_CONFLICT_MARKER, 'A matching classified item was not eligible over the written field');
	bulkAssert(count($clean['apply_set']) === 2, BULK_CONFLICT_MARKER, 'The apply set is not the full selected set when nothing drifted');

	// Test 2: drift P1's underlying written value after generation -> P1 conflicts and drops; P2 stays.
	$taxonomy->AssignProductTaxonomy(1, ['leaf_slug' => 'produce', 'ruleset_version' => 'v1']);
	$drift = $service->DetectApplyConflicts($planId);
	$driftBySeq = bulkConflictBySeq($drift['items']);
	bulkAssert($driftBySeq[0]['annotation'] === 'conflict' && $driftBySeq[0]['conflict'] === true, BULK_CONFLICT_MARKER, 'A drifted item was not marked conflict');
	bulkAssert($driftBySeq[0]['before_image'] === null && $driftBySeq[0]['current_value'] === 'produce', BULK_CONFLICT_MARKER, 'The conflict did not reflect the live drifted written value');
	bulkAssert($driftBySeq[1]['annotation'] === 'no_conflict', BULK_CONFLICT_MARKER, 'A non-drifted sibling was disturbed by a drifted item');
	bulkAssert(count($drift['apply_set']) === 1 && $drift['apply_set'][0]['object_id'] === 2, BULK_CONFLICT_MARKER, 'The drifted item was not dropped from the apply set while the valid sibling remained');

	// Test 3a: detection is zero-write (no native, cache, taxonomy, plan, item, or outcome row changes).
	$before = bulkConflictStateSnapshot($pdo);
	$service->DetectApplyConflicts($planId);
	$after = bulkConflictStateSnapshot($pdo);
	bulkAssert($before['schema'] === $after['schema'], BULK_CONFLICT_MARKER, 'Conflict detection created, altered, or dropped a database object');
	bulkAssert($before['rows'] === $after['rows'], BULK_CONFLICT_MARKER, 'Conflict detection mutated a persistent table');
	bulkAssert($before['total_changes'] === $after['total_changes'], BULK_CONFLICT_MARKER, 'Conflict detection issued a row change');
	// The per-item outcome column stays untouched: recording conflict is 05-07's job, inside the write txn.
	$outcomes = $pdo->query('SELECT outcome FROM grocy_ai_bulk_plan_items WHERE plan_id = ' . $planId . ' ORDER BY seq')->fetchAll(PDO::FETCH_COLUMN);
	bulkAssert($outcomes === ['pending', 'pending'], BULK_CONFLICT_MARKER, 'Conflict detection wrote the outcome column');

	// Test 3b: detection re-reads live reality on every call — a fresh drift on P2 flips it to conflict.
	$taxonomy->AssignProductTaxonomy(2, ['leaf_slug' => 'produce', 'ruleset_version' => 'v1']);
	$freshDrift = $service->DetectApplyConflicts($planId);
	$freshBySeq = bulkConflictBySeq($freshDrift['items']);
	bulkAssert($freshBySeq[1]['annotation'] === 'conflict' && $freshBySeq[1]['current_value'] === 'produce', BULK_CONFLICT_MARKER, 'A second call did not reflect fresh drift: detection trusted the stored plan as current');
	bulkAssert($freshDrift['apply_set'] === [], BULK_CONFLICT_MARKER, 'A fully-drifted plan produced a non-empty apply set');

	// Test 3c: a changed VOLATILE evidence field must NOT false-conflict — only the written field counts.
	$vePdo = bulkGenerationPdo();
	$veService = new GrocyAiBulkService($vePdo);
	bulkSeedGenerationFixture($vePdo, $veService);
	$vePlanId = (int)$veService->GeneratePlan([])['id'];
	$veService->SetItemSelection($vePlanId, 1, true); // select P2 (before dairy-eggs, current dairy-eggs)
	// Mutate ONLY the volatile evidence for product 2: provider category, confidence band, reason code.
	// This changes ReadProductTaxonomy's suggested_leaf/provider_category/confidence_band/reason_code but
	// leaves the WRITTEN classification leaf (current_leaf) at dairy-eggs.
	$vePdo->prepare('UPDATE grocy_ai_taxonomy_evidence SET provider_category = ?, confidence_band = ?, reason_code = ? WHERE product_id = 2')->execute(['produce', 'medium', 'provider_category']);
	$veDto = (new GrocyAiTaxonomyService($vePdo))->ReadProductTaxonomy(2);
	// Guard the test against vacuity: the volatile fields really did drift while the written field held.
	bulkAssert(($veDto['suggested_leaf']['slug'] ?? null) === 'produce' && $veDto['confidence_band'] === 'medium', BULK_CONFLICT_MARKER, 'The volatile-evidence fixture did not actually change the evidence DTO');
	bulkAssert(($veDto['current_leaf']['slug'] ?? null) === 'dairy-eggs', BULK_CONFLICT_MARKER, 'The volatile-evidence fixture unexpectedly changed the written classification leaf');
	$veResult = $veService->DetectApplyConflicts($vePlanId);
	$veBySeq = bulkConflictBySeq($veResult['items']);
	bulkAssert($veBySeq[1]['annotation'] === 'no_conflict' && $veBySeq[1]['before_image'] === 'dairy-eggs' && $veBySeq[1]['current_value'] === 'dairy-eggs', BULK_CONFLICT_MARKER, 'A changed volatile evidence field false-conflicted an item whose written field was unchanged');
	// P1 (pre-selected, unchanged) and P2 (volatile evidence changed, written field held) both stay eligible.
	$veApplyIds = array_map(static fn(array $item): int => $item['object_id'], $veResult['apply_set']);
	sort($veApplyIds);
	bulkAssert($veApplyIds === [1, 2], BULK_CONFLICT_MARKER, 'The volatile-evidence item was wrongly dropped from the apply set');

	// ---- Task 2: no partial write, fail-closed, deterministic order-independent apply set ------------

	// Test 1: a fully-conflicted plan returns an empty apply set and writes nothing to any table.
	$fullBefore = bulkConflictStateSnapshot($pdo); // $pdo has both P1 and P2 drifted from Test 3b
	$full = $service->DetectApplyConflicts($planId);
	$fullAfter = bulkConflictStateSnapshot($pdo);
	bulkAssert($full['apply_set'] === [], BULK_CONFLICT_MARKER, 'A fully-conflicted plan did not yield an empty apply set');
	bulkAssert(count($full['items']) === 2, BULK_CONFLICT_MARKER, 'A fully-conflicted plan dropped items from the conflict report');
	foreach ($full['items'] as $item)
	{
		bulkAssert($item['annotation'] === 'conflict', BULK_CONFLICT_MARKER, 'A fully-conflicted plan reported a non-conflict item');
	}
	bulkAssert($fullBefore['rows'] === $fullAfter['rows'] && $fullBefore['total_changes'] === $fullAfter['total_changes'], BULK_CONFLICT_MARKER, 'A fully-conflicted detection issued a write');

	// Test 2a: a corrupted (invalid JSON) or absent (NULL) before-image fails closed to conflict — the
	// stored plan is never trusted as a self-certifying match.
	$cbPdo = bulkGenerationPdo();
	$cbService = new GrocyAiBulkService($cbPdo);
	bulkSeedGenerationFixture($cbPdo, $cbService);
	$cbPlanId = (int)$cbService->GeneratePlan([])['id'];
	$cbService->SetItemSelection($cbPlanId, 1, true);
	$cbPdo->exec("UPDATE grocy_ai_bulk_plan_items SET before_image_json = 'not-json' WHERE plan_id = " . $cbPlanId . ' AND seq = 0');
	// An image that omits the written leaf-slug field is treated as absent and fails closed.
	$cbPdo->exec("UPDATE grocy_ai_bulk_plan_items SET before_image_json = '{}' WHERE plan_id = " . $cbPlanId . ' AND seq = 1');
	$cbBefore = bulkConflictStateSnapshot($cbPdo);
	$cb = $cbService->DetectApplyConflicts($cbPlanId);
	$cbAfter = bulkConflictStateSnapshot($cbPdo);
	$cbBySeq = bulkConflictBySeq($cb['items']);
	bulkAssert($cbBySeq[0]['annotation'] === 'conflict' && $cbBySeq[1]['annotation'] === 'conflict', BULK_CONFLICT_MARKER, 'A corrupt/absent before-image did not fail closed to conflict');
	bulkAssert($cb['apply_set'] === [], BULK_CONFLICT_MARKER, 'A plan with unreadable before-images produced a non-empty apply set');
	bulkAssert($cbBefore['rows'] === $cbAfter['rows'] && $cbBefore['total_changes'] === $cbAfter['total_changes'], BULK_CONFLICT_MARKER, 'Fail-closed before-image handling issued a write');

	// Test 2b: an unreadable current value (object vanished) or an operation outside the closed registry
	// also fails closed — never assumed to match.
	$fcPdo = bulkGenerationPdo();
	$fcService = new GrocyAiBulkService($fcPdo);
	bulkSeedGenerationFixture($fcPdo, $fcService);
	$fcPlanId = (int)$fcService->GeneratePlan([])['id'];
	$fcService->SetItemSelection($fcPlanId, 1, true);
	$fcPdo->exec('DELETE FROM products WHERE id = 1'); // P1 current value now unreadable
	$fcPdo->exec("UPDATE grocy_ai_bulk_plan_items SET operation = 'delete_all_rows' WHERE plan_id = " . $fcPlanId . ' AND seq = 1'); // P2 operation off-registry
	$fc = $fcService->DetectApplyConflicts($fcPlanId);
	$fcBySeq = bulkConflictBySeq($fc['items']);
	bulkAssert($fcBySeq[0]['annotation'] === 'conflict', BULK_CONFLICT_MARKER, 'An unreadable current value did not fail closed to conflict');
	bulkAssert($fcBySeq[1]['annotation'] === 'conflict', BULK_CONFLICT_MARKER, 'An off-registry operation did not fail closed to conflict');
	bulkAssert($fc['apply_set'] === [], BULK_CONFLICT_MARKER, 'A fully-unreadable plan produced a non-empty apply set');

	// Test 3: mixed drift yields exactly the non-drifted items, deterministically and order-independently,
	// with no plan-level TTL and no item-count cap applied (the only bound is the generated scope).
	$mixPdo = bulkGenerationPdo();
	$mixService = new GrocyAiBulkService($mixPdo);
	bulkSeedGenerationFixture($mixPdo, $mixService);
	$mixPlanId = (int)$mixService->GeneratePlan([])['id'];
	$mixService->SetItemSelection($mixPlanId, 1, true);
	(new GrocyAiTaxonomyService($mixPdo))->AssignProductTaxonomy(1, ['leaf_slug' => 'produce', 'ruleset_version' => 'v1']); // drift only P1
	$mixA = $mixService->DetectApplyConflicts($mixPlanId);
	$mixB = $mixService->DetectApplyConflicts($mixPlanId);
	bulkAssert($mixA === $mixB, BULK_CONFLICT_MARKER, 'Mixed-drift detection is not deterministic across identical calls');
	$applyIds = array_map(static fn(array $item): int => $item['object_id'], $mixA['apply_set']);
	bulkAssert($applyIds === [2], BULK_CONFLICT_MARKER, 'Mixed drift did not leave exactly the non-drifted item in the apply set');
	// Order-independence: the apply set is precisely the selected-and-non-conflicted subset, regardless of
	// the order items are reported in.
	$independentApply = [];
	foreach ($mixA['items'] as $item)
	{
		if ($item['conflict'] === false)
		{
			$independentApply[] = $item['object_id'];
		}
	}
	sort($independentApply);
	$sortedApply = $applyIds;
	sort($sortedApply);
	bulkAssert($independentApply === $sortedApply, BULK_CONFLICT_MARKER, 'The apply set is not exactly the non-conflicted selected subset');

	fwrite(STDOUT, "Bulk conflict tests passed\n");
	exit(0);
}
