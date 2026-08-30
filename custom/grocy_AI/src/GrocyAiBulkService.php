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
