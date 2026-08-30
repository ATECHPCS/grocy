<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiBulkService;
use GrocyAI\Services\GrocyAiBulkMigration;
use GrocyAI\Services\GrocyAiTaxonomyMigration;
use GrocyAI\Services\GrocyAiTaxonomyService;

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
