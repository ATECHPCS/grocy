<?php

use Grocy\Middleware\CorsMiddleware;
use Grocy\Middleware\JsonMiddleware;
use GrocyAI\Controllers\Api\GrocyAiApiController;
use Slim\Routing\RouteCollectorProxy;

require_once __DIR__ . '/src/GrocyAiService.php';
require_once __DIR__ . '/src/GrocyAiApiController.php';

$app->group('/api/grocy-ai', function (RouteCollectorProxy $group)
{
	$group->get('/status', [GrocyAiApiController::class, 'Status']);
	$group->get('/products/enrich/upc/{upc}', [GrocyAiApiController::class, 'EnrichByUpc']);
})->add(new CorsMiddleware($container, $app->getResponseFactory()))->add(new JsonMiddleware($container, $app->getResponseFactory()));
