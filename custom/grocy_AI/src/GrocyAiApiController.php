<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Controllers\Users\User;
use GrocyAI\Services\GrocyAiBarcodeService;
use GrocyAI\Services\GrocyAiDiagnostic;
use GrocyAI\Services\GrocyAiService;
use GrocyAI\Services\GrocyAiServiceException;
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

		try
		{
			$barcodeService = new GrocyAiBarcodeService(null, self::CurrentProductId($request));
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

	public function QuantityUnitConversions(Request $request, Response $response, array $args): Response
	{
		$productId = filter_var($args['productId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($productId === false || $this->getDatabase()->products($productId) === null)
		{
			return $this->GenericErrorResponse($response, 'Product not found', 404);
		}

		return $this->ApiResponse(
			$response,
			$this->getDatabase()->cache__quantity_unit_conversions_resolved()->where('product_id', $productId)->fetchAll()
		);
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
