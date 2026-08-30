<?php

namespace GrocyAI\Services;

use PDO;

/**
 * The Phase 5 bulk maintenance & recovery engine.
 *
 * This plan (05-03) implements only `GeneratePlan`: a bounded, zero-mutation dry-run that reports the
 * exact closed counts, captures an immutable before-image of the written field once at generation, and
 * seals the plan with a deterministic SHA-256 checksum over its immutable content. Generation reads
 * current values exclusively through the shipped taxonomy read paths (`ValidateInventoryTaxonomy` for
 * the bounded per-outcome counts, `ReadProductTaxonomy` for per-object current/suggested leaves) and
 * writes only the two module tables `grocy_ai_bulk_plans` / `grocy_ai_bulk_plan_items` — never a native
 * Grocy row and never ad-hoc cache SQL. The apply/rollback/export surface arrives in later plans.
 */
class GrocyAiBulkService
{
	/**
	 * A deterministic, documented ceiling on the number of objects examined per generation call
	 * (BULK-01 bounded scope). Phase 5 proves the engine over the current inventory; the full sweep
	 * belongs to Phase 6. Per the locked stale-plan decision there is no plan TTL and no cap on the
	 * result set — only this bound on objects examined.
	 */
	public const MAX_SCOPE_OBJECTS = 10000;

	public const OPERATION_TYPE = 'taxonomy_assignment';

	private PDO $Db;
	private GrocyAiTaxonomyService $Taxonomy;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiBulkMigration::Bootstrap($this->Db);
		}
		$this->Taxonomy = new GrocyAiTaxonomyService($this->Db, $bootstrap);
	}

	/**
	 * The deterministic plan checksum: a lowercase 64-hex SHA-256 over the immutable content — item
	 * identities, before/proposed values, per-item operation types, and the ruleset version — via the
	 * canonical-JSON idiom (D-03). Items are normalized to the covered fields and sorted by identity, so
	 * reordering items or non-covered fields never changes the checksum and mutating any covered value
	 * always does. The reviewed plan and the applied plan are provably the same artifact.
	 *
	 * @param array<int, array<string, mixed>> $items
	 */
	public function ChecksumForPlan(string $operationType, string $rulesetVersion, array $items): string
	{
		$normalized = [];
		foreach ($items as $item)
		{
			$normalized[] = [
				'object_type' => (string)$item['object_type'],
				'object_id' => (int)$item['object_id'],
				'operation' => (string)$item['operation'],
				'before_image' => $item['before_image'] ?? null,
				'proposed_value' => $item['proposed_value'] ?? null
			];
		}
		usort($normalized, static fn(array $left, array $right): int =>
			[$left['object_type'], $left['object_id']] <=> [$right['object_type'], $right['object_id']]);

		return hash('sha256', $this->CanonicalJson([
			'operation_type' => $operationType,
			'ruleset_version' => $rulesetVersion,
			'items' => $normalized
		]));
	}

	/**
	 * Produce a bounded, zero-mutation dry-run plan and persist its header and immutable items.
	 *
	 * @param array{actor?: string} $scope
	 * @return array<string, mixed> the closed plan header DTO
	 */
	public function GeneratePlan(array $scope = []): array
	{
		$rulesetVersion = GrocyAiTaxonomyMigration::VERSION;
		$actor = is_string($scope['actor'] ?? null) ? (string)$scope['actor'] : null;

		// Bounded per-outcome counts come only from the shipped read path, never ad-hoc cache SQL.
		$report = $this->Taxonomy->ValidateInventoryTaxonomy();
		$excluded = (int)$report['excluded'];
		// The evidence-level `conflicting` bucket is a stored-evidence reason conflict, NOT the
		// apply-time optimistic-concurrency conflict, so it is skipped at generation, never counted as
		// `conflicted`. `conflicted` is reserved 0 at generation (D-01) and becomes meaningful only at
		// apply, where a later plan re-reads live reality per item.
		$skipped = (int)$report['unclassified'] + (int)$report['low_confidence'] + (int)$report['conflicting'];

		// Enumerate the bounded scope of objects to examine, then build one immutable item per
		// actionable (mapped) object. Excluded/unclassified/low-confidence/conflicting objects yield no
		// actionable suggestion via ReadProductTaxonomy and so produce no item.
		$productIds = $this->Db->query('SELECT id FROM products ORDER BY id LIMIT ' . self::MAX_SCOPE_OBJECTS)->fetchAll(PDO::FETCH_COLUMN);
		$items = [];
		$changed = 0;
		$unchanged = 0;
		foreach ($productIds as $productId)
		{
			$current = $this->Taxonomy->ReadProductTaxonomy((int)$productId);
			$suggested = $current['suggested_leaf'];
			if (!is_array($suggested) || !is_string($suggested['slug'] ?? null))
			{
				continue;
			}
			$proposedSlug = (string)$suggested['slug'];
			// The before-image and proposed value are over the WRITTEN field only — the current
			// classification leaf slug — never the volatile evidence fields, and captured once here.
			$beforeSlug = is_array($current['current_leaf']) ? (string)$current['current_leaf']['slug'] : null;
			$isChanged = $beforeSlug !== $proposedSlug;
			if ($isChanged)
			{
				$changed++;
			}
			else
			{
				$unchanged++;
			}

			$items[] = [
				'object_type' => 'product',
				'object_id' => (int)$productId,
				'operation' => 'assign_taxonomy_leaf',
				'before_image' => $beforeSlug,
				'proposed_value' => $proposedSlug,
				'reason' => (string)$current['reason_code'],
				'provenance' => (string)$current['evidence_source'],
				// Default selection rule: pre-select only items that actually change; unchanged no-ops
				// start deselected. The user may re-select any item during review.
				'selected' => $isChanged ? 1 : 0
			];
		}

		$included = count($items);
		$counts = [
			'included' => $included,
			'excluded' => $excluded,
			'skipped' => $skipped,
			'conflicted' => 0,
			'changed' => $changed,
			'unchanged' => $unchanged
		];

		$checksum = $this->ChecksumForPlan(self::OPERATION_TYPE, $rulesetVersion, $items);
		$scopeJson = $this->CanonicalJson([
			'selector' => 'full_inventory',
			'object_type' => 'product',
			'max_objects' => self::MAX_SCOPE_OBJECTS,
			'examined' => count($productIds)
		]);
		$moduleVersion = $this->ModuleVersion();

		$startedTransaction = !$this->Db->inTransaction();
		if ($startedTransaction)
		{
			$this->Db->beginTransaction();
		}
		try
		{
			$planStatement = $this->Db->prepare('INSERT INTO grocy_ai_bulk_plans (created_by, ruleset_version, operation_type, scope_json, counts_json, checksum, status, module_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
			$planStatement->execute([$actor, $rulesetVersion, self::OPERATION_TYPE, $scopeJson, $this->CanonicalJson($counts), $checksum, 'draft', $moduleVersion]);
			$planId = (int)$this->Db->lastInsertId();

			$itemStatement = $this->Db->prepare('INSERT INTO grocy_ai_bulk_plan_items (plan_id, seq, object_type, object_id, operation, before_image_json, proposed_value_json, reason, provenance, selected, outcome, applied_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
			$seq = 0;
			foreach ($items as $item)
			{
				$itemStatement->execute([
					$planId,
					$seq++,
					$item['object_type'],
					$item['object_id'],
					$item['operation'],
					$this->CanonicalJson(['leaf_slug' => $item['before_image']]),
					$this->CanonicalJson(['leaf_slug' => $item['proposed_value']]),
					$item['reason'],
					$item['provenance'],
					$item['selected'],
					'pending'
				]);
			}

			if ($startedTransaction)
			{
				$this->Db->commit();
			}
		}
		catch (\Throwable $exception)
		{
			if ($startedTransaction && $this->Db->inTransaction())
			{
				$this->Db->rollBack();
			}
			throw $exception;
		}

		$header = $this->Db->prepare('SELECT * FROM grocy_ai_bulk_plans WHERE id = ?');
		$header->execute([$planId]);
		return $header->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * The closed, server-side typed-operation registry (D-05/D-06). Its only members are
	 * `assign_taxonomy_leaf` and `set_unclassified`; each delegates to the shipped
	 * `GrocyAiTaxonomyService::AssignProductTaxonomy` write with a fixed assignment key set. It is not
	 * derived from any request payload. Conversion-cleanup and other operations are registered later in
	 * Phase 6 and are deliberately absent here.
	 *
	 * @return array<string, array{operation: string, delegate_write: string, assignment_keys: array<int, string>}>
	 */
	public function RegisteredOperations(): array
	{
		return [
			'assign_taxonomy_leaf' => [
				'operation' => 'assign_taxonomy_leaf',
				'delegate_write' => 'AssignProductTaxonomy',
				'assignment_keys' => ['leaf_slug', 'ruleset_version']
			],
			'set_unclassified' => [
				'operation' => 'set_unclassified',
				'delegate_write' => 'AssignProductTaxonomy',
				'assignment_keys' => ['unclassified', 'ruleset_version']
			]
		];
	}

	/**
	 * Resolve a named operation to a bound delegate over the shipped taxonomy write, or fail closed.
	 *
	 * Any operation outside the closed registry — a free-form entity/field target, a CRUD verb, or a
	 * raw SQL string — yields exactly one bounded `unknown_operation` blocker and no callable, with no
	 * partial resolution, no fallback to a default operation, and no provider/network call. Resolution
	 * examines only the server-side operation name and never trusts a request-supplied entity, field,
	 * or SQL fragment. The returned delegate builds only the exact `AssignProductTaxonomy` assignment
	 * shape, pins `ruleset_version` to the migration version server-side, and joins the caller's outer
	 * transaction; it introduces no new SQL and no new low-level write.
	 *
	 * @return array{operation: string, delegate: (callable(int, ?string): array)|null, blockers: array<int, string>}
	 */
	public function ResolveOperation(string $operation): array
	{
		$registry = $this->RegisteredOperations();
		if (!isset($registry[$operation]))
		{
			return ['operation' => $operation, 'delegate' => null, 'blockers' => ['unknown_operation']];
		}

		$taxonomy = $this->Taxonomy;
		$rulesetVersion = GrocyAiTaxonomyMigration::VERSION;
		if ($operation === 'assign_taxonomy_leaf')
		{
			$delegate = static fn(int $objectId, ?string $leafSlug): array =>
				$taxonomy->AssignProductTaxonomy($objectId, ['leaf_slug' => (string)$leafSlug, 'ruleset_version' => $rulesetVersion], true);
		}
		else
		{
			$delegate = static fn(int $objectId, ?string $leafSlug = null): array =>
				$taxonomy->AssignProductTaxonomy($objectId, ['unclassified' => true, 'ruleset_version' => $rulesetVersion], true);
		}

		return ['operation' => $operation, 'delegate' => $delegate, 'blockers' => []];
	}

	/**
	 * The set of plan statuses that still accept human review. Selection may only be toggled while the
	 * plan is reviewable; a plan that has already been applied or rolled back is frozen (D-04) and its
	 * selection flags become an immutable part of the audit trail. `draft` is the status GeneratePlan
	 * assigns; later plans may introduce further pre-apply statuses, so reviewability is expressed as an
	 * exclusion of the terminal states rather than a fixed allow-list.
	 */
	private const TERMINAL_STATUSES = ['applied', 'rolled_back'];

	/**
	 * Toggle exactly one item's `selected` flag on a stored, still-reviewable plan (D-04). The apply set
	 * is derived server-side from these flags — never from a browser-supplied item list. This writes only
	 * the `selected` column via a single UPDATE, never re-derives the before-image, and touches no native
	 * or resolved-cache state (D-13/D-14). It is idempotent: when the flag already holds the requested
	 * value it issues no write at all, so a repeated identical call changes nothing.
	 *
	 * Fails closed with no write when the plan does not exist, is no longer reviewable, was generated
	 * under a now-stale ruleset version, the seq is not one of the plan's own items, or `$selected` is not
	 * a strict boolean. Returns the re-read plan (header + counts + items) so the caller re-renders from
	 * server-owned state.
	 *
	 * @return array{plan: array<string, mixed>, counts: array<string, int>, items: array<int, array<string, mixed>>}
	 */
	public function SetItemSelection(int $planId, int $seq, mixed $selected): array
	{
		if (!is_bool($selected))
		{
			throw new \InvalidArgumentException('selection_flag_invalid');
		}

		$plan = $this->LoadPlanHeader($planId);
		if (in_array((string)$plan['status'], self::TERMINAL_STATUSES, true))
		{
			throw new \RuntimeException('plan_not_reviewable');
		}
		if ((string)$plan['ruleset_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			throw new \RuntimeException('plan_ruleset_stale');
		}

		$itemStatement = $this->Db->prepare('SELECT selected FROM grocy_ai_bulk_plan_items WHERE plan_id = ? AND seq = ?');
		$itemStatement->execute([$planId, $seq]);
		$current = $itemStatement->fetchColumn();
		if ($current === false)
		{
			throw new \InvalidArgumentException('unknown_item_seq');
		}

		$desired = $selected ? 1 : 0;
		if ((int)$current !== $desired)
		{
			// A single flag write, no other column, no before-image re-derivation, no native/cache SQL.
			$update = $this->Db->prepare('UPDATE grocy_ai_bulk_plan_items SET selected = ? WHERE plan_id = ? AND seq = ?');
			$update->execute([$desired, $planId, $seq]);
		}

		return $this->ReadPlan($planId);
	}

	/**
	 * Read a stored plan's header, closed counts, and every item verbatim (D-13). Read-only: it decodes
	 * the persisted before/proposed images and never re-derives them. The item DTO carries the object
	 * identity, immutable before-image, proposed value, reason, provenance, registered operation, the
	 * boolean selection flag, and the per-item outcome.
	 *
	 * @return array{plan: array<string, mixed>, counts: array<string, int>, items: array<int, array<string, mixed>>}
	 */
	public function ReadPlan(int $planId): array
	{
		$plan = $this->LoadPlanHeader($planId);
		$itemsStatement = $this->Db->prepare('SELECT * FROM grocy_ai_bulk_plan_items WHERE plan_id = ? ORDER BY seq');
		$itemsStatement->execute([$planId]);

		$items = [];
		foreach ($itemsStatement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$items[] = $this->PlanItemDto($row);
		}

		return [
			'plan' => $plan,
			'counts' => json_decode((string)$plan['counts_json'], true, 512, JSON_THROW_ON_ERROR),
			'items' => $items
		];
	}

	/**
	 * The complete selected diff over a stored plan (D-04/D-13): every currently selected item, verbatim,
	 * with rejected items omitted entirely. Read-only. The apply-set count is the number of selected items
	 * only — 05-06 later subtracts apply-time conflicts. The stored plan checksum is returned unchanged;
	 * selection never alters it, so the reviewed diff and the applied artifact stay provably the same.
	 *
	 * @return array{plan_id: int, checksum: string, operation_type: string, ruleset_version: string, included: int, items: array<int, array<string, mixed>>}
	 */
	public function SelectedDiff(int $planId): array
	{
		$plan = $this->LoadPlanHeader($planId);
		$itemsStatement = $this->Db->prepare('SELECT * FROM grocy_ai_bulk_plan_items WHERE plan_id = ? AND selected = 1 ORDER BY seq');
		$itemsStatement->execute([$planId]);

		$items = [];
		foreach ($itemsStatement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$items[] = $this->PlanItemDto($row);
		}

		return [
			'plan_id' => (int)$plan['id'],
			'checksum' => (string)$plan['checksum'],
			'operation_type' => (string)$plan['operation_type'],
			'ruleset_version' => (string)$plan['ruleset_version'],
			// Apply-set size using the closed count vocabulary: selected items are `included`.
			'included' => count($items),
			'items' => $items
		];
	}

	/**
	 * Per-item optimistic-concurrency conflict detection (D-07): a pure, zero-write pre-apply check that
	 * binds the apply set to present reality. For each SELECTED item it re-reads the current WRITTEN field
	 * through the shipped public read path and refuses any item whose value has drifted from the reviewed
	 * before-image, so a stale plan can never silently overwrite a value that changed after generation.
	 *
	 * Contract (consumed by 05-07's `ApplyPlan`, which records the outcome inside its write transaction):
	 *   - Only the WRITTEN field is compared. For the two closed taxonomy operations the written field is the
	 *     product's current classification leaf slug (`current_leaf`, `null` when unclassified), re-read via
	 *     `GrocyAiTaxonomyService::ReadProductTaxonomy` — never the private `CurrentLeaf` helper and never the
	 *     whole DTO with its volatile evidence fields (`suggested_leaf`, `provider_category`, `confidence_band`,
	 *     `reason_code`, `evidence_source`). A change to a volatile evidence field therefore never false-conflicts.
	 *   - The current value is re-read fresh on every call through the live read path; the stored
	 *     `before_image_json` is only ever the past claim being compared against, never the source of truth.
	 *   - Fail-closed: an unreadable current value (e.g. the object vanished), an operation outside the closed
	 *     registry, or a malformed/absent before-image yields `conflict` — never a silent "match".
	 *   - Zero-write: only SELECTs are issued. The per-item `outcome` column is deliberately NOT written here —
	 *     conflict is a transient, re-read-fresh judgement, and keeping detection write-free is what proves it
	 *     re-reads reality on each call. There is no plan-level TTL and no item-count cap (locked stale-plan
	 *     decision); the only bound is the scope `GeneratePlan` produced.
	 *
	 * @return array{plan_id: int, checksum: string, items: array<int, array<string, mixed>>, apply_set: array<int, array<string, mixed>>}
	 */
	public function DetectApplyConflicts(int $planId): array
	{
		$plan = $this->LoadPlanHeader($planId);

		// The candidate apply set is exactly the selected items (05-05); this check subtracts conflicts.
		$statement = $this->Db->prepare('SELECT * FROM grocy_ai_bulk_plan_items WHERE plan_id = ? AND selected = 1 ORDER BY seq');
		$statement->execute([$planId]);

		$items = [];
		$applySet = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$beforeValue = null;
			$currentValue = null;
			try
			{
				// The immutable written-field before-image (the leaf slug / null). A malformed or absent
				// image fails closed to a conflict rather than being assumed to match reality.
				$beforeValue = $this->WrittenBeforeImage((string)$row['before_image_json']);
				// Re-read the CURRENT written field through the shipped public read path — never the stored plan.
				$currentValue = $this->CurrentWrittenValue((string)$row['operation'], (string)$row['object_type'], (int)$row['object_id']);
				// Exact, normalized comparison over the written field ONLY.
				$conflict = $beforeValue !== $currentValue;
			}
			catch (\Throwable $exception)
			{
				$conflict = true;
			}

			$annotated = [
				'seq' => (int)$row['seq'],
				'object_type' => (string)$row['object_type'],
				'object_id' => (int)$row['object_id'],
				'operation' => (string)$row['operation'],
				// The written field on both sides of the comparison, made explicit for the caller.
				'before_image' => $beforeValue,
				'current_value' => $currentValue,
				'conflict' => $conflict,
				// A transient annotation; the closed stored outcome vocabulary reuses `conflict` verbatim.
				'annotation' => $conflict ? 'conflict' : 'no_conflict'
			];
			$items[] = $annotated;
			if (!$conflict)
			{
				$applySet[] = $annotated;
			}
		}

		return [
			'plan_id' => (int)$plan['id'],
			'checksum' => (string)$plan['checksum'],
			'items' => $items,
			'apply_set' => $applySet
		];
	}

	/**
	 * The bounded, runtime-failure blocker returned after a mid-apply throw forces a rollback. It is
	 * distinct from the review/refusal blocker vocabulary (`unknown_operation`, `plan_checksum_mismatch`,
	 * …): it names the fail-closed transaction rollback, mirroring `ActivateVerifiedRuleset`'s
	 * `activation_transaction_failed`. On this blocker the prior state is byte-identical — nothing applied.
	 */
	private const APPLY_TRANSACTION_FAILED = 'apply_transaction_failed';

	/**
	 * Apply an approved plan exactly once through one short `BEGIN IMMEDIATE` transaction (D-08), routing
	 * every selected, non-conflicted, not-yet-completed item through its registered typed operation
	 * (D-05/D-06 → `AssignProductTaxonomy`), idempotent via a plan-checksum-bound per-item completion
	 * ledger (D-09/BULK-07), and fully rolled back to a byte-identical prior state on any throw.
	 *
	 * Transaction scheme (LOCKED): the write lock is taken up front with `$this->Db->exec('BEGIN
	 * IMMEDIATE')`, there is exactly one commit path `$this->Db->exec('COMMIT')`, and any `\Throwable`
	 * issues `$this->Db->exec('ROLLBACK')`. PDO's `beginTransaction()`/`commit()`/`inTransaction()` are
	 * deliberately NOT used (a raw BEGIN IMMEDIATE leaves `inTransaction()` reading `false`). The delegate
	 * is invoked with `$joinExistingTransaction = true` so it joins this single outer transaction and never
	 * opens or commits its own. There are NO per-item commits inside the loop and NO network/provider call
	 * while the lock is held.
	 *
	 * Idempotency (BULK-07): at entry the plan checksum is recomputed over the immutable items and
	 * `hash_equals`-checked against the stored `checksum` (and, when the caller confirms one, against the
	 * reviewed checksum), refusing before any write on mismatch — the direct analogue of
	 * `ActivateVerifiedRuleset`'s `evidence_hash` existence gate. A completed item carries
	 * `outcome='applied'` + `applied_at`; because those stamps commit only with the single `COMMIT`, an
	 * interrupted (rolled-back) apply leaves every item un-stamped and the DB byte-identical, so a resume
	 * safely redoes the whole apply set with no duplication and the final state equals exactly one clean
	 * apply. The completion marker is consulted before conflict detection for a given item, so an item
	 * already applied under this reviewed checksum is skipped (never re-mutated) rather than false-flagged
	 * as a drift conflict against its own reviewed before-image.
	 *
	 * Conflict handling (TOCTOU-free): `DetectApplyConflicts` is re-run AFTER the write lock is acquired,
	 * inside the transaction; a freshly drifted, not-yet-completed item is recorded `outcome='conflict'`
	 * and never written. The apply set it returns does not carry the proposed value, so each write
	 * re-fetches `proposed_value_json` by `seq` from `grocy_ai_bulk_plan_items`.
	 *
	 * @param string $actor the authenticated Grocy session user resolved by the controller
	 * @param string|null $confirmedChecksum the reviewed checksum the caller confirms, cross-checked here
	 * @return array{plan_id: int, checksum: string, status: string, blockers: array<int, string>, outcomes: array{applied: int, conflict: int, skipped: int}, actor: string}
	 */
	public function ApplyPlan(int $planId, string $actor, ?string $confirmedChecksum = null): array
	{
		$plan = $this->LoadPlanHeader($planId);
		$storedChecksum = (string)$plan['checksum'];
		$status = (string)$plan['status'];

		// Bind the applied plan to the reviewed one before any write: the recomputed checksum must equal
		// the stored checksum, and — when the caller confirms one — the caller's reviewed checksum too.
		$recomputed = $this->RecomputePlanChecksum($planId, $plan);
		if (!hash_equals($storedChecksum, $recomputed)
			|| ($confirmedChecksum !== null && !hash_equals($storedChecksum, $confirmedChecksum)))
		{
			return $this->ApplyResult($planId, $storedChecksum, $status, ['plan_checksum_mismatch'], 0, 0, 0, $actor);
		}

		// LOCKED scheme: take the write lock up front with a raw BEGIN IMMEDIATE (never a PDO deferred begin,
		// and never gated on the PDO transaction-state read, which is false after a raw BEGIN IMMEDIATE).
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			// TOCTOU-free: recompute the apply set against live reality INSIDE the lock.
			$conflicts = $this->DetectApplyConflicts($planId);
			$conflictBySeq = [];
			foreach ($conflicts['items'] as $conflictItem)
			{
				$conflictBySeq[(int)$conflictItem['seq']] = (bool)$conflictItem['conflict'];
			}

			$selected = $this->Db->prepare('SELECT seq, object_id, operation, proposed_value_json, outcome, applied_at FROM grocy_ai_bulk_plan_items WHERE plan_id = ? AND selected = 1 ORDER BY seq');
			$selected->execute([$planId]);

			$markConflict = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'conflict' WHERE plan_id = ? AND seq = ?");
			$markApplied = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'applied', applied_at = CURRENT_TIMESTAMP WHERE plan_id = ? AND seq = ?");

			$applied = 0;
			$conflicted = 0;
			$skipped = 0;
			foreach ($selected->fetchAll(PDO::FETCH_ASSOC) as $row)
			{
				$seq = (int)$row['seq'];

				// Idempotency ledger (BULK-07): a completed item for this reviewed checksum is never redone
				// and, critically, is consulted BEFORE conflict detection so an already-applied item is not
				// false-flagged as a drift against its own reviewed before-image.
				if ((string)$row['outcome'] === 'applied' && $row['applied_at'] !== null)
				{
					$skipped++;
					continue;
				}

				// A freshly drifted item is recorded conflict and never written (optimistic concurrency).
				if (($conflictBySeq[$seq] ?? true) === true)
				{
					$markConflict->execute([$planId, $seq]);
					$conflicted++;
					continue;
				}

				// Dispatch ONLY the closed registered typed operation; anything else fails closed (rollback).
				$resolution = $this->ResolveOperation((string)$row['operation']);
				if ($resolution['delegate'] === null)
				{
					throw new \RuntimeException('unknown_operation');
				}
				$proposed = json_decode((string)$row['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
				$leafSlug = is_array($proposed) && isset($proposed['leaf_slug']) && is_string($proposed['leaf_slug']) ? (string)$proposed['leaf_slug'] : null;

				// The delegate joins THIS outer transaction ($joinExistingTransaction = true) and performs
				// only the native/module upsert — no network, no own BEGIN/COMMIT, no per-item commit.
				($resolution['delegate'])((int)$row['object_id'], $leafSlug);
				$markApplied->execute([$planId, $seq]);
				$applied++;
			}

			// Status vocab: any conflict among the selected set → partially_applied; otherwise applied.
			$finalStatus = $conflicted > 0 ? 'partially_applied' : 'applied';
			if ($finalStatus !== $status)
			{
				$this->Db->prepare('UPDATE grocy_ai_bulk_plans SET status = ? WHERE id = ?')->execute([$finalStatus, $planId]);
			}

			// The single commit path. Every completion stamp lands atomically here or not at all.
			$this->Db->exec('COMMIT');
			return $this->ApplyResult($planId, $storedChecksum, $finalStatus, [], $applied, $conflicted, $skipped, $actor);
		}
		catch (\Throwable $exception)
		{
			// Fail closed to a byte-identical prior state: nothing applied, no item stamped.
			$this->Db->exec('ROLLBACK');
			$blocker = $exception->getMessage() === 'unknown_operation' ? 'unknown_operation' : self::APPLY_TRANSACTION_FAILED;
			return $this->ApplyResult($planId, $storedChecksum, $status, [$blocker], 0, 0, 0, $actor);
		}
	}

	/**
	 * Recompute the immutable plan checksum from the persisted items via the shared `ChecksumForPlan`
	 * idiom, so the applied plan is provably the reviewed artifact. Decodes only the written-field leaf
	 * slug from each before/proposed image — the same content `GeneratePlan` sealed.
	 *
	 * @param array<string, mixed> $plan the loaded plan header
	 */
	private function RecomputePlanChecksum(int $planId, array $plan): string
	{
		$statement = $this->Db->prepare('SELECT object_type, object_id, operation, before_image_json, proposed_value_json FROM grocy_ai_bulk_plan_items WHERE plan_id = ? ORDER BY seq');
		$statement->execute([$planId]);

		$items = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$before = json_decode((string)$row['before_image_json'], true, 512, JSON_THROW_ON_ERROR);
			$proposed = json_decode((string)$row['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
			$items[] = [
				'object_type' => (string)$row['object_type'],
				'object_id' => (int)$row['object_id'],
				'operation' => (string)$row['operation'],
				'before_image' => is_array($before) ? ($before['leaf_slug'] ?? null) : null,
				'proposed_value' => is_array($proposed) ? ($proposed['leaf_slug'] ?? null) : null
			];
		}

		return $this->ChecksumForPlan((string)$plan['operation_type'], (string)$plan['ruleset_version'], $items);
	}

	/**
	 * The closed apply-outcome DTO. `outcomes` counts use the closed per-item vocabulary; `blockers` is a
	 * bounded list (empty on success). `actor` echoes the authenticated session user threaded from the
	 * controller (the append-only audit ledger itself is Plan 05-08).
	 *
	 * @return array{plan_id: int, checksum: string, status: string, blockers: array<int, string>, outcomes: array{applied: int, conflict: int, skipped: int}, actor: string}
	 */
	private function ApplyResult(int $planId, string $checksum, string $status, array $blockers, int $applied, int $conflicted, int $skipped, string $actor): array
	{
		return [
			'plan_id' => $planId,
			'checksum' => $checksum,
			'status' => $status,
			'blockers' => $blockers,
			'outcomes' => ['applied' => $applied, 'conflict' => $conflicted, 'skipped' => $skipped],
			'actor' => $actor
		];
	}

	/**
	 * Decode a stored item's immutable written-field before-image to the leaf slug (`null` when the item
	 * was unclassified at review). Fails closed on any malformed/absent image so the stored plan can never
	 * be trusted as a self-certifying "match".
	 */
	private function WrittenBeforeImage(string $beforeImageJson): ?string
	{
		$decoded = json_decode($beforeImageJson, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || array_keys($decoded) !== ['leaf_slug'])
		{
			throw new \RuntimeException('before_image_malformed');
		}
		$slug = $decoded['leaf_slug'];
		if ($slug !== null && !is_string($slug))
		{
			throw new \RuntimeException('before_image_malformed');
		}

		return $slug;
	}

	/**
	 * Re-read the current WRITTEN field for a named operation through the shipped public read path. Both
	 * closed taxonomy operations write the product's classification leaf, so the written value is the
	 * current leaf slug (`null` when unclassified) via `ReadProductTaxonomy['current_leaf']`. Fails closed
	 * for an operation outside the closed registry or an unreadable object.
	 */
	private function CurrentWrittenValue(string $operation, string $objectType, int $objectId): ?string
	{
		$registry = $this->RegisteredOperations();
		if (!isset($registry[$operation]) || $objectType !== 'product')
		{
			throw new \RuntimeException('unreadable_current_value');
		}

		$current = $this->Taxonomy->ReadProductTaxonomy($objectId);
		$leaf = $current['current_leaf'];

		return is_array($leaf) ? (string)$leaf['slug'] : null;
	}

	/**
	 * Load a stored plan header or fail closed. Read-only single-row lookup.
	 *
	 * @return array<string, mixed>
	 */
	private function LoadPlanHeader(int $planId): array
	{
		$statement = $this->Db->prepare('SELECT * FROM grocy_ai_bulk_plans WHERE id = ?');
		$statement->execute([$planId]);
		$plan = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($plan))
		{
			throw new \RuntimeException('plan_not_found');
		}

		return $plan;
	}

	/**
	 * Normalize a stored plan-item row into the verbatim review DTO. The before/proposed images are the
	 * immutable JSON captured at generation, decoded without re-derivation.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function PlanItemDto(array $row): array
	{
		return [
			'seq' => (int)$row['seq'],
			'object_type' => (string)$row['object_type'],
			'object_id' => (int)$row['object_id'],
			'operation' => (string)$row['operation'],
			'before_image' => json_decode((string)$row['before_image_json'], true, 512, JSON_THROW_ON_ERROR),
			'proposed_value' => json_decode((string)$row['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR),
			'reason' => (string)$row['reason'],
			'provenance' => (string)$row['provenance'],
			'selected' => (int)$row['selected'] === 1,
			'outcome' => (string)$row['outcome']
		];
	}

	private function ModuleVersion(): string
	{
		$path = __DIR__ . '/../module-version.json';
		$data = is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
		return (string)($data['module_version'] ?? '');
	}

	private function CanonicalJson(mixed $value): string
	{
		return (string)json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}
}
