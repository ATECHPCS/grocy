<?php

namespace GrocyAI\Controllers\Api;

use Grocy\Controllers\Api\BaseApiController;
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
}
