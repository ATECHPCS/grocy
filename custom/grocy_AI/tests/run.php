<?php

declare(strict_types=1);

define('GROCY_FEATURE_FLAG_GROCY_AI', true);
define('GROCY_AI_SERVICE_URL', 'https://grocy-ai.internal/base/');
define('GROCY_AI_SERVICE_API_KEY', 'test-secret-never-return');
define('GROCY_AI_REQUEST_TIMEOUT_SECONDS', 17);

$diagnosticFile = __DIR__ . '/../src/GrocyAiDiagnostic.php';
if (is_file($diagnosticFile))
{
	require_once $diagnosticFile;
}
require_once __DIR__ . '/../src/GrocyAiService.php';

use GrocyAI\Services\GrocyAiDiagnostic;
use GrocyAI\Services\GrocyAiService;

$failures = 0;
$tests = 0;

$repoRoot = dirname(__DIR__, 3);
$moduleVersionData = json_decode(file_get_contents($repoRoot . '/custom/grocy_AI/module-version.json'), true, 512, JSON_THROW_ON_ERROR);
$moduleVersion = (string)($moduleVersionData['module_version'] ?? '');
$productFormTemplate = file_get_contents($repoRoot . '/views/productform.blade.php');
$assetVersionMatch = [];
$hasAssetVersion = preg_match('/\$grocyAiAssetVersion = \'([^\']+)\'/', $productFormTemplate, $assetVersionMatch) === 1;

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

check($moduleVersion !== '', 'The portable module version is defined');
check($hasAssetVersion, 'The product form defines one grocy_AI asset version token');
check(($assetVersionMatch[1] ?? null) === $moduleVersion, 'The grocy_AI asset token matches the portable module version');
check(substr_count($productFormTemplate, '{{ $grocyAiAssetVersion }}') === 2, 'Both custom product-form assets use the grocy_AI token');
check(!str_contains($productFormTemplate, 'grocy-ai.css?v=\', true) }}{{ $version }}'), 'Custom CSS is independent from the Grocy core version');
check(!str_contains($productFormTemplate, 'product-enrichment.js?v=\', true) }}{{ $version }}'), 'Custom JavaScript is independent from the Grocy core version');

function companionBody(string $outcome = 'success', array $extra = []): string
{
	return json_encode(array_merge([
		'found' => in_array($outcome, ['success', 'partial_image'], true),
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
		'warnings' => ['Review before applying'],
		'outcome' => $outcome,
		'diagnostics' => [
			'schema_version' => 1,
			'contract_version' => '1',
			'companion_version' => '0.1.0',
			'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
			'outcome' => $outcome,
			'stages' => [
				[
					'name' => 'federation',
					'status' => $outcome === 'timeout' ? 'timeout' : 'ok',
					'error_code' => $outcome === 'timeout' ? 'deadline' : null,
					'cache' => 'miss',
					'duration_ms' => 12.6,
					'url' => 'https://provider.invalid/search?q=url-canary'
				]
			],
			'overall_duration_ms' => 13.4,
			'payload' => 'payload-canary'
		]
	], $extra), JSON_THROW_ON_ERROR);
}

class FakeTransferStats
{
	private float $TransferTime;
	private array $HandlerStats;

	public function __construct(float $transferTime, array $handlerStats)
	{
		$this->TransferTime = $transferTime;
		$this->HandlerStats = $handlerStats;
	}

	public function getTransferTime(): float
	{
		return $this->TransferTime;
	}

	public function getHandlerStats(): array
	{
		return $this->HandlerStats;
	}
}

$validGtins = ['96385074', '012345678905', '4006381333931', '10012345000017'];
$invalidChecksumGtins = ['96385075', '012345678906', '4006381333932', '10012345000018'];

foreach ($validGtins as $gtin)
{
	try
	{
		check(GrocyAiService::NormalizeUpc($gtin) === $gtin, "Valid GTIN {$gtin} is accepted");
	}
	catch (Throwable)
	{
		check(false, "Valid GTIN {$gtin} is accepted");
	}
}
check(GrocyAiService::NormalizeUpc('0123-4567 8905') === '012345678905', 'GTIN separators are normalized without losing a leading zero');
expectException(fn() => GrocyAiService::NormalizeUpc('123456789'), InvalidArgumentException::class, 'Unsupported barcode lengths are rejected');
expectException(fn() => GrocyAiService::NormalizeUpc('not-a-upc'), InvalidArgumentException::class, 'Non-digits are rejected');

$invalidTransportCalls = 0;
$invalidService = new GrocyAiService(function () use (&$invalidTransportCalls): array
{
	$invalidTransportCalls++;
	return ['status' => 200, 'body' => companionBody()];
});
foreach ($invalidChecksumGtins as $gtin)
{
	expectException(fn() => $invalidService->EnrichByUpc($gtin), InvalidArgumentException::class, "Invalid GTIN checksum {$gtin} is rejected");
}
check($invalidTransportCalls === 0, 'Invalid GTIN checksums invoke no companion transport');

$validTraceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
$traceContext = [
	'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
	'parent_id' => '1111111111111111',
	'flags' => '01',
	'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-1111111111111111-01'
];

check(class_exists(GrocyAiDiagnostic::class), 'The strict Grocy diagnostic contract exists');
if (class_exists(GrocyAiDiagnostic::class))
{
	$bytes = [hex2bin('1111111111111111')];
	$acceptedTrace = GrocyAiDiagnostic::CreateTraceContext($validTraceparent, function () use (&$bytes): string
	{
		return array_shift($bytes);
	});
	check($acceptedTrace['trace_id'] === '4bf92f3577b34da6a3ce929d0e0e4736', 'A valid inbound trace ID is preserved');
	check($acceptedTrace['parent_id'] === '1111111111111111', 'A valid inbound trace receives a fresh owned parent ID');
	check($acceptedTrace['traceparent'] === $traceContext['traceparent'], 'The outbound traceparent is rebuilt from finite fields');
	check(!array_key_exists('tracestate', $acceptedTrace), 'Trace context never contains tracestate');

	$invalidHeaders = [
		null,
		'',
		'00-00000000000000000000000000000000-00f067aa0ba902b7-01',
		'00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
		'00-CBF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01',
		'01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'
	];
	foreach ($invalidHeaders as $header)
	{
		$replacementBytes = [
			hex2bin('22222222222222222222222222222222'),
			hex2bin('3333333333333333')
		];
		$replacement = GrocyAiDiagnostic::CreateTraceContext($header, function () use (&$replacementBytes): string
		{
			return array_shift($replacementBytes);
		});
		check($replacement['trace_id'] === '22222222222222222222222222222222', 'Invalid or zero trace IDs are replaced');
		check($replacement['parent_id'] === '3333333333333333', 'Replacement trace context receives a non-zero parent ID');
	}

	check(GrocyAiDiagnostic::NormalizeDuration(-5, 12000) === 0, 'Negative timing is clamped to zero');
	check(GrocyAiDiagnostic::NormalizeDuration(999999, 12000) === 12000, 'Timing is clamped to its boundary maximum');
	check(GrocyAiDiagnostic::NormalizeDuration(INF, 12000) === null, 'Infinite timing becomes nullable');
	check(GrocyAiDiagnostic::NormalizeDuration('not-a-number', 12000) === null, 'Malformed timing becomes nullable');
}

$captured = [];
$service = new GrocyAiService(function (string $url, array $headers, $options) use (&$captured): array
{
	$captured = compact('url', 'headers', 'options');
	if (is_array($options) && is_callable($options['on_stats'] ?? null))
	{
		$options['on_stats'](new FakeTransferStats(0.0254, ['connect_time' => 0.0046]));
	}
	return [
		'status' => 200,
		'body' => companionBody()
	];
});

try
{
	$result = $service->EnrichByUpc('012345678905', $traceContext);
}
catch (Throwable $ex)
{
	check(false, 'A valid companion success envelope is accepted (' . get_class($ex) . ')');
	$result = [];
}

check(($captured['url'] ?? null) === 'https://grocy-ai.internal/base/v1/products/enrich/upc/012345678905', 'The companion v1 route is called');
check(($captured['headers']['X-API-Key'] ?? null) === 'test-secret-never-return', 'The configured service key is sent');
check(($captured['headers']['traceparent'] ?? null) === $traceContext['traceparent'], 'One validated traceparent is forwarded to the companion');
check(!isset($captured['headers']['tracestate']), 'Tracestate is not forwarded to the companion');
check(($captured['options']['timeout'] ?? null) === 12.0, 'The total companion timeout is exactly 12 seconds');
check(($captured['options']['connect_timeout'] ?? null) === 2.0, 'The companion connect timeout is exactly 2 seconds');
check(($captured['options']['allow_redirects'] ?? null) === false, 'Companion redirects are disabled');
check(is_callable($captured['options']['on_stats'] ?? null), 'The transport receives a timing capture callback');
check(($result['upc'] ?? null) === '012345678905', 'The requested UPC remains authoritative in the preview response');
check(($result['product']['name'] ?? null) === 'Test Product', 'Product metadata is normalized');
check(count($result['images'] ?? []) === 1, 'Unsafe and invalid image URLs are removed');
check(($result['images'][0]['download_token'] ?? null) === 'abcdefghijklmnopqrstuvwx', 'Opaque image tokens remain available only in preview data');
check(($result['sources'] ?? null) === ['openfoodfacts', 'searxng'], 'Sources are de-duplicated');
check(($result['outcome'] ?? null) === 'success', 'Companion success is preserved as a finite outcome');
check(($result['diagnostics']['trace_id'] ?? null) === $traceContext['trace_id'], 'Diagnostics use the trusted owned trace ID');
check(($result['diagnostics']['versions'] ?? null) === [
	'grocy' => '4.6.0',
	'module' => $moduleVersion,
	'companion' => '0.1.0',
	'contract' => '1'
], 'Diagnostics contain only portable version values');
check(($result['diagnostics']['overall_duration_ms'] ?? null) === 25, 'Overall transfer timing is safely rounded in milliseconds');

$serializedDiagnostics = json_encode($result['diagnostics'] ?? [], JSON_THROW_ON_ERROR);
foreach ([
	'012345678905',
	'test-secret-never-return',
	'session=cookie-canary',
	'Bearer bearer-canary',
	'traceparent',
	'https://provider.invalid/search?q=url-canary',
	'image-token-canary',
	'payload-canary',
	'exception-canary',
	'abcdefghijklmnopqrstuvwx'
] as $canary)
{
	check(!str_contains($serializedDiagnostics, $canary), "Diagnostic JSON excludes privacy canary {$canary}");
}

foreach (['success', 'partial_image', 'not_found', 'timeout', 'provider_error'] as $outcome)
{
	$outcomeService = new GrocyAiService(function (string $url, array $headers, $options) use ($outcome): array
	{
		return ['status' => 200, 'body' => companionBody($outcome)];
	});
	try
	{
		$outcomeResult = $outcomeService->EnrichByUpc('012345678905', $traceContext);
		check(($outcomeResult['outcome'] ?? null) === $outcome, "Finite companion outcome {$outcome} is preserved");
		check(($outcomeResult['diagnostics']['outcome'] ?? null) === $outcome, "Diagnostic outcome {$outcome} matches the response");
	}
	catch (Throwable)
	{
		check(false, "Finite companion outcome {$outcome} is accepted");
	}
}

$malformedImagesService = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => companionBody('success', ['images' => 'not-a-list'])
]);
check($malformedImagesService->EnrichByUpc('012345678905', $traceContext)['images'] === [], 'Malformed image collections are ignored safely');

$statusJson = json_encode($service->GetStatus(), JSON_THROW_ON_ERROR);
check(!str_contains($statusJson, GROCY_AI_SERVICE_API_KEY), 'Status never exposes the API key');
check(!str_contains($statusJson, '012345678905'), 'Status never exposes a GTIN');
check(!str_contains($statusJson, GROCY_AI_SERVICE_URL), 'Status never exposes the companion URL');
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
expectException(fn() => $badJsonService->EnrichByUpc('012345678905', $traceContext), RuntimeException::class, 'Invalid companion JSON is rejected');

$failedService = new GrocyAiService(fn(): array => ['status' => 500, 'body' => '{}']);
expectException(fn() => $failedService->EnrichByUpc('012345678905', $traceContext), RuntimeException::class, 'Companion HTTP errors are rejected');

if (class_exists(GrocyAiDiagnostic::class))
{
	$failure = GrocyAiDiagnostic::FailureEnvelope($traceContext, 'timeout', 'timeout', 'deadline', 'exception-canary');
	$failureJson = json_encode($failure, JSON_THROW_ON_ERROR);
	check(($failure['outcome'] ?? null) === 'timeout', 'Timeout failures use a finite safe outcome');
	check(!str_contains($failureJson, 'exception-canary'), 'Failure envelopes never include raw exception text');
	check(!str_contains($failureJson, '012345678905'), 'Failure envelopes never include GTIN values');
}

if ($failures > 0)
{
	fwrite(STDERR, "{$failures} of {$tests} checks failed\n");
	exit(1);
}

fwrite(STDOUT, "All {$tests} grocy_AI checks passed\n");
