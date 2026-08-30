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

	/**
	 * The closed, default-deny export field allowlist (D-12/BULK-10). `ExportPlan` emits ONLY these
	 * per-item fields in EXACTLY this order, in both JSON and CSV. Redaction is default-deny: the
	 * snapshot is projected field-by-field through this constant, so a future column added to any bulk
	 * DTO — or an unrelated native column (companion API keys, service secrets/tokens, opaque media/
	 * image handles, session identifiers, raw actor credentials, and unrelated household detail such as
	 * stock levels, prices, purchase/consumption history, and locations) — can never auto-leak into the
	 * export. `object identity` is emitted as `object_type` + `object_id`; `product_name` is the object's
	 * human-readable identity (a bounded read-only `id, name` lookup, never any other product column).
	 */
	public const EXPORT_ITEM_FIELDS = [
		'plan_checksum',
		'object_type',
		'object_id',
		'product_name',
		'operation',
		'before_value',
		'proposed_or_after_value',
		'reason',
		'provenance',
		'ruleset_version',
		'selection_state',
		'outcome'
	];

	/** The snapshot schema version, so a reviewer can bind the file shape to this contract. */
	public const EXPORT_SNAPSHOT_VERSION = 1;

	/**
	 * The explicit non-authoritative marker text (D-12). The snapshot is recovery evidence for
	 * independent human review only; it is NOT authoritative and offers NO re-import authority (re-import
	 * after a fresh conflict check is the deferred v2 item V2-03). No controller path consumes it back.
	 */
	public const EXPORT_NON_AUTHORITATIVE_NOTE = 'Non-authoritative recovery snapshot for independent human review only. It is NOT authoritative and cannot be re-imported to change data (re-import is deferred to V2-03). Grocy remains the sole durable mutation authority.';

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
	 * Read the append-only audit ledger for a stored plan in stable insertion order (D-10/D-13). Read-only:
	 * it only ever SELECTs and returns every `grocy_ai_bulk_audit` row verbatim (the closed audit key set)
	 * so a maintainer can reconstruct exactly who previewed and applied the plan and what each item changed
	 * — actor, event, event timestamp, module version, per-item outcome, and the exact before/after values.
	 * It exposes no mutation path; the ledger is immutable once written. Fails closed if the plan is unknown.
	 *
	 * @return array{plan_id: int, records: array<int, array<string, mixed>>}
	 */
	public function ReadPlanAudit(int $planId): array
	{
		$plan = $this->LoadPlanHeader($planId);
		$statement = $this->Db->prepare('SELECT id, plan_id, plan_item_id, actor, event, event_at, module_version, before_json, after_json, outcome FROM grocy_ai_bulk_audit WHERE plan_id = ? ORDER BY id');
		$statement->execute([$planId]);

		$records = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$records[] = [
				'id' => (int)$row['id'],
				'plan_id' => (int)$row['plan_id'],
				'plan_item_id' => $row['plan_item_id'] === null ? null : (int)$row['plan_item_id'],
				'actor' => (string)$row['actor'],
				'event' => (string)$row['event'],
				'event_at' => (string)$row['event_at'],
				'module_version' => (string)$row['module_version'],
				'before_json' => $row['before_json'] === null ? null : (string)$row['before_json'],
				'after_json' => $row['after_json'] === null ? null : (string)$row['after_json'],
				'outcome' => (string)$row['outcome']
			];
		}

		return ['plan_id' => (int)$plan['id'], 'records' => $records];
	}

	/**
	 * Produce a redacted, explicitly non-authoritative JSON or CSV snapshot of a stored plan/preview for
	 * independent human review and recovery evidence (D-12/BULK-10). Zero-write: it only SELECTs.
	 *
	 * Redaction is default-deny. Every emitted per-item field is projected through the closed
	 * `EXPORT_ITEM_FIELDS` allowlist; the plan header row may be read broadly, but ONLY the
	 * allowlisted named header fields are ever emitted, so an injected header column can never leak.
	 * The plan-item rows are read via an
	 * EXPLICIT column list (never `SELECT *`), and product names come from a bounded read-only `id, name`
	 * lookup (never any other product column). Companion API keys, service secrets/tokens, opaque media/
	 * image handles, session identifiers, raw actor credentials, and unrelated household detail (stock,
	 * prices, purchase/consumption history, locations) are therefore never emitted in either format.
	 *
	 * The snapshot binds to the reviewed artifact via the plan checksum + closed counts, is marked
	 * `authoritative: false` / `reimport_supported: false` with a human-readable note, and offers NO
	 * re-import authority — there is no import path anywhere (re-import is the deferred v2 item V2-03).
	 *
	 * @return array<string, mixed>|string a JSON-ready array for `json`, a CSV document string for `csv`
	 */
	public function ExportPlan(int|string $planId, string $format): array|string
	{
		// Fail closed on any format outside the two supported snapshots, before any read.
		if ($format !== 'json' && $format !== 'csv')
		{
			throw new \InvalidArgumentException('unsupported_export_format');
		}

		$planIdInt = (int)$planId;
		$plan = $this->LoadPlanHeader($planIdInt);

		// Plan metadata: read ONLY named header fields — never the whole row — so an injected header
		// column can never leak. Counts are projected onto exactly the closed count vocabulary.
		$checksum = (string)$plan['checksum'];
		$rulesetVersion = (string)$plan['ruleset_version'];
		$rawCounts = json_decode((string)$plan['counts_json'], true, 512, JSON_THROW_ON_ERROR);
		$counts = [];
		foreach (['included', 'excluded', 'skipped', 'conflicted', 'changed', 'unchanged'] as $countKey)
		{
			$counts[$countKey] = (int)(is_array($rawCounts) ? ($rawCounts[$countKey] ?? 0) : 0);
		}

		// Items: an EXPLICIT column projection (default-deny) — never SELECT *. Only the columns that feed
		// the closed allowlist are read; an injected plan-item column is never selected.
		$statement = $this->Db->prepare('SELECT seq, object_type, object_id, operation, before_image_json, proposed_value_json, reason, provenance, selected, outcome FROM grocy_ai_bulk_plan_items WHERE plan_id = ? ORDER BY seq');
		$statement->execute([$planIdInt]);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

		$productNames = $this->ExportProductNames($rows);

		$items = [];
		foreach ($rows as $row)
		{
			$items[] = $this->ExportItemRow($checksum, $rulesetVersion, $row, $productNames);
		}

		$snapshot = [
			'snapshot_version' => self::EXPORT_SNAPSHOT_VERSION,
			// The explicit non-authoritative / no-re-import marker (D-12).
			'authoritative' => false,
			'reimport_supported' => false,
			'non_authoritative_note' => self::EXPORT_NON_AUTHORITATIVE_NOTE,
			'plan' => [
				'plan_checksum' => $checksum,
				'operation_type' => (string)$plan['operation_type'],
				'ruleset_version' => $rulesetVersion,
				'generated_at' => (string)$plan['created_at'],
				'module_version' => (string)$plan['module_version'],
				'counts' => $counts
			],
			'items' => $items
		];

		return $format === 'json' ? $snapshot : $this->ExportCsv($snapshot);
	}

	/**
	 * Project one stored plan-item row onto the closed export allowlist (D-12). The before/proposed images
	 * are decoded to their single written field (the leaf slug / null); the proposed value is the reviewed
	 * proposal, which for an applied item equals the value the apply wrote (its audited after-image). The
	 * per-item outcome column carries the applied/conflict/rolled_back story, so the snapshot needs no raw
	 * audit dump. Only allowlisted keys are ever set here.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string> $productNames
	 * @return array<string, mixed>
	 */
	private function ExportItemRow(string $checksum, string $rulesetVersion, array $row, array $productNames): array
	{
		$before = json_decode((string)$row['before_image_json'], true, 512, JSON_THROW_ON_ERROR);
		$proposed = json_decode((string)$row['proposed_value_json'], true, 512, JSON_THROW_ON_ERROR);
		$objectId = (int)$row['object_id'];

		return [
			'plan_checksum' => $checksum,
			'object_type' => (string)$row['object_type'],
			'object_id' => $objectId,
			'product_name' => $productNames[$objectId] ?? null,
			'operation' => (string)$row['operation'],
			'before_value' => is_array($before) ? ($before['leaf_slug'] ?? null) : null,
			'proposed_or_after_value' => is_array($proposed) ? ($proposed['leaf_slug'] ?? null) : null,
			'reason' => (string)$row['reason'],
			'provenance' => (string)$row['provenance'],
			'ruleset_version' => $rulesetVersion,
			'selection_state' => (int)$row['selected'] === 1 ? 'selected' : 'not_selected',
			'outcome' => (string)$row['outcome']
		];
	}

	/**
	 * The human-readable product identities for the plan's product items: a bounded, read-only lookup of
	 * ONLY the native `id, name` columns keyed by the plan items' stored object ids. No other product
	 * column (stock, price, purchase history, image handle, …) is ever read, so no unrelated household
	 * detail can enter the snapshot; a since-deleted product simply yields no name. This mirrors the
	 * direct products read `GeneratePlan` already performs and issues no write and no cache SQL.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, string>
	 */
	private function ExportProductNames(array $rows): array
	{
		$ids = [];
		foreach ($rows as $row)
		{
			if ((string)$row['object_type'] === 'product')
			{
				$ids[(int)$row['object_id']] = true;
			}
		}
		if ($ids === [])
		{
			return [];
		}

		$ids = array_keys($ids);
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$statement = $this->Db->prepare('SELECT id, name FROM products WHERE id IN (' . $placeholders . ')');
		$statement->execute($ids);

		$names = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $productRow)
		{
			$names[(int)$productRow['id']] = (string)$productRow['name'];
		}

		return $names;
	}

	/**
	 * Render the snapshot as an RFC 4180 CSV document over the SAME closed column set as the JSON items,
	 * with the non-authoritative marker + checksum-binding metadata in leading `#` comment rows. Every
	 * field is quoted and its quotes doubled, so a comma/quote/newline in a value cannot break the shape.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private function ExportCsv(array $snapshot): string
	{
		$plan = $snapshot['plan'];
		$counts = $plan['counts'];

		$lines = [];
		$lines[] = '# NON-AUTHORITATIVE RECOVERY SNAPSHOT — not re-importable (re-import is deferred to V2-03). Grocy remains the sole durable authority.';
		$lines[] = '# authoritative=false reimport_supported=false snapshot_version=' . self::EXPORT_SNAPSHOT_VERSION;
		$lines[] = '# plan_checksum=' . $plan['plan_checksum'] . ' operation_type=' . $plan['operation_type'] . ' ruleset_version=' . $plan['ruleset_version'] . ' generated_at=' . $plan['generated_at'] . ' module_version=' . $plan['module_version'];
		$lines[] = '# counts included=' . $counts['included'] . ' excluded=' . $counts['excluded'] . ' skipped=' . $counts['skipped'] . ' conflicted=' . $counts['conflicted'] . ' changed=' . $counts['changed'] . ' unchanged=' . $counts['unchanged'];
		$lines[] = $this->ExportCsvRow(self::EXPORT_ITEM_FIELDS);

		foreach ($snapshot['items'] as $item)
		{
			$ordered = [];
			foreach (self::EXPORT_ITEM_FIELDS as $field)
			{
				$value = $item[$field] ?? null;
				$ordered[] = $value === null ? '' : (string)$value;
			}
			$lines[] = $this->ExportCsvRow($ordered);
		}

		return implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Escape and join one CSV record: every field is wrapped in double quotes with embedded quotes
	 * doubled (RFC 4180), so no value can inject a delimiter, row break, or leading `#` comment marker.
	 *
	 * Before quoting, any field whose first character is a spreadsheet formula trigger (`=`, `+`, `-`,
	 * `@`, tab, or CR) is prefixed with a single quote so the receiving spreadsheet treats it as literal
	 * text instead of executing it (CSV formula injection, CWE-1236). Values that do not start with a
	 * trigger are emitted exactly as before.
	 *
	 * @param array<int, string> $fields
	 */
	private function ExportCsvRow(array $fields): string
	{
		$escaped = [];
		foreach ($fields as $field)
		{
			$value = (string)$field;
			// Neutralize CSV formula injection (CWE-1236): a non-empty value starting with a formula
			// trigger becomes literal text via a leading apostrophe, applied BEFORE RFC-4180 quoting.
			if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1)
			{
				$value = "'" . $value;
			}
			$escaped[] = '"' . str_replace('"', '""', $value) . '"';
		}

		return implode(',', $escaped);
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
	 * The closed audit-event vocabulary for the append-only `grocy_ai_bulk_audit` ledger (D-10). Exactly
	 * two events are ever recorded: the `previewed` fact (who generated/previewed the plan and when —
	 * reconstructed at apply time from the immutable plan header, never re-derived) and the `applied` fact
	 * (who applied the plan and when, per item and once at plan scope). Both are INSERT-only; the ledger
	 * exposes no UPDATE/DELETE/REPLACE path anywhere (D-14).
	 */
	public const AUDIT_EVENT_PREVIEWED = 'previewed';
	public const AUDIT_EVENT_APPLIED = 'applied';

	/**
	 * The rollback audit event (D-11). A guarded rollback appends `rolled_back` rows through the same
	 * append-only INSERT writer as forward apply, inside its own single transaction; it never rewrites an
	 * existing ledger row. `event` and per-item/plan `outcome` both use the pinned `rolled_back` token.
	 */
	public const AUDIT_EVENT_ROLLED_BACK = 'rolled_back';

	/**
	 * The pinned per-item refusal blocker when a field's live value drifted from the audited after-image —
	 * i.e. it was hand-edited after the original apply (D-11). Such an item is never overwritten.
	 */
	private const ROLLBACK_MANUAL_EDIT = 'manual_edit_after_apply';

	/**
	 * The bounded fail-closed blocker after a mid-rollback throw forces the whole transaction back to a
	 * byte-identical prior state. Mirrors the forward-apply runtime blocker; distinct from the closed
	 * review/refusal vocabulary.
	 */
	private const ROLLBACK_TRANSACTION_FAILED = 'rollback_transaction_failed';

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
			// One applied-timestamp value for the whole transaction: the per-item completion stamp and every
			// audit `event_at` share it, so the ledger's applied timestamp is exactly the item stamp (D-10).
			$appliedAt = (string)$this->Db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
			$moduleVersion = $this->ModuleVersion();

			// The append-only audit ledger writer (D-10/D-14). It only ever INSERTs; the ledger is never
			// rewritten or removed anywhere in this service or the migration. Every append here lives inside
			// THIS BEGIN IMMEDIATE transaction, before its single COMMIT, so a rolled-back apply discards its
			// audit rows atomically with the mutations they record (no orphaned audit).
			$auditInsert = $this->Db->prepare('INSERT INTO grocy_ai_bulk_audit (plan_id, plan_item_id, actor, event, event_at, module_version, before_json, after_json, outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

			// TOCTOU-free: recompute the apply set against live reality INSIDE the lock.
			$conflicts = $this->DetectApplyConflicts($planId);
			$conflictBySeq = [];
			foreach ($conflicts['items'] as $conflictItem)
			{
				$conflictBySeq[(int)$conflictItem['seq']] = (bool)$conflictItem['conflict'];
			}

			$selected = $this->Db->prepare('SELECT id, seq, object_id, operation, before_image_json, proposed_value_json, outcome, applied_at FROM grocy_ai_bulk_plan_items WHERE plan_id = ? AND selected = 1 ORDER BY seq');
			$selected->execute([$planId]);

			$markConflict = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'conflict' WHERE plan_id = ? AND seq = ?");
			$markApplied = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'applied', applied_at = ? WHERE plan_id = ? AND seq = ?");

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

				// A freshly drifted item is recorded conflict and never written (optimistic concurrency). The
				// conflict is audited too: before = the reviewed before-image, after = null (nothing written).
				if (($conflictBySeq[$seq] ?? true) === true)
				{
					$markConflict->execute([$planId, $seq]);
					$auditInsert->execute([$planId, (int)$row['id'], $actor, self::AUDIT_EVENT_APPLIED, $appliedAt, $moduleVersion, (string)$row['before_image_json'], null, 'conflict']);
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
				$markApplied->execute([$appliedAt, $planId, $seq]);
				// Append the immutable audit row for this applied item: before = the reviewed before-image,
				// after = the value actually written (the reviewed proposed value). Same transaction, so a
				// rollback discards it together with the mutation it records.
				$auditInsert->execute([$planId, (int)$row['id'], $actor, self::AUDIT_EVENT_APPLIED, $appliedAt, $moduleVersion, (string)$row['before_image_json'], (string)$row['proposed_value_json'], 'applied']);
				$applied++;
			}

			// Status vocab: any conflict among the selected set → partially_applied; otherwise applied.
			$finalStatus = $conflicted > 0 ? 'partially_applied' : 'applied';
			if ($finalStatus !== $status)
			{
				$this->Db->prepare('UPDATE grocy_ai_bulk_plans SET status = ? WHERE id = ?')->execute([$finalStatus, $planId]);
			}

			// The plan-level audit events are appended only when this apply did real work (at least one item
			// applied or conflicted). A wholly idempotent re-apply (every selected item already completed) does
			// no item work and appends NO audit row at all, preserving the 05-07 zero-write-on-reapply
			// invariant. Two plan-scope rows make the ledger self-reconstructing without joining the plan
			// header: the `previewed` event carries the plan's previewed timestamp (its immutable created_at)
			// and the actor who generated it, and the `applied` event carries this apply's applied timestamp,
			// actor, confirmed checksum, and final status/outcomes.
			if ($applied + $conflicted > 0)
			{
				$previewedAt = (string)$plan['created_at'];
				$previewedBy = $plan['created_by'] === null ? '' : (string)$plan['created_by'];
				$auditInsert->execute([$planId, null, $previewedBy, self::AUDIT_EVENT_PREVIEWED, $previewedAt, $moduleVersion, null, $this->CanonicalJson(['checksum' => $storedChecksum]), 'pending']);
				$auditInsert->execute([$planId, null, $actor, self::AUDIT_EVENT_APPLIED, $appliedAt, $moduleVersion, $this->CanonicalJson(['status' => $status, 'previewed_at' => $previewedAt]), $this->CanonicalJson(['status' => $finalStatus, 'checksum' => $storedChecksum, 'outcomes' => ['applied' => $applied, 'conflict' => $conflicted, 'skipped' => $skipped]]), 'applied']);
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
	 * Compute a zero-write rollback preview from the append-only audit ledger (D-11). The reversible set is
	 * derived ONLY from the `applied` item rows recorded in `grocy_ai_bulk_audit` — the immutable
	 * after-image (what the original apply wrote) and before-image (the value to restore) — never re-derived
	 * from a fresh classification scan. For each applied item it re-reads the CURRENT written field through
	 * the shipped public read path (`ReadProductTaxonomy` via `CurrentWrittenValue`) and marks it
	 * `reversible` only when the current value STILL equals the audited after-image. An item whose current
	 * value drifted (a manual edit after the original apply) is `refused` with the pinned
	 * `manual_edit_after_apply` blocker and its inverse operation is withheld, so it can never be silently
	 * overwritten. Items already reversed under this plan leave the actionable preview entirely. This method
	 * issues no write and no rollback execution; the returned `checksum` is the deterministic rollback-plan
	 * checksum over the full audit-derived reversal set (identities, both images, inverse operation types,
	 * and the ruleset version), stable across preview and execute. Fails closed if the plan is unknown.
	 *
	 * @return array{plan_id: int, plan_checksum: string, checksum: string, items: array<int, array<string, mixed>>, reversible: array<int, array<string, mixed>>, refused: array<int, array<string, mixed>>}
	 */
	public function PreviewRollback(int|string $planId): array
	{
		$planIdInt = (int)$planId;
		$plan = $this->LoadPlanHeader($planIdInt);
		$rulesetVersion = (string)$plan['ruleset_version'];
		$candidates = $this->RollbackAppliedLedger($planIdInt);

		$items = [];
		$reversible = [];
		$refused = [];
		foreach ($candidates as $candidate)
		{
			// An item already reversed under this plan is done: it is no longer an actionable candidate.
			if ($candidate['current_outcome'] === 'rolled_back')
			{
				continue;
			}

			try
			{
				// Re-read the CURRENT written field live — never the stored ledger — to bind the preview to reality.
				$current = $this->CurrentWrittenValue($candidate['inverse_operation'], $candidate['object_type'], $candidate['object_id']);
				$isReversible = $current === $candidate['after_image'];
			}
			catch (\Throwable $exception)
			{
				// An unreadable current value fails closed to a refusal, never a silent "still matches".
				$current = null;
				$isReversible = false;
			}

			$entry = [
				'plan_item_id' => $candidate['plan_item_id'],
				'object_type' => $candidate['object_type'],
				'object_id' => $candidate['object_id'],
				// The audited before-image (the value to restore) and after-image (what apply wrote).
				'before_image' => $candidate['before_image'],
				'after_image' => $candidate['after_image'],
				'current_value' => $current,
				// The inverse named operation is emitted only for a reversible item; a refused one withholds it.
				'inverse_operation' => $isReversible ? $candidate['inverse_operation'] : null,
				'reversible' => $isReversible,
				'blocker' => $isReversible ? null : self::ROLLBACK_MANUAL_EDIT
			];
			$items[] = $entry;
			if ($isReversible)
			{
				$reversible[] = $entry;
			}
			else
			{
				$refused[] = $entry;
			}
		}

		return [
			'plan_id' => $planIdInt,
			'plan_checksum' => (string)$plan['checksum'],
			'checksum' => $this->RollbackChecksum($candidates, $rulesetVersion),
			'items' => $items,
			'reversible' => $reversible,
			'refused' => $refused
		];
	}

	/**
	 * Execute a guarded rollback through the SAME path as forward apply (D-11). It restores only the
	 * reversible items' audited before-images by reusing the 05-04 named typed-operation registry, the
	 * 05-06 optimistic-concurrency re-read, and the 05-07 single `BEGIN IMMEDIATE` transaction — it opens no
	 * parallel write path and issues no ad-hoc native/cache SQL. Each restore delegates to
	 * `AssignProductTaxonomy($objectId, <before-image assignment>, $joinExistingTransaction = true)` so it
	 * joins this one outer transaction (no own BEGIN/COMMIT, no per-item commit, no network/provider call
	 * under the lock). Optimistic concurrency: each item's current written value is re-read inside the lock
	 * and MUST still equal the audited after-image; a drifted item is recorded `conflict` and never written
	 * (no partial write). Idempotency (D-09): an item already `rolled_back` under this plan is skipped, so a
	 * re-run or a resumed interrupted rollback repeats no completed reversal; on any throw the whole
	 * transaction rolls back to a byte-identical prior state and its audit rows vanish with it. Every
	 * durable write and audit append lives inside the single transaction; the ledger stays append-only and
	 * gains only `rolled_back` (and per-item `conflict`) rows. On success the plan status becomes
	 * `rolled_back`.
	 *
	 * @param string $actor the authenticated Grocy session user resolved by the controller
	 * @param string|null $confirmedChecksum the reviewed rollback-plan checksum, cross-checked before any write
	 * @return array{plan_id: int, checksum: string, status: string, blockers: array<int, string>, outcomes: array{rolled_back: int, conflict: int, skipped: int}, actor: string}
	 */
	public function RollbackPlan(int $planId, string $actor, ?string $confirmedChecksum = null): array
	{
		$plan = $this->LoadPlanHeader($planId);
		$rulesetVersion = (string)$plan['ruleset_version'];
		$rollbackChecksum = $this->RollbackChecksum($this->RollbackAppliedLedger($planId), $rulesetVersion);

		// Bind the executed reversal to the reviewed one before any write: when the caller confirms a
		// checksum it must equal the audit-derived rollback-plan checksum, else refuse with no write.
		if ($confirmedChecksum !== null && !hash_equals($rollbackChecksum, $confirmedChecksum))
		{
			return $this->RollbackResult($planId, $rollbackChecksum, (string)$plan['status'], ['plan_checksum_mismatch'], 0, 0, 0, $actor);
		}

		// LOCKED scheme identical to ApplyPlan: raw BEGIN IMMEDIATE up front, one COMMIT, one ROLLBACK; no
		// PDO transaction idiom, no network/provider call while the lock is held.
		$this->Db->exec('BEGIN IMMEDIATE');
		try
		{
			$rolledBackAt = (string)$this->Db->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
			$moduleVersion = $this->ModuleVersion();
			// The append-only ledger writer: INSERT only, inside THIS transaction, so a rolled-back rollback
			// discards its own rows atomically with the reversals they record.
			$auditInsert = $this->Db->prepare('INSERT INTO grocy_ai_bulk_audit (plan_id, plan_item_id, actor, event, event_at, module_version, before_json, after_json, outcome) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
			$markRolledBack = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'rolled_back' WHERE plan_id = ? AND id = ?");
			$markConflict = $this->Db->prepare("UPDATE grocy_ai_bulk_plan_items SET outcome = 'conflict' WHERE plan_id = ? AND id = ?");

			// TOCTOU-free: recompute the audit-derived reversal set INSIDE the lock.
			$candidates = $this->RollbackAppliedLedger($planId);

			$rolledBack = 0;
			$conflicted = 0;
			$skipped = 0;
			foreach ($candidates as $candidate)
			{
				$planItemId = $candidate['plan_item_id'];

				// Idempotency (D-09): an item already reversed under this plan is never reversed twice.
				if ($candidate['current_outcome'] === 'rolled_back')
				{
					$skipped++;
					continue;
				}

				$afterJson = $this->CanonicalJson(['leaf_slug' => $candidate['after_image']]);
				$beforeJson = $this->CanonicalJson(['leaf_slug' => $candidate['before_image']]);

				// Optimistic concurrency: the live value MUST still equal the audited after-image. A field
				// hand-edited after the original apply has drifted, so it is refused and never overwritten.
				$current = $this->CurrentWrittenValue($candidate['inverse_operation'], $candidate['object_type'], $candidate['object_id']);
				if ($current !== $candidate['after_image'])
				{
					$markConflict->execute([$planId, $planItemId]);
					$auditInsert->execute([$planId, $planItemId, $actor, self::AUDIT_EVENT_ROLLED_BACK, $rolledBackAt, $moduleVersion, $afterJson, null, 'conflict']);
					$conflicted++;
					continue;
				}

				// Restore the audited before-image through the SAME named typed operation + shipped write.
				$resolution = $this->ResolveOperation($candidate['inverse_operation']);
				if ($resolution['delegate'] === null)
				{
					throw new \RuntimeException('unknown_operation');
				}
				($resolution['delegate'])($candidate['object_id'], $candidate['before_image']);
				$markRolledBack->execute([$planId, $planItemId]);
				// before = the value that stood before this reversal (the audited after-image); after = the
				// restored before-image. Same transaction, so a rollback discards it with the reversal.
				$auditInsert->execute([$planId, $planItemId, $actor, self::AUDIT_EVENT_ROLLED_BACK, $rolledBackAt, $moduleVersion, $afterJson, $beforeJson, 'rolled_back']);
				$rolledBack++;
			}

			$status = (string)$plan['status'];
			$finalStatus = $rolledBack > 0 ? 'rolled_back' : $status;
			if ($finalStatus !== $status)
			{
				$this->Db->prepare('UPDATE grocy_ai_bulk_plans SET status = ? WHERE id = ?')->execute([$finalStatus, $planId]);
			}

			// Two plan-scope rows make the ledger self-reconstructing without a plan-header join, appended
			// only when this rollback did real work so an idempotent re-run appends nothing.
			if ($rolledBack + $conflicted > 0)
			{
				$previewedAt = (string)$plan['created_at'];
				$previewedBy = $plan['created_by'] === null ? '' : (string)$plan['created_by'];
				$auditInsert->execute([$planId, null, $previewedBy, self::AUDIT_EVENT_PREVIEWED, $previewedAt, $moduleVersion, null, $this->CanonicalJson(['checksum' => (string)$plan['checksum']]), 'pending']);
				$auditInsert->execute([$planId, null, $actor, self::AUDIT_EVENT_ROLLED_BACK, $rolledBackAt, $moduleVersion, $this->CanonicalJson(['status' => $status]), $this->CanonicalJson(['status' => $finalStatus, 'checksum' => $rollbackChecksum, 'outcomes' => ['rolled_back' => $rolledBack, 'conflict' => $conflicted, 'skipped' => $skipped]]), self::AUDIT_EVENT_ROLLED_BACK]);
			}

			$this->Db->exec('COMMIT');
			return $this->RollbackResult($planId, $rollbackChecksum, $finalStatus, [], $rolledBack, $conflicted, $skipped, $actor);
		}
		catch (\Throwable $exception)
		{
			// Fail closed to a byte-identical prior state: nothing reverted, nothing stamped, no audit row.
			$this->Db->exec('ROLLBACK');
			$blocker = $exception->getMessage() === 'unknown_operation' ? 'unknown_operation' : self::ROLLBACK_TRANSACTION_FAILED;
			return $this->RollbackResult($planId, $rollbackChecksum, (string)$plan['status'], [$blocker], 0, 0, 0, $actor);
		}
	}

	/**
	 * The audit-derived reversal set (D-11): one entry per successfully applied item recorded in the
	 * append-only ledger, joined to its stored plan item for identity and its current outcome. The restore
	 * target is the audited before-image; the audited after-image is the value the original apply wrote; the
	 * inverse named operation restores the before-image. Read-only; nothing here re-derives from a fresh
	 * classification scan.
	 *
	 * @return array<int, array{plan_item_id: int, object_type: string, object_id: int, before_image: ?string, after_image: ?string, inverse_operation: string, current_outcome: string}>
	 */
	private function RollbackAppliedLedger(int $planId): array
	{
		$statement = $this->Db->prepare('SELECT audit.plan_item_id, audit.before_json, audit.after_json, item.object_type, item.object_id, item.outcome FROM grocy_ai_bulk_audit AS audit INNER JOIN grocy_ai_bulk_plan_items AS item ON item.id = audit.plan_item_id WHERE audit.plan_id = ? AND audit.plan_item_id IS NOT NULL AND audit.event = ? AND audit.outcome = ? ORDER BY audit.plan_item_id');
		$statement->execute([$planId, self::AUDIT_EVENT_APPLIED, 'applied']);

		$candidates = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			// Both images are the closed written-field shape captured at apply; a malformed image fails closed.
			$beforeImage = $this->WrittenBeforeImage((string)$row['before_json']);
			$afterImage = $this->WrittenBeforeImage((string)$row['after_json']);
			$candidates[] = [
				'plan_item_id' => (int)$row['plan_item_id'],
				'object_type' => (string)$row['object_type'],
				'object_id' => (int)$row['object_id'],
				'before_image' => $beforeImage,
				'after_image' => $afterImage,
				// The inverse restores the before-image: a prior leaf via assign_taxonomy_leaf, a prior
				// unclassified state via set_unclassified.
				'inverse_operation' => $beforeImage === null ? 'set_unclassified' : 'assign_taxonomy_leaf',
				'current_outcome' => (string)$row['outcome']
			];
		}

		return $candidates;
	}

	/**
	 * The deterministic rollback-plan checksum over the full audit-derived reversal set — item identities,
	 * both images, inverse operation types, and the ruleset version — via the shared `ChecksumForPlan`
	 * idiom. It is stable across preview and execute (independent of per-item completion), so the reviewed
	 * reversal and the executed reversal are provably the same artifact.
	 *
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function RollbackChecksum(array $candidates, string $rulesetVersion): string
	{
		$items = [];
		foreach ($candidates as $candidate)
		{
			$items[] = [
				'object_type' => $candidate['object_type'],
				'object_id' => $candidate['object_id'],
				'operation' => $candidate['inverse_operation'],
				// The expected current value (audited after-image) and the restore target (before-image).
				'before_image' => $candidate['after_image'],
				'proposed_value' => $candidate['before_image']
			];
		}

		return $this->ChecksumForPlan('taxonomy_rollback', $rulesetVersion, $items);
	}

	/**
	 * The closed rollback-outcome DTO, mirroring `ApplyResult`. `outcomes` uses the rollback per-item
	 * vocabulary; `blockers` is bounded (empty on success); `actor` echoes the authenticated session user.
	 *
	 * @return array{plan_id: int, checksum: string, status: string, blockers: array<int, string>, outcomes: array{rolled_back: int, conflict: int, skipped: int}, actor: string}
	 */
	private function RollbackResult(int $planId, string $checksum, string $status, array $blockers, int $rolledBack, int $conflicted, int $skipped, string $actor): array
	{
		return [
			'plan_id' => $planId,
			'checksum' => $checksum,
			'status' => $status,
			'blockers' => $blockers,
			'outcomes' => ['rolled_back' => $rolledBack, 'conflict' => $conflicted, 'skipped' => $skipped],
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
