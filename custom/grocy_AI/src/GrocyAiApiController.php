<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\Api\BaseApiController;
use Grocy\Controllers\Users\User;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiBarcodeService;
use GrocyAI\Services\GrocyAiBulkService;
use GrocyAI\Services\GrocyAiCaptureService;
use GrocyAI\Services\GrocyAiConversionMigration;
use GrocyAI\Services\GrocyAiDiagnostic;
use GrocyAI\Services\GrocyAiService;
use GrocyAI\Services\GrocyAiServiceException;
use GrocyAI\Services\GrocyAiTaxonomyService;
use GrocyAI\Services\GrocyAiConversionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiApiController extends BaseApiController
{
	/**
	 * Request-body selector (Phase 6 / 06-07) that routes a generation to the conflict-first classification
	 * generator (`GenerateClassificationPlan`). It is NOT a stored plan operation_type — the classification
	 * plan header remains `taxonomy_assignment` (byte-identical to 06-05); this token only chooses which
	 * server-side generator runs, so the browser can still never supply proposed values or operations.
	 */
	private const CLASSIFICATION_REVIEW_SELECTOR = 'classification_review';

	public function Status(Request $request, Response $response, array $args): Response
	{
		return $this->ApiResponse($response, (new GrocyAiService())->GetStatus());
	}

	public function EnrichByUpc(Request $request, Response $response, array $args): Response
	{
		$traceContext = GrocyAiDiagnostic::CreateTraceContext($request->getHeaderLine('traceparent'));
		$currentProductId = self::CurrentProductId($request);

		try
		{
			$barcodeService = new GrocyAiBarcodeService(null, $currentProductId);
			$guarded = $barcodeService->ResolveBeforeProvider(
				$args['upc'],
				static function () use ($request): void
				{
					User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
				},
				static fn(): array => (new GrocyAiService())->EnrichByUpc($args['upc'], $traceContext)
			);
			$ownership = $guarded['ownership'];
			if ($guarded['provider_result'] === null)
			{
				$result = [
					'contract_version' => 2,
					'outcome' => 'found',
					'barcode' => $ownership,
					'suggestions' => [],
					'media' => [],
					'warnings' => [],
					'diagnostics' => ['trace_id' => $traceContext['trace_id']]
				];
			}
			else
			{
				$result = $guarded['provider_result'];
				$result['barcode'] = $ownership;
				// The companion response has passed the closed Phase 2 contract. Only this
				// server-owned response may update the module evidence snapshot.
				(new GrocyAiTaxonomyService())->ReconcileEnrichmentEvidence($currentProductId, $result);
			}
			return $this->DiagnosticResponse($response, $result);
		}
		catch (\InvalidArgumentException)
		{
			return $this->DiagnosticResponse(
				$response,
				GrocyAiDiagnostic::FailureEnvelope($traceContext, 'provider_error', 'error', 'invalid_gtin'),
				400
			);
		}
		catch (\LogicException)
		{
			return $this->DiagnosticResponse(
				$response,
				GrocyAiDiagnostic::FailureEnvelope($traceContext, 'provider_error', 'unavailable', 'not_configured'),
				503
			);
		}
		catch (GrocyAiServiceException $ex)
		{
			return $this->DiagnosticResponse(
				$response,
				GrocyAiDiagnostic::FailureEnvelope(
					$traceContext,
					$ex->GetDiagnosticOutcome(),
					$ex->GetDiagnosticStatus(),
					$ex->GetDiagnosticErrorCode()
				),
				$ex->GetHttpStatus()
			);
		}
		catch (\RuntimeException)
		{
			return $this->DiagnosticResponse(
				$response,
				GrocyAiDiagnostic::FailureEnvelope($traceContext, 'provider_error', 'error', 'provider_error'),
				502
			);
		}
	}

	public function ResolveBarcode(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			$result = (new GrocyAiBarcodeService(null, self::CurrentProductId($request)))->ResolveOwner($args['barcode']);
			return $this->ApiResponse($response, $result);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid barcode', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Barcode ownership unavailable', 409);
		}
	}

	public function ProductTaxonomy(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$productId = $args['productId'] ?? null;
		if (!is_string($productId) || preg_match('/^[1-9][0-9]{0,9}$/D', $productId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid product', 400);
		}

		try
		{
			return $this->ApiResponse($response, (new GrocyAiTaxonomyService())->ReadProductTaxonomy((int)$productId));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid product', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Product unavailable', 404);
		}
	}

	public function AssignProductTaxonomy(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$productId = $args['productId'] ?? null;
		$assignment = $request->getParsedBody();
		if (!is_string($productId) || preg_match('/^[1-9][0-9]{0,9}$/D', $productId) !== 1 || !is_array($assignment))
		{
			return $this->GenericErrorResponse($response, 'Invalid taxonomy assignment', 400);
		}

		try
		{
			return $this->ApiResponse($response, (new GrocyAiTaxonomyService())->AssignProductTaxonomy((int)$productId, $assignment));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid taxonomy assignment', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Product unavailable', 404);
		}
	}

	public function ValidateConversion(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$query = $request->getQueryParams();
		$candidate = array_intersect_key($query, array_flip([
			'product_id', 'from_qu_id', 'to_qu_id', 'factor', 'inactive_revision_id', 'source_version'
		]));
		$objectId = $query['object_id'] ?? null;
		$objectId = is_string($objectId) && preg_match('/^[1-9][0-9]{0,9}$/D', $objectId) === 1 ? (int)$objectId : null;

		try
		{
			$database = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$schemaObjects = $database->query("SELECT type || ':' || name FROM sqlite_master WHERE name IN (
				'grocy_ai_conversion_migrations', 'grocy_ai_conversion_catalog_units', 'grocy_ai_conversion_revisions',
				'grocy_ai_conversion_rules', 'grocy_ai_conversion_rules_revision_idx', 'grocy_ai_conversion_validation_ledger'
			) ORDER BY type, name")->fetchAll(\PDO::FETCH_COLUMN);
			if ($schemaObjects !== [
				'index:grocy_ai_conversion_rules_revision_idx',
				'table:grocy_ai_conversion_catalog_units',
				'table:grocy_ai_conversion_migrations',
				'table:grocy_ai_conversion_revisions',
				'table:grocy_ai_conversion_rules',
				'table:grocy_ai_conversion_validation_ledger'
			])
			{
				throw new \RuntimeException('conversion_validation_schema_unavailable');
			}
			$state = $database->prepare("SELECT
				(SELECT COUNT(*) FROM grocy_ai_conversion_migrations WHERE version = ?) AS migration_count,
				(SELECT COUNT(*) FROM grocy_ai_conversion_revisions WHERE id = ? AND status = 'inactive' AND source_version = ?) AS revision_count,
				(SELECT COUNT(*) FROM grocy_ai_conversion_catalog_units WHERE source_version = ?) AS catalog_count,
				(SELECT COUNT(*) FROM grocy_ai_conversion_rules WHERE revision_id = ? AND source_version = ?) AS rule_count,
				(SELECT COUNT(*) FROM grocy_ai_conversion_validation_ledger WHERE revision_id IS NULL OR status NOT IN ('inactive', 'blocked', 'product_native') OR validated_at IS NULL) AS invalid_ledger_count,
				(SELECT COUNT(id) + COUNT(blocker) FROM grocy_ai_conversion_validation_ledger) AS ledger_shape_probe");
			$state->execute([
				GrocyAiConversionMigration::VERSION,
				GrocyAiConversionMigration::INACTIVE_REVISION_ID, GrocyAiConversionMigration::SOURCE_VERSION,
				GrocyAiConversionMigration::SOURCE_VERSION, GrocyAiConversionMigration::INACTIVE_REVISION_ID,
				GrocyAiConversionMigration::SOURCE_VERSION
			]);
			$state = $state->fetch(\PDO::FETCH_ASSOC);
			if (!is_array($state) || (int)$state['migration_count'] !== 1 || (int)$state['revision_count'] !== 1
				|| (int)$state['catalog_count'] !== 14 || (int)$state['rule_count'] !== 12 || (int)$state['invalid_ledger_count'] !== 0)
			{
				throw new \RuntimeException('conversion_validation_state_unavailable');
			}
			$catalog = $database->query('SELECT unit_key, dimension, metric_factor, source_version FROM grocy_ai_conversion_catalog_units ORDER BY unit_key')->fetchAll(\PDO::FETCH_ASSOC);
			if ($catalog !== self::ExpectedConversionCatalogSeed())
			{
				throw new \RuntimeException('conversion_validation_catalog_invalid');
			}
			$rules = $database->prepare('SELECT from_unit_key, to_unit_key, factor, source_version FROM grocy_ai_conversion_rules WHERE revision_id = ? ORDER BY from_unit_key, to_unit_key');
			$rules->execute([GrocyAiConversionMigration::INACTIVE_REVISION_ID]);
			if ($rules->fetchAll(\PDO::FETCH_ASSOC) !== self::ExpectedConversionRuleSeed())
			{
				throw new \RuntimeException('conversion_validation_rules_invalid');
			}
			return $this->ApiResponse($response, (new GrocyAiConversionService($database, false))->ValidateNativeConversionBeforeWrite($candidate, $objectId));
		}
		catch (\Throwable)
		{
			return $this->GenericErrorResponse($response, 'Conversion validation unavailable', 503);
		}
	}

	public function ProductConversionStatus(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$productId = $args['productId'] ?? null;
		$query = $request->getQueryParams();
		$fromUnitKey = $query['from_unit_key'] ?? null;
		$toUnitKey = $query['to_unit_key'] ?? null;
		if (!is_string($productId) || preg_match('/^[1-9][0-9]{0,9}$/D', $productId) !== 1
			|| !is_string($fromUnitKey) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $fromUnitKey) !== 1
			|| !is_string($toUnitKey) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $toUnitKey) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid conversion status request', 400);
		}

		try
		{
			$database = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$product = $database->prepare('SELECT 1 FROM products WHERE id = ?');
			$product->execute([(int)$productId]);
			if ($product->fetchColumn() === false)
			{
				return $this->GenericErrorResponse($response, 'Product unavailable', 404);
			}

			$status = (new GrocyAiConversionService($database, false))->InspectConversionResolution(
				(int)$productId,
				$fromUnitKey,
				$toUnitKey
			);
			$keys = [
				'status', 'blockers', 'factor', 'dimension', 'approximate', 'winner_source', 'source_name', 'source_version',
				'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'
			];
			if (array_keys($status) !== $keys || !in_array($status['status'], ['product_native', 'inactive', 'unavailable', 'blocked'], true))
			{
				throw new \RuntimeException('conversion_status_contract_invalid');
			}
			if ($status['status'] !== 'product_native')
			{
				$status['factor'] = null;
			}

			return $this->ApiResponse($response, $status);
		}
		catch (\Throwable)
		{
			return $this->GenericErrorResponse($response, 'Conversion status unavailable', 503);
		}
	}

	public function ResolvedConversionProvenance(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$query = $request->getQueryParams();
		$productId = $query['product_id'] ?? null;
		$fromQuId = $query['from_qu_id'] ?? null;
		$toQuId = $query['to_qu_id'] ?? null;
		if (!is_string($productId) || preg_match('/^[1-9][0-9]{0,9}$/D', $productId) !== 1
			|| !is_string($fromQuId) || preg_match('/^[1-9][0-9]{0,9}$/D', $fromQuId) !== 1
			|| !is_string($toQuId) || preg_match('/^[1-9][0-9]{0,9}$/D', $toQuId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid resolved conversion request', 400);
		}

		try
		{
			$database = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$product = $database->prepare('SELECT 1 FROM products WHERE id = ?');
			$product->execute([(int)$productId]);
			if ($product->fetchColumn() === false)
			{
				return $this->GenericErrorResponse($response, 'Product unavailable', 404);
			}

			$units = $database->prepare('SELECT id, name FROM quantity_units WHERE id IN (?, ?)');
			$units->execute([(int)$fromQuId, (int)$toQuId]);
			$names = [];
			foreach ($units->fetchAll(\PDO::FETCH_ASSOC) as $unit)
			{
				$names[(int)$unit['id']] = (string)$unit['name'];
			}
			if (!isset($names[(int)$fromQuId], $names[(int)$toQuId]))
			{
				return $this->GenericErrorResponse($response, 'Quantity unit unavailable', 404);
			}

			$fromUnitKey = GrocyAiConversionService::UnitKeyForName($names[(int)$fromQuId]);
			$toUnitKey = GrocyAiConversionService::UnitKeyForName($names[(int)$toQuId]);
			if ($fromUnitKey === null || $toUnitKey === null)
			{
				// Package and count relationships stay product- or barcode-bound. They are reported
				// as an eligibility outcome rather than resolved, so no factor can be borrowed.
				return $this->ApiResponse($response, self::CountScopeInspectionDto());
			}

			$status = (new GrocyAiConversionService($database, false))->InspectConversionResolution(
				(int)$productId,
				$fromUnitKey,
				$toUnitKey
			);
			$keys = [
				'status', 'blockers', 'factor', 'dimension', 'approximate', 'winner_source', 'source_name', 'source_version',
				'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'
			];
			if (array_keys($status) !== $keys || !in_array($status['status'], ['product_native', 'inactive', 'unavailable', 'blocked'], true))
			{
				throw new \RuntimeException('resolved_provenance_contract_invalid');
			}
			if ($status['status'] !== 'product_native')
			{
				$status['factor'] = null;
			}

			return $this->ApiResponse($response, $status);
		}
		catch (\Throwable)
		{
			return $this->GenericErrorResponse($response, 'Resolved conversion provenance unavailable', 503);
		}
	}

	public function ConversionCoverage(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			$report = (new GrocyAiConversionService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false))
				->ValidateConversionCoverage();

			return $this->ApiResponse($response, $report);
		}
		catch (\Throwable)
		{
			return $this->GenericErrorResponse($response, 'Conversion coverage unavailable', 503);
		}
	}

	/**
	 * Generate a bounded, zero-mutation bulk plan (D-01/D-13/BULK-01): the user-facing plan-CREATION
	 * surface, so a MASTER_DATA_EDIT user can create a plan in-product and immediately review it. The
	 * permission is checked before any write. The body is the closed `{ "operation_type": "taxonomy_assignment" }`
	 * shape only — the single server-registered plan operation type; any extra key (a browser-supplied item
	 * list, object_id, operation, value, or SQL) or an operation_type outside the closed set is a bounded 400
	 * before the engine runs, so the browser can never supply the proposed values or operations — GeneratePlan
	 * derives those server-side. The authenticated session user (`GROCY_USER_ID`) is recorded as the plan
	 * actor; generation writes ONLY the two module tables and performs zero native/taxonomy mutation. On
	 * success the new plan is returned in the same closed shape as BulkPlan (header + counts + items) at HTTP
	 * 201 so the UI can render it without a second round-trip.
	 */
	public function GenerateBulkPlan(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid plan generation request', 400);
		}
		// Closed candidate-key set: the request may supply only operation_type, restricted to the closed set
		// of three server-side generators (Phase 6 / 06-07): the confident-only taxonomy pass, the
		// product-group suggestion pass, and the conflict-first classification pass. Any extra key makes the
		// intersected candidate differ from the raw body and is refused before the engine, so no free-form
		// entity/field/value/SQL payload can reach generation. The proposed values and operations are always
		// server-derived by the selected generator; the browser only picks which closed pass runs.
		$candidate = array_intersect_key($body, array_flip(['operation_type']));
		$allowedSelectors = [
			GrocyAiBulkService::OPERATION_TYPE,
			GrocyAiBulkService::GROUP_OPERATION_TYPE,
			self::CLASSIFICATION_REVIEW_SELECTOR
		];
		if ($candidate !== $body || !array_key_exists('operation_type', $candidate)
			|| !is_string($candidate['operation_type'])
			|| !in_array($candidate['operation_type'], $allowedSelectors, true))
		{
			return $this->GenericErrorResponse($response, 'Invalid plan generation request', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			// The actor is the authenticated session user only — never a browser-supplied value. Each pass
			// runs its own explicit server-side generator (the group/classification ops are dispatched via
			// GrocyAiBulkService::ResolveOperation, never enumerated from RegisteredOperations).
			$scope = ['actor' => (string)GROCY_USER_ID];
			$generated = match ($candidate['operation_type'])
			{
				GrocyAiBulkService::GROUP_OPERATION_TYPE => $service->GenerateGroupPlan($scope),
				self::CLASSIFICATION_REVIEW_SELECTOR => $service->GenerateClassificationPlan($scope),
				default => $service->GeneratePlan($scope)
			};
			return $this->ApiResponse($response->withStatus(201), $service->ReadPlan((int)$generated['id']));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan generation request', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan generation unavailable', 503);
		}
	}

	/**
	 * Read-only conversion audit report (Phase 6 / 06-06 surfaced by 06-07). MASTER_DATA_EDIT-gated. The
	 * conversion pass is a classifying READ-ONLY audit, never a plan: it returns the baseline (global count,
	 * product-specific/expected counts and distinct product count) plus any SUSPICIOUS rows the integrity
	 * tripwire found. The underlying library issues only SELECTs, so this endpoint declares no apply,
	 * rollback, or write path of its own; the UI renders it as a report with no mutation controls.
	 */
	public function BulkConversionAudit(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$auditLibrary = __DIR__ . '/../bin/audit-conversions.php';
		if (!is_file($auditLibrary))
		{
			return $this->GenericErrorResponse($response, 'Conversion audit unavailable', 503);
		}
		require_once $auditLibrary;

		try
		{
			// The audit library lives in the global namespace; it only SELECTs, mutating nothing.
			$report = \auditConversions(DatabaseService::GetInstance()->GetDbConnectionRaw());
			$suspicious = [];
			foreach ($report['suspicious'] as $row)
			{
				$suspicious[] = [
					'id' => (int)$row['id'],
					'product_id' => (int)$row['product_id'],
					'from_qu_id' => (int)$row['from_qu_id'],
					'to_qu_id' => (int)$row['to_qu_id'],
					'factor' => (float)$row['factor'],
					'rule' => (string)$row['rule']
				];
			}
			return $this->ApiResponse($response, [
				'global_count' => (int)$report['global_count'],
				'product_specific_count' => (int)$report['product_specific_count'],
				'expected_count' => (int)$report['expected_count'],
				'expected_product_count' => (int)$report['expected_product_count'],
				'suspicious' => $suspicious,
				'ok' => (bool)$report['ok']
			]);
		}
		catch (\Throwable)
		{
			return $this->GenericErrorResponse($response, 'Conversion audit unavailable', 503);
		}
	}

	/**
	 * Read a stored bulk plan header, counts, and items (D-13). MASTER_DATA_EDIT-gated, read-only.
	 */
	public function BulkPlan(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			return $this->ApiResponse($response, $service->ReadPlan((int)$planId));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Toggle one plan item's selection flag (D-04). The body is the closed `{ "selected": true|false }`
	 * shape only; any other key or a non-boolean value is rejected before the service is called, so no
	 * free-form entity/field/CRUD target can reach the selection write. MASTER_DATA_EDIT-gated.
	 */
	public function BulkPlanSetItemSelection(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		$seq = $args['seq'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1
			|| !is_string($seq) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $seq) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid selection request', 400);
		}

		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid selection request', 400);
		}
		// Closed candidate-key set: the request may supply only the boolean `selected`. Any extra key
		// makes the intersected candidate differ from the raw body and is refused.
		$candidate = array_intersect_key($body, array_flip(['selected']));
		if ($candidate !== $body || !array_key_exists('selected', $candidate) || !is_bool($candidate['selected']))
		{
			return $this->GenericErrorResponse($response, 'Invalid selection request', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			return $this->ApiResponse($response, $service->SetItemSelection((int)$planId, (int)$seq, $candidate['selected']));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid selection request', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 409);
		}
	}

	/**
	 * Return the complete selected diff for a stored plan (D-04/D-13). Read-only; declares no apply or
	 * write action. MASTER_DATA_EDIT-gated.
	 */
	public function BulkPlanSelectedDiff(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			return $this->ApiResponse($response, $service->SelectedDiff((int)$planId));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Apply an approved plan (D-08/D-13): a user-facing, authenticated, MASTER_DATA_EDIT-gated durable
	 * action — NOT a maintainer CLI. The permission is checked before any write. The body is the closed
	 * confirmation `{ "checksum": "<sha256>" }` only; any extra key or a non-64-hex value is a bounded 400,
	 * so no free-form entity/field/CRUD/SQL payload can reach the engine. The authenticated session user
	 * (`GROCY_USER_ID`) is resolved as the actor and threaded to `ApplyPlan`; the confirmed checksum is
	 * cross-checked by the engine, which returns a bounded outcome (never a partial write) on mismatch or
	 * all-conflict. This is the sole apply surface — there is no CLI and no second apply route.
	 */
	public function BulkPlanApply(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid apply request', 400);
		}

		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid apply request', 400);
		}
		// Closed candidate-key set: the request may supply only the reviewed 64-hex `checksum`. Any extra
		// key makes the intersected candidate differ from the raw body and is refused before the engine.
		$candidate = array_intersect_key($body, array_flip(['checksum']));
		if ($candidate !== $body || !array_key_exists('checksum', $candidate)
			|| !is_string($candidate['checksum']) || preg_match('/^[0-9a-f]{64}$/D', $candidate['checksum']) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid apply request', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			$result = $service->ApplyPlan((int)$planId, (string)GROCY_USER_ID, $candidate['checksum']);
			// The engine returns a bounded outcome; a refusal or a rolled-back apply maps to 409, never a
			// partial write.
			if ($result['blockers'] !== [])
			{
				return $this->ApiResponse($response->withStatus(409), $result);
			}
			return $this->ApiResponse($response, $result);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid apply request', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Read a stored plan's append-only audit ledger (D-10). MASTER_DATA_EDIT-gated, read-only: it returns
	 * the plan's ordered immutable audit records for reconstruction and declares no edit or delete surface —
	 * no endpoint can rewrite or remove an audit row. An unknown plan id returns a bounded 404.
	 */
	public function BulkPlanAudit(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			return $this->ApiResponse($response, $service->ReadPlanAudit((int)$planId));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Zero-write rollback preview (D-11/D-13). MASTER_DATA_EDIT-gated read: the permission is checked before
	 * any read, then `PreviewRollback` returns the audit-derived reversible/refused breakdown without any
	 * write. An unknown plan id returns a bounded 404; a non-integer id a bounded 400.
	 */
	public function BulkPlanRollbackPreview(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			return $this->ApiResponse($response, $service->PreviewRollback((int)$planId));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid plan', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Execute a guarded rollback (D-11/D-13): an authenticated, MASTER_DATA_EDIT-gated, audited durable
	 * action. The permission is checked before any write. The body is the closed, optional confirmation
	 * `{ "checksum": "<sha256>" }` only — any extra key (an item list, an entity/field/value, or SQL) or a
	 * non-64-hex value is a bounded 400, so no free-form target and no per-item value can reach the engine.
	 * The authenticated session user (`GROCY_USER_ID`) is resolved as the actor and threaded to
	 * `RollbackPlan`, which reuses the single-transaction, optimistic-concurrency, idempotent, append-only
	 * forward-apply path and returns a bounded outcome (never a partial write) — a refusal or a rolled-back
	 * transaction maps to 409.
	 */
	public function BulkPlanRollback(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid rollback request', 400);
		}

		$body = $request->getParsedBody() ?? [];
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid rollback request', 400);
		}
		// Closed candidate-key set: only the optional reviewed 64-hex checksum. Any other key makes the
		// intersected candidate differ from the raw body and is refused before the engine.
		$candidate = array_intersect_key($body, array_flip(['checksum']));
		if ($candidate !== $body)
		{
			return $this->GenericErrorResponse($response, 'Invalid rollback request', 400);
		}
		$confirmedChecksum = null;
		if (array_key_exists('checksum', $candidate))
		{
			if (!is_string($candidate['checksum']) || preg_match('/^[0-9a-f]{64}$/D', $candidate['checksum']) !== 1)
			{
				return $this->GenericErrorResponse($response, 'Invalid rollback request', 400);
			}
			$confirmedChecksum = $candidate['checksum'];
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			$result = $service->RollbackPlan((int)$planId, (string)GROCY_USER_ID, $confirmedChecksum);
			// The engine returns a bounded outcome; a refusal or a rolled-back transaction maps to 409,
			// never a partial write.
			if ($result['blockers'] !== [])
			{
				return $this->ApiResponse($response->withStatus(409), $result);
			}
			return $this->ApiResponse($response, $result);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid rollback request', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	/**
	 * Export a redacted, non-authoritative JSON or CSV snapshot of a stored plan (D-12/D-13). This is a
	 * MASTER_DATA_EDIT-gated read: the permission is checked before any read, and the export writes
	 * nothing. The `format` query param selects `json` (default) or `csv`; any other value — like a
	 * non-integer plan id — is a bounded 400, and an unknown plan is a bounded 404. The response is a file
	 * download marked non-authoritative in its own body/metadata. There is deliberately NO companion
	 * endpoint that consumes an uploaded snapshot: re-import as authority stays deferred to V2-03, so this
	 * export can never become a back-door write path.
	 */
	public function ExportBulkPlan(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$planId = $args['planId'] ?? null;
		if (!is_string($planId) || preg_match('/^[1-9][0-9]{0,9}$/D', $planId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid export request', 400);
		}

		$format = $request->getQueryParams()['format'] ?? 'json';
		if (!is_string($format) || ($format !== 'json' && $format !== 'csv'))
		{
			return $this->GenericErrorResponse($response, 'Invalid export request', 400);
		}

		try
		{
			$service = new GrocyAiBulkService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false);
			$snapshot = $service->ExportPlan((int)$planId, $format);
			$filename = 'grocy-ai-bulk-plan-' . (int)$planId . '-non-authoritative.' . $format;

			if ($format === 'csv')
			{
				$response->getBody()->write((string)$snapshot);
				return $response
					->withHeader('Cache-Control', 'private, no-store')
					->withHeader('Content-Type', 'text/csv; charset=utf-8')
					->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
					->withHeader('X-Content-Type-Options', 'nosniff');
			}

			return $this->ApiResponse(
				$response
					->withHeader('Cache-Control', 'private, no-store')
					->withHeader('Content-Type', 'application/json')
					->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
					->withHeader('X-Content-Type-Options', 'nosniff'),
				$snapshot
			);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid export request', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Plan unavailable', 404);
		}
	}

	private static function CountScopeInspectionDto(): array
	{
		return [
			'status' => 'unavailable',
			'blockers' => ['reusable_count_scope'],
			'factor' => null,
			'dimension' => null,
			'approximate' => null,
			'winner_source' => null,
			'source_name' => null,
			'source_version' => null,
			'source_status' => null,
			'source_item_id' => null,
			'profile_key' => null,
			'taxonomy_leaf' => null,
			'precedence' => 'product_override>food_profile>universal',
			'inactive_revision_id' => null
		];
	}

	public function FetchImage(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			$image = (new GrocyAiService())->FetchImage($args['variant'], $args['token']);
			$response->getBody()->write($image['body']);
			$extension = [
				'image/png' => 'png',
				'image/webp' => 'webp'
			][$image['content_type']] ?? 'jpg';

			return $response
				->withHeader('Cache-Control', 'private, no-store')
				->withHeader('Content-Type', $image['content_type'])
				->withHeader('Content-Disposition', 'inline; filename="product-image.' . $extension . '"')
				->withHeader('X-Content-Type-Options', 'nosniff');
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid image selection', 400);
		}
		catch (\LogicException)
		{
			return $this->GenericErrorResponse($response, 'Product image service unavailable', 503);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Selected product image unavailable', 502);
		}
	}

	/**
	 * Open a fresh capture trip (CAP-01). STOCK_PURCHASE-gated; the actor is the authenticated session user
	 * only, never a browser-supplied value. Writes no stock.
	 */
	public function StartCaptureTrip(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			return $this->ApiResponse($response->withStatus(201), $service->StartTrip((string)GROCY_USER_ID));
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture unavailable', 503);
		}
	}

	/**
	 * List capture trips, newest first (CAP-03 review surface). STOCK_PURCHASE-gated read.
	 */
	public function ListCaptureTrips(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			return $this->ApiResponse($response, ['trips' => $service->ListTrips()]);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture unavailable', 503);
		}
	}

	/**
	 * Scan a barcode into a trip (CAP-01/CAP-02): resolve ownership immediately and coalesce duplicates. The
	 * body is the closed `{ "barcode": "..." }` shape only. STOCK_PURCHASE-gated; writes no stock.
	 */
	public function ScanCaptureTrip(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$tripId = $args['tripId'] ?? null;
		if (!is_string($tripId) || preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}
		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid capture scan', 400);
		}
		$candidate = array_intersect_key($body, array_flip(['barcode']));
		if ($candidate !== $body || !array_key_exists('barcode', $candidate) || !is_string($candidate['barcode']) || $candidate['barcode'] === '')
		{
			return $this->GenericErrorResponse($response, 'Invalid capture scan', 400);
		}

		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			return $this->ApiResponse($response->withStatus(201), $service->ScanIntoTrip((int)$tripId, $candidate['barcode'], (string)GROCY_USER_ID));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture scan', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture unavailable', 409);
		}
	}

	/**
	 * Load a trip and its ordered lines (CAP-01), re-resolving still-unknown lines by barcode, plus the
	 * current commit checksum so the review UI can echo it back on commit. STOCK_PURCHASE-gated read.
	 */
	public function CaptureTrip(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$tripId = $args['tripId'] ?? null;
		if (!is_string($tripId) || preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}

		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			$loaded = $service->LoadTrip((int)$tripId, (string)GROCY_USER_ID);
			$loaded['checksum'] = $service->ChecksumForTrip((int)$tripId);
			return $this->ApiResponse($response, $loaded);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture trip unavailable', 404);
		}
	}

	/**
	 * Commit a reviewed trip to real Grocy stock (CAP-05..CAP-08) — the only stock-write endpoint. The body
	 * is the closed `{ "confirmed_checksum": "<64-hex>" }` shape; the actor is the authenticated session
	 * user. A checksum mismatch / already-committed / transaction failure returns the result DTO at 409 (no
	 * write); a successful full or partial commit returns it at 200. STOCK_PURCHASE-gated.
	 */
	public function CommitCaptureTrip(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$tripId = $args['tripId'] ?? null;
		if (!is_string($tripId) || preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}
		$body = $request->getParsedBody();
		if (!is_array($body))
		{
			return $this->GenericErrorResponse($response, 'Invalid capture commit', 400);
		}
		$candidate = array_intersect_key($body, array_flip(['confirmed_checksum']));
		if ($candidate !== $body || !array_key_exists('confirmed_checksum', $candidate)
			|| !is_string($candidate['confirmed_checksum']) || preg_match('/^[0-9a-f]{64}$/D', $candidate['confirmed_checksum']) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture commit', 400);
		}

		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			$result = $service->CommitTrip((int)$tripId, (string)GROCY_USER_ID, $candidate['confirmed_checksum']);
			if (in_array($result['outcome'], ['checksum_mismatch', 'already_committed', 'commit_failed'], true))
			{
				return $this->ApiResponse($response->withStatus(409), $result);
			}
			return $this->ApiResponse($response, $result);
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture commit unavailable', 503);
		}
	}

	/**
	 * Advance a trip's status (open -> reviewing) OR set its location/shopping-location defaults (CAP-01).
	 * The body is exactly one closed shape: `{ "status": "reviewing" }`, or a non-empty subset of
	 * `{ "default_location_id", "default_shopping_location_id" }` with integer-or-null values.
	 * STOCK_PURCHASE-gated; writes no stock.
	 */
	public function UpdateCaptureTrip(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$tripId = $args['tripId'] ?? null;
		if (!is_string($tripId) || preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip', 400);
		}
		$body = $request->getParsedBody();
		if (!is_array($body) || $body === [])
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip update', 400);
		}

		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());

			if (array_key_exists('status', $body))
			{
				if ($body !== ['status' => 'reviewing'])
				{
					return $this->GenericErrorResponse($response, 'Invalid capture trip update', 400);
				}
				return $this->ApiResponse($response, $service->SetStatus((int)$tripId, 'reviewing', (string)GROCY_USER_ID));
			}

			$defaults = array_intersect_key($body, array_flip(['default_location_id', 'default_shopping_location_id']));
			if ($defaults !== $body)
			{
				return $this->GenericErrorResponse($response, 'Invalid capture trip update', 400);
			}
			foreach ($defaults as $value)
			{
				if ($value !== null && !is_int($value))
				{
					return $this->GenericErrorResponse($response, 'Invalid capture trip update', 400);
				}
			}
			$location = array_key_exists('default_location_id', $defaults) ? $defaults['default_location_id'] : null;
			$store = array_key_exists('default_shopping_location_id', $defaults) ? $defaults['default_shopping_location_id'] : null;
			return $this->ApiResponse($response, $service->SetTripDefaults((int)$tripId, $location, $store, (string)GROCY_USER_ID));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture trip update', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture trip unavailable', 404);
		}
	}

	/**
	 * Edit one line of a trip (CAP-01): the closed change set is one or more of `quantity` / `price` /
	 * `selected`, or `{ "delete": true }` alone. STOCK_PURCHASE-gated; writes no stock.
	 */
	public function UpdateCaptureLine(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);
		$tripId = $args['tripId'] ?? null;
		$seq = $args['seq'] ?? null;
		if (!is_string($tripId) || preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) !== 1
			|| !is_string($seq) || preg_match('/^[1-9][0-9]{0,9}$/D', $seq) !== 1)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture line', 400);
		}
		$body = $request->getParsedBody();
		if (!is_array($body) || $body === [])
		{
			return $this->GenericErrorResponse($response, 'Invalid capture line update', 400);
		}
		$candidate = array_intersect_key($body, array_flip(['quantity', 'price', 'selected', 'delete']));
		if ($candidate !== $body)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture line update', 400);
		}

		try
		{
			$service = new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
			return $this->ApiResponse($response, $service->UpdateLine((int)$tripId, (int)$seq, $candidate, (string)GROCY_USER_ID));
		}
		catch (\InvalidArgumentException)
		{
			return $this->GenericErrorResponse($response, 'Invalid capture line update', 400);
		}
		catch (\RuntimeException)
		{
			return $this->GenericErrorResponse($response, 'Capture line unavailable', 404);
		}
	}

	private static function ExpectedConversionCatalogSeed(): array
	{
		$sourceVersion = GrocyAiConversionMigration::SOURCE_VERSION;
		return [
			['unit_key' => 'cup', 'dimension' => 'volume', 'metric_factor' => '0.2365882365', 'source_version' => $sourceVersion],
			['unit_key' => 'fl_oz', 'dimension' => 'volume', 'metric_factor' => '0.0295735295625', 'source_version' => $sourceVersion],
			['unit_key' => 'g', 'dimension' => 'mass', 'metric_factor' => '1', 'source_version' => $sourceVersion],
			['unit_key' => 'gallon', 'dimension' => 'volume', 'metric_factor' => '3.785411784', 'source_version' => $sourceVersion],
			['unit_key' => 'kg', 'dimension' => 'mass', 'metric_factor' => '1000', 'source_version' => $sourceVersion],
			['unit_key' => 'l', 'dimension' => 'volume', 'metric_factor' => '1', 'source_version' => $sourceVersion],
			['unit_key' => 'lb', 'dimension' => 'mass', 'metric_factor' => '453.59237', 'source_version' => $sourceVersion],
			['unit_key' => 'mg', 'dimension' => 'mass', 'metric_factor' => '0.001', 'source_version' => $sourceVersion],
			['unit_key' => 'ml', 'dimension' => 'volume', 'metric_factor' => '0.001', 'source_version' => $sourceVersion],
			['unit_key' => 'oz', 'dimension' => 'mass', 'metric_factor' => '28.349523125', 'source_version' => $sourceVersion],
			['unit_key' => 'pint', 'dimension' => 'volume', 'metric_factor' => '0.473176473', 'source_version' => $sourceVersion],
			['unit_key' => 'quart', 'dimension' => 'volume', 'metric_factor' => '0.946352946', 'source_version' => $sourceVersion],
			['unit_key' => 'tbsp', 'dimension' => 'volume', 'metric_factor' => '0.01478676478125', 'source_version' => $sourceVersion],
			['unit_key' => 'tsp', 'dimension' => 'volume', 'metric_factor' => '0.00492892159375', 'source_version' => $sourceVersion]
		];
	}

	private static function ExpectedConversionRuleSeed(): array
	{
		$sourceVersion = GrocyAiConversionMigration::SOURCE_VERSION;
		return [
			['from_unit_key' => 'cup', 'to_unit_key' => 'l', 'factor' => '0.2365882365', 'source_version' => $sourceVersion],
			['from_unit_key' => 'fl_oz', 'to_unit_key' => 'l', 'factor' => '0.0295735295625', 'source_version' => $sourceVersion],
			['from_unit_key' => 'gallon', 'to_unit_key' => 'l', 'factor' => '3.785411784', 'source_version' => $sourceVersion],
			['from_unit_key' => 'kg', 'to_unit_key' => 'g', 'factor' => '1000', 'source_version' => $sourceVersion],
			['from_unit_key' => 'lb', 'to_unit_key' => 'g', 'factor' => '453.59237', 'source_version' => $sourceVersion],
			['from_unit_key' => 'mg', 'to_unit_key' => 'g', 'factor' => '0.001', 'source_version' => $sourceVersion],
			['from_unit_key' => 'ml', 'to_unit_key' => 'l', 'factor' => '0.001', 'source_version' => $sourceVersion],
			['from_unit_key' => 'oz', 'to_unit_key' => 'g', 'factor' => '28.349523125', 'source_version' => $sourceVersion],
			['from_unit_key' => 'pint', 'to_unit_key' => 'l', 'factor' => '0.473176473', 'source_version' => $sourceVersion],
			['from_unit_key' => 'quart', 'to_unit_key' => 'l', 'factor' => '0.946352946', 'source_version' => $sourceVersion],
			['from_unit_key' => 'tbsp', 'to_unit_key' => 'l', 'factor' => '0.01478676478125', 'source_version' => $sourceVersion],
			['from_unit_key' => 'tsp', 'to_unit_key' => 'l', 'factor' => '0.00492892159375', 'source_version' => $sourceVersion]
		];
	}

	private function DiagnosticResponse(Response $response, array $data, int $status = 200): Response
	{
		$response = $response->withStatus($status);
		$serverTiming = GrocyAiDiagnostic::ServerTiming(is_array($data['diagnostics'] ?? null) ? $data['diagnostics'] : []);
		if ($serverTiming !== '')
		{
			$response = $response->withHeader('Server-Timing', $serverTiming);
		}

		return $this->ApiResponse($response, $data);
	}

	private static function CurrentProductId(Request $request): ?int
	{
		$value = $request->getQueryParams()['current_product_id'] ?? null;
		if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1)
		{
			return null;
		}

		return (int)$value;
	}
}
