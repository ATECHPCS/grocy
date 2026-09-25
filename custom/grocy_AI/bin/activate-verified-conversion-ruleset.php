<?php

declare(strict_types=1);

/**
 * The maintainer-only promotion command: the one operational path that can make a named inactive
 * reusable conversion revision effective.
 *
 * It is deliberately thin. It authenticates the operator against a deployment-owned secret, checks
 * that the two supplied immutable proof artifacts still match the current characterization, and
 * then delegates every decision and every write to the single activation transaction. It resolves
 * no adapter, factor, cache key, or projection detail of its own, accepts no SQL or path option,
 * and prints only redacted references.
 */

use GrocyAI\Services\GrocyAiConversionMigration;
use GrocyAI\Services\GrocyAiConversionService;

const REFUSAL_USAGE = 2;
const REFUSAL_UNAUTHORIZED = 3;
const REFUSAL_EVIDENCE = 4;
const REFUSAL_INTERNAL = 1;

/**
 * The only output channel for a refusal: one fixed-shape redacted line, never a secret, a complete
 * immutable identifier, a configured path, a household value, or a raw exception.
 */
function refuse(string $reason, int $code): never
{
	fwrite(STDERR, json_encode(['status' => 'refused', 'reason' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit($code);
}

function reference(string $identifier): string
{
	return substr(hash('sha256', $identifier), 0, 12);
}

if (PHP_SAPI !== 'cli')
{
	refuse('cli_only', REFUSAL_USAGE);
}

/**
 * Exactly three required options, each once, each a closed identifier. Anything else — an unknown
 * option, a repeat, a positional argument, a wrong shape — is refused before any configuration or
 * database work.
 */
$options = [];
$arguments = array_slice($argv, 1);
for ($index = 0; $index < count($arguments); $index += 2)
{
	$name = $arguments[$index];
	if (!in_array($name, ['--revision', '--main-proof-artifact', '--stable-proof-artifact'], true)
		|| array_key_exists($name, $options) || !array_key_exists($index + 1, $arguments))
	{
		refuse('arguments_invalid', REFUSAL_USAGE);
	}
	$options[$name] = $arguments[$index + 1];
}
if (count($options) !== 3)
{
	refuse('arguments_invalid', REFUSAL_USAGE);
}

$revision = $options['--revision'];
$mainProof = $options['--main-proof-artifact'];
$stableProof = $options['--stable-proof-artifact'];
if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $revision) !== 1
	|| preg_match('/^[0-9a-f]{40}$/D', $mainProof) !== 1
	|| preg_match('/^[0-9a-f]{40}$/D', $stableProof) !== 1)
{
	refuse('arguments_invalid', REFUSAL_USAGE);
}

$dataPath = getenv('GROCY_DATAPATH');
if (!is_string($dataPath) || $dataPath === '' || $dataPath[0] !== '/')
{
	refuse('datapath_unconfigured', REFUSAL_USAGE);
}
$dataPath = rtrim($dataPath, '/');

/**
 * The maintainer secret is deployment-owned: an absolute path outside the Grocy data path, readable
 * only by its owner. The operator presents the same secret on standard input, so the promotion also
 * requires a deliberate act rather than merely inheriting the process's file access.
 */
$authFile = getenv('GROCY_AI_MAINTAINER_AUTH_FILE');
if (!is_string($authFile) || $authFile === '' || $authFile[0] !== '/')
{
	refuse('maintainer_auth_unconfigured', REFUSAL_USAGE);
}
$resolvedAuthFile = realpath($authFile);
if ($resolvedAuthFile === false || !is_file($resolvedAuthFile) || !is_readable($resolvedAuthFile)
	|| str_starts_with($resolvedAuthFile, $dataPath . '/') || $resolvedAuthFile === $dataPath)
{
	refuse('maintainer_auth_unavailable', REFUSAL_USAGE);
}
$permissions = fileperms($resolvedAuthFile);
if ($permissions === false || ($permissions & 0o077) !== 0)
{
	refuse('maintainer_auth_unavailable', REFUSAL_USAGE);
}
$expectedSecret = file_get_contents($resolvedAuthFile);
if (!is_string($expectedSecret) || preg_match('/^[0-9a-f]{64}$/D', trim($expectedSecret)) !== 1)
{
	refuse('maintainer_auth_unavailable', REFUSAL_USAGE);
}
$expectedSecret = trim($expectedSecret);

$presentedSecret = stream_get_contents(STDIN);
$presentedSecret = is_string($presentedSecret) ? trim($presentedSecret) : '';
if (!hash_equals($expectedSecret, $presentedSecret))
{
	refuse('maintainer_unauthorized', REFUSAL_UNAUTHORIZED);
}
$expectedSecret = '';
$presentedSecret = '';

$databasePath = $dataPath . '/grocy.db';
if (!is_file($databasePath) || !is_readable($databasePath) || !is_writable($databasePath))
{
	refuse('database_unavailable', REFUSAL_USAGE);
}

$characterizationFile = getenv('GROCY_AI_CHARACTERIZATION_FILE');
if (!is_string($characterizationFile) || $characterizationFile === '' || $characterizationFile[0] !== '/')
{
	$characterizationFile = dirname(__DIR__, 3) . '/.planning/phases/04-reusable-conversion-model/04-CHARACTERIZATION.md';
}

require_once __DIR__ . '/../src/GrocyAiTaxonomyMigration.php';
require_once __DIR__ . '/../src/GrocyAiConversionMigration.php';
require_once __DIR__ . '/../src/GrocyAiConversionService.php';

try
{
	$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$service = new GrocyAiConversionService($pdo, false);

	// The operator asserts which immutable revisions they reviewed. If the current characterization
	// no longer names exactly those, the assertion is stale and nothing may proceed.
	$proof = $service->ImmutableProofArtifacts($characterizationFile);
	if ($proof !== null && ($proof['main'] !== $mainProof || $proof['stable'] !== $stableProof))
	{
		refuse('immutable_proof_mismatch', REFUSAL_EVIDENCE);
	}
	if (!$service->RevisionIsPromotable($revision))
	{
		refuse('revision_not_promotable', REFUSAL_EVIDENCE);
	}

	// Everything else — evidence content, selected adapter, factors, projection, cache effects — is
	// resolved and enforced inside the one activation transaction.
	$result = $service->ActivateVerifiedRuleset(
		$service->ActivationBundleFromCharacterization($characterizationFile, [$revision])
	);
}
catch (Throwable)
{
	refuse('promotion_unavailable', REFUSAL_INTERNAL);
}

if (($result['status'] ?? null) !== 'active')
{
	refuse('activation_refused', REFUSAL_EVIDENCE);
}

fwrite(STDOUT, json_encode([
	'status' => 'active',
	'result_code' => 'promoted',
	'revision' => $revision,
	'selected_adapter' => $result['selected_adapter'],
	'main_proof_reference' => reference($mainProof),
	'stable_proof_reference' => reference($stableProof),
	'evidence_reference' => reference((string)$result['evidence_hash'])
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit(0);
