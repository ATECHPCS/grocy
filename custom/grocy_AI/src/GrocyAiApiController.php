<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Controllers\Users\User;
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
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		$traceContext = GrocyAiDiagnostic::CreateTraceContext($request->getHeaderLine('traceparent'));

		try
		{
			$result = (new GrocyAiService())->EnrichByUpc($args['upc'], $traceContext);
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

	public function FetchImage(Request $request, Response $response, array $args): Response
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			$image = (new GrocyAiService())->FetchImage($args['token']);
			$response->getBody()->write($image['body']);
			$extension = [
				'image/png' => 'png',
				'image/webp' => 'webp'
			][$image['content_type']] ?? 'jpg';

			return $response
				->withHeader('Cache-Control', 'private, no-store')
				->withHeader('Content-Type', $image['content_type'])
				->withHeader('Content-Disposition', 'inline; filename="grocy-ai-product.' . $extension . '"')
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
}
