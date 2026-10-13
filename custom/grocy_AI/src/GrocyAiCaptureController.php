<?php

namespace GrocyAI\Controllers;

use Grocy\Controllers\BaseController;
use Grocy\Controllers\Users\User;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiCaptureService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiCaptureController extends BaseController
{
	/**
	 * Renders the personal dashboard, or the scanner for an explicit trip bookmark.
	 * Landing and refresh never implicitly select or create a purchase session.
	 */
	public function Capture(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);

		$query = $request->getQueryParams();
		if (array_key_exists('trip', $query) && (!is_string($query['trip']) || preg_match('/^[1-9][0-9]{0,9}$/D', $query['trip']) !== 1))
		{
			$response->getBody()->write('Invalid capture trip');
			return $response->withStatus(400);
		}

		new GrocyAiCaptureService(DatabaseService::GetInstance()->GetDbConnectionRaw());
		if (!array_key_exists('trip', $query))
		{
			return $this->RenderPage($response, 'grocyai_capture_dashboard');
		}

		return $this->RenderPage($response, 'grocyai_capture', [
			'defaultTripId' => (int)$query['trip']
		]);
	}

	/**
	 * Renders the trip review surface (CAP-03/CAP-04): a trip list plus, for the selected trip, lean
	 * per-line edits (quantity/price/selection/delete) and trip-level location/store defaults, wired to the
	 * STOCK_PURCHASE-gated capture endpoints. Unknown lines link out to the MASTER_DATA_EDIT-gated
	 * `/product/new` enrichment flow; this page itself writes no stock and needs only STOCK_PURCHASE.
	 */
	public function Review(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);

		$tripId = $request->getQueryParams()['trip'] ?? null;
		$tripId = is_string($tripId) && preg_match('/^[1-9][0-9]{0,9}$/D', $tripId) === 1 ? (int)$tripId : null;

		return $this->RenderPage($response, 'grocyai_capture_review', [
			'tripId' => $tripId
		]);
	}
}
