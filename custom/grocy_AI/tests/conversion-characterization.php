<?php

declare(strict_types=1);

function characterizationAssert(bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

function characterizationAssertNoPathPrefix(array $openedPaths, string $blockedDataPath, string $message): void
{
	$blockedPrefix = rtrim(str_replace('\\', '/', $blockedDataPath), '/') . '/';
	foreach ($openedPaths as $openedPath)
	{
		$normalizedPath = str_replace('\\', '/', (string)$openedPath);
		characterizationAssert($normalizedPath !== rtrim($blockedPrefix, '/') && !str_starts_with($normalizedPath, $blockedPrefix), $message);
	}
}

function characterizationExpectFailure(string $expectedReason, Closure $callback): void
{
	try
	{
		$callback();
	}
	catch (RuntimeException $exception)
	{
		characterizationAssert($exception->getMessage() === $expectedReason, "Expected {$expectedReason}, got {$exception->getMessage()}");
		return;
	}

	throw new RuntimeException("Expected failure {$expectedReason}");
}

function runConversionCharacterizationContract(): void
{
	if (!function_exists('runConversionCharacterization'))
	{
		throw new RuntimeException('EXPECTED_RED: conversion-characterization: disposable harness is not implemented');
	}

	$fixtureRoot = __DIR__ . '/fixtures';
	$worktreeRoot = dirname(__DIR__, 3);
	$primaryCheckout = dirname(dirname($worktreeRoot));
	$mainRoot = getenv('GROCY_CHARACTERIZATION_MAIN_ROOT') ?: ((is_dir($primaryCheckout . '/.git') || is_file($primaryCheckout . '/.git')) ? $primaryCheckout : $worktreeRoot);
	$stableRoot = getenv('GROCY_CHARACTERIZATION_STABLE_ROOT') ?: dirname($mainRoot) . '/grocy-atech-release';
	$previousDataPath = getenv('GROCY_DATAPATH');
	$fixtureConfiguredDataPath = __DIR__ . '/fixtures/configured-grocy-datapath';
	putenv('GROCY_DATAPATH=' . $fixtureConfiguredDataPath);
	characterizationAssert(conversionCharacterizationConfiguredDataPath($mainRoot) === str_replace('\\', '/', $fixtureConfiguredDataPath), 'configured GROCY_DATAPATH is resolved without opening it');
	if ($previousDataPath === false)
	{
		putenv('GROCY_DATAPATH');
	}
	else
	{
		putenv('GROCY_DATAPATH=' . $previousDataPath);
	}
	$blockedDataPath = conversionCharacterizationConfiguredDataPath($mainRoot);

	$result = runConversionCharacterization($mainRoot, $stableRoot, $fixtureRoot, $blockedDataPath);
	characterizationAssert($result['main']['fixture_deleted'] === true, 'main fixture is deleted');
	characterizationAssert($result['stable']['fixture_deleted'] === true, 'stable fixture is deleted');
	characterizationAssert($result['protected_outputs']['equal'] === true, 'protected categories are equivalent');
	characterizationAssert(count(array_unique(array_column($result['main']['protected_outputs']['baseline'], 'converted_amount'))) === 8, 'protected categories have distinct fixture outputs');
	characterizationAssert($result['manifest_json'] === conversionCharacterizationRedactedManifestJson($result), 'redacted manifest serialization is deterministic');
	conversionCharacterizationVerifyEvidenceDocument(dirname(__DIR__, 3) . '/.planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md', $result['manifest']);
	characterizationAssertNoPathPrefix($result['opened_paths'], $blockedDataPath, 'configured data path is never opened');
	characterizationExpectFailure('missing_branch_manifest', fn() => runConversionCharacterization($mainRoot, $stableRoot, $fixtureRoot . '/missing', $blockedDataPath));
	characterizationExpectFailure('fixture_path_inside_grocy_datapath', fn() => runConversionCharacterization($mainRoot, $stableRoot, $blockedDataPath, $blockedDataPath));
	characterizationExpectFailure('identical_branch_roots', fn() => runConversionCharacterization($mainRoot, $mainRoot, $fixtureRoot, $blockedDataPath));
	characterizationExpectFailure('cache_aggregate_changed', fn() => conversionCharacterizationAssertProbeCacheParity(
		['row_count' => 7, 'row_key_factor_path_sha256' => 'baseline'],
		['row_count' => 7, 'row_key_factor_path_sha256' => 'probe']
	));
	characterizationExpectFailure('branch_characterization_mismatch', fn() => conversionCharacterizationAssertBranchParity(
		['schema' => ['migration_hashes' => [], 'cache_objects' => [], 'fixture_sqlite_master' => []], 'cache' => ['baseline' => ['row_key_factor_path_sha256' => 'main'], 'probe' => ['row_key_factor_path_sha256' => 'main']], 'query_plan' => ['main-plan'], 'protected_outputs' => ['baseline' => [], 'probe' => []]],
		['schema' => ['migration_hashes' => [], 'cache_objects' => [], 'fixture_sqlite_master' => []], 'cache' => ['baseline' => ['row_key_factor_path_sha256' => 'stable'], 'probe' => ['row_key_factor_path_sha256' => 'stable']], 'query_plan' => ['stable-plan'], 'protected_outputs' => ['baseline' => [], 'probe' => []]]
	));
	characterizationExpectFailure('branch_characterization_mismatch', fn() => conversionCharacterizationAssertBranchParity(
		['schema' => ['migration_hashes' => [], 'cache_objects' => [], 'fixture_sqlite_master' => []], 'cache' => ['baseline' => ['row_key_factor_path_sha256' => 'same'], 'probe' => ['row_key_factor_path_sha256' => 'same']], 'query_plan' => ['main-plan'], 'protected_outputs' => ['baseline' => [], 'probe' => []]],
		['schema' => ['migration_hashes' => [], 'cache_objects' => [], 'fixture_sqlite_master' => []], 'cache' => ['baseline' => ['row_key_factor_path_sha256' => 'same'], 'probe' => ['row_key_factor_path_sha256' => 'same']], 'query_plan' => ['stable-plan'], 'protected_outputs' => ['baseline' => [], 'probe' => []]]
	));

	fwrite(STDOUT, 'Conversion characterization manifest: ' . $result['manifest_json'] . PHP_EOL);
	fwrite(STDOUT, "Conversion characterization contract passed\n");
}
