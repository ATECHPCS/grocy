<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\Api\BaseApiController;
use Grocy\Controllers\Users\User;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiBarcodeService;
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
