<?php

namespace GrocyAI\Controllers;

use Grocy\Controllers\BaseController;
use Grocy\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiBulkController extends BaseController
{
	/**
	 * Renders the maintainer-only bulk plan review page. The page is strictly read-only: it neither
	 * generates a plan nor applies one. It hands the review surface an optional `plan` id (from the
	 * query string) so the client can load the plan, its selection state, and the selected diff through
	 * the MASTER_DATA_EDIT-gated read endpoints. All durable selection/apply happens through those
	 * endpoints, never this page.
	 */
	public function BulkReview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$planId = $request->getQueryParams()['plan'] ?? null;
		$planId = is_string($planId) && preg_match('/^[1-9][0-9]{0,9}$/D', $planId) === 1 ? (int)$planId : null;

		return $this->RenderPage($response, 'grocyai_bulkreview', [
			'planId' => $planId
		]);
	}
}
