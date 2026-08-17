<?php

use Grocy\Middleware\JsonMiddleware;
use GrocyAI\Controllers\Api\GrocyAiApiController;
use Slim\Routing\RouteCollectorProxy;

require_once __DIR__ . '/src/GrocyAiDiagnostic.php';
require_once __DIR__ . '/src/GrocyAiContract.php';
require_once __DIR__ . '/src/GrocyAiGtin.php';
require_once __DIR__ . '/src/GrocyAiBarcodeService.php';
require_once __DIR__ . '/src/GrocyAiService.php';
require_once __DIR__ . '/src/GrocyAiApiController.php';

$app->group('/api/grocy-ai', function (RouteCollectorProxy $group)
{
	$group->get('/status', [GrocyAiApiController::class, 'Status']);
	$group->get('/barcodes/resolve/{barcode}', [GrocyAiApiController::class, 'ResolveBarcode']);
	$group->get('/products/enrich/upc/{upc}', [GrocyAiApiController::class, 'EnrichByUpc']);
	$group->get('/images/{variant}/{token}', [GrocyAiApiController::class, 'FetchImage']);
})->add(JsonMiddleware::class);
