<?php

declare(strict_types=1);

define('GROCY_FEATURE_FLAG_GROCY_AI', true);
define('GROCY_AI_SERVICE_URL', 'https://grocy-ai.internal/base/');
define('GROCY_AI_SERVICE_API_KEY', 'test-secret-never-return');
define('GROCY_AI_REQUEST_TIMEOUT_SECONDS', 17);

require_once __DIR__ . '/../src/GrocyAiService.php';

use GrocyAI\Services\GrocyAiService;

$failures = 0;
$tests = 0;

function check(bool $condition, string $message): void
{
	global $failures, $tests;
	$tests++;
	if (!$condition)
	{
		$failures++;
		fwrite(STDERR, "FAIL: {$message}\n");
	}
}

function expectException(callable $callback, string $exceptionClass, string $message): void
{
	try
	{
		$callback();
		check(false, $message);
	}
	catch (Throwable $ex)
	{
		check($ex instanceof $exceptionClass, $message . ' (received ' . get_class($ex) . ')');
	}
}

check(GrocyAiService::NormalizeUpc('0123-4567 8905') === '012345678905', 'UPC separators are normalized');
check(GrocyAiService::NormalizeUpc('12345678') === '12345678', 'EAN-8 is accepted');
expectException(fn() => GrocyAiService::NormalizeUpc('123456789'), InvalidArgumentException::class, 'Unsupported barcode lengths are rejected');
expectException(fn() => GrocyAiService::NormalizeUpc('not-a-upc'), InvalidArgumentException::class, 'Non-digits are rejected');

$captured = [];
$service = new GrocyAiService(function (string $url, array $headers, int $timeout) use (&$captured): array
{
	$captured = compact('url', 'headers', 'timeout');
	return [
		'status' => 200,
		'body' => json_encode([
			'found' => true,
			'product' => [
				'name' => 'Test Product',
				'brand' => 'Test Brand',
				'size' => '12 oz'
			],
			'images' => [
				['url' => 'https://images.example/front.png', 'download_token' => 'abcdefghijklmnopqrstuvwx', 'source' => 'openfoodfacts', 'score' => 99],
				['url' => 'javascript:alert(1)', 'source' => 'unsafe'],
				['url' => 'not a URL', 'source' => 'invalid']
			],
			'sources' => ['openfoodfacts', 'searxng', 'searxng'],
			'warnings' => ['Review before applying']
		], JSON_THROW_ON_ERROR)
	];
});

$result = $service->EnrichByUpc('012345678905');
check($captured['url'] === 'https://grocy-ai.internal/base/v1/products/enrich/upc/012345678905', 'The companion v1 route is called');
check($captured['headers']['X-API-Key'] === 'test-secret-never-return', 'The configured service key is sent');
check($captured['timeout'] === 17, 'The configured timeout is used');
check($result['upc'] === '012345678905', 'The requested UPC is authoritative in the response');
check($result['product']['name'] === 'Test Product', 'Product metadata is normalized');
check(count($result['images']) === 1, 'Unsafe and invalid image URLs are removed');
check($result['images'][0]['download_token'] === 'abcdefghijklmnopqrstuvwx', 'Opaque image tokens are preserved');
check($result['sources'] === ['openfoodfacts', 'searxng'], 'Sources are de-duplicated');

$malformedImagesService = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => '{"found":true,"images":"not-a-list"}'
]);
check($malformedImagesService->EnrichByUpc('012345678905')['images'] === [], 'Malformed image collections are ignored safely');

$statusJson = json_encode($service->GetStatus(), JSON_THROW_ON_ERROR);
check(!str_contains($statusJson, GROCY_AI_SERVICE_API_KEY), 'Status never exposes the API key');
check($service->GetStatus()['mode'] === 'review-before-save', 'Phase 1 reports review-before-save mode');

$imageBody = "\x89PNG\r\n\x1a\n" . str_repeat('x', 2500);
$imageService = new GrocyAiService(function (string $url, array $headers) use ($imageBody): array
{
	return ['status' => 200, 'body' => $imageBody, 'content_type' => 'image/png'];
});
$image = $imageService->FetchImage('abcdefghijklmnopqrstuvwx');
check($image['body'] === $imageBody, 'A selected image is returned without modification');
check($image['content_type'] === 'image/png', 'A supported image content type is preserved');
expectException(fn() => $imageService->FetchImage('../internal'), InvalidArgumentException::class, 'Invalid image handles are rejected');

$htmlService = new GrocyAiService(fn(): array => ['status' => 200, 'body' => str_repeat('x', 2500), 'content_type' => 'text/html']);
expectException(fn() => $htmlService->FetchImage('abcdefghijklmnopqrstuvwx'), RuntimeException::class, 'Non-image downloads are rejected');

$badJsonService = new GrocyAiService(fn(): array => ['status' => 200, 'body' => '{']);
expectException(fn() => $badJsonService->EnrichByUpc('012345678905'), RuntimeException::class, 'Invalid companion JSON is rejected');

$failedService = new GrocyAiService(fn(): array => ['status' => 500, 'body' => '{}']);
expectException(fn() => $failedService->EnrichByUpc('012345678905'), RuntimeException::class, 'Companion HTTP errors are rejected');

if ($failures > 0)
{
	fwrite(STDERR, "{$failures} of {$tests} checks failed\n");
	exit(1);
}

fwrite(STDOUT, "All {$tests} grocy_AI checks passed\n");
