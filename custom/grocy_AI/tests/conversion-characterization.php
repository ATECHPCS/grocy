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
	$blockedDataPath = __DIR__ . '/fixtures/blocked-grocy-datapath';
	$worktreeRoot = dirname(__DIR__, 3);
	$primaryCheckout = dirname(dirname($worktreeRoot));
	$mainRoot = getenv('GROCY_CHARACTERIZATION_MAIN_ROOT') ?: ((is_dir($primaryCheckout . '/.git') || is_file($primaryCheckout . '/.git')) ? $primaryCheckout : $worktreeRoot);
	$stableRoot = getenv('GROCY_CHARACTERIZATION_STABLE_ROOT') ?: dirname($mainRoot) . '/grocy-atech-release';

	$result = runConversionCharacterization($mainRoot, $stableRoot, $fixtureRoot, $blockedDataPath);
	characterizationAssert($result['main']['fixture_deleted'] === true, 'main fixture is deleted');
	characterizationAssert($result['stable']['fixture_deleted'] === true, 'stable fixture is deleted');
	characterizationAssert($result['protected_outputs']['equal'] === true, 'protected categories are equivalent');
	characterizationAssertNoPathPrefix($result['opened_paths'], $blockedDataPath, 'configured data path is never opened');
	characterizationExpectFailure('missing_branch_manifest', fn() => runConversionCharacterization($mainRoot, $stableRoot, $fixtureRoot . '/missing', $blockedDataPath));
	characterizationExpectFailure('fixture_path_inside_grocy_datapath', fn() => runConversionCharacterization($mainRoot, $stableRoot, $blockedDataPath, $blockedDataPath));

	fwrite(STDOUT, "Conversion characterization contract passed\n");
}
