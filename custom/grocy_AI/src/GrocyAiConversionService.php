<?php

namespace GrocyAI\Services;

use PDO;

class GrocyAiConversionService
{
	private const RELATIVE_TOLERANCE = 0.000000000001;
	/**
	 * The pinned checksum of every immutable Plan 01 fact: both branch revisions, the characterized
	 * migration hashes, the cache objects, the cache key schema, the query-plan checksum, and all
	 * eight protected-consumer outputs. The selected projection is deliberately excluded — it is the
	 * one field the gate exists to let change.
	 *
	 * A characterization document that disagrees with this constant cannot activate anything, so
	 * editing the document is not enough to widen the gate: the constant must change too, and the
	 * release gate re-derives the document's facts from the two immutable git revisions.
	 */
	private const CHARACTERIZATION_FACTS_SHA256 = '92e23d21fa9caa3c96e9b28cbade9ef6f38c9797393196b51293225a1be3c0e1';
	// Redundancy is a maintainer-facing observation about a hand-entered override, so it uses a
	// looser tolerance than the exact reciprocal checks that block a rule.
	private const COVERAGE_REDUNDANCY_TOLERANCE = 0.0001;
	private const PROTECTED_CONSUMER_CATEGORIES = [
		'stock', 'recipe', 'purchase', 'consumption', 'price', 'transfer', 'meal_plan', 'quantity_display'
	];
	private const SOURCED_PROFILE_RECORDS = [
		'water-like-beverage' => ['leaf-beverages', 'cup', 'g', '237', '174158', '1 cup = 237 g'],
		'whole-milk' => ['leaf-dairy-eggs', 'cup', 'g', '244', '171265', '1 cup = 244 g'],
		'olive-oil' => ['leaf-oils-vinegars', 'tbsp', 'g', '13.5', '171413', '1 tablespoon = 13.5 g']
	];
	private PDO $Db;

	public function __construct(?PDO $pdo = null, bool $bootstrap = true)
	{
		$this->Db = $pdo ?? \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
		if ($bootstrap)
		{
			GrocyAiConversionMigration::Bootstrap($this->Db);
		}
	}

	public function ValidateNativeConversionBeforeWrite(array $candidate, ?int $objectId): array
	{
		$productId = $this->PositiveInteger($candidate['product_id'] ?? null);
		$scope = $productId === null ? 'reusable' : 'product';
		$factor = $this->Factor($candidate['factor'] ?? null);
		if ($factor === null)
		{
			return $this->Blocked($scope, 'factor_not_finite');
		}
		if ($factor <= 0)
		{
			return $this->Blocked($scope, 'factor_non_positive');
		}

		$units = $this->CandidateUnits($candidate);
		if ($scope === 'product')
		{
			return $this->Dto('product_native', 'product', [], $this->CandidateFactor($candidate), 'product_scoped');
		}

		if ($units === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}
		if ($this->IsCountUnit($units['from_name']) || $this->IsCountUnit($units['to_name']))
		{
			return $this->Blocked('reusable', 'reusable_count_scope');
		}
		if ($units['from_key'] === null || $units['to_key'] === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}

		$revision = $this->InactiveRevision();
		if ($revision === null)
		{
			return $this->Blocked('reusable', 'inactive_revision_unavailable', null);
		}
		$sourceVersion = (string)$revision['source_version'];
		if ($sourceVersion !== GrocyAiConversionMigration::SOURCE_VERSION)
		{
			return $this->Blocked('reusable', 'revision_source_version_invalid', $sourceVersion);
		}

		$catalog = $this->Catalog();
		foreach ($catalog as $unit)
		{
			if ($unit['source_version'] !== $sourceVersion)
			{
				return $this->Blocked('reusable', 'catalog_source_version_invalid', $sourceVersion);
			}
		}
		$from = $catalog[$units['from_key']] ?? null;
		$to = $catalog[$units['to_key']] ?? null;
		if ($from === null || $to === null)
		{
			return $this->Blocked('reusable', 'unit_not_cataloged');
		}
		if ($from['metric_factor'] === null || $from['metric_factor'] <= 0 || $to['metric_factor'] === null || $to['metric_factor'] <= 0)
		{
			return $this->Blocked('reusable', 'catalog_unit_invalid', $sourceVersion);
		}
		if ($from['dimension'] !== $to['dimension'])
		{
			return $this->Blocked('reusable', 'dimension_mismatch');
		}
		if (($candidate['inactive_revision_id'] ?? GrocyAiConversionMigration::INACTIVE_REVISION_ID) !== GrocyAiConversionMigration::INACTIVE_REVISION_ID)
		{
			return $this->Blocked('reusable', 'stale_revision_identity');
		}
		if (($candidate['source_version'] ?? $sourceVersion) !== $sourceVersion)
		{
			return $this->Blocked('reusable', 'stale_source_version', $sourceVersion);
		}

		$graphBlocker = $this->ValidateCatalogGraph($catalog, $sourceVersion);
		if ($graphBlocker !== null)
		{
			return $this->Blocked('reusable', $graphBlocker, $sourceVersion);
		}
		$expected = $from['metric_factor'] / $to['metric_factor'];
		if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
		{
			return $this->Blocked('reusable', 'factor_tolerance', $sourceVersion);
		}

		return $this->Dto('inactive', 'reusable', [], $this->CandidateFactor($candidate), $from['dimension'], $sourceVersion);
	}

	/**
	 * The sole operation allowed to transition a reusable rule revision active or to create the
	 * gate-created universal native rows whose cache effects Grocy's own characterized triggers
	 * rebuild. It runs in one transaction and fails closed — leaving module records, native rows,
	 * and cache snapshots untouched — unless the supplied bundle equals the current immutable
	 * dual-branch characterization on disk in every field, including every protected-consumer
	 * output. It never modifies product-scoped rows and never performs Phase 6 cleanup.
	 */
	public function ActivateVerifiedRuleset(array $bundle): array
	{
		foreach (['grocy_ai_conversion_activation_evidence', 'grocy_ai_conversion_rule_revisions', 'grocy_ai_conversion_rules', 'grocy_ai_conversion_catalog_units'] as $relation)
		{
			if (!$this->ModuleRelationAvailable($relation))
			{
				return $this->ActivationResult('inactive', ['activation_schema_unavailable']);
			}
		}

		$documentPath = is_string($bundle['characterization_path'] ?? null) ? (string)$bundle['characterization_path'] : '';
		$document = $documentPath === '' || !is_file($documentPath) ? false : file_get_contents($documentPath);
		if (!is_string($document) || $document === '')
		{
			return $this->ActivationResult('inactive', ['characterization_unavailable']);
		}
		$characterization = $this->ParseCharacterization($document);
		if ($characterization === null)
		{
			return $this->ActivationResult('inactive', ['characterization_unreadable']);
		}
		if (!hash_equals(self::CHARACTERIZATION_FACTS_SHA256, $this->CharacterizationFactsHash($characterization)))
		{
			return $this->ActivationResult('inactive', ['characterization_facts_mismatch']);
		}
		if (!is_string($bundle['characterization_sha256'] ?? null) || hash('sha256', $document) !== (string)$bundle['characterization_sha256'])
		{
			return $this->ActivationResult('inactive', ['characterization_stale']);
		}

		$blocker = $this->ValidateImmutableEvidence($bundle, $characterization);
		if ($blocker !== null)
		{
			return $this->ActivationResult('inactive', [$blocker]);
		}

		$revisionIds = $this->ActivationRevisionIds($bundle);
		if ($revisionIds === null)
		{
			return $this->ActivationResult('inactive', ['revision_set_empty']);
		}
		$revisions = $this->LoadCandidateRevisions($revisionIds);
		if (is_string($revisions))
		{
			return $this->ActivationResult('inactive', [$revisions]);
		}

		$documentAdapter = $characterization['selected_adapter'];
		$bundleAdapter = is_string($bundle['selected_adapter'] ?? null) ? (string)$bundle['selected_adapter'] : '';
		if ($bundleAdapter !== $documentAdapter)
		{
			return $this->ActivationResult('inactive', ['selected_adapter_mismatch']);
		}
		if ($documentAdapter === 'none')
		{
			return $this->ActivationResult('inactive', ['selected_projection_absent']);
		}
		if (!in_array($documentAdapter, GrocyAiConversionMigration::SELECTED_ADAPTERS, true))
		{
			return $this->ActivationResult('inactive', ['selected_adapter_unsupported']);
		}

		$catalog = $this->Catalog();
		$graphBlocker = $this->ValidateInspectionUniversalGraph($catalog);
		if ($graphBlocker !== null)
		{
			return $this->ActivationResult('inactive', [$graphBlocker]);
		}

		$evidenceHash = $this->EvidenceHash($bundle, $characterization, $revisionIds);
		$startedTransaction = !$this->Db->inTransaction();
		if ($startedTransaction)
		{
			$this->Db->beginTransaction();
		}

		try
		{
			$existing = $this->Db->prepare('SELECT id FROM grocy_ai_conversion_activation_evidence WHERE evidence_hash = ?');
			$existing->execute([$evidenceHash]);
			$evidenceId = $existing->fetchColumn();
			$projected = 0;
			if ($evidenceId === false)
			{
				$evidenceId = $this->RecordActivationEvidence($bundle, $characterization, $evidenceHash);
				$activate = $this->Db->prepare("UPDATE grocy_ai_conversion_rule_revisions SET status = 'active', activation_evidence_id = ? WHERE id = ? AND status = 'inactive'");
				foreach ($revisionIds as $revisionId)
				{
					$activate->execute([$evidenceId, $revisionId]);
				}
				$projected = $this->ApplySelectedProjection($documentAdapter, $revisions, $catalog);
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
			return $this->ActivationResult('inactive', ['activation_transaction_failed']);
		}

		return [
			'status' => 'active',
			'blockers' => [],
			'evidence_hash' => $evidenceHash,
			'activated_revision_ids' => $revisionIds,
			'selected_adapter' => $documentAdapter,
			'projected_universal_rows' => $projected
		];
	}

	/**
	 * The two immutable branch revisions the current characterization names, for a caller that must
	 * assert which evidence it reviewed. Returns null when the document is unavailable or
	 * unparseable, so the caller defers the real refusal to `ActivateVerifiedRuleset`.
	 *
	 * @return array{main: string, stable: string}|null
	 */
	public function ImmutableProofArtifacts(string $characterizationPath): ?array
	{
		$document = is_file($characterizationPath) ? file_get_contents($characterizationPath) : false;
		if (!is_string($document) || $document === '')
		{
			return null;
		}
		$characterization = $this->ParseCharacterization($document);
		if ($characterization === null)
		{
			return null;
		}
		return ['main' => $characterization['main_commit'], 'stable' => $characterization['stable_commit']];
	}

	/**
	 * Whether a named revision is one this module owns and could still be promoted. It is a bounded
	 * pre-check for a caller's error reporting only; `ActivateVerifiedRuleset` re-proves everything.
	 */
	public function RevisionIsPromotable(string $revisionId): bool
	{
		if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $revisionId) !== 1
			|| !$this->ModuleRelationAvailable('grocy_ai_conversion_rule_revisions'))
		{
			return false;
		}
		$statement = $this->Db->prepare("SELECT 1 FROM grocy_ai_conversion_rule_revisions WHERE id = ? AND status = 'inactive'");
		$statement->execute([$revisionId]);
		return $statement->fetchColumn() !== false;
	}

	/**
	 * Builds the activation bundle from the current characterization document so no caller has to
	 * restate — or can substitute — an adapter, factor, cache key, or protected-consumer proof.
	 * `ActivateVerifiedRuleset` re-reads the same document independently and re-proves every field,
	 * so a document changed between the two reads still fails closed.
	 */
	public function ActivationBundleFromCharacterization(string $characterizationPath, array $revisionIds): array
	{
		$document = is_file($characterizationPath) ? file_get_contents($characterizationPath) : false;
		if (!is_string($document) || $document === '')
		{
			return ['characterization_path' => $characterizationPath, 'revision_ids' => $revisionIds];
		}
		$characterization = $this->ParseCharacterization($document);
		if ($characterization === null)
		{
			return ['characterization_path' => $characterizationPath, 'revision_ids' => $revisionIds];
		}

		$protectedOutputs = [];
		foreach ($characterization['protected_outputs'] as $category => $proof)
		{
			$protectedOutputs[] = [
				'category' => $category,
				'main' => $proof['value'],
				'stable' => $proof['value'],
				'path' => $proof['path']
			];
		}

		return [
			'characterization_path' => $characterizationPath,
			'characterization_sha256' => hash('sha256', $document),
			'main_commit' => $characterization['main_commit'],
			'stable_commit' => $characterization['stable_commit'],
			'migration_hashes' => $characterization['migration_hashes'],
			'cache_objects' => $characterization['cache_objects'],
			'cache_key_schema' => $characterization['cache_key_schema'],
			'query_plan_sha256' => $characterization['query_plan_sha256'],
			'selected_adapter' => $characterization['selected_adapter'],
			'protected_outputs' => $protectedOutputs,
			'revision_ids' => $revisionIds
		];
	}

	/**
	 * The named Plan 01 adapter. It writes universal `quantity_unit_conversions` rows for the
	 * activated mass and volume rules whose units Grocy already owns, then stops: the resolved
	 * cache is rebuilt only by the characterized native INSERT trigger.
	 */
	private function ApplySelectedProjection(string $adapter, array $revisions, array $catalog): int
	{
		if ($adapter !== 'universal_native_rows_v1')
		{
			throw new \RuntimeException('selected_adapter_unsupported');
		}

		$nativeUnits = [];
		foreach ($this->Db->query('SELECT id, name FROM quantity_units ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $unit)
		{
			$key = self::UnitKeyForName((string)$unit['name']);
			if ($key === null)
			{
				continue;
			}
			if (isset($nativeUnits[$key]))
			{
				throw new \RuntimeException('native_unit_ambiguous');
			}
			$nativeUnits[$key] = (int)$unit['id'];
		}

		$existing = $this->Db->prepare('SELECT COUNT(*) FROM quantity_unit_conversions WHERE product_id IS NULL AND from_qu_id = ? AND to_qu_id = ?');
		$insert = $this->Db->prepare('INSERT INTO quantity_unit_conversions (product_id, from_qu_id, to_qu_id, factor) VALUES (NULL, ?, ?, ?)');
		$projected = 0;
		foreach ($revisions as $revision)
		{
			$from = (string)$revision['from_unit_key'];
			$to = (string)$revision['to_unit_key'];
			// D-01: only cataloged universal mass and volume rules may be projected.
			if ((string)$revision['kind'] !== 'universal'
				|| !isset($catalog[$from], $catalog[$to], $nativeUnits[$from], $nativeUnits[$to])
				|| $catalog[$from]['dimension'] !== $catalog[$to]['dimension']
				|| !in_array($catalog[$from]['dimension'], ['mass', 'volume'], true))
			{
				continue;
			}
			$existing->execute([$nativeUnits[$from], $nativeUnits[$to]]);
			if ((int)$existing->fetchColumn() > 0)
			{
				continue;
			}
			$insert->execute([$nativeUnits[$from], $nativeUnits[$to], (string)$revision['factor']]);
			$projected++;
		}
		return $projected;
	}

	private function RecordActivationEvidence(array $bundle, array $characterization, string $evidenceHash): int
	{
		$statement = $this->Db->prepare('INSERT INTO grocy_ai_conversion_activation_evidence (main_commit, stable_commit, characterization_sha256, selected_adapter, cache_key_schema, query_plan_sha256, migration_hashes, cache_objects, protected_outputs_sha256, evidence_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
		$statement->execute([
			$characterization['main_commit'],
			$characterization['stable_commit'],
			(string)$bundle['characterization_sha256'],
			$characterization['selected_adapter'],
			$characterization['cache_key_schema'],
			$characterization['query_plan_sha256'],
			$this->CanonicalJson($characterization['migration_hashes']),
			$this->CanonicalJson($characterization['cache_objects']),
			hash('sha256', $this->CanonicalJson($characterization['protected_outputs'])),
			$evidenceHash
		]);
		return (int)$this->Db->lastInsertId();
	}

	private function ActivationRevisionIds(array $bundle): ?array
	{
		$ids = $bundle['revision_ids'] ?? null;
		if (!is_array($ids) || $ids === [])
		{
			return null;
		}
		$normalized = [];
		foreach ($ids as $id)
		{
			if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1)
			{
				return null;
			}
			$normalized[$id] = true;
		}
		$normalized = array_keys($normalized);
		sort($normalized, SORT_STRING);
		return $normalized;
	}

	/**
	 * @return array<int, array<string, mixed>>|string the eligible revisions, or a bounded blocker
	 */
	private function LoadCandidateRevisions(array $revisionIds): array|string
	{
		$placeholders = implode(', ', array_fill(0, count($revisionIds), '?'));
		$statement = $this->Db->prepare('SELECT id, kind, version, source_name, source_version, from_unit_key, to_unit_key, factor, revision_hash, status FROM grocy_ai_conversion_rule_revisions WHERE id IN (' . $placeholders . ') ORDER BY id');
		$statement->execute($revisionIds);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (count($rows) !== count($revisionIds))
		{
			return 'revision_unknown';
		}

		foreach ($rows as $row)
		{
			if ((string)$row['version'] !== GrocyAiConversionMigration::VERSION)
			{
				return 'revision_version_invalid';
			}
			$expectedSourceVersion = (string)$row['kind'] === 'profile'
				? GrocyAiConversionMigration::PROFILE_SOURCE_VERSION
				: GrocyAiConversionMigration::SOURCE_VERSION;
			if ((string)$row['source_version'] !== $expectedSourceVersion)
			{
				return 'revision_source_version_invalid';
			}
			if ($this->Factor($row['factor']) === null || $this->Factor($row['factor']) <= 0)
			{
				return 'revision_factor_invalid';
			}
			$expectedHash = GrocyAiConversionMigration::RevisionHash(
				(string)$row['kind'], (string)$row['source_name'], (string)$row['source_version'],
				(string)$row['from_unit_key'], (string)$row['to_unit_key'], (string)$row['factor']
			);
			if (!hash_equals($expectedHash, (string)$row['revision_hash']))
			{
				return 'revision_hash_mismatch';
			}
		}
		return $rows;
	}

	private function ValidateImmutableEvidence(array $bundle, array $characterization): ?string
	{
		if (($bundle['main_commit'] ?? null) !== $characterization['main_commit'])
		{
			return 'main_commit_mismatch';
		}
		if (($bundle['stable_commit'] ?? null) !== $characterization['stable_commit'])
		{
			return 'stable_commit_mismatch';
		}
		$migrations = $bundle['migration_hashes'] ?? null;
		if (!is_array($migrations) || $this->CanonicalJson($migrations) !== $this->CanonicalJson($characterization['migration_hashes']))
		{
			return 'migration_hash_mismatch';
		}
		$cacheObjects = $bundle['cache_objects'] ?? null;
		if (!is_array($cacheObjects) || $this->NormalizedCacheObjects($cacheObjects) !== $this->NormalizedCacheObjects($characterization['cache_objects']))
		{
			return 'cache_contract_mismatch';
		}
		if (($bundle['cache_key_schema'] ?? null) !== $characterization['cache_key_schema'])
		{
			return 'cache_key_schema_mismatch';
		}
		if (($bundle['query_plan_sha256'] ?? null) !== $characterization['query_plan_sha256'])
		{
			return 'query_plan_mismatch';
		}
		return $this->ValidateProtectedOutputs($bundle['protected_outputs'] ?? null, $characterization['protected_outputs']);
	}

	private function ValidateProtectedOutputs(mixed $supplied, array $expected): ?string
	{
		if (!is_array($supplied) || count($supplied) !== count($expected))
		{
			return 'protected_outputs_incomplete';
		}
		$byCategory = [];
		foreach ($supplied as $entry)
		{
			if (!is_array($entry) || !is_string($entry['category'] ?? null) || isset($byCategory[$entry['category']]))
			{
				return 'protected_outputs_incomplete';
			}
			$byCategory[(string)$entry['category']] = $entry;
		}
		foreach ($expected as $category => $proof)
		{
			$entry = $byCategory[$category] ?? null;
			if ($entry === null)
			{
				return 'protected_outputs_incomplete';
			}
			$main = is_string($entry['main'] ?? null) ? (string)$entry['main'] : null;
			$stable = is_string($entry['stable'] ?? null) ? (string)$entry['stable'] : null;
			if ($main === null || $stable === null)
			{
				return 'protected_outputs_incomplete';
			}
			// D-13: every protected consumer must be proved equivalent on both maintained branches.
			if ($main !== $stable)
			{
				return 'protected_outputs_unequal';
			}
			if ($main !== $proof['value'] || ($entry['path'] ?? null) !== $proof['path'])
			{
				return 'protected_outputs_mismatch';
			}
		}
		return null;
	}

	/**
	 * Reads the immutable Plan 01 facts out of `04-CHARACTERIZATION.md`. Every field is required
	 * and single-valued: a document that has drifted into an unparseable shape is never guessed at.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ParseCharacterization(string $document): ?array
	{
		if (preg_match_all('/^\|\s*main\s*\|\s*`([0-9a-f]{40})`\s*\|\s*$/m', $document, $mainMatch) !== 1
			|| preg_match_all('/^\|\s*stable\s*\|\s*`([0-9a-f]{40})`\s*\|\s*$/m', $document, $stableMatch) !== 1)
		{
			return null;
		}
		if (preg_match_all('/^- `(migrations\/[0-9]{4}\.sql)` SHA-256: `([0-9a-f]{64})` on both branches\.$/m', $document, $migrationMatch) < 1)
		{
			return null;
		}
		$migrationHashes = [];
		foreach ($migrationMatch[1] as $index => $path)
		{
			if (isset($migrationHashes[$path]))
			{
				return null;
			}
			$migrationHashes[$path] = $migrationMatch[2][$index];
		}
		ksort($migrationHashes);

		if (preg_match('/^- The matching cache objects are (?P<objects>.+)$/m', $document, $cacheMatch) !== 1)
		{
			return null;
		}
		$cacheObjects = $this->ParseCacheObjects($cacheMatch['objects']);
		if ($cacheObjects === null)
		{
			return null;
		}
		if (preg_match_all('/`(ix_[a-z0-9_]+)` for the cache key `(\([^`]+\))` on both branches/m', $document, $keyMatch) !== 1)
		{
			return null;
		}
		array_unshift($cacheObjects, $keyMatch[1][0]);
		if (preg_match_all('/^- The deterministic redacted manifest has query-plan SHA-256 `([0-9a-f]{64})` on main and stable\.$/m', $document, $planMatch) !== 1)
		{
			return null;
		}

		$protected = [];
		if (preg_match_all('/^\| (?P<category>[a-z][a-z-]*) \| (?P<value>[0-9]+(?:\.[0-9]+)?) \| `(?P<path>\/[0-9\/]+)` \|$/m', $document, $protectedMatch, PREG_SET_ORDER) < 1)
		{
			return null;
		}
		foreach ($protectedMatch as $row)
		{
			if (isset($protected[$row['category']]))
			{
				return null;
			}
			$protected[$row['category']] = ['value' => $row['value'], 'path' => $row['path']];
		}

		$selectsNone = preg_match_all('/\*\*No projection is selected\.\*\*/', $document);
		$selectsAdapter = preg_match_all('/\*\*Selected adapter:\*\* `([a-z][a-z0-9_]{0,63})`/', $document, $adapterMatch);
		if ($selectsNone + $selectsAdapter !== 1)
		{
			return null;
		}

		return [
			'main_commit' => $mainMatch[1][0],
			'stable_commit' => $stableMatch[1][0],
			'migration_hashes' => $migrationHashes,
			'cache_objects' => $cacheObjects,
			'cache_key_schema' => $keyMatch[2][0],
			'query_plan_sha256' => $planMatch[1][0],
			'protected_outputs' => $protected,
			'selected_adapter' => $selectsNone === 1 ? 'none' : $adapterMatch[1][0]
		];
	}

	/**
	 * Expands the characterized cache sentence into its exact object names. The trigger suffixes are
	 * written against the `quantity_unit_conversions` prefix, so each one is reconstructed rather
	 * than accepted as free text.
	 */
	private function ParseCacheObjects(string $sentence): ?array
	{
		if (preg_match_all('/`([a-zA-Z_][a-zA-Z0-9_]*)`/', $sentence, $matches) < 1)
		{
			return null;
		}
		$objects = [];
		foreach ($matches[1] as $token)
		{
			$name = str_starts_with($token, '_') ? 'quantity_unit_conversions' . $token : $token;
			if (in_array($name, $objects, true))
			{
				return null;
			}
			$objects[] = $name;
		}
		return $objects;
	}

	/**
	 * The characterized cache objects are an unordered set: the document names the table, the index,
	 * and the three triggers in prose order, so comparison is order-independent but exact.
	 */
	private function NormalizedCacheObjects(array $objects): ?string
	{
		$normalized = [];
		foreach ($objects as $object)
		{
			if (!is_string($object))
			{
				return null;
			}
			$normalized[] = $object;
		}
		sort($normalized, SORT_STRING);
		return $this->CanonicalJson($normalized);
	}

	/**
	 * The checksum of the immutable facts only. Excluding the selected projection is what lets a
	 * maintainer record a chosen adapter without being able to restate any characterized fact.
	 */
	private function CharacterizationFactsHash(array $characterization): string
	{
		unset($characterization['selected_adapter']);
		return hash('sha256', $this->CanonicalJson($characterization));
	}

	private function EvidenceHash(array $bundle, array $characterization, array $revisionIds): string
	{
		return hash('sha256', $this->CanonicalJson([
			'characterization_sha256' => (string)$bundle['characterization_sha256'],
			'main_commit' => $characterization['main_commit'],
			'stable_commit' => $characterization['stable_commit'],
			'migration_hashes' => $characterization['migration_hashes'],
			'cache_objects' => $characterization['cache_objects'],
			'cache_key_schema' => $characterization['cache_key_schema'],
			'query_plan_sha256' => $characterization['query_plan_sha256'],
			'protected_outputs' => $characterization['protected_outputs'],
			'selected_adapter' => $characterization['selected_adapter'],
			'revision_ids' => $revisionIds
		]));
	}

	private function CanonicalJson(mixed $value): string
	{
		return (string)json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
	}

	private function ActivationResult(string $status, array $blockers): array
	{
		return [
			'status' => $status,
			'blockers' => $blockers,
			'evidence_hash' => null,
			'activated_revision_ids' => [],
			'selected_adapter' => null,
			'projected_universal_rows' => 0
		];
	}

	public function InspectSourcedProfile(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		if ($productId < 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $fromUnitKey) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $toUnitKey) !== 1)
		{
			return $this->ProfileUnavailable('profile_request_invalid');
		}

		if (!$this->TaxonomyClassificationsRelationAvailable())
		{
			return $this->ProfileUnavailable('taxonomy_unavailable');
		}
		$classification = $this->Db->prepare('SELECT node.id, node.slug FROM grocy_ai_taxonomy_classifications AS classification INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND classification.leaf_id IS NOT NULL AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2');
		$classification->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION]);
		$leaf = $classification->fetch(PDO::FETCH_ASSOC);
		if (!is_array($leaf))
		{
			return $this->ProfileUnavailable('explicit_taxonomy_required');
		}
		$leafSlug = (string)$leaf['slug'];
		if (preg_match('/baby|pet|frozen|preserved/i', $leafSlug) === 1)
		{
			return $this->ProfileUnavailable('taxonomy_leaf_excluded');
		}

		$profileStatement = $this->Db->prepare('SELECT profile.profile_key, profile.factor, profile.approximate, profile.source_name, profile.source_item_id, profile.source_version, profile.source_basis, profile.status, revision.status AS revision_status, revision.version AS revision_version FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_conversion_profile_revisions AS revision ON revision.id = profile.revision_id WHERE profile.revision_id = ? AND profile.taxonomy_leaf_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$profileStatement->execute([GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, (string)$leaf['id'], $fromUnitKey, $toUnitKey]);
		$profile = $profileStatement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($profile))
		{
			return $this->ProfileUnavailable('profile_unavailable');
		}

		$expected = self::SOURCED_PROFILE_RECORDS[(string)$profile['profile_key']] ?? null;
		$factor = $this->Factor($profile['factor']);
		if ($expected === null
			|| $expected !== [(string)$leaf['id'], $fromUnitKey, $toUnitKey, (string)$profile['factor'], (string)$profile['source_item_id'], (string)$profile['source_basis']]
			|| $factor === null || $factor <= 0 || (int)$profile['approximate'] !== 1 || $profile['status'] !== 'inactive'
			|| $profile['revision_status'] !== 'inactive' || $profile['revision_version'] !== GrocyAiConversionMigration::VERSION
			|| $profile['source_name'] !== GrocyAiConversionMigration::PROFILE_SOURCE_NAME
			|| $profile['source_version'] !== GrocyAiConversionMigration::PROFILE_SOURCE_VERSION
			|| preg_match('/^[1-9][0-9]{0,9}$/D', (string)$profile['source_item_id']) !== 1
			|| trim((string)$profile['source_basis']) === '')
		{
			return $this->ProfileUnavailable('profile_invalid');
		}

		return $this->ProfileDto(
			'inactive', [], trim((string)$profile['factor']), 'mass_volume', (string)$profile['profile_key'], $leafSlug,
			(string)$profile['source_name'], (string)$profile['source_item_id'], (string)$profile['source_version'], (string)$profile['source_basis']
		);
	}

	public function InspectConversionResolution(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		if ($productId < 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $fromUnitKey) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $toUnitKey) !== 1)
		{
			return $this->InspectionUnavailable('resolution_request_invalid');
		}

		$productGraph = $this->InspectProductGraph($productId, $fromUnitKey, $toUnitKey);
		if ($productGraph['blocker'] !== null)
		{
			return $this->InspectionBlocked($productGraph['blocker']);
		}
		if ($productGraph['candidate'] !== null)
		{
			$candidate = $productGraph['candidate'];
			$fromDimension = $this->UnitDimension($fromUnitKey);
			$toDimension = $this->UnitDimension($toUnitKey);
			$dimension = $fromDimension !== null && $fromDimension === $toDimension ? $fromDimension : 'product_scoped';
			return $this->InspectionDto(
				'product_native', [], $candidate['factor_raw'], $dimension, false, 'product_override',
				'Grocy native product conversion', null, 'native', null, null, null, null
			);
		}

		if (!$this->TaxonomyClassificationsRelationAvailable())
		{
			return $this->InspectionUnavailable('taxonomy_unavailable');
		}
		if ($this->EligibleProfileCount($productId, $fromUnitKey, $toUnitKey) > 1)
		{
			return $this->InspectionBlocked('same_rank_collision');
		}
		$profile = $this->InspectSourcedProfile($productId, $fromUnitKey, $toUnitKey);
		if ($profile['status'] === 'inactive')
		{
			return $this->InspectionDto(
				'inactive', [], $profile['factor'], $profile['dimension'], true, 'food_profile',
				$profile['source_name'], $profile['source_version'], 'inactive', $profile['source_item_id'],
				$profile['profile_key'], $profile['taxonomy_leaf'], $profile['inactive_revision_id']
			);
		}
		if (($profile['blockers'][0] ?? null) === 'profile_invalid')
		{
			return $this->InspectionBlocked($this->InvalidProfileBlocker($productId, $fromUnitKey, $toUnitKey));
		}

		$catalog = $this->Catalog();
		$from = $catalog[$fromUnitKey] ?? null;
		$to = $catalog[$toUnitKey] ?? null;
		if ($from !== null && $to !== null && $from['dimension'] === $to['dimension'])
		{
			$universalBlocker = $this->ValidateInspectionUniversalGraph($catalog);
			if ($universalBlocker !== null)
			{
				return $this->InspectionBlocked($universalBlocker);
			}
			$factor = $from['metric_factor'] / $to['metric_factor'];
			return $this->InspectionDto(
				'inactive', [], (string)$factor, $from['dimension'], false, 'universal', 'NIST SP 811',
				GrocyAiConversionMigration::SOURCE_VERSION, 'inactive', null, null, null, GrocyAiConversionMigration::INACTIVE_REVISION_ID
			);
		}

		return $this->InspectionUnavailable((string)($profile['blockers'][0] ?? 'conversion_unavailable'));
	}

	/**
	 * Read-only maintainer diagnostics. It reports current evidence only: it never bootstraps
	 * module schema, activates a revision, projects a rule, or touches a native or product row.
	 */
	public function ValidateConversionCoverage(): array
	{
		$report = [
			'ruleset_version' => GrocyAiConversionMigration::VERSION,
			'source_version' => GrocyAiConversionMigration::SOURCE_VERSION,
			'profile_source_version' => GrocyAiConversionMigration::PROFILE_SOURCE_VERSION,
			'gate' => [
				'state' => 'inactive',
				'main_branch_evidence' => 'absent',
				'stable_branch_evidence' => 'absent',
				'selected_projection' => 'none'
			],
			'counts' => [
				'catalog_units' => 0,
				'universal_rules' => 0,
				'profiles' => 0,
				'covered_pairs' => 0,
				'missing_paths' => 0,
				'unavailable_profiles' => 0,
				'redundant_product_overrides' => 0,
				'blockers' => 0
			],
			'blockers' => [],
			'effective_sources' => [],
			'protected_behavior' => []
		];
		foreach (self::PROTECTED_CONSUMER_CATEGORIES as $category)
		{
			$report['protected_behavior'][] = ['category' => $category, 'state' => 'unverified'];
		}

		if (!$this->ModuleRelationAvailable('grocy_ai_conversion_catalog_units')
			|| !$this->ModuleRelationAvailable('grocy_ai_conversion_rules')
			|| !$this->ModuleRelationAvailable('grocy_ai_conversion_revisions'))
		{
			// An unavailable or stale module schema is reported as an inactive gate with no
			// invented blockers rather than being bootstrapped into existence.
			$report['effective_sources'] = $this->CoverageEffectiveSources(0, 0, 0);
			return $report;
		}

		$catalog = $this->Catalog();
		$report['counts']['catalog_units'] = count($catalog);

		$rules = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$rules->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$ruleRows = $rules->fetchAll(PDO::FETCH_ASSOC);
		$report['counts']['universal_rules'] = count($ruleRows);

		$profileCount = 0;
		if ($this->ModuleRelationAvailable('grocy_ai_conversion_profiles'))
		{
			$profiles = $this->Db->prepare('SELECT COUNT(*) FROM grocy_ai_conversion_profiles WHERE revision_id = ?');
			$profiles->execute([GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID]);
			$profileCount = (int)$profiles->fetchColumn();
		}
		$report['counts']['profiles'] = $profileCount;

		$ruled = [];
		foreach ($ruleRows as $row)
		{
			$ruled[(string)$row['from_unit_key'] . '>' . (string)$row['to_unit_key']] = true;
		}
		foreach ($catalog as $fromKey => $from)
		{
			foreach ($catalog as $toKey => $to)
			{
				if ($fromKey === $toKey || $from['dimension'] !== $to['dimension'])
				{
					continue;
				}
				if (isset($ruled[$fromKey . '>' . $toKey]) || isset($ruled[$toKey . '>' . $fromKey]))
				{
					$report['counts']['covered_pairs']++;
				}
				else
				{
					$report['counts']['missing_paths']++;
				}
			}
		}

		$report['counts']['unavailable_profiles'] = $this->CoverageUnavailableProfiles();
		$report['counts']['redundant_product_overrides'] = $this->CoverageRedundantProductOverrides($catalog);

		$blockers = [];
		$universalBlocker = $this->ValidateInspectionUniversalGraph($catalog);
		if ($universalBlocker !== null)
		{
			$blockers[] = $this->CoverageBlockerCategory($universalBlocker);
		}
		$tallied = [];
		foreach ($blockers as $category)
		{
			$tallied[$category] = ($tallied[$category] ?? 0) + 1;
		}
		ksort($tallied);
		foreach ($tallied as $category => $count)
		{
			$report['blockers'][] = ['category' => $category, 'count' => $count];
		}
		$report['counts']['blockers'] = count($blockers);

		if ($report['counts']['blockers'] > 0)
		{
			$report['gate']['state'] = 'blocked';
		}
		elseif (($activation = $this->RecordedActivation()) !== null)
		{
			// D-12: the gate reports itself ready only from immutable evidence actually recorded by
			// `ActivateVerifiedRuleset`. `04-CHARACTERIZATION.md` alone is never live evidence.
			$report['gate']['state'] = 'ready';
			$report['gate']['main_branch_evidence'] = 'present';
			$report['gate']['stable_branch_evidence'] = 'present';
			$report['gate']['selected_projection'] = $activation['selected_adapter'];
			$report['protected_behavior'] = [];
			foreach (self::PROTECTED_CONSUMER_CATEGORIES as $category)
			{
				$report['protected_behavior'][] = ['category' => $category, 'state' => 'passed'];
			}
		}

		$report['effective_sources'] = $this->CoverageEffectiveSources(
			$report['counts']['redundant_product_overrides'],
			$profileCount,
			count($ruleRows)
		);

		return $report;
	}

	/**
	 * The single recorded activation, if one exists. A ledger row only counts when at least one
	 * reusable revision is actually bound to it, so an orphaned or hand-inserted row reports nothing.
	 *
	 * @return array{selected_adapter: string}|null
	 */
	private function RecordedActivation(): ?array
	{
		if (!$this->ModuleRelationAvailable('grocy_ai_conversion_activation_evidence')
			|| !$this->ModuleRelationAvailable('grocy_ai_conversion_rule_revisions'))
		{
			return null;
		}
		$statement = $this->Db->query("SELECT evidence.selected_adapter FROM grocy_ai_conversion_activation_evidence AS evidence INNER JOIN grocy_ai_conversion_rule_revisions AS revision ON revision.activation_evidence_id = evidence.id WHERE revision.status = 'active' GROUP BY evidence.id, evidence.selected_adapter ORDER BY evidence.id");
		$rows = $statement->fetchAll(PDO::FETCH_COLUMN);
		if (count($rows) !== 1 || !in_array((string)$rows[0], GrocyAiConversionMigration::SELECTED_ADAPTERS, true))
		{
			return null;
		}
		return ['selected_adapter' => (string)$rows[0]];
	}

	private function CoverageEffectiveSources(int $productOverrides, int $profiles, int $universalRules): array
	{
		return [
			['source' => 'product_override', 'count' => $productOverrides],
			['source' => 'food_profile', 'count' => $profiles],
			['source' => 'universal', 'count' => $universalRules]
		];
	}

	private function CoverageBlockerCategory(string $blocker): string
	{
		return [
			'malformed_factor' => 'malformed_factor',
			'tolerance_drift' => 'reciprocal_inconsistency',
			'reciprocal_inconsistency' => 'reciprocal_inconsistency',
			'reciprocal_mismatch' => 'reciprocal_inconsistency',
			'factor_tolerance' => 'reciprocal_inconsistency',
			'dimension_mismatch' => 'dimension_mismatch',
			'mass_volume' => 'dimension_mismatch',
			'same_rank_collision' => 'competing_path',
			'competing_path' => 'competing_path',
			'competing_paths' => 'competing_path',
			'cycle_detected' => 'cycle_detected'
		][$blocker] ?? 'provenance_mismatch';
	}

	private function CoverageUnavailableProfiles(): int
	{
		if (!$this->ModuleRelationAvailable('grocy_ai_conversion_profiles')
			|| !$this->ModuleRelationAvailable('grocy_ai_taxonomy_nodes'))
		{
			return 0;
		}
		$leaves = $this->Db->prepare('SELECT id, slug FROM grocy_ai_taxonomy_nodes WHERE version = ? AND parent_id IS NOT NULL AND depth = 2 ORDER BY id');
		$leaves->execute([GrocyAiTaxonomyMigration::VERSION]);
		$profile = $this->Db->prepare('SELECT COUNT(*) FROM grocy_ai_conversion_profiles WHERE revision_id = ? AND taxonomy_leaf_id = ?');
		$unavailable = 0;
		foreach ($leaves->fetchAll(PDO::FETCH_ASSOC) as $leaf)
		{
			if (preg_match('/baby|pet|frozen|preserved/i', (string)$leaf['slug']) === 1)
			{
				continue;
			}
			$profile->execute([GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, (string)$leaf['id']]);
			if ((int)$profile->fetchColumn() === 0)
			{
				$unavailable++;
			}
		}
		return $unavailable;
	}

	private function CoverageRedundantProductOverrides(array $catalog): int
	{
		$rows = $this->Db->query('SELECT conversion.factor, from_unit.name AS from_name, to_unit.name AS to_name FROM quantity_unit_conversions AS conversion INNER JOIN quantity_units AS from_unit ON from_unit.id = conversion.from_qu_id INNER JOIN quantity_units AS to_unit ON to_unit.id = conversion.to_qu_id WHERE conversion.product_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
		$redundant = 0;
		foreach ($rows as $row)
		{
			$from = $catalog[(string)self::UnitKeyForName((string)$row['from_name'])] ?? null;
			$to = $catalog[(string)self::UnitKeyForName((string)$row['to_name'])] ?? null;
			$factor = $this->Factor($row['factor']);
			if ($from === null || $to === null || $factor === null || $factor <= 0
				|| $from['dimension'] !== $to['dimension']
				|| $from['metric_factor'] === null || $to['metric_factor'] === null || $to['metric_factor'] <= 0)
			{
				continue;
			}
			// The override restates a factor the universal catalog already derives.
			if ($this->RelativeDifference($factor, $from['metric_factor'] / $to['metric_factor']) <= self::COVERAGE_REDUNDANCY_TOLERANCE)
			{
				$redundant++;
			}
		}
		return $redundant;
	}

	private function ModuleRelationAvailable(string $relation): bool
	{
		$statement = $this->Db->prepare('SELECT 1 FROM sqlite_master WHERE name = ? LIMIT 1');
		$statement->execute([$relation]);
		return $statement->fetchColumn() !== false;
	}

	private function ValidateInspectionUniversalGraph(array $catalog): ?string
	{
		$revision = $this->InactiveRevision();
		if ($revision === null || (string)$revision['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
		{
			return 'provenance_mismatch';
		}
		foreach ($catalog as $unit)
		{
			if ($unit['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
			{
				return 'provenance_mismatch';
			}
			if ($unit['metric_factor'] === null || $unit['metric_factor'] <= 0)
			{
				return 'malformed_factor';
			}
			if (!in_array($unit['dimension'], ['mass', 'volume'], true))
			{
				return 'dimension_mismatch';
			}
		}

		$statement = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$rules = $statement->fetchAll(PDO::FETCH_ASSOC);
		usort($rules, static function(array $left, array $right): int
		{
			return [(string)$left['from_unit_key'], (string)$left['to_unit_key'], (string)$left['factor']]
				<=> [(string)$right['from_unit_key'], (string)$right['to_unit_key'], (string)$right['factor']];
		});
		$edges = [];
		$pairs = [];
		foreach ($rules as $rule)
		{
			$from = (string)$rule['from_unit_key'];
			$to = (string)$rule['to_unit_key'];
			$factor = $this->Factor($rule['factor']);
			if ($factor === null || $factor <= 0)
			{
				return 'malformed_factor';
			}
			if ((string)$rule['source_version'] !== GrocyAiConversionMigration::SOURCE_VERSION)
			{
				return 'provenance_mismatch';
			}
			if (!isset($catalog[$from], $catalog[$to]) || $catalog[$from]['dimension'] !== $catalog[$to]['dimension'])
			{
				return 'dimension_mismatch';
			}
			$key = $from . '>' . $to;
			if (isset($pairs[$key]))
			{
				return 'same_rank_collision';
			}
			$expected = $catalog[$from]['metric_factor'] / $catalog[$to]['metric_factor'];
			if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
			{
				return 'tolerance_drift';
			}
			$pairs[$key] = $factor;
			$edges[] = ['from' => $from, 'to' => $to, 'factor' => $factor];
		}
		foreach ($pairs as $key => $factor)
		{
			[$from, $to] = explode('>', $key, 2);
			$reverse = $pairs[$to . '>' . $from] ?? null;
			if ($reverse !== null && $this->RelativeDifference($factor * $reverse, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return 'reciprocal_inconsistency';
			}
		}
		return $this->InspectionGraphBlocker($edges, $pairs);
	}

	private function InspectProductGraph(int $productId, string $fromUnitKey, string $toUnitKey): array
	{
		$statement = $this->Db->prepare('SELECT conversion.id, conversion.factor, from_unit.name AS from_name, to_unit.name AS to_name FROM quantity_unit_conversions AS conversion INNER JOIN quantity_units AS from_unit ON from_unit.id = conversion.from_qu_id INNER JOIN quantity_units AS to_unit ON to_unit.id = conversion.to_qu_id WHERE conversion.product_id = ?');
		$statement->execute([$productId]);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		usort($rows, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
		$edges = [];
		$pairs = [];
		$candidates = [];
		foreach ($rows as $row)
		{
			$from = $this->UnitKey((string)$row['from_name']);
			$to = $this->UnitKey((string)$row['to_name']);
			if ($from === null || $to === null)
			{
				continue;
			}
			$factor = $this->Factor($row['factor']);
			if ($factor === null || $factor <= 0)
			{
				return ['blocker' => 'malformed_factor', 'candidate' => null];
			}
			$key = $from . '>' . $to;
			if (isset($pairs[$key]))
			{
				return ['blocker' => 'same_rank_collision', 'candidate' => null];
			}
			$pairs[$key] = $factor;
			$edge = ['from' => $from, 'to' => $to, 'factor' => $factor, 'factor_raw' => trim((string)$row['factor'])];
			$edges[] = $edge;
			if ($from === $fromUnitKey && $to === $toUnitKey)
			{
				$candidates[] = $edge;
			}
		}
		foreach ($pairs as $key => $factor)
		{
			[$from, $to] = explode('>', $key, 2);
			$reverse = $pairs[$to . '>' . $from] ?? null;
			if ($reverse !== null && $this->RelativeDifference($factor * $reverse, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return ['blocker' => 'reciprocal_inconsistency', 'candidate' => null];
			}
		}
		$blocker = $this->InspectionGraphBlocker($edges, $pairs);
		if ($blocker !== null)
		{
			return ['blocker' => $blocker, 'candidate' => null];
		}
		if (count($candidates) === 1)
		{
			return ['blocker' => null, 'candidate' => $candidates[0]];
		}
		$adjacency = $this->InspectionAdjacency($edges);
		$paths = [];
		$this->CollectInspectionPaths($fromUnitKey, 1.0, [$fromUnitKey => true], $adjacency, $paths);
		if (isset($paths[$toUnitKey][0]))
		{
			return ['blocker' => null, 'candidate' => ['factor_raw' => (string)$paths[$toUnitKey][0]]];
		}
		return ['blocker' => null, 'candidate' => null];
	}

	private function InspectionGraphBlocker(array $edges, array $pairs): ?string
	{
		$adjacency = $this->InspectionAdjacency($edges);
		$state = [];
		foreach (array_keys($adjacency) as $node)
		{
			if ($this->HasInspectionCycle($node, null, $adjacency, $pairs, $state))
			{
				return 'cycle_detected';
			}
		}

		foreach (array_keys($adjacency) as $source)
		{
			$paths = [];
			$this->CollectInspectionPaths($source, 1.0, [$source => true], $adjacency, $paths);
			foreach ($paths as $factors)
			{
				if (count($factors) < 2)
				{
					continue;
				}
				$expected = $factors[0];
				foreach (array_slice($factors, 1) as $factor)
				{
					if ($this->RelativeDifference($factor, $expected) > self::RELATIVE_TOLERANCE)
					{
						return 'competing_paths';
					}
				}
			}
		}
		return null;
	}

	private function InspectionAdjacency(array $edges): array
	{
		$adjacency = [];
		foreach ($edges as $edge)
		{
			$adjacency[$edge['from']][] = $edge;
		}
		foreach ($adjacency as &$neighbors)
		{
			usort($neighbors, static fn(array $left, array $right): int => [$left['to'], $left['factor']] <=> [$right['to'], $right['factor']]);
		}
		unset($neighbors);
		return $adjacency;
	}

	private function HasInspectionCycle(string $node, ?string $parent, array $adjacency, array $pairs, array &$state): bool
	{
		if (($state[$node] ?? 0) === 1)
		{
			return true;
		}
		if (($state[$node] ?? 0) === 2)
		{
			return false;
		}
		$state[$node] = 1;
		foreach ($adjacency[$node] ?? [] as $edge)
		{
			$neighbor = $edge['to'];
			if ($neighbor === $parent && isset($pairs[$neighbor . '>' . $node]))
			{
				continue;
			}
			if ($this->HasInspectionCycle($neighbor, $node, $adjacency, $pairs, $state))
			{
				return true;
			}
		}
		$state[$node] = 2;
		return false;
	}

	private function CollectInspectionPaths(string $node, float $factor, array $visited, array $adjacency, array &$paths): void
	{
		foreach ($adjacency[$node] ?? [] as $edge)
		{
			$target = $edge['to'];
			if (isset($visited[$target]))
			{
				continue;
			}
			$pathFactor = $factor * $edge['factor'];
			$paths[$target][] = $pathFactor;
			if (count($paths[$target]) > 64)
			{
				continue;
			}
			$nextVisited = $visited;
			$nextVisited[$target] = true;
			$this->CollectInspectionPaths($target, $pathFactor, $nextVisited, $adjacency, $paths);
		}
	}

	private function EligibleProfileCount(int $productId, string $fromUnitKey, string $toUnitKey): int
	{
		$statement = $this->Db->prepare('SELECT COUNT(*) FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_taxonomy_classifications AS classification ON classification.leaf_id = profile.taxonomy_leaf_id INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2 AND profile.revision_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION, GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, $fromUnitKey, $toUnitKey]);
		return (int)$statement->fetchColumn();
	}

	private function InvalidProfileBlocker(int $productId, string $fromUnitKey, string $toUnitKey): string
	{
		$statement = $this->Db->prepare('SELECT profile.profile_key, profile.factor FROM grocy_ai_conversion_profiles AS profile INNER JOIN grocy_ai_taxonomy_classifications AS classification ON classification.leaf_id = profile.taxonomy_leaf_id INNER JOIN grocy_ai_taxonomy_nodes AS node ON node.id = classification.leaf_id WHERE classification.product_id = ? AND classification.ruleset_version = ? AND node.version = ? AND node.parent_id IS NOT NULL AND node.depth = 2 AND profile.revision_id = ? AND profile.from_unit_key = ? AND profile.to_unit_key = ?');
		$statement->execute([$productId, GrocyAiTaxonomyMigration::VERSION, GrocyAiTaxonomyMigration::VERSION, GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID, $fromUnitKey, $toUnitKey]);
		$profile = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($profile))
		{
			return 'profile_invalid';
		}
		$factor = $this->Factor($profile['factor']);
		if ($factor === null || $factor <= 0)
		{
			return 'malformed_factor';
		}
		$expected = self::SOURCED_PROFILE_RECORDS[(string)$profile['profile_key']] ?? null;
		$expectedFactor = is_array($expected) ? $this->Factor($expected[3] ?? null) : null;
		if ($expectedFactor !== null && $this->RelativeDifference($factor, $expectedFactor) > self::RELATIVE_TOLERANCE)
		{
			return 'tolerance_drift';
		}
		return 'profile_invalid';
	}

	private function CandidateUnits(array $candidate): ?array
	{
		$fromId = $this->PositiveInteger($candidate['from_qu_id'] ?? null);
		$toId = $this->PositiveInteger($candidate['to_qu_id'] ?? null);
		if ($fromId === null || $toId === null)
		{
			return null;
		}
		$statement = $this->Db->prepare('SELECT id, name FROM quantity_units WHERE id IN (?, ?)');
		$statement->execute([$fromId, $toId]);
		$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
		if (count($rows) !== 2)
		{
			return null;
		}
		$byId = [];
		foreach ($rows as $row)
		{
			$byId[(int)$row['id']] = (string)$row['name'];
		}
		if (!isset($byId[$fromId], $byId[$toId]))
		{
			return null;
		}
		return [
			'from_name' => $byId[$fromId],
			'to_name' => $byId[$toId],
			'from_key' => $this->UnitKey($byId[$fromId]),
			'to_key' => $this->UnitKey($byId[$toId])
		];
	}

	private function Catalog(): array
	{
		$rows = $this->Db->query('SELECT unit_key, dimension, metric_factor, source_version FROM grocy_ai_conversion_catalog_units')->fetchAll(PDO::FETCH_ASSOC);
		$catalog = [];
		foreach ($rows as $row)
		{
			$catalog[(string)$row['unit_key']] = [
				'dimension' => (string)$row['dimension'],
				'metric_factor' => $this->Factor($row['metric_factor']),
				'source_version' => (string)$row['source_version']
			];
		}
		return $catalog;
	}

	private function InactiveRevision(): ?array
	{
		$statement = $this->Db->prepare('SELECT id, status, source_version FROM grocy_ai_conversion_revisions WHERE id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$revision = $statement->fetch(PDO::FETCH_ASSOC);
		if (!is_array($revision) || $revision['status'] !== 'inactive')
		{
			return null;
		}
		return $revision;
	}

	private function ValidateCatalogGraph(array $catalog, string $sourceVersion): ?string
	{
		$statement = $this->Db->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ?');
		$statement->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
		$edges = [];
		$direct = [];
		$rules = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rule)
		{
			$from = (string)$rule['from_unit_key'];
			$to = (string)$rule['to_unit_key'];
			$factor = $this->Factor($rule['factor']);
			if ((string)$rule['source_version'] !== $sourceVersion)
			{
				return 'catalog_rule_source_version_invalid';
			}
			if ($factor === null || $factor <= 0 || !isset($catalog[$from], $catalog[$to]) || $catalog[$from]['dimension'] !== $catalog[$to]['dimension']
				|| $catalog[$from]['metric_factor'] === null || $catalog[$from]['metric_factor'] <= 0 || $catalog[$to]['metric_factor'] === null || $catalog[$to]['metric_factor'] <= 0)
			{
				return 'catalog_rule_invalid';
			}
			$key = $from . '>' . $to;
			if (isset($direct[$key]) && $this->RelativeDifference($direct[$key], $factor) > self::RELATIVE_TOLERANCE)
			{
				return 'competing_path';
			}
			$reverseKey = $to . '>' . $from;
			if (isset($direct[$reverseKey]) && $this->RelativeDifference($direct[$reverseKey] * $factor, 1.0) > self::RELATIVE_TOLERANCE)
			{
				return 'reciprocal_mismatch';
			}
			$direct[$key] = $factor;
			$rules[] = ['from' => $from, 'to' => $to, 'factor' => $factor];
		}

		foreach ($rules as $rule)
		{
			$from = $rule['from'];
			$to = $rule['to'];
			$factor = $rule['factor'];
			if ($this->RelativeDifference($factor, $catalog[$from]['metric_factor'] / $catalog[$to]['metric_factor']) > self::RELATIVE_TOLERANCE)
			{
				return 'catalog_rule_invalid';
			}
			$edges[$from][] = $to;
			$edges[$to][] = $from;
		}

		$visited = [];
		foreach (array_keys($edges) as $node)
		{
			if ($this->HasCycle($node, null, $edges, $visited))
			{
				return 'cycle_detected';
			}
		}
		return null;
	}

	private function HasCycle(string $node, ?string $parent, array $edges, array &$visited): bool
	{
		if (isset($visited[$node]))
		{
			return false;
		}
		$visited[$node] = true;
		foreach ($edges[$node] ?? [] as $neighbor)
		{
			if ($neighbor === $parent)
			{
				continue;
			}
			if (isset($visited[$neighbor]) || $this->HasCycle($neighbor, $node, $edges, $visited))
			{
				return true;
			}
		}
		return false;
	}

	private function Dto(string $status, string $scope, array $blockers, ?string $factor, ?string $dimension, ?string $sourceVersion = null): array
	{
		return [
			'status' => $status,
			'scope' => $scope,
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'source_version' => $sourceVersion ?? GrocyAiConversionMigration::SOURCE_VERSION,
			'inactive_revision_id' => GrocyAiConversionMigration::INACTIVE_REVISION_ID
		];
	}

	private function Blocked(string $scope, string $blocker, ?string $sourceVersion = null): array
	{
		return $this->Dto('blocked', $scope, [$blocker], null, null, $sourceVersion);
	}

	private function ProfileUnavailable(string $blocker): array
	{
		return $this->ProfileDto('unavailable', [$blocker], null, null, null, null, null, null, null, null);
	}

	private function TaxonomyClassificationsRelationAvailable(): bool
	{
		$statement = $this->Db->prepare('SELECT 1 FROM sqlite_master WHERE name = ? LIMIT 1');
		$statement->execute(['grocy_ai_taxonomy_classifications']);
		return $statement->fetchColumn() !== false;
	}

	private function ProfileDto(string $status, array $blockers, ?string $factor, ?string $dimension, ?string $profileKey, ?string $taxonomyLeaf, ?string $sourceName, ?string $sourceItemId, ?string $sourceVersion, ?string $sourceBasis): array
	{
		return [
			'status' => $status,
			'scope' => 'food_profile',
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'approximate' => true,
			'profile_key' => $profileKey,
			'taxonomy_leaf' => $taxonomyLeaf,
			'source_name' => $sourceName,
			'source_item_id' => $sourceItemId,
			'source_version' => $sourceVersion,
			'source_basis' => $sourceBasis,
			'inactive_revision_id' => GrocyAiConversionMigration::INACTIVE_PROFILE_REVISION_ID
		];
	}

	private function InspectionBlocked(string $blocker): array
	{
		return $this->InspectionDto('blocked', [$blocker], null, null, null, null, null, null, null, null, null, null, null);
	}

	private function InspectionUnavailable(string $blocker): array
	{
		return $this->InspectionDto('unavailable', [$blocker], null, null, null, null, null, null, null, null, null, null, null);
	}

	private function InspectionDto(string $status, array $blockers, ?string $factor, ?string $dimension, ?bool $approximate, ?string $winnerSource, ?string $sourceName, ?string $sourceVersion, ?string $sourceStatus, ?string $sourceItemId, ?string $profileKey, ?string $taxonomyLeaf, ?string $inactiveRevisionId): array
	{
		return [
			'status' => $status,
			'blockers' => $blockers,
			'factor' => $factor,
			'dimension' => $dimension,
			'approximate' => $approximate,
			'winner_source' => $winnerSource,
			'source_name' => $sourceName,
			'source_version' => $sourceVersion,
			'source_status' => $sourceStatus,
			'source_item_id' => $sourceItemId,
			'profile_key' => $profileKey,
			'taxonomy_leaf' => $taxonomyLeaf,
			'precedence' => 'product_override>food_profile>universal',
			'inactive_revision_id' => $inactiveRevisionId
		];
	}

	private function Factor(mixed $value): ?float
	{
		if (!is_string($value) && !is_int($value) && !is_float($value))
		{
			return null;
		}
		$raw = trim((string)$value);
		if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $raw) !== 1)
		{
			return null;
		}
		$factor = (float)$raw;
		return is_finite($factor) ? $factor : null;
	}

	private function CandidateFactor(array $candidate): string
	{
		return trim((string)$candidate['factor']);
	}

	private function PositiveInteger(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}
		if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1)
		{
			return (int)$value;
		}
		return null;
	}

	private function UnitKey(string $name): ?string
	{
		return self::UnitKeyForName($name);
	}

	/**
	 * Single owner of the Grocy quantity-unit-name to reusable catalog-key mapping.
	 * Inspection callers outside this service must use this predicate rather than
	 * restating the table, so the two cannot drift.
	 */
	public static function UnitKeyForName(string $name): ?string
	{
		$normalized = strtolower(trim($name));
		$normalized = str_replace(['-', '_'], ' ', $normalized);
		$normalized = (string)preg_replace('/\s+/', ' ', $normalized);
		return [
			'mg' => 'mg', 'milligram' => 'mg', 'milligrams' => 'mg',
			'g' => 'g', 'gram' => 'g', 'grams' => 'g',
			'kg' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg',
			'oz' => 'oz', 'ounce' => 'oz', 'ounces' => 'oz',
			'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
			'ml' => 'ml', 'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
			'l' => 'l', 'liter' => 'l', 'liters' => 'l', 'litre' => 'l', 'litres' => 'l',
			'tsp' => 'tsp', 'teaspoon' => 'tsp', 'teaspoons' => 'tsp',
			'tbsp' => 'tbsp', 'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp',
			'cup' => 'cup', 'cups' => 'cup',
			'fl oz' => 'fl_oz', 'fluid ounce' => 'fl_oz', 'fluid ounces' => 'fl_oz',
			'pint' => 'pint', 'pints' => 'pint', 'quart' => 'quart', 'quarts' => 'quart', 'gallon' => 'gallon', 'gallons' => 'gallon'
		][$normalized] ?? null;
	}

	private function IsCountUnit(string $name): bool
	{
		$normalized = strtolower(trim($name));
		return preg_match('/^(?:pack|packs|can|cans|bottle|bottles|piece|pieces|count|counts)$/D', $normalized) === 1;
	}

	private function UnitDimension(string $unitKey): ?string
	{
		if (in_array($unitKey, ['mg', 'g', 'kg', 'oz', 'lb'], true))
		{
			return 'mass';
		}
		if (in_array($unitKey, ['ml', 'l', 'tsp', 'tbsp', 'cup', 'fl_oz', 'pint', 'quart', 'gallon'], true))
		{
			return 'volume';
		}
		return null;
	}

	private function RelativeDifference(float $actual, float $expected): float
	{
		return abs($actual - $expected) / max(abs($expected), 1.0);
	}
}
