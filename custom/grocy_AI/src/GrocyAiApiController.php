<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\BaseApiController;
use Grocy\Controllers\Users\User;
use GrocyAI\Services\GrocyAiService;
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

		try
		{
			return $this->ApiResponse($response, (new GrocyAiService())->EnrichByUpc($args['upc']));
		}
		catch (\InvalidArgumentException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 400);
		}
		catch (\LogicException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 503);
		}
		catch (\RuntimeException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 502);
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
		catch (\InvalidArgumentException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 400);
		}
		catch (\LogicException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 503);
		}
		catch (\RuntimeException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 502);
		}
	}
}
