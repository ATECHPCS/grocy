<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiTaxonomyService
{
	/**
	 * Deterministic candidate scoring for the classification review path (06-05). Each evidence source
	 * implies a candidate leaf with a score; the Grocy product-group signal scores as a high band. The
	 * scores drive the confidence band and the two conflict forms surfaced by ReviewProductTaxonomy, and
	 * are derived only from stored evidence + the group signal (no network, no volatile input).
	 */
	public const GROUP_SIGNAL_SCORE = 3;
	private const EVIDENCE_BAND_SCORES = ['high' => 3, 'medium' => 2, 'low' => 1, 'unverified' => 0];

	/** The minimum candidate score (>= a medium band) for a classification to be reviewed as confident. */
	public const CONFIDENCE_THRESHOLD = 2;

	/** Two distinct candidate leaves whose scores are within this margin are a tie (Q7 conflict form b). */
	public const CANDIDATE_TIE_MARGIN = 1;

	private PDO $Db;
	private ?GrocyAiInventoryScope $Scope = null;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiTaxonomyMigration::Bootstrap($this->Db);
		}
	}

	/**
	 * The single owner of the in-scope predicate (06-03). The classification/taxonomy path consults it
	 * here rather than restating "active AND group-not-excluded AND override != excluded" locally.
	 */
	private function Scope(): GrocyAiInventoryScope
	{
		return $this->Scope ??= new GrocyAiInventoryScope($this->Db);
	}

	/** Expose the shared in-scope predicate so the bulk profilers reuse the one owner, not a copy. */
	public function IsProductInScope(int $productId): bool
	{
		return $this->Scope()->IsInScope($productId);
	}

	/**
	 * Store only the server-validated Phase 2 food-type suggestion for the current
	 * local product. Browser payloads never supply this evidence or its metadata.
	 */
	public function ReconcileEnrichmentEvidence(?int $productId, array $enrichment): bool
	{
		if ($productId === null || $productId < 1)
		{
			return false;
		}

		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			return false;
		}

		$foodType = null;
		$suggestions = $enrichment['suggestions'] ?? null;
		if (!is_array($suggestions) || !array_is_list($suggestions))
		{
			throw new \InvalidArgumentException('Invalid enrichment taxonomy evidence');
		}
		foreach ($suggestions as $suggestion)
		{
			if (is_array($suggestion) && ($suggestion['field'] ?? null) === 'food_type')
			{
				$foodType = $suggestion;
				break;
			}
		}

		if ($foodType === null)
		{
			$delete = $this->Db->prepare('DELETE FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
			$delete->execute([$productId]);
			return true;
		}

		$providerCategory = $foodType['value'] ?? null;
		$confidenceBand = $foodType['confidence_band'] ?? null;
		$reasonCode = $foodType['reason_code'] ?? null;
		if (!is_string($providerCategory) || trim($providerCategory) === '' || strlen($providerCategory) > 500
			|| !is_string($confidenceBand) || !in_array($confidenceBand, ['high', 'medium', 'low', 'unverified'], true)
			|| !is_string($reasonCode) || trim($reasonCode) === '' || strlen($reasonCode) > 500)
		{
			throw new \InvalidArgumentException('Invalid enrichment taxonomy evidence');
		}

		$write = $this->Db->prepare('INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code, recorded_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(product_id) DO UPDATE SET provider_category = excluded.provider_category, mapping_version = excluded.mapping_version, confidence_band = excluded.confidence_band, reason_code = excluded.reason_code, recorded_at = CURRENT_TIMESTAMP');
		$write->execute([$productId, $providerCategory, GrocyAiTaxonomyMigration::VERSION, $confidenceBand, $reasonCode]);
		return true;
	}

	public function LeafBySlug(string $slug): array
	{
		if (preg_match('/^[a-z][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/baby|pet|frozen|preserved/i', $slug) === 1)
		{
			throw new \InvalidArgumentException('Unknown local taxonomy leaf');
		}

		$statement = $this->Db->prepare('SELECT id, slug, label FROM grocy_ai_taxonomy_nodes WHERE slug = ? AND parent_id IS NOT NULL AND version = ?');
		$statement->execute([$slug, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($leaf))
		{
			throw new \InvalidArgumentException('Unknown local taxonomy leaf');
		}

		return $this->LeafDto($leaf);
	}

	public function ReadProductTaxonomy(int $productId): array
	{
		if ($productId < 1)
		{
			throw new \InvalidArgumentException('Invalid product ID');
		}
		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			throw new \RuntimeException('Product unavailable');
		}

		$currentLeaf = $this->CurrentLeaf($productId);
		$evidence = $this->Evidence($productId);
		return [
			'product_id' => $productId,
			'current_leaf' => $currentLeaf,
			'suggested_leaf' => $evidence['suggested_leaf'],
			'evidence_source' => $evidence['evidence_source'],
			'ruleset_version' => GrocyAiTaxonomyMigration::VERSION,
			'provider_category' => $evidence['provider_category'],
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => $evidence['reason_code']
		];
	}

	/**
	 * The deterministic classification-review view for one product (06-05): the winning candidate leaf,
	 * its confidence band/score, and the two conflict signals. It is surfaced WITHOUT changing the closed
	 * ReadProductTaxonomy shape or the AssignProductTaxonomy write path; it reads only stored evidence +
	 * the Grocy product-group signal, so the same committed state always yields the same review.
	 *
	 * Conflict is defined two ways (Q7 / DATA-02):
	 *   (a) group_signal_contradiction — the product carries a Grocy product-group signal implying one leaf
	 *       while its provider evidence implies a different leaf; and
	 *   (b) candidate_tie — the top two DISTINCT candidate leaves score within CANDIDATE_TIE_MARGIN.
	 * A winner scoring below CONFIDENCE_THRESHOLD and NOT in conflict is reviewed `low_confidence`, so the
	 * classification pass retains Unclassified rather than forcing a leaf. No evidence at all → `unclassified`.
	 *
	 * @return array{product_id:int, current_leaf:?array, suggested_leaf:?array, evidence_source:?string, confidence_band:?string, confidence_score:int, review_band:string, conflict:bool, conflict_reasons:array<int,string>, reason_code:string, candidates:array<int,array<string,mixed>>}
	 */
	public function ReviewProductTaxonomy(int $productId): array
	{
		if ($productId < 1)
		{
			throw new \InvalidArgumentException('Invalid product ID');
		}
		$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
		$product->execute([$productId]);
		if ($product->fetchColumn() === false)
		{
			throw new \RuntimeException('Product unavailable');
		}

		$currentLeaf = $this->CurrentLeaf($productId);
		$candidates = [];
		$group = $this->GroupSignalCandidate($productId);
		if ($group !== null)
		{
			$candidates[] = $group;
		}
		$provider = $this->ProviderCandidate($productId);
		if ($provider !== null)
		{
			$candidates[] = $provider;
		}

		if ($candidates === [])
		{
			return [
				'product_id' => $productId,
				'current_leaf' => $currentLeaf,
				'suggested_leaf' => null,
				'evidence_source' => null,
				'confidence_band' => null,
				'confidence_score' => 0,
				'review_band' => 'unclassified',
				'conflict' => false,
				'conflict_reasons' => [],
				'reason_code' => 'no_accepted_evidence',
				'candidates' => []
			];
		}

		// The winner is the highest-scoring candidate; the group signal (added first) precedes provider
		// evidence on an equal score, the same precedence ReadProductTaxonomy uses, so the choice is stable.
		$winner = $candidates[0];
		foreach ($candidates as $candidate)
		{
			if ($candidate['score'] > $winner['score'])
			{
				$winner = $candidate;
			}
		}

		// Distinct candidate leaves (best score per slug) drive the tie/contradiction tests below.
		$bySlug = [];
		foreach ($candidates as $candidate)
		{
			$slug = $candidate['slug'];
			if (!isset($bySlug[$slug]) || $candidate['score'] > $bySlug[$slug]['score'])
			{
				$bySlug[$slug] = $candidate;
			}
		}

		$conflictReasons = [];
		if ($group !== null && $provider !== null && $group['slug'] !== $provider['slug'])
		{
			$conflictReasons[] = 'group_signal_contradiction';
		}
		if (count($bySlug) >= 2)
		{
			$scores = array_map(static fn(array $candidate): int => (int)$candidate['score'], array_values($bySlug));
			rsort($scores);
			if (($scores[0] - $scores[1]) <= self::CANDIDATE_TIE_MARGIN)
			{
				$conflictReasons[] = 'candidate_tie';
			}
		}

		$reviewBand = $conflictReasons !== []
			? 'conflict'
			: ((int)$winner['score'] >= self::CONFIDENCE_THRESHOLD ? 'confident' : 'low_confidence');

		$reasonCode = match ($reviewBand)
		{
			'conflict' => 'review_conflict',
			'low_confidence' => 'below_confidence_threshold',
			default => $winner['source'] === 'grocy_product_group' ? 'mapped_grocy_product_group' : 'mapped_provider_category'
		};

		return [
			'product_id' => $productId,
			'current_leaf' => $currentLeaf,
			'suggested_leaf' => $winner['leaf'],
			'evidence_source' => $winner['source'],
			'confidence_band' => $winner['confidence_band'],
			'confidence_score' => (int)$winner['score'],
			'review_band' => $reviewBand,
			'conflict' => $conflictReasons !== [],
			'conflict_reasons' => $conflictReasons,
			'reason_code' => $reasonCode,
			'candidates' => array_values($candidates)
		];
	}

	/**
	 * @param bool $joinExistingTransaction When false (the default, unchanged for the controller
	 *   endpoint and the taxonomy test callers) this method opens and commits its own transaction.
	 *   When true it runs the identical validation and the same single `INSERT ... ON CONFLICT` upsert
	 *   but issues no `beginTransaction()`/`commit()`/`rollBack()` of its own, so a caller such as the
	 *   bulk engine's `ApplyPlan` can own one outer `BEGIN IMMEDIATE` and nest this delegate inside it.
	 *   The write statement itself is unchanged either way.
	 */
	public function AssignProductTaxonomy(int $productId, array $assignment, bool $joinExistingTransaction = false): array
	{
		if ($productId < 1 || array_keys($assignment) !== ['leaf_slug', 'ruleset_version'] && array_keys($assignment) !== ['unclassified', 'ruleset_version'])
		{
			throw new \InvalidArgumentException('Invalid taxonomy assignment');
		}
		if (($assignment['ruleset_version'] ?? null) !== GrocyAiTaxonomyMigration::VERSION)
		{
			throw new \InvalidArgumentException('Stale taxonomy ruleset');
		}

		$isUnclassified = ($assignment['unclassified'] ?? null) === true;
		if (!$isUnclassified && (!is_string($assignment['leaf_slug'] ?? null) || !isset($assignment['leaf_slug'])))
		{
			throw new \InvalidArgumentException('Invalid taxonomy assignment');
		}

		$ownsTransaction = !$joinExistingTransaction;
		if ($ownsTransaction)
		{
			$this->Db->beginTransaction();
		}
		try
		{
			$product = $this->Db->prepare('SELECT id FROM products WHERE id = ?');
			$product->execute([$productId]);
			if ($product->fetchColumn() === false)
			{
				throw new \RuntimeException('Product unavailable');
			}

			$this->Evidence($productId);
			$leafId = null;
			if (!$isUnclassified)
			{
				$leaf = $this->LeafBySlug($assignment['leaf_slug']);
				$leafId = $leaf['id'];
			}
			$write = $this->Db->prepare('INSERT INTO grocy_ai_taxonomy_classifications (product_id, leaf_id, ruleset_version, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(product_id) DO UPDATE SET leaf_id = excluded.leaf_id, ruleset_version = excluded.ruleset_version, updated_at = CURRENT_TIMESTAMP');
			$write->execute([$productId, $leafId, GrocyAiTaxonomyMigration::VERSION]);
			if ($ownsTransaction)
			{
				$this->Db->commit();
			}
		}
		catch (\Throwable $ex)
		{
			if ($ownsTransaction && $this->Db->inTransaction())
			{
				$this->Db->rollBack();
			}
			throw $ex;
		}

		return $this->ReadProductTaxonomy($productId);
	}

	public function ValidateInventoryTaxonomy(): array
	{
		$products = $this->Db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		$report = [
			'ruleset_version' => GrocyAiTaxonomyMigration::VERSION,
			'frozen_preserved_boundary' => 'Frozen and preserved are handling/location concerns, not taxonomy identities.',
			'in_scope_products' => count($products),
			'mapped' => 0,
			'unclassified' => 0,
			'excluded' => 0,
			'conflicting' => 0,
			'low_confidence' => 0
		];

		$scope = $this->Scope();
		foreach ($products as $productId)
		{
			// Out-of-scope products (inactive, excluded group, or override = excluded) are held out by
			// the single scope owner and counted as excluded before any evidence outcome is computed.
			if (!$scope->IsInScope((int)$productId))
			{
				$report['excluded']++;
				continue;
			}
			$outcome = $this->ValidationOutcome((int)$productId);
			$report[$outcome]++;
		}

		return $report;
	}

	private function CurrentLeaf(int $productId): ?array
	{
		$statement = $this->Db->prepare('SELECT node.id, node.slug, node.label FROM grocy_ai_taxonomy_classifications AS classification INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $statement->fetch(PDO::FETCH_ASSOC);
		return is_array($leaf) ? $this->LeafDto($leaf) : null;
	}

	private function Evidence(int $productId): array
	{
		$productGroup = $this->ProductGroupEvidence($productId);
		if ($productGroup !== null)
		{
			return $productGroup;
		}

		$statement = $this->Db->prepare('SELECT provider_category, mapping_version, confidence_band, reason_code FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$evidence = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($evidence) || $evidence['mapping_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			return $this->Unclassified('no_accepted_evidence');
		}
		$providerCategory = self::ProviderCategoryKey((string)$evidence['provider_category']);
		$rule = $this->Db->prepare('SELECT target_slug, disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([$providerCategory, GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (!is_array($mapping))
		{
			return $this->Unclassified('unknown_mapping');
		}
		if ($mapping['disposition'] === 'excluded')
		{
			return $this->Unclassified('excluded_mapping');
		}
		if ($mapping['disposition'] !== 'mapped' || !is_string($mapping['target_slug'])
			|| !in_array($evidence['confidence_band'], ['high', 'medium'], true))
		{
			return $this->Unclassified('unknown_mapping');
		}

		return [
			'suggested_leaf' => $this->LeafBySlug($mapping['target_slug']),
			'evidence_source' => 'provider_food_type',
			'provider_category' => $providerCategory,
			'confidence_band' => $evidence['confidence_band'],
			'reason_code' => 'mapped_provider_category'
		];
	}

	private function ProductGroupEvidence(int $productId): ?array
	{
		$statement = $this->Db->prepare('SELECT product_group.name FROM products INNER JOIN product_groups AS product_group ON product_group.id = products.product_group_id WHERE products.id = ? AND product_group.active = 1');
		$statement->execute([$productId]);
		$productGroup = $statement->fetchColumn();
		if (!is_string($productGroup) || trim($productGroup) === '')
		{
			return null;
		}

		$key = self::ProviderCategoryKey($productGroup);
		$rule = $this->Db->prepare('SELECT target_slug, disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([$key, GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (!is_array($mapping) || $mapping['disposition'] !== 'mapped' || !is_string($mapping['target_slug']))
		{
			return null;
		}

		return [
			'suggested_leaf' => $this->LeafBySlug($mapping['target_slug']),
			'evidence_source' => 'grocy_product_group',
			'provider_category' => $productGroup,
			'confidence_band' => 'high',
			'reason_code' => 'mapped_grocy_product_group'
		];
	}

	/**
	 * The Grocy product-group signal as a scored candidate leaf (06-05), or null when the product is
	 * ungrouped / its group maps to no leaf. Reuses the shipped ProductGroupEvidence read; scores high.
	 *
	 * @return array{slug:string, leaf:array<string,string>, source:string, confidence_band:string, score:int}|null
	 */
	private function GroupSignalCandidate(int $productId): ?array
	{
		$group = $this->ProductGroupEvidence($productId);
		if ($group === null)
		{
			return null;
		}
		$leaf = $group['suggested_leaf'];
		return [
			'slug' => (string)$leaf['slug'],
			'leaf' => $leaf,
			'source' => 'grocy_product_group',
			'confidence_band' => 'high',
			'score' => self::GROUP_SIGNAL_SCORE
		];
	}

	/**
	 * The provider enrichment evidence as a scored candidate leaf (06-05), or null when there is no
	 * accepted provider evidence, the category maps to no leaf, or the mapping is excluded. Unlike the
	 * classification Evidence() path this keeps low/unverified bands as candidates (scored low) so a weak
	 * signal surfaces as `low_confidence` rather than vanishing. Pure read of stored evidence + rules.
	 *
	 * @return array{slug:string, leaf:array<string,string>, source:string, confidence_band:string, score:int}|null
	 */
	private function ProviderCandidate(int $productId): ?array
	{
		$statement = $this->Db->prepare('SELECT provider_category, mapping_version, confidence_band FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$evidence = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($evidence) || $evidence['mapping_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			return null;
		}
		$providerCategory = self::ProviderCategoryKey((string)$evidence['provider_category']);
		$rule = $this->Db->prepare('SELECT target_slug, disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([$providerCategory, GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (!is_array($mapping) || $mapping['disposition'] !== 'mapped' || !is_string($mapping['target_slug']))
		{
			return null;
		}
		$band = (string)$evidence['confidence_band'];
		$leaf = $this->LeafBySlug($mapping['target_slug']);
		return [
			'slug' => (string)$leaf['slug'],
			'leaf' => $leaf,
			'source' => 'provider_food_type',
			'confidence_band' => $band,
			'score' => self::EVIDENCE_BAND_SCORES[$band] ?? 0
		];
	}

	private function ValidationOutcome(int $productId): string
	{
		$statement = $this->Db->prepare('SELECT provider_category, mapping_version, confidence_band, reason_code FROM grocy_ai_taxonomy_evidence WHERE product_id = ?');
		$statement->execute([$productId]);
		$evidence = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($evidence) || $evidence['mapping_version'] !== GrocyAiTaxonomyMigration::VERSION)
		{
			return 'unclassified';
		}
		if (str_contains(strtolower((string)$evidence['reason_code']), 'conflict'))
		{
			return 'conflicting';
		}

		$rule = $this->Db->prepare('SELECT disposition FROM grocy_ai_taxonomy_mapping_rules WHERE provider_category = ? AND version = ?');
		$rule->execute([self::ProviderCategoryKey((string)$evidence['provider_category']), GrocyAiTaxonomyMigration::VERSION]);
		$mapping = $rule->fetch(PDO::FETCH_ASSOC);
		if (is_array($mapping) && $mapping['disposition'] === 'excluded')
		{
			return 'excluded';
		}
		if (is_array($mapping) && $mapping['disposition'] === 'mapped' && !in_array($evidence['confidence_band'], ['high', 'medium'], true))
		{
			return 'low_confidence';
		}
		if (is_array($mapping) && $mapping['disposition'] === 'mapped')
		{
			return 'mapped';
		}

		return 'unclassified';
	}

	private function Unclassified(string $reasonCode): array
	{
		return [
			'suggested_leaf' => null,
			'evidence_source' => null,
			'provider_category' => null,
			'confidence_band' => null,
			'reason_code' => $reasonCode
		];
	}

	private static function ProviderCategoryKey(string $providerCategory): string
	{
		return strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($providerCategory)));
	}

	private function LeafDto(array $leaf): array
	{
		return [
			'id' => (string)$leaf['id'],
			'slug' => (string)$leaf['slug'],
			'label' => (string)$leaf['label']
		];
	}
}
