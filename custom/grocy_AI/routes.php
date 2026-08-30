<?php

use Grocy\Middleware\CorsMiddleware;
use Grocy\Middleware\JsonMiddleware;
use GrocyAI\Controllers\Api\GrocyAiApiController;
use GrocyAI\Controllers\GrocyAiBulkController;
use GrocyAI\Controllers\GrocyAiConversionController;
use Slim\Routing\RouteCollectorProxy;

require_once __DIR__ . '/src/GrocyAiDiagnostic.php';
require_once __DIR__ . '/src/GrocyAiContract.php';
require_once __DIR__ . '/src/GrocyAiGtin.php';
require_once __DIR__ . '/src/GrocyAiBarcodeService.php';
require_once __DIR__ . '/src/GrocyAiService.php';
require_once __DIR__ . '/src/GrocyAiTaxonomyMigration.php';
require_once __DIR__ . '/src/GrocyAiTaxonomyService.php';
require_once __DIR__ . '/src/GrocyAiConversionMigration.php';
require_once __DIR__ . '/src/GrocyAiConversionService.php';
require_once __DIR__ . '/src/GrocyAiBulkMigration.php';
require_once __DIR__ . '/src/GrocyAiBulkService.php';
require_once __DIR__ . '/src/GrocyAiApiController.php';
require_once __DIR__ . '/src/GrocyAiConversionController.php';
require_once __DIR__ . '/src/GrocyAiBulkController.php';

$app->group('/api/grocy-ai', function (RouteCollectorProxy $group)
{
	$group->get('/status', [GrocyAiApiController::class, 'Status']);
	$group->get('/barcodes/resolve/{barcode}', [GrocyAiApiController::class, 'ResolveBarcode']);
	$group->get('/products/enrich/upc/{upc}', [GrocyAiApiController::class, 'EnrichByUpc']);
	$group->get('/products/{productId}/taxonomy', [GrocyAiApiController::class, 'ProductTaxonomy']);
	$group->put('/products/{productId}/taxonomy', [GrocyAiApiController::class, 'AssignProductTaxonomy']);
	$group->get('/products/{productId}/conversion-status', [GrocyAiApiController::class, 'ProductConversionStatus']);
	$group->get('/conversions/validate', [GrocyAiApiController::class, 'ValidateConversion']);
	$group->get('/conversions/resolved-provenance', [GrocyAiApiController::class, 'ResolvedConversionProvenance']);
	$group->get('/conversions/coverage', [GrocyAiApiController::class, 'ConversionCoverage']);
	$group->get('/bulk/plans/{planId}', [GrocyAiApiController::class, 'BulkPlan']);
	$group->put('/bulk/plans/{planId}/items/{seq}/selection', [GrocyAiApiController::class, 'BulkPlanSetItemSelection']);
	$group->get('/bulk/plans/{planId}/selected-diff', [GrocyAiApiController::class, 'BulkPlanSelectedDiff']);
	$group->post('/bulk/plans/{planId}/apply', [GrocyAiApiController::class, 'BulkPlanApply']);
	$group->get('/bulk/plans/{planId}/audit', [GrocyAiApiController::class, 'BulkPlanAudit']);
	$group->get('/bulk/plans/{planId}/rollback-preview', [GrocyAiApiController::class, 'BulkPlanRollbackPreview']);
	$group->post('/bulk/plans/{planId}/rollback', [GrocyAiApiController::class, 'BulkPlanRollback']);
	$group->get('/images/{variant}/{token}', [GrocyAiApiController::class, 'FetchImage']);
})->add(new CorsMiddleware($container, $app->getResponseFactory()))->add(new JsonMiddleware($container, $app->getResponseFactory()));

$app->get('/grocyai/conversioncoverage', [GrocyAiConversionController::class, 'ConversionCoverage']);
$app->get('/grocyai/bulkreview', [GrocyAiBulkController::class, 'BulkReview']);
