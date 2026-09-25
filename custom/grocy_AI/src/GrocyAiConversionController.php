<?php

namespace GrocyAI\Controllers;

use Grocy\Controllers\BaseController;
use Grocy\Controllers\Users\User;
use Grocy\Services\DatabaseService;
use GrocyAI\Services\GrocyAiConversionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GrocyAiConversionController extends BaseController
{
	/**
	 * Renders the maintainer-only conversion coverage report. The page is strictly read-only:
	 * it never bootstraps module schema, activates a revision, or projects a rule.
	 */
	public function ConversionCoverage(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			$report = (new GrocyAiConversionService(DatabaseService::GetInstance()->GetDbConnectionRaw(), false))
				->ValidateConversionCoverage();
			$reportAvailable = true;
		}
		catch (\Throwable)
		{
			$report = null;
			$reportAvailable = false;
		}

		return $this->RenderPage($response, 'grocyai_conversioncoverage', [
			'report' => $report,
			'reportAvailable' => $reportAvailable
		]);
	}
}
