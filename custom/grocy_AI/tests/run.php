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

$contractFile = __DIR__ . '/../src/GrocyAiContract.php';
if (is_file($contractFile))
{
	require_once $contractFile;
}

$gtinFile = __DIR__ . '/../src/GrocyAiGtin.php';
if (is_file($gtinFile))
{
	require_once $gtinFile;
}

$barcodeServiceFile = __DIR__ . '/../src/GrocyAiBarcodeService.php';
if (is_file($barcodeServiceFile))
{
	require_once $barcodeServiceFile;
}

$taxonomyMigrationFile = __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
if (is_file($taxonomyMigrationFile))
{
	require_once $taxonomyMigrationFile;
}

$taxonomyServiceFile = __DIR__ . '/../src/GrocyAiTaxonomyService.php';
if (is_file($taxonomyServiceFile))
{
	require_once $taxonomyServiceFile;
}

$taxonomyTestFile = __DIR__ . '/taxonomy.php';
if (is_file($taxonomyTestFile))
{
	require_once $taxonomyTestFile;
}

$conversionMigrationFile = __DIR__ . '/../src/GrocyAiConversionMigration.php';
if (is_file($conversionMigrationFile))
{
	require_once $conversionMigrationFile;
}

$conversionServiceFile = __DIR__ . '/../src/GrocyAiConversionService.php';
if (is_file($conversionServiceFile))
{
	require_once $conversionServiceFile;
}

$conversionRulesTestFile = __DIR__ . '/conversions.php';
if (is_file($conversionRulesTestFile))
{
	require_once $conversionRulesTestFile;
}

if (($argv[1] ?? '') === 'conversion-characterization')
{
	require_once __DIR__ . '/conversion-characterization.php';
	runConversionCharacterizationContract();
	exit(0);
}

use GrocyAI\Services\GrocyAiDiagnostic;
use GrocyAI\Services\GrocyAiContract;
use GrocyAI\Services\GrocyAiService;
use GrocyAI\Services\GrocyAiServiceException;

function expectedRed(string $marker, string $message): never
{
	fwrite(STDERR, $marker . PHP_EOL);
	fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
	exit(1);
}

function enrichmentV2Fixture(string $caseId): string
{
	$fixturePath = __DIR__ . '/fixtures/enrichment-v2-cases.json';
	$fixtureDocument = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
	foreach ($fixtureDocument['cases'] ?? [] as $case)
	{
		if (($case['id'] ?? null) === $caseId && is_string($case['raw_json'] ?? null))
		{
			return $case['raw_json'];
		}
	}

	throw new RuntimeException("Missing enrichment-v2 fixture case {$caseId}");
}

function runDuplicateContractCase(string $caseId, string $marker): never
{
	$rawDocuments = [$caseId];
	if ($caseId === 'duplicate_nested')
	{
		$rawDocuments[] = 'duplicate_escaped_nested';
	}

	if (!class_exists(GrocyAiContract::class) || !method_exists(GrocyAiContract::class, 'DecodeAndValidateRaw'))
	{
		expectedRed($marker, 'The raw duplicate-aware contract-v2 validator is not implemented');
	}

	foreach ($rawDocuments as $fixtureId)
	{
		try
		{
			GrocyAiContract::DecodeAndValidateRaw(enrichmentV2Fixture($fixtureId));
			expectedRed($marker, "Fixture {$fixtureId} was accepted after duplicate members collapsed");
		}
		catch (InvalidArgumentException)
		{
			// The whole raw document was rejected as contract_invalid.
		}
	}

	fwrite(STDOUT, "Contract case {$caseId} passed\n");
	exit(0);
}

function phase2BladeRender(): array
{
	$repoRoot = dirname(__DIR__, 3);
	$autoload = resolveBladeAutoload($repoRoot);
	if ($autoload === '')
	{
		return [false, 'The real Blade autoloader is unavailable', ''];
	}
	require_once $autoload;

	$template = file_get_contents($repoRoot . '/views/productform.blade.php');
	$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
	try
	{
		$compiledTemplate = $compiler->compileString($template);
		token_get_all($compiledTemplate, TOKEN_PARSE);

		$rootOffset = strpos($template, 'id="grocy-ai-product-enrichment"');
		$pictureOffset = strpos($template, '<div class="row @if($mode == \'edit\' && !GROCY_FEATURE_FLAG_GROCY_AI)', $rootOffset ?: 0);
		$startOffset = $rootOffset === false ? false : strrpos(substr($template, 0, $rootOffset), '@if(GROCY_FEATURE_FLAG_GROCY_AI)');
		if ($startOffset === false || $pictureOffset === false)
		{
			return [false, 'The actual feature-hook block could not be isolated', ''];
		}
		$hook = substr($template, $startOffset, $pictureOffset - $startOffset);
		$compiledHook = $compiler->compileString($hook);
		token_get_all($compiledHook, TOKEN_PARSE);

		$mode = 'create';
		$product = (object)['id' => 91];
		$barcodes = [];
		$userfields = [(object)[
			'id' => 27,
			'entity' => 'products',
			'name' => 'products.brand',
			'type' => 'text-single-line',
			'caption' => 'Brand "quoted" <verified>',
			'input_required' => 0
		]];
		$__t = static fn(string $value): string => 'L10N:' . $value;
		$U = static fn(string $value): string => $value;
		$bufferLevel = ob_get_level();
		ob_start();
		eval('?>' . $compiledHook);
		$rendered = (string)ob_get_clean();
		return [true, '', $rendered];
	}
	catch (Throwable $ex)
	{
		$bufferLevel ??= ob_get_level();
		while (ob_get_level() > $bufferLevel)
		{
			ob_end_clean();
		}
		return [false, $ex->getMessage(), ''];
	}
}

function resolveBladeAutoload(string $repoRoot): string
{
	$configured = getenv('GROCY_BLADE_AUTOLOAD');
	$candidates = [];
	if (is_string($configured) && $configured !== '')
	{
		$candidates[] = $configured;
	}
	$candidates = array_merge($candidates, [
		$repoRoot . '/packages/autoload.php',
		'/app/packages/autoload.php',
		'/var/www/html/packages/autoload.php',
		'/var/www/grocy/packages/autoload.php'
	]);

	foreach (array_unique($candidates) as $candidate)
	{
		if (is_file($candidate))
		{
			return $candidate;
		}
	}

	return '';
}

function runBladeGroup(): never
{
	$repoRoot = dirname(__DIR__, 3);
	$autoload = resolveBladeAutoload($repoRoot);
	if ($autoload === '')
	{
		expectedRed('EXPECTED_RED: blade.integrated_acceptance', 'The real Blade autoloader is unavailable in the checkout or approved container paths');
	}
	require_once $autoload;

	$template = file_get_contents($repoRoot . '/views/productform.blade.php');
	$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
	try
	{
		$compiled = $compiler->compileString($template);
		token_get_all($compiled, TOKEN_PARSE);
	}
	catch (Throwable $ex)
	{
		expectedRed('EXPECTED_RED: blade.integrated_acceptance', 'The complete product form did not compile as parseable PHP: ' . $ex->getMessage());
	}

	$moduleVersion = (string)(json_decode(file_get_contents($repoRoot . '/custom/grocy_AI/module-version.json'), true, 512, JSON_THROW_ON_ERROR)['module_version'] ?? '');
	$assetMatch = [];
	if (preg_match('/\$grocyAiAssetVersion = \'([^\']+)\'/', $template, $assetMatch) !== 1
		|| ($assetMatch[1] ?? '') !== $moduleVersion
		|| substr_count($template, '{{ $grocyAiAssetVersion }}') !== 4)
	{
		expectedRed('EXPECTED_RED: blade.integrated_acceptance', 'The CSS and JavaScript asset token is not synchronized with module-version.json');
	}

	[$renderedOk, $message, $rendered] = phase2BladeRender();
	$required = [
		'id="grocy-ai-product-enrichment"',
		'id="grocy-ai-field-review"',
		'id="grocy-ai-media-review"',
		'id="grocy-ai-final-diff"',
		'id="grocy-ai-stage-selected-button"',
		'data-brand-target-id="27"',
		'data-brand-target-name="products.brand"',
		'data-package-size-target-available="false"',
		'data-food-type-target-available="false"',
		'L10N:Review selected changes',
		'L10N:Stage selected changes',
		'L10N:Load thumbnail',
		'L10N:Selected changes are staged in the form. Review the form, then use Grocy&#039;s Save button to save them.'
	];
	foreach ($required as $expected)
	{
		if (!$renderedOk || !str_contains($rendered, $expected))
		{
			expectedRed('EXPECTED_RED: blade.integrated_acceptance', $renderedOk ? "Rendered feature hook is missing {$expected}" : $message);
		}
	}
	if (str_contains($rendered, 'Nutrition Facts') || str_contains($rendered, '<verified>'))
	{
		expectedRed('EXPECTED_RED: blade.integrated_acceptance', 'Rendered markup exposed deferred nutrition or unescaped target metadata');
	}

	fwrite(STDOUT, "Blade integrated acceptance passed\n");
	exit(0);
}

function runBladePhase2TargetsCase(): never
{
	[$renderedOk, $message, $rendered] = phase2BladeRender();
	$required = [
		'id="grocy-ai-field-review"',
		'id="grocy-ai-field-rows"',
		'id="grocy-ai-selection-status"',
		'id="grocy-ai-final-diff"',
		'id="grocy-ai-stage-selected-button"',
		'id="grocy-ai-barcode-ownership"',
		'id="grocy-ai-scanned-barcode"',
		'data-current-product-id=""',
		'data-product-route-template="/product/__PRODUCT_ID__"',
		'data-brand-target-id="27"',
		'data-brand-target-name="products.brand"',
		'data-package-size-target-available="false"',
		'data-food-type-target-available="false"',
		'L10N:Use suggested value',
		'Brand &quot;quoted&quot; &lt;verified&gt;'
	];
	foreach ($required as $expected)
	{
		if (!$renderedOk || !str_contains($rendered, $expected))
		{
			expectedRed('EXPECTED_RED: blade.phase2_targets', $renderedOk ? "Rendered feature hook is missing {$expected}" : $message);
		}
	}
	if (str_contains($rendered, '<verified>'))
	{
		expectedRed('EXPECTED_RED: blade.phase2_targets', 'Target metadata was not HTML-escaped');
	}

	fwrite(STDOUT, "Blade phase2 target rendering passed\n");
	exit(0);
}

function pngDimensionFixture(int $width, int $height): string
{
	$header = "\x89PNG\r\n\x1a\n";
	$ihdr = pack('N', 13) . 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0) . pack('N', 0);
	return $header . $ihdr . str_repeat('x', 2200);
}

function runMediaPixelLimitCase(): never
{
	$marker = 'EXPECTED_RED: media.pixel_limit';
	$validDimensions = [
		[32, 32],
		[4000, 4000],
		[4096, 3906]
	];
	foreach ($validDimensions as [$width, $height])
	{
		$body = pngDimensionFixture($width, $height);
		$service = new GrocyAiService(fn(): array => [
			'status' => 200,
			'body' => $body,
			'content_type' => 'image/png'
		]);
		try
		{
			$result = $service->FetchImage('full', 'opaquevariantboundhandle01');
			if (($result['body'] ?? null) !== $body)
			{
				expectedRed($marker, "Valid {$width}x{$height} PNG bytes were changed");
			}
		}
		catch (Throwable $ex)
		{
			expectedRed($marker, "Valid inclusive {$width}x{$height} PNG was rejected");
		}
	}

	$invalidBodies = [
		'zero_width' => pngDimensionFixture(0, 32),
		'below_minimum_width' => pngDimensionFixture(31, 32),
		'above_maximum_width' => pngDimensionFixture(4097, 32),
		'above_pixel_limit' => pngDimensionFixture(4000, 4001),
		'malformed_dimensions' => "\x89PNG\r\n\x1a\n" . str_repeat('x', 2200)
	];
	foreach ($invalidBodies as $caseId => $body)
	{
		$service = new GrocyAiService(fn(): array => [
			'status' => 200,
			'body' => $body,
			'content_type' => 'image/png'
		]);
		try
		{
			$service->FetchImage('full', 'opaquevariantboundhandle01');
			expectedRed($marker, "Invalid decoded dimension case {$caseId} was accepted");
		}
		catch (RuntimeException)
		{
			// Exact MIME, magic, dimensions, and pixel bounds rejected the bytes.
		}
	}

	fwrite(STDOUT, "Grocy decoded media dimension bounds passed\n");
	exit(0);
}

final class BoundedReadableTestBody
{
	public int $bytesRead = 0;
	public bool $stringified = false;
	private int $offset = 0;

	public function __construct(private string $body)
	{
	}

	public function read(int $length): string
	{
		$chunk = substr($this->body, $this->offset, $length);
		$this->offset += strlen($chunk);
		$this->bytesRead += strlen($chunk);
		return $chunk;
	}

	public function eof(): bool
	{
		return $this->offset >= strlen($this->body);
	}

	public function __toString(): string
	{
		$this->stringified = true;
		return $this->body;
	}
}

function validContractDocument(): array
{
	return json_decode(enrichmentV2Fixture('valid_name_review'), true, 512, JSON_THROW_ON_ERROR);
}

function traceContextForDocument(array &$document): array
{
	$traceId = '1234567890abcdef1234567890abcdef';
	$document['diagnostics']['trace_id'] = $traceId;
	return [
		'trace_id' => $traceId,
		'parent_id' => '1234567890abcdef',
		'flags' => '01',
		'traceparent' => '00-' . $traceId . '-1234567890abcdef-01'
	];
}

function assertContractInvalidService(string $raw, string $marker, array $response = []): void
{
	$document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
	$traceContext = traceContextForDocument($document);
	$raw = json_encode($document, JSON_THROW_ON_ERROR);
	$service = new GrocyAiService(static fn(): array => array_merge([
		'status' => 200,
		'body' => $raw
	], $response));
	try
	{
		$service->EnrichByUpc('012345678905', $traceContext);
		expectedRed($marker, 'The service returned a partial DTO instead of the finite contract-invalid recovery');
	}
	catch (GrocyAiServiceException $ex)
	{
		if ($ex->GetDiagnosticErrorCode() !== 'contract_invalid')
		{
			expectedRed($marker, 'The service did not return the finite contract-invalid recovery');
		}
	}
}

function runDuplicateFieldCase(): never
{
	$marker = 'EXPECTED_RED: contract.duplicate_field';
	$document = validContractDocument();
	$duplicate = $document['suggestions'][0];
	$duplicate['id'] = 'name:openfoodfacts:1';
	$duplicate['value'] = 'Different fixture name';
	$duplicate['display_value'] = 'Different fixture name';
	$document['suggestions'][] = $duplicate;
	$raw = json_encode($document, JSON_THROW_ON_ERROR);
	try
	{
		GrocyAiContract::DecodeAndValidateRaw($raw, '012345678905');
		expectedRed($marker, 'Two different IDs for the same closed field were accepted');
	}
	catch (InvalidArgumentException)
	{
		// The all-or-nothing PHP validator rejected the duplicate field.
	}
	assertContractInvalidService($raw, $marker);
	fwrite(STDOUT, "Duplicate suggestion field rejection passed\n");
	exit(0);
}

function runCrossedMediaSourceCase(): never
{
	$marker = 'EXPECTED_RED: contract.crossed_media_source';
	$cases = [
		'front_with_search_source' => ['front_package', 'searxng', 'Search result', 'high', 'canonical_structured_front_image', 'structured_direct'],
		'front_with_noncanonical_label' => ['front_package', 'openfoodfacts', 'OpenFoodFacts', 'high', 'canonical_structured_front_image', 'structured_direct'],
		'search_with_structured_source' => ['search_alternative', 'openfoodfacts', 'Open Food Facts', 'unverified', 'unverified_search_result', 'search'],
		'search_with_noncanonical_label' => ['search_alternative', 'searxng', 'Search Result', 'unverified', 'unverified_search_result', 'search']
	];
	foreach ($cases as $caseId => [$kind, $sourceId, $sourceLabel, $confidence, $reason, $evidence])
	{
		$document = validContractDocument();
		$document['media'] = [[
			'id' => 'media:' . $caseId,
			'kind' => $kind,
			'thumbnail_handle' => 'abcdefghijklmnopqrstuvwx',
			'full_handle' => 'zyxwvutsrqponmlkjihgfedc',
			'source' => ['id' => $sourceId, 'label' => $sourceLabel],
			'confidence_band' => $confidence,
			'reason_code' => $reason,
			'evidence_kind' => $evidence,
			'retrieved_at' => '2026-08-13T12:00:00Z'
		]];
		$raw = json_encode($document, JSON_THROW_ON_ERROR);
		try
		{
			GrocyAiContract::DecodeAndValidateRaw($raw, '012345678905');
			expectedRed($marker, "Crossed source pair {$caseId} was accepted");
		}
		catch (InvalidArgumentException)
		{
			// The all-or-nothing PHP validator rejected the source-pair forgery.
		}
		assertContractInvalidService($raw, $marker);
	}
	fwrite(STDOUT, "Crossed media source rejection passed\n");
	exit(0);
}

function runResponseLimitCase(): never
{
	$marker = 'EXPECTED_RED: service.response_limit';
	if (!defined(GrocyAiService::class . '::MAX_ENRICHMENT_RESPONSE_BYTES'))
	{
		expectedRed($marker, 'The enrichment response ceiling is not defined');
	}
	$limit = constant(GrocyAiService::class . '::MAX_ENRICHMENT_RESPONSE_BYTES');
	$document = validContractDocument();
	$traceContext = traceContextForDocument($document);
	$raw = json_encode($document, JSON_THROW_ON_ERROR);
	$oversizedRaw = $raw . str_repeat(' ', $limit + 1 - strlen($raw));
	foreach ([
		'declared_oversized' => ['body' => $raw, 'content_length' => (string)($limit + 1)],
		'declared_malformed' => ['body' => $raw, 'content_length' => '65x536']
	] as $caseId => $response)
	{
		$service = new GrocyAiService(static fn(): array => ['status' => 200] + $response);
		try
		{
			$service->EnrichByUpc('012345678905', $traceContext);
			expectedRed($marker, "{$caseId} Content-Length was accepted");
		}
		catch (GrocyAiServiceException $ex)
		{
			if ($ex->GetDiagnosticErrorCode() !== 'contract_invalid') expectedRed($marker, "{$caseId} did not map to contract_invalid");
		}
	}
	$body = new BoundedReadableTestBody($oversizedRaw);
	$service = new GrocyAiService(static fn() => ['status' => 200, 'body' => $body, 'content_length' => '1']);
	try
	{
		$service->EnrichByUpc('012345678905', $traceContext);
		expectedRed($marker, 'A stream crossing the response ceiling was accepted');
	}
	catch (GrocyAiServiceException $ex)
	{
		if ($ex->GetDiagnosticErrorCode() !== 'contract_invalid' || $body->stringified || $body->bytesRead > $limit + 8192)
		{
			expectedRed($marker, 'The bounded reader did not stop at the response ceiling');
		}
	}
	fwrite(STDOUT, "Enrichment response limit rejection passed\n");
	exit(0);
}

function runDepthLimitCase(): never
{
	$marker = 'EXPECTED_RED: contract.depth_limit';
	if (!defined(GrocyAiContract::class . '::MAX_JSON_DEPTH'))
	{
		expectedRed($marker, 'The raw JSON nesting boundary is not defined');
	}
	$depth = constant(GrocyAiContract::class . '::MAX_JSON_DEPTH');
	$raw = str_repeat('[', $depth + 1) . '0' . str_repeat(']', $depth + 1);
	try
	{
		GrocyAiContract::DecodeAndValidateRaw($raw);
		expectedRed($marker, 'Raw JSON nested beyond the explicit boundary was accepted');
	}
	catch (InvalidArgumentException)
	{
		// The raw lexical parser failed before a decoded document was accepted.
	}
	fwrite(STDOUT, "Raw JSON depth rejection passed\n");
	exit(0);
}

if (($argv[1] ?? null) === '--case')
{
	$selectedCase = $argv[2] ?? '';
	if ($selectedCase === 'contract.duplicate_top_level')
	{
		runDuplicateContractCase('duplicate_top_level', 'EXPECTED_RED: contract.duplicate_top_level');
	}
	if ($selectedCase === 'contract.duplicate_nested')
	{
		runDuplicateContractCase('duplicate_nested', 'EXPECTED_RED: contract.duplicate_nested');
	}
	if ($selectedCase === 'blade.phase2_targets')
	{
		runBladePhase2TargetsCase();
	}
	if ($selectedCase === 'media.pixel_limit')
	{
		runMediaPixelLimitCase();
	}
	if ($selectedCase === 'contract.duplicate_field')
	{
		runDuplicateFieldCase();
	}
	if ($selectedCase === 'contract.crossed_media_source')
	{
		runCrossedMediaSourceCase();
	}
	if ($selectedCase === 'service.response_limit')
	{
		runResponseLimitCase();
	}
	if ($selectedCase === 'contract.depth_limit')
	{
		runDepthLimitCase();
	}

	fwrite(STDERR, "Unknown test case: {$selectedCase}\n");
	exit(2);
}

if (($argv[1] ?? null) === 'taxonomy-schema')
{
	runTaxonomySchema();
}

if (($argv[1] ?? null) === 'taxonomy-api')
{
	runTaxonomyApi();
}

if (($argv[1] ?? null) === 'taxonomy-assignment')
{
	runTaxonomyAssignment();
}

if (($argv[1] ?? null) === 'taxonomy-validation')
{
	runTaxonomyValidation();
}

if (($argv[1] ?? null) === 'taxonomy-production-paths')
{
	runTaxonomyProductionPaths();
}

if (($argv[1] ?? null) === 'conversion-rules')
{
	runConversionRules();
}

if (($argv[1] ?? null) === 'conversion-resolution')
{
	runConversionResolution();
}

if (($argv[1] ?? null) === 'conversion-product-status')
{
	runConversionProductStatus();
}

if (($argv[1] ?? null) === 'conversion-coverage')
{
	runConversionCoverage();
}

if (($argv[1] ?? null) === 'conversion-readonly-cli')
{
	runConversionReadOnlyCli();
}

if (($argv[1] ?? null) === 'conversion-native-save-hook')
{
	runConversionNativeSaveHook();
}

if (($argv[1] ?? null) === 'conversion-release-gate')
{
	runConversionReleaseGate();
}

if (($argv[1] ?? null) === 'conversion-post-activation-bypass')
{
	runConversionPostActivationBypass();
}

if (($argv[1] ?? null) === 'conversion-activation-command')
{
	runConversionActivationCommand();
}

if (($argv[1] ?? null) === '--list')
{
	foreach ([
		'contract.duplicate_top_level',
		'contract.duplicate_nested',
		'blade.phase2_targets',
		'media.pixel_limit',
		'contract.duplicate_field',
		'contract.crossed_media_source',
		'service.response_limit',
		'contract.depth_limit'
	] as $caseId)
	{
		fwrite(STDOUT, $caseId . PHP_EOL);
	}
	exit(0);
}

if (($argv[1] ?? null) === '--group' && ($argv[2] ?? null) === 'contract')
{
	if (!class_exists(GrocyAiContract::class))
	{
		fwrite(STDERR, "FAIL: The closed contract-v2 validator exists\n");
		exit(1);
	}

	$fixtureDocument = json_decode(file_get_contents(__DIR__ . '/fixtures/enrichment-v2-cases.json'), true, 512, JSON_THROW_ON_ERROR);
	$contractFailures = 0;
	foreach ($fixtureDocument['cases'] ?? [] as $case)
	{
		$expected = $case['expected'] ?? 'contract_invalid';
		try
		{
			$result = GrocyAiContract::DecodeAndValidateRaw((string)$case['raw_json'], $case['id'] === 'valid_name_review' ? '012345678905' : '');
			if ($expected !== 'valid' || ($result['contract_version'] ?? null) !== 2)
			{
				$contractFailures++;
				fwrite(STDERR, "FAIL: Contract fixture {$case['id']} is rejected wholesale\n");
			}
		}
		catch (InvalidArgumentException)
		{
			if ($expected === 'valid')
			{
				$contractFailures++;
				fwrite(STDERR, "FAIL: Contract fixture {$case['id']} is accepted\n");
			}
		}
	}

	$duplicateService = new GrocyAiService(fn(): array => [
		'status' => 200,
		'body' => enrichmentV2Fixture('duplicate_nested')
	]);
	try
	{
		$duplicateService->EnrichByUpc('012345678905');
		$contractFailures++;
		fwrite(STDERR, "FAIL: Service rejects duplicate members before lossy decode\n");
	}
	catch (GrocyAiServiceException $ex)
	{
		if ($ex->GetDiagnosticErrorCode() !== 'contract_invalid')
		{
			$contractFailures++;
			fwrite(STDERR, "FAIL: Service maps contract defects to contract_invalid\n");
		}
	}

	if ($contractFailures > 0)
	{
		exit(1);
	}
	printf("All %d contract-v2 fixture cases passed\n", count($fixtureDocument['cases'] ?? []) + 1);
	exit(0);
}

if (($argv[1] ?? null) === '--group' && ($argv[2] ?? null) === 'blade')
{
	runBladeGroup();
}

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
check(substr_count($productFormTemplate, '{{ $grocyAiAssetVersion }}') === 4, 'All custom product-form assets use the grocy_AI token');
$resolvedTemplate = file_get_contents($repoRoot . '/views/quantityunitconversionsresolved.blade.php');
$resolvedAssetMatch = [];
$hasResolvedAssetVersion = preg_match('/\$grocyAiAssetVersion = \'([^\']+)\'/', $resolvedTemplate, $resolvedAssetMatch) === 1;
check($hasResolvedAssetVersion, 'The resolved-conversions view defines one grocy_AI asset version token');
check(($resolvedAssetMatch[1] ?? null) === $moduleVersion, 'The resolved-conversions asset token matches the portable module version');
check(substr_count($resolvedTemplate, '{{ $grocyAiAssetVersion }}') === 2, 'All custom resolved-conversions assets use the grocy_AI token');
check(!str_contains($resolvedTemplate, 'conversion-explanations.js?v=\', true) }}{{ $version }}'), 'Resolved-conversions custom JavaScript is independent from the Grocy core version');
$coverageTemplate = file_get_contents($repoRoot . '/views/grocyai_conversioncoverage.blade.php');
$coverageAssetMatch = [];
$hasCoverageAssetVersion = preg_match('/\$grocyAiAssetVersion = \'([^\']+)\'/', $coverageTemplate, $coverageAssetMatch) === 1;
check($hasCoverageAssetVersion, 'The conversion coverage view defines one grocy_AI asset version token');
check(($coverageAssetMatch[1] ?? null) === $moduleVersion, 'The conversion coverage asset token matches the portable module version');
check(substr_count($coverageTemplate, '{{ $grocyAiAssetVersion }}') === 2, 'All custom conversion coverage assets use the grocy_AI token');
check(str_contains($coverageTemplate, 'permission-MASTER_DATA_EDIT'), 'The conversion coverage report is scoped to MASTER_DATA_EDIT');
check(!preg_match('/\b(POST|PUT|DELETE)\b/', $coverageTemplate), 'The conversion coverage view declares no write action');
check(!str_contains($productFormTemplate, 'grocy-ai.css?v=\', true) }}{{ $version }}'), 'Custom CSS is independent from the Grocy core version');
check(!str_contains($productFormTemplate, 'product-enrichment.js?v=\', true) }}{{ $version }}'), 'Custom JavaScript is independent from the Grocy core version');

$bladeAutoload = resolveBladeAutoload($repoRoot);
if ($bladeAutoload !== '')
{
	require_once $bladeAutoload;

	$bladeCompiler = new Illuminate\View\Compilers\BladeCompiler(
		new Illuminate\Filesystem\Filesystem(),
		sys_get_temp_dir()
	);

	try
	{
		$compiledProductForm = $bladeCompiler->compileString($productFormTemplate);
		token_get_all($compiledProductForm, TOKEN_PARSE);
		check(true, 'The real Blade compiler produces parseable PHP for the complete product form');
	}
	catch (Throwable $ex)
	{
		check(false, 'The real Blade compiler produces parseable PHP for the complete product form (' . $ex->getMessage() . ')');
	}

	$assetVersionFixture = <<<'BLADE'
@if(true)
@php
$grocyAiAssetVersion = '1.0.1';
@endphp
asset={{ $grocyAiAssetVersion }}
@endif
BLADE;

	try
	{
		$compiledFixture = $bladeCompiler->compileString($assetVersionFixture);
		$bufferLevel = ob_get_level();
		ob_start();
		eval('?>' . $compiledFixture);
		$renderedFixture = trim((string)ob_get_clean());
		check($renderedFixture === 'asset=1.0.1', 'The real Blade compiler renders the block-form asset token');
	}
	catch (Throwable $ex)
	{
		$bufferLevel ??= ob_get_level();
		while (ob_get_level() > $bufferLevel)
		{
			ob_end_clean();
		}
		check(false, 'The real Blade compiler renders the block-form asset token (' . $ex->getMessage() . ')');
	}
}

[$phase2BladeOk, $phase2BladeMessage, $phase2BladeHtml] = phase2BladeRender();
check($phase2BladeOk, 'The real Blade compiler renders the actual Phase 2 feature hook' . ($phase2BladeMessage === '' ? '' : ' (' . $phase2BladeMessage . ')'));
foreach ([
	'id="grocy-ai-field-review"',
	'id="grocy-ai-field-rows"',
	'id="grocy-ai-media-review"',
	'id="grocy-ai-structured-media-group"',
	'id="grocy-ai-search-media-group"',
	'id="grocy-ai-selection-status"',
	'id="grocy-ai-final-diff"',
	'id="grocy-ai-stage-selected-button"',
	'id="grocy-ai-barcode-ownership"',
	'id="grocy-ai-scanned-barcode"',
	'data-current-product-id=""',
	'data-product-route-template="/product/__PRODUCT_ID__"',
	'data-brand-target-id="27"',
	'data-brand-target-name="products.brand"',
	'data-package-size-target-available="false"',
	'data-food-type-target-available="false"',
	'L10N:Use suggested value',
	'Brand &quot;quoted&quot; &lt;verified&gt;'
] as $expectedPhase2Markup)
{
	check(str_contains($phase2BladeHtml, $expectedPhase2Markup), "The rendered Phase 2 hook contains {$expectedPhase2Markup}");
}
check(!str_contains($phase2BladeHtml, '<verified>'), 'The rendered Phase 2 target metadata remains escaped');

function companionBody(string $outcome = 'success', array $extra = []): string
{
	$contractOutcome = in_array($outcome, ['success', 'partial_image'], true) ? 'found' : $outcome;
	$suggestions = $contractOutcome === 'found' ? [[
		'id' => 'name:openfoodfacts:0',
		'field' => 'name',
		'value' => 'Test Product',
		'display_value' => 'Test Product',
		'source' => ['id' => 'openfoodfacts', 'label' => 'Open Food Facts'],
		'confidence_band' => 'high',
		'reason_code' => 'canonical_structured_match',
		'evidence_kind' => 'structured_direct',
		'retrieved_at' => '2026-08-13T12:00:00Z',
		'source_updated_at' => null,
		'target' => null
	]] : [];
	return json_encode(array_merge([
		'contract_version' => 2,
		'outcome' => $contractOutcome,
		'barcode' => [
			'scanned_gtin' => '012345678905',
			'canonical_gtin' => '00012345678905',
			'equivalents_checked' => ['012345678905', '00012345678905'],
			'status' => 'unused',
			'owner_product_id' => null
		],
		'suggestions' => $suggestions,
		'media' => [],
		'warnings' => [],
		'diagnostics' => [
			'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'
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
check(($result['barcode']['scanned_gtin'] ?? null) === '012345678905', 'The requested GTIN remains authoritative in the review contract');
check(($result['suggestions'][0]['value'] ?? null) === 'Test Product', 'Structured product metadata is a closed suggestion');
check(($result['media'] ?? null) === [], 'No raw media URL enters contract v2');
check(($result['warnings'] ?? null) === [], 'Warnings are a closed code list');
check(($result['outcome'] ?? null) === 'found', 'Companion success maps to the finite found outcome');
check(($result['diagnostics']['trace_id'] ?? null) === $traceContext['trace_id'], 'Diagnostics use the trusted owned trace ID');
check(array_keys($result['diagnostics'] ?? []) === ['trace_id'], 'Contract diagnostics expose only the owned trace ID');

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

foreach (['found', 'not_found', 'timeout', 'provider_error'] as $outcome)
{
	$outcomeService = new GrocyAiService(function (string $url, array $headers, $options) use ($outcome): array
	{
		return ['status' => 200, 'body' => companionBody($outcome)];
	});
	try
	{
		$outcomeResult = $outcomeService->EnrichByUpc('012345678905', $traceContext);
		check(($outcomeResult['outcome'] ?? null) === $outcome, "Finite companion outcome {$outcome} is preserved");
		check(($outcomeResult['diagnostics']['trace_id'] ?? null) === $traceContext['trace_id'], "Finite outcome {$outcome} retains only owned correlation");
	}
	catch (Throwable)
	{
		check(false, "Finite companion outcome {$outcome} is accepted");
	}
}

$malformedSuggestionsService = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => companionBody('found', ['suggestions' => 'not-a-list'])
]);
expectException(fn() => $malformedSuggestionsService->EnrichByUpc('012345678905', $traceContext), GrocyAiServiceException::class, 'Malformed suggestion collections reject the whole contract');

$structuredMedia = [
	'id' => 'image:openfoodfacts:front',
	'kind' => 'front_package',
	'thumbnail_handle' => 'thumbnail_front_capability_0001',
	'full_handle' => 'full_front_capability_0000000001',
	'source' => ['id' => 'openfoodfacts', 'label' => 'Open Food Facts'],
	'confidence_band' => 'high',
	'reason_code' => 'canonical_structured_front_image',
	'evidence_kind' => 'structured_direct',
	'retrieved_at' => '2026-08-13T12:00:00Z'
];
$searchMedia = [
	'id' => 'image:searxng:1',
	'kind' => 'search_alternative',
	'thumbnail_handle' => 'thumbnail_search_capability_001',
	'full_handle' => 'full_search_capability_000000001',
	'source' => ['id' => 'searxng', 'label' => 'Search result'],
	'confidence_band' => 'unverified',
	'reason_code' => 'unverified_search_result',
	'evidence_kind' => 'search',
	'retrieved_at' => '2026-08-13T12:00:00Z'
];
$mediaContractService = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => companionBody('found', ['media' => [$structuredMedia, $searchMedia]])
]);
$mediaContract = $mediaContractService->EnrichByUpc('012345678905', $traceContext);
check(array_column($mediaContract['media'], 'kind') === ['front_package', 'search_alternative'], 'Structured front media precedes the unverified search fallback');
check(!str_contains(json_encode($mediaContract['media'], JSON_THROW_ON_ERROR), 'http'), 'The validated Grocy media contract contains no external origin');
$misorderedMediaService = new GrocyAiService(fn(): array => [
	'status' => 200,
	'body' => companionBody('found', ['media' => [$searchMedia, $structuredMedia]])
]);
expectException(fn() => $misorderedMediaService->EnrichByUpc('012345678905', $traceContext), GrocyAiServiceException::class, 'Structured media after a search alternative rejects the whole contract');

$statusJson = json_encode($service->GetStatus(), JSON_THROW_ON_ERROR);
check(!str_contains($statusJson, GROCY_AI_SERVICE_API_KEY), 'Status never exposes the API key');
check(!str_contains($statusJson, '012345678905'), 'Status never exposes a GTIN');
check(!str_contains($statusJson, GROCY_AI_SERVICE_URL), 'Status never exposes the companion URL');
check($service->GetStatus()['mode'] === 'review-before-save', 'Phase 1 reports review-before-save mode');

$imageBody = pngDimensionFixture(32, 32);
$imageRequestUrl = null;
$imageService = new GrocyAiService(function (string $url, array $headers) use ($imageBody, &$imageRequestUrl): array
{
	$imageRequestUrl = $url;
	return ['status' => 200, 'body' => $imageBody, 'content_type' => 'image/png'];
});
$image = $imageService->FetchImage('full', 'abcdefghijklmnopqrstuvwx');
check($image['body'] === $imageBody, 'A selected image is returned without modification');
check($image['content_type'] === 'image/png', 'A supported image content type is preserved');
check($imageRequestUrl === 'https://grocy-ai.internal/base/v1/products/images/full/abcdefghijklmnopqrstuvwx', 'Grocy requests only the closed companion variant and opaque handle path');
expectException(fn() => $imageService->FetchImage('full', '../internal'), InvalidArgumentException::class, 'Invalid image handles are rejected');
expectException(fn() => $imageService->FetchImage('preview', 'abcdefghijklmnopqrstuvwx'), InvalidArgumentException::class, 'Invalid image variants are rejected');

$htmlService = new GrocyAiService(fn(): array => ['status' => 200, 'body' => str_repeat('x', 2500), 'content_type' => 'text/html']);
expectException(fn() => $htmlService->FetchImage('full', 'abcdefghijklmnopqrstuvwx'), RuntimeException::class, 'Non-image downloads are rejected');

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
