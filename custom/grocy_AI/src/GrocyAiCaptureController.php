<?php

namespace GrocyAI\Controllers;

use Grocy\Controllers\BaseController;
use Grocy\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiCaptureController extends BaseController
{
	/**
	 * Renders the mobile purchase-capture scan-loop page (CAP-01). The page holds no durable state of its
	 * own: it starts a trip and appends scans through the STOCK_PURCHASE-gated
	 * `/api/grocy-ai/capture/trips` endpoints in capture.js. It writes no stock — the only audited
	 * stock-write path is `CommitTrip`, reached from the later review surface, never this page.
	 */
	public function Capture(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_PURCHASE);

		return $this->RenderPage($response, 'grocyai_capture', []);
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
