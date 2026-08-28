<?php

declare(strict_types=1);

use GrocyAI\Services\GrocyAiConversionMigration;
use GrocyAI\Services\GrocyAiConversionService;

function conversionRulesPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE quantity_units (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL)');
	$units = [
		[1, 'kg'], [2, 'lb'], [3, 'mL'], [4, 'L'], [5, 'pack'], [6, 'count'], [7, 'g'], [8, 'tsp'], [9, 'fl oz'], [10, 'piece']
	];
	$insert = $pdo->prepare('INSERT INTO quantity_units (id, name) VALUES (?, ?)');
	foreach ($units as [$id, $name])
	{
		$insert->execute([$id, $name]);
	}
	$pdo->exec('INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (91, 11, 5, 7, 12)');
	return $pdo;
}

function conversionAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
	if ($expected !== $actual)
	{
		throw new RuntimeException($message === '' ? 'conversion_assert_same_failed' : $message);
	}
}

function conversionAssertBlocked(GrocyAiConversionService $service, array $candidate, string $blocker): void
{
	$result = $service->ValidateNativeConversionBeforeWrite($candidate, null);
	conversionAssertSame('blocked', $result['status'], 'invalid conversion must be blocked');
	conversionAssertSame([$blocker], $result['blockers'], 'conversion blocker must be bounded and deterministic');
}

function conversionAssertAllowed(GrocyAiConversionService $service, array $candidate, string $status): void
{
	$result = $service->ValidateNativeConversionBeforeWrite($candidate, null);
	conversionAssertSame($status, $result['status'], 'eligible conversion must retain its scope status');
	conversionAssertSame([], $result['blockers'], 'eligible conversion must have no blockers');
}

function crossDimensionCandidate(): array
{
	return ['product_id' => null, 'from_qu_id' => 7, 'to_qu_id' => 3, 'factor' => '1'];
}

function reusablePackageCandidate(): array
{
	return ['product_id' => null, 'from_qu_id' => 5, 'to_qu_id' => 7, 'factor' => '12'];
}

function productScopedPackageCandidate(): array
{
	return ['product_id' => 11, 'from_qu_id' => 5, 'to_qu_id' => 7, 'factor' => '12'];
}

function productScopedDensityCandidate(): array
{
	return ['product_id' => 11, 'from_qu_id' => 7, 'to_qu_id' => 3, 'factor' => '0.9'];
}

function runConversionRules(): never
{
	if (!class_exists(GrocyAiConversionMigration::class) || !class_exists(GrocyAiConversionService::class))
	{
		expectedRed('EXPECTED_RED: conversion-rules', 'The inactive conversion migration and service are not implemented');
	}

	$pdo = conversionRulesPdo();
	$nativeBefore = $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	GrocyAiConversionMigration::Bootstrap($pdo);
	$service = new GrocyAiConversionService($pdo);

	$catalog = $pdo->query('SELECT unit_key, dimension, metric_factor, source_version FROM grocy_ai_conversion_catalog_units ORDER BY unit_key')->fetchAll(PDO::FETCH_ASSOC);
	conversionAssertSame([
		['unit_key' => 'cup', 'dimension' => 'volume', 'metric_factor' => '0.2365882365', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'fl_oz', 'dimension' => 'volume', 'metric_factor' => '0.0295735295625', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'g', 'dimension' => 'mass', 'metric_factor' => '1', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'gallon', 'dimension' => 'volume', 'metric_factor' => '3.785411784', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'kg', 'dimension' => 'mass', 'metric_factor' => '1000', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'l', 'dimension' => 'volume', 'metric_factor' => '1', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'lb', 'dimension' => 'mass', 'metric_factor' => '453.59237', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'mg', 'dimension' => 'mass', 'metric_factor' => '0.001', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'ml', 'dimension' => 'volume', 'metric_factor' => '0.001', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'oz', 'dimension' => 'mass', 'metric_factor' => '28.349523125', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'pint', 'dimension' => 'volume', 'metric_factor' => '0.473176473', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'quart', 'dimension' => 'volume', 'metric_factor' => '0.946352946', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'tbsp', 'dimension' => 'volume', 'metric_factor' => '0.01478676478125', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9'],
		['unit_key' => 'tsp', 'dimension' => 'volume', 'metric_factor' => '0.00492892159375', 'source_version' => 'NIST-SP-811-2008-Appendix-B.9']
	], $catalog, 'catalog must contain exactly the source-versioned D-01 identities and factors');
	conversionAssertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name LIKE 'cache__quantity_unit_conversions_resolved%'")->fetchAll(PDO::FETCH_COLUMN), 'inactive bootstrap must not project cache objects');

	$result = $service->ValidateNativeConversionBeforeWrite([
		'product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'
	], null);
	conversionAssertSame('inactive', $result['status'], 'valid reusable mass candidate must remain inactive');
	conversionAssertSame(['status', 'scope', 'blockers', 'factor', 'dimension', 'source_version', 'inactive_revision_id'], array_keys($result), 'conversion validation DTO keys must stay fixed');
	conversionAssertSame('mass', $result['dimension'], 'same-dimension catalog candidate must disclose its dimension');
	conversionAssertSame('2.2046226218487757', $result['factor'], 'candidate factor must retain precision without display rounding');
	conversionAssertSame('conversion-catalog-v1', $result['inactive_revision_id'], 'valid reusable candidate must identify its inactive revision');

	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '0'], 'factor_non_positive');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => 'NAN'], 'factor_not_finite');
	conversionAssertBlocked($service, crossDimensionCandidate(), 'dimension_mismatch');
	conversionAssertBlocked($service, reusablePackageCandidate(), 'reusable_count_scope');
	conversionAssertAllowed($service, productScopedPackageCandidate(), 'product_native');
	conversionAssertAllowed($service, productScopedDensityCandidate(), 'product_native');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.1'], 'factor_tolerance');
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757', 'inactive_revision_id' => 'stale'], 'stale_revision_identity');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET source_version = 'tampered-catalog-source' WHERE unit_key = 'lb'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE unit_key = 'lb'");
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET metric_factor = 'NAN', source_version = 'tampered-unrelated-source' WHERE unit_key = 'gallon'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_catalog_units SET metric_factor = '3.785411784', source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE unit_key = 'gallon'");
	$pdo->exec("UPDATE grocy_ai_conversion_revisions SET source_version = 'tampered-revision-source' WHERE id = 'conversion-catalog-v1'");
	$revisionSource = $service->ValidateNativeConversionBeforeWrite(['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], null);
	conversionAssertSame('blocked', $revisionSource['status'], 'tampered revision provenance must block reusable validation');
	conversionAssertSame(['revision_source_version_invalid'], $revisionSource['blockers'], 'tampered revision provenance must return a bounded blocker');
	conversionAssertSame('tampered-revision-source', $revisionSource['source_version'], 'validation DTO must disclose stored, not hard-coded, revision provenance');
	$pdo->exec("UPDATE grocy_ai_conversion_revisions SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE id = 'conversion-catalog-v1'");
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET source_version = 'tampered-rule-source' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_rule_source_version_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET source_version = 'NIST-SP-811-2008-Appendix-B.9' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET factor = '1001' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'catalog_rule_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_rules SET factor = '1000' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'");
	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'g', 'kg', '0.0011', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'reciprocal_mismatch');
	$pdo->exec("DELETE FROM grocy_ai_conversion_rules WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'g' AND to_unit_key = 'kg'");
	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'kg', 'g', '1001', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'competing_path');
	$pdo->exec("DELETE FROM grocy_ai_conversion_rules WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g' AND factor = '1001'");

	$pdo->prepare('INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES (?, ?, ?, ?, ?)')
		->execute(['conversion-catalog-v1', 'kg', 'lb', '2.2046226218487757', 'NIST-SP-811-2008-Appendix-B.9']);
	conversionAssertBlocked($service, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'cycle_detected');

	if ($nativeBefore !== $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
	{
		throw new RuntimeException('inactive_catalog_mutated_native_conversions');
	}
	if ((int)$pdo->query('SELECT COUNT(*) FROM grocy_ai_conversion_revisions WHERE status = \'inactive\'')->fetchColumn() !== 1)
	{
		throw new RuntimeException('inactive_revision_missing');
	}

	fwrite(STDOUT, "Conversion rule tests passed\n");
	exit(0);
}

function conversionResolutionPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec(<<<'SQL'
CREATE TABLE quantity_units (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL UNIQUE);
CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY, product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL);
CREATE TABLE product_groups (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL);
CREATE TABLE products (id INTEGER NOT NULL PRIMARY KEY, product_group_id INTEGER NULL, name TEXT NOT NULL);
CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, path TEXT NOT NULL);
INSERT INTO quantity_units (id, name) VALUES (1, 'cup'), (2, 'g'), (3, 'tbsp'), (4, 'ml');
INSERT INTO product_groups (id, name, active) VALUES (1, 'Beverages', 1);
INSERT INTO products (id, product_group_id, name) VALUES
	(1, NULL, 'Explicit water'),
	(2, NULL, 'Provider only'),
	(3, 1, 'Group only'),
	(4, NULL, 'Absent assignment'),
	(5, NULL, 'Explicit unclassified'),
	(6, NULL, 'Stale assignment'),
	(7, NULL, 'Excluded assignment'),
	(8, NULL, 'Unprofiled produce'),
	(9, NULL, 'Explicit whole milk'),
	(10, NULL, 'Explicit olive oil');
INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (91, 1, 4, 2, 1.01);
INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (1, 4, 2, 1.01, '91');
SQL);
	GrocyAI\Services\GrocyAiTaxonomyMigration::Bootstrap($pdo);
	$pdo->exec("INSERT INTO grocy_ai_taxonomy_nodes (id, version, parent_id, slug, label, depth) VALUES ('leaf-pet-food', 'v1', 'group-pantry', 'pet-food', 'Pet food', 2)");
	$pdo->exec(<<<'SQL'
INSERT INTO grocy_ai_taxonomy_evidence (product_id, provider_category, mapping_version, confidence_band, reason_code) VALUES (2, 'beverages', 'v1', 'high', 'provider_only');
INSERT INTO grocy_ai_taxonomy_classifications (product_id, leaf_id, ruleset_version) VALUES
	(1, 'leaf-beverages', 'v1'),
	(5, NULL, 'v1'),
	(6, 'leaf-beverages', 'stale'),
	(7, 'leaf-pet-food', 'v1'),
	(8, 'leaf-produce', 'v1'),
	(9, 'leaf-dairy-eggs', 'v1'),
	(10, 'leaf-oils-vinegars', 'v1');
SQL);
	return $pdo;
}

function conversionResolutionProtectedSnapshot(PDO $pdo): array
{
	return [
		'products' => $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'taxonomy_nodes' => $pdo->query('SELECT * FROM grocy_ai_taxonomy_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'taxonomy_classifications' => $pdo->query('SELECT * FROM grocy_ai_taxonomy_classifications ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC),
		'taxonomy_evidence' => $pdo->query('SELECT * FROM grocy_ai_taxonomy_evidence ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC),
		'native_conversions' => $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'native_cache' => $pdo->query('SELECT * FROM cache__quantity_unit_conversions_resolved ORDER BY product_id, from_qu_id, to_qu_id')->fetchAll(PDO::FETCH_ASSOC),
		'projection_objects' => $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE 'grocy_ai_conversion_projection%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC)
	];
}

function conversionResolutionInspectionSnapshot(PDO $pdo): array
{
	return array_merge(conversionResolutionProtectedSnapshot($pdo), [
		'conversion_catalog' => $pdo->query('SELECT * FROM grocy_ai_conversion_catalog_units ORDER BY unit_key')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_revisions' => $pdo->query('SELECT * FROM grocy_ai_conversion_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_rules' => $pdo->query('SELECT * FROM grocy_ai_conversion_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_validation_ledger' => $pdo->query('SELECT * FROM grocy_ai_conversion_validation_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_profile_revisions' => $pdo->query('SELECT * FROM grocy_ai_conversion_profile_revisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_profiles' => $pdo->query('SELECT * FROM grocy_ai_conversion_profiles ORDER BY profile_key')->fetchAll(PDO::FETCH_ASSOC),
		'conversion_migrations' => $pdo->query('SELECT * FROM grocy_ai_conversion_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC)
	]);
}

function conversionResolutionMissingTaxonomySnapshot(PDO $pdo): array
{
	return [
		'module_schema' => $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE 'grocy_ai_conversion_%' OR name LIKE 'grocy_ai_taxonomy_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC),
		'products' => $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'native_conversions' => $pdo->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'native_cache' => $pdo->query('SELECT * FROM cache__quantity_unit_conversions_resolved ORDER BY product_id, from_qu_id, to_qu_id')->fetchAll(PDO::FETCH_ASSOC),
		'total_changes' => (int)$pdo->query('SELECT total_changes()')->fetchColumn()
	];
}

function conversionResolutionMissingTaxonomyPdo(): PDO
{
	$pdo = conversionRulesPdo();
	$pdo->exec("CREATE TABLE products (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL); INSERT INTO products (id, name) VALUES (1, 'Fresh database product')");
	$pdo->exec("CREATE TABLE cache__quantity_unit_conversions_resolved (product_id INTEGER NULL, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, path TEXT NOT NULL); INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (11, 5, 7, 12, '91')");
	return $pdo;
}

function conversionResolutionMalformedTaxonomyPdo(): PDO
{
	$pdo = conversionResolutionMissingTaxonomyPdo();
	$pdo->exec("CREATE TABLE grocy_ai_taxonomy_nodes (id TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, parent_id TEXT NULL, slug TEXT NOT NULL, depth INTEGER NOT NULL)");
	$pdo->exec("CREATE TABLE grocy_ai_taxonomy_classifications (product_id INTEGER NOT NULL PRIMARY KEY, leaf_id TEXT NULL)");
	return $pdo;
}

function conversionResolutionViewTaxonomyPdo(): PDO
{
	$pdo = conversionResolutionMissingTaxonomyPdo();
	$pdo->exec("CREATE TABLE grocy_ai_taxonomy_nodes (id TEXT NOT NULL PRIMARY KEY, version TEXT NOT NULL, parent_id TEXT NULL, slug TEXT NOT NULL, depth INTEGER NOT NULL)");
	$pdo->exec("CREATE VIEW grocy_ai_taxonomy_classifications AS SELECT 1 AS product_id, NULL AS leaf_id");
	return $pdo;
}

function conversionResolutionAssertUnavailable(GrocyAiConversionService $service, int $productId, string $fromUnit, string $toUnit, string $blocker): void
{
	$result = $service->InspectSourcedProfile($productId, $fromUnit, $toUnit);
	conversionAssertSame('unavailable', $result['status'], 'ineligible profile inspection must be unavailable');
	conversionAssertSame([$blocker], $result['blockers'], 'unavailable profile inspection must identify one bounded eligibility reason');
	conversionAssertSame(null, $result['factor'], 'unavailable profile inspection must never expose a usable factor');
	conversionAssertSame(true, $result['approximate'], 'profile inspection must remain visibly approximate even when unavailable');
}

function conversionResolutionAssertBlockedInspection(PDO $pdo, int $productId, string $fromUnit, string $toUnit, string $blocker): void
{
	$before = conversionResolutionInspectionSnapshot($pdo);
	$result = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution($productId, $fromUnit, $toUnit);
	conversionAssertSame('blocked', $result['status'], 'invalid effective conversion graph must block inspection');
	conversionAssertSame([$blocker], $result['blockers'], 'effective graph blocker must be bounded and deterministic');
	conversionAssertSame(null, $result['factor'], 'blocked inspection must never expose a usable factor');
	conversionAssertSame(null, $result['winner_source'], 'blocked inspection must not claim a winning source');
	conversionAssertSame($before, conversionResolutionInspectionSnapshot($pdo), 'blocked inspection must not mutate products, taxonomy, module lifecycle, native conversions, cache, or projection state');
}

function conversionResolutionGraphPdo(): PDO
{
	$pdo = conversionResolutionPdo();
	GrocyAiConversionMigration::Bootstrap($pdo);
	return $pdo;
}

function conversionProductStatusRuntime(): void
{
	if (!defined('GROCY_MODE'))
	{
		define('GROCY_MODE', 'production');
	}
	if (!defined('GROCY_DATAPATH'))
	{
		define('GROCY_DATAPATH', sys_get_temp_dir());
	}
	if (!defined('GROCY_USER_ID'))
	{
		define('GROCY_USER_ID', 1);
	}
	require_once dirname(__DIR__, 3) . '/packages/autoload.php';
	require_once dirname(__DIR__) . '/src/GrocyAiApiController.php';
}

function conversionProductStatusRequest(string $productId, array $query = []): Psr\Http\Message\ServerRequestInterface
{
	return (new Slim\Psr7\Factory\ServerRequestFactory())
		->createServerRequest('GET', '/api/grocy-ai/products/' . $productId . '/conversion-status')
		->withQueryParams($query);
}

function conversionProductStatusInvoke(
	GrocyAI\Controllers\Api\GrocyAiApiController $controller,
	string|int|null $productId,
	array $query
): Psr\Http\Message\ResponseInterface
{
	return $controller->ProductConversionStatus(
		conversionProductStatusRequest(is_scalar($productId) ? (string)$productId : 'missing', $query),
		conversionNativeSaveHookResponse(),
		$productId === null ? [] : ['productId' => $productId]
	);
}

function conversionProductStatusBody(Psr\Http\Message\ResponseInterface $response): array
{
	$body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($body))
	{
		throw new RuntimeException('conversion_product_status_body_invalid');
	}
	return $body;
}

function conversionProductStatusSnapshot(PDO $pdo): array
{
	return array_merge(conversionResolutionInspectionSnapshot($pdo), [
		'activation_evidence' => $pdo->query('SELECT * FROM grocy_ai_conversion_activation_evidence ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'projection_spy' => $pdo->query('SELECT * FROM grocy_ai_conversion_projection_spy ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'route_write_spy' => $pdo->query('SELECT * FROM grocy_ai_conversion_route_write_spy ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'total_changes' => (int)$pdo->query('SELECT total_changes()')->fetchColumn()
	]);
}

function conversionProductStatusReadOnlyCall(
	PDO $pdo,
	GrocyAI\Controllers\Api\GrocyAiApiController $controller,
	string|int|null $productId,
	array $query
): Psr\Http\Message\ResponseInterface
{
	$before = conversionProductStatusSnapshot($pdo);
	$pdo->exec('PRAGMA query_only = ON');
	try
	{
		$response = conversionProductStatusInvoke($controller, $productId, $query);
	}
	finally
	{
		$pdo->exec('PRAGMA query_only = OFF');
	}
	conversionAssertSame($before, conversionProductStatusSnapshot($pdo), 'product status GET must not bootstrap, activate, project, refresh cache, or mutate module/native/taxonomy/product state');
	return $response;
}

function conversionProductStatusNativeSnapshot(PDO $pdo): array
{
	return array_merge(conversionValidationReadSnapshot($pdo), [
		'products' => $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
	]);
}

function conversionProductStatusNativeReadOnlyCall(
	PDO $pdo,
	GrocyAI\Controllers\Api\GrocyAiApiController $controller,
	string $productId,
	array $query
): Psr\Http\Message\ResponseInterface
{
	$before = conversionProductStatusNativeSnapshot($pdo);
	$pdo->exec('PRAGMA query_only = ON');
	try
	{
		$response = conversionProductStatusInvoke($controller, $productId, $query);
	}
	finally
	{
		$pdo->exec('PRAGMA query_only = OFF');
	}
	conversionAssertSame($before, conversionProductStatusNativeSnapshot($pdo), 'product status GET after native Save must not bootstrap, activate, project, refresh cache, or write again');
	return $response;
}

function conversionProductStatusAssertError(Psr\Http\Message\ResponseInterface $response, int $status, string $message): void
{
	conversionAssertSame($status, $response->getStatusCode(), 'product status failure must use its fixed HTTP status');
	conversionAssertSame(['error_message' => $message], conversionProductStatusBody($response), 'product status failure must expose only one bounded message');
}

function conversionProductStatusAssertRouteRegistered(): void
{
	$container = new DI\Container();
	$app = Slim\Factory\AppFactory::createFromContainer($container);
	require dirname(__DIR__) . '/routes.php';
	$matching = [];
	foreach ($app->getRouteCollector()->getRoutes() as $route)
	{
		if ($route->getPattern() === '/api/grocy-ai/products/{productId}/conversion-status')
		{
			$matching[] = $route->getMethods();
		}
	}
	conversionAssertSame([['GET']], $matching, 'product conversion status must be registered exactly once as GET with no custom write route');
}

function conversionProductStatusContract(): void
{
	conversionProductStatusRuntime();
	if (!method_exists(GrocyAI\Controllers\Api\GrocyAiApiController::class, 'ProductConversionStatus'))
	{
		expectedRed('EXPECTED_RED: conversion-product-status', 'The bounded read-only product conversion status endpoint is not implemented');
	}

	$pdo = conversionResolutionGraphPdo();
	$pdo->exec(<<<'SQL'
CREATE TABLE user_permissions_resolved (id INTEGER NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, permission_name TEXT NOT NULL);
INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT');
CREATE TABLE grocy_ai_conversion_activation_evidence (id INTEGER NOT NULL PRIMARY KEY, revision_id TEXT NOT NULL, evidence_hash TEXT NOT NULL);
CREATE TABLE grocy_ai_conversion_projection_spy (id INTEGER NOT NULL PRIMARY KEY, operation TEXT NOT NULL);
CREATE TABLE grocy_ai_conversion_route_write_spy (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, target TEXT NOT NULL, operation TEXT NOT NULL);
CREATE TRIGGER grocy_ai_conversion_route_products_update AFTER UPDATE ON products BEGIN
	INSERT INTO grocy_ai_conversion_route_write_spy (target, operation) VALUES ('products', 'update');
END;
CREATE TRIGGER grocy_ai_conversion_route_native_insert AFTER INSERT ON quantity_unit_conversions BEGIN
	INSERT INTO grocy_ai_conversion_route_write_spy (target, operation) VALUES ('quantity_unit_conversions', 'insert');
END;
CREATE TRIGGER grocy_ai_conversion_route_native_update AFTER UPDATE ON quantity_unit_conversions BEGIN
	INSERT INTO grocy_ai_conversion_route_write_spy (target, operation) VALUES ('quantity_unit_conversions', 'update');
END;
CREATE TRIGGER grocy_ai_conversion_route_cache_insert AFTER INSERT ON cache__quantity_unit_conversions_resolved BEGIN
	INSERT INTO grocy_ai_conversion_route_write_spy (target, operation) VALUES ('cache', 'insert');
END;
CREATE TRIGGER grocy_ai_conversion_route_cache_update AFTER UPDATE ON cache__quantity_unit_conversions_resolved BEGIN
	INSERT INTO grocy_ai_conversion_route_write_spy (target, operation) VALUES ('cache', 'update');
END;
SQL);
	conversionNativeSaveHookInstallDatabase($pdo);
	$controller = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiApiController::class))->newInstanceWithoutConstructor();
	conversionProductStatusAssertRouteRegistered();

	$query = ['from_unit_key' => 'ml', 'to_unit_key' => 'g'];
	$nativeResponse = conversionProductStatusReadOnlyCall($pdo, $controller, '1', $query);
	conversionAssertSame(200, $nativeResponse->getStatusCode(), 'valid product status request must succeed');
	$nativeBody = conversionProductStatusBody($nativeResponse);
	$expectedDtoKeys = [
		'status', 'blockers', 'factor', 'dimension', 'approximate', 'winner_source', 'source_name', 'source_version',
		'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'
	];
	conversionAssertSame($expectedDtoKeys, array_keys($nativeBody), 'product status response must expose only the fixed resolver DTO keys');
	conversionAssertSame('product_native', $nativeBody['status'], 'native product override must retain its closed status');
	conversionAssertSame('1.01', $nativeBody['factor'], 'native product override may expose its precise usable factor');
	conversionAssertSame('product_override', $nativeBody['winner_source'], 'native product override must identify its closed winner source');

	$inactiveQuery = ['from_unit_key' => 'cup', 'to_unit_key' => 'g'];
	$inactiveBeforeActivation = conversionProductStatusBody(conversionProductStatusReadOnlyCall($pdo, $controller, '1', $inactiveQuery));
	conversionAssertSame('inactive', $inactiveBeforeActivation['status'], 'eligible sourced profile must remain visibly inactive');
	conversionAssertSame(null, $inactiveBeforeActivation['factor'], 'inactive source must not expose a usable factor through the product status boundary');
	conversionAssertSame('food_profile', $inactiveBeforeActivation['winner_source'], 'inactive source may retain its bounded provenance category');

	$pdo->exec("INSERT INTO grocy_ai_conversion_activation_evidence (id, revision_id, evidence_hash) VALUES (1, 'conversion-catalog-v1', 'fixture-approved-evidence')");
	$pdo->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (301, NULL, 1, 4, 236.5882365)");
	$pdo->exec("INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (NULL, 1, 4, 236.5882365, '301')");
	$pdo->exec("INSERT INTO grocy_ai_conversion_projection_spy (id, operation) VALUES (1, 'fixture_activation_completed')");
	$pdo->exec('DELETE FROM grocy_ai_conversion_route_write_spy');
	$inactiveAfterActivation = conversionProductStatusBody(conversionProductStatusReadOnlyCall($pdo, $controller, '1', $inactiveQuery));
	conversionAssertSame($inactiveBeforeActivation, $inactiveAfterActivation, 'status read before and after activation fixture must retain the same closed inspection contract');
	conversionAssertSame([], $pdo->query('SELECT * FROM grocy_ai_conversion_route_write_spy')->fetchAll(PDO::FETCH_ASSOC), 'status route must invoke no activation, native projection, cache refresh, taxonomy, or product write seam');

	$pdo->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (401, 2, 1, 2, 238), (402, 2, 1, 2, 239)");
	$pdo->exec('DELETE FROM grocy_ai_conversion_route_write_spy');
	$blockedResponse = conversionProductStatusReadOnlyCall($pdo, $controller, '2', $inactiveQuery);
	conversionAssertSame(200, $blockedResponse->getStatusCode(), 'blocked resolver outcome must remain a bounded successful inspection response');
	$blockedBody = conversionProductStatusBody($blockedResponse);
	conversionAssertSame($expectedDtoKeys, array_keys($blockedBody), 'blocked endpoint response must retain the exact fixed resolver DTO keys');
	conversionAssertSame('blocked', $blockedBody['status'], 'same-rank product graph conflict must reach the endpoint as blocked');
	conversionAssertSame(['same_rank_collision'], $blockedBody['blockers'], 'blocked endpoint response must expose only the bounded resolver blocker');
	conversionAssertSame(null, $blockedBody['factor'], 'blocked endpoint response must never expose a usable factor');

	$unavailableResponse = conversionProductStatusReadOnlyCall($pdo, $controller, '4', $inactiveQuery);
	conversionAssertSame(200, $unavailableResponse->getStatusCode(), 'unavailable resolver outcome must remain a bounded successful inspection response');
	$unavailableBody = conversionProductStatusBody($unavailableResponse);
	conversionAssertSame($expectedDtoKeys, array_keys($unavailableBody), 'unavailable endpoint response must retain the exact fixed resolver DTO keys');
	conversionAssertSame('unavailable', $unavailableBody['status'], 'product without an explicit taxonomy assignment must reach the endpoint as unavailable');
	conversionAssertSame(['explicit_taxonomy_required'], $unavailableBody['blockers'], 'unavailable endpoint response must expose only the bounded resolver reason');
	conversionAssertSame(null, $unavailableBody['factor'], 'unavailable endpoint response must never expose a usable factor');

	$malformedCases = [
		['0', $inactiveQuery], ['01', $inactiveQuery], ['+1', $inactiveQuery], ['1e0', $inactiveQuery],
		['10000000000', $inactiveQuery], [1, $inactiveQuery], [null, $inactiveQuery],
		['1', []], ['1', ['from_unit_key' => 'cup']], ['1', ['to_unit_key' => 'g']],
		['1', ['from_unit_key' => 'Cup', 'to_unit_key' => 'g']],
		['1', ['from_unit_key' => 'cup;select', 'to_unit_key' => 'g']],
		['1', ['from_unit_key' => str_repeat('a', 33), 'to_unit_key' => 'g']]
	];
	foreach ($malformedCases as [$productId, $malformedQuery])
	{
		conversionProductStatusAssertError(
			conversionProductStatusReadOnlyCall($pdo, $controller, $productId, $malformedQuery),
			400,
			'Invalid conversion status request'
		);
	}
	conversionProductStatusAssertError(
		conversionProductStatusReadOnlyCall($pdo, $controller, '999', $inactiveQuery),
		404,
		'Product unavailable'
	);

	$pdo->exec("DELETE FROM user_permissions_resolved WHERE permission_name = 'MASTER_DATA_EDIT'");
	$unauthorizedBefore = conversionProductStatusSnapshot($pdo);
	try
	{
		conversionProductStatusInvoke($controller, '1', $inactiveQuery);
		throw new RuntimeException('conversion_product_status_permission_not_checked');
	}
	catch (Grocy\Controllers\Users\PermissionMissingException $ex)
	{
		conversionAssertSame('Permission missing: MASTER_DATA_EDIT', $ex->getMessage(), 'unauthorized status failure must disclose no SQL, evidence, product, or household detail');
	}
	conversionAssertSame($unauthorizedBefore, conversionProductStatusSnapshot($pdo), 'unauthorized status request must mutate no lifecycle, native, cache, taxonomy, or product state');

	$nativePdo = conversionNativeSaveHookPdo();
	$nativeDatabase = conversionNativeSaveHookInstallDatabase($nativePdo);
	$nativeController = conversionNativeSaveHookController($nativeDatabase);
	$nativeApiController = (new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiApiController::class))->newInstanceWithoutConstructor();
	$addResponse = conversionNativeSaveHookInvokeAdd($nativeController, productScopedPackageCandidate());
	$editResponse = conversionNativeSaveHookInvokeEdit($nativeController, 91, productScopedDensityCandidate());
	conversionAssertSame(200, $addResponse->getStatusCode(), 'normal product-scoped package save must retain independent native authority');
	conversionAssertSame(204, $editResponse->getStatusCode(), 'normal measured-density edit must retain independent native authority');
	$nativeAfterSave = conversionNativeSaveHookSnapshot($nativePdo);
	conversionAssertSame(4, $nativeAfterSave['audit_count'], 'normal native controller must own the two submitted product writes and their two native inverse-trigger writes');
	$densityResponse = conversionProductStatusNativeReadOnlyCall(
		$nativePdo,
		$nativeApiController,
		'11',
		['from_unit_key' => 'g', 'to_unit_key' => 'ml']
	);
	$densityBody = conversionProductStatusBody($densityResponse);
	conversionAssertSame('product_native', $densityBody['status'], 'status route may inspect the independently saved measured-density row');
	conversionAssertSame('0.9', $densityBody['factor'], 'status route may expose the precise independently saved native factor');
	conversionAssertSame($nativeAfterSave, conversionNativeSaveHookSnapshot($nativePdo), 'status inspection after native saves must not perform another native or cache write');
}

function runConversionProductStatus(): never
{
	conversionProductStatusContract();
	fwrite(STDOUT, "Conversion product status tests passed\n");
	exit(0);
}

function runConversionResolution(): never
{
	if (!class_exists(GrocyAiConversionMigration::class) || !method_exists(GrocyAiConversionService::class, 'InspectSourcedProfile') || !method_exists(GrocyAiConversionService::class, 'InspectConversionResolution'))
	{
		expectedRed('EXPECTED_RED: conversion-resolution', 'Deterministic conversion resolution inspection is not implemented');
	}

	$pdo = conversionResolutionPdo();
	$protectedBeforeBootstrap = conversionResolutionProtectedSnapshot($pdo);
	GrocyAiConversionMigration::Bootstrap($pdo);
	conversionAssertSame($protectedBeforeBootstrap, conversionResolutionProtectedSnapshot($pdo), 'profile bootstrap must not mutate products, taxonomy, native conversions, native cache, or projection state');

	$profiles = $pdo->query('SELECT profile_key, taxonomy_leaf_id, from_unit_key, to_unit_key, factor, approximate, source_name, source_item_id, source_version, source_basis, status FROM grocy_ai_conversion_profiles ORDER BY profile_key')->fetchAll(PDO::FETCH_ASSOC);
	conversionAssertSame([
		['profile_key' => 'olive-oil', 'taxonomy_leaf_id' => 'leaf-oils-vinegars', 'from_unit_key' => 'tbsp', 'to_unit_key' => 'g', 'factor' => '13.5', 'approximate' => 1, 'source_name' => 'USDA FoodData Central', 'source_item_id' => '171413', 'source_version' => 'SR Legacy 2018-04; published 2019-04-01', 'source_basis' => '1 tablespoon = 13.5 g', 'status' => 'inactive'],
		['profile_key' => 'water-like-beverage', 'taxonomy_leaf_id' => 'leaf-beverages', 'from_unit_key' => 'cup', 'to_unit_key' => 'g', 'factor' => '237', 'approximate' => 1, 'source_name' => 'USDA FoodData Central', 'source_item_id' => '174158', 'source_version' => 'SR Legacy 2018-04; published 2019-04-01', 'source_basis' => '1 cup = 237 g', 'status' => 'inactive'],
		['profile_key' => 'whole-milk', 'taxonomy_leaf_id' => 'leaf-dairy-eggs', 'from_unit_key' => 'cup', 'to_unit_key' => 'g', 'factor' => '244', 'approximate' => 1, 'source_name' => 'USDA FoodData Central', 'source_item_id' => '171265', 'source_version' => 'SR Legacy 2018-04; published 2019-04-01', 'source_basis' => '1 cup = 244 g', 'status' => 'inactive']
	], $profiles, 'starter profiles must be the closed reviewed USDA FDC records with exact portion calculations');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM grocy_ai_conversion_profile_revisions WHERE id = 'conversion-profiles-v1' AND status = 'inactive'")->fetchColumn(), 'profile lifecycle must remain module-owned and inactive');

	$missingTaxonomy = conversionResolutionMissingTaxonomyPdo();
	GrocyAiConversionMigration::Bootstrap($missingTaxonomy);
	$missingTaxonomyBefore = conversionResolutionMissingTaxonomySnapshot($missingTaxonomy);
	$taxonomyUnavailable = (new GrocyAiConversionService($missingTaxonomy, false))->InspectSourcedProfile(1, 'cup', 'g');
	conversionAssertSame('unavailable', $taxonomyUnavailable['status'] ?? null, 'missing taxonomy module schema must return the bounded unavailable DTO');
	conversionAssertSame(['taxonomy_unavailable'], $taxonomyUnavailable['blockers'] ?? null, 'missing taxonomy module schema must identify taxonomy availability without exposing database errors');
	conversionAssertSame(null, $taxonomyUnavailable['factor'] ?? null, 'missing taxonomy module schema must not expose a profile factor');
	$missingResolution = (new GrocyAiConversionService($missingTaxonomy, false))->InspectConversionResolution(1, 'cup', 'g');
	conversionAssertSame('unavailable', $missingResolution['status'] ?? null, 'missing taxonomy relation must keep full resolution inspection unavailable');
	conversionAssertSame(['taxonomy_unavailable'], $missingResolution['blockers'] ?? null, 'full resolution must preserve the bounded missing-taxonomy guard');
	conversionAssertSame(null, $missingResolution['factor'] ?? null, 'missing taxonomy relation must not fall through to a usable factor');
	conversionAssertSame($missingTaxonomyBefore, conversionResolutionMissingTaxonomySnapshot($missingTaxonomy), 'missing-taxonomy inspection must not bootstrap or mutate module, native conversion, product, or cache state');

	$malformedTaxonomy = conversionResolutionMalformedTaxonomyPdo();
	GrocyAiConversionMigration::Bootstrap($malformedTaxonomy);
	$malformedTaxonomyBefore = conversionResolutionMissingTaxonomySnapshot($malformedTaxonomy);
	try
	{
		(new GrocyAiConversionService($malformedTaxonomy, false))->InspectSourcedProfile(1, 'cup', 'g');
		throw new RuntimeException('malformed taxonomy schema error was converted to an unavailable DTO');
	}
	catch (PDOException $ex)
	{
		if (!str_contains($ex->getMessage(), 'ruleset_version'))
		{
			throw new RuntimeException('unexpected malformed taxonomy schema error');
		}
	}
	try
	{
		(new GrocyAiConversionService($malformedTaxonomy, false))->InspectConversionResolution(1, 'cup', 'g');
		throw new RuntimeException('full resolution converted malformed taxonomy schema to a DTO');
	}
	catch (PDOException $ex)
	{
		if (!str_contains($ex->getMessage(), 'ruleset_version'))
		{
			throw new RuntimeException('unexpected malformed taxonomy resolution error');
		}
	}
	conversionAssertSame($malformedTaxonomyBefore, conversionResolutionMissingTaxonomySnapshot($malformedTaxonomy), 'malformed taxonomy inspection must propagate without mutating module, native conversion, product, or cache state');

	$viewTaxonomy = conversionResolutionViewTaxonomyPdo();
	GrocyAiConversionMigration::Bootstrap($viewTaxonomy);
	$viewTaxonomyBefore = conversionResolutionMissingTaxonomySnapshot($viewTaxonomy);
	try
	{
		(new GrocyAiConversionService($viewTaxonomy, false))->InspectSourcedProfile(1, 'cup', 'g');
		throw new RuntimeException('view-backed malformed taxonomy schema was converted to an unavailable DTO');
	}
	catch (PDOException $ex)
	{
		if (!str_contains($ex->getMessage(), 'ruleset_version'))
		{
			throw new RuntimeException('unexpected view-backed taxonomy schema error');
		}
	}
	conversionAssertSame($viewTaxonomyBefore, conversionResolutionMissingTaxonomySnapshot($viewTaxonomy), 'view-backed malformed taxonomy inspection must propagate without mutating module, native conversion, product, or cache state');

	$protectedBeforeInspection = conversionResolutionProtectedSnapshot($pdo);
	$water = (new GrocyAiConversionService($pdo, false))->InspectSourcedProfile(1, 'cup', 'g');
	conversionAssertSame(['status', 'scope', 'blockers', 'factor', 'dimension', 'approximate', 'profile_key', 'taxonomy_leaf', 'source_name', 'source_item_id', 'source_version', 'source_basis', 'inactive_revision_id'], array_keys($water), 'profile inspection DTO keys must remain fixed and bounded');
	conversionAssertSame('inactive', $water['status'], 'eligible sourced profile must remain inactive for inspection');
	conversionAssertSame('food_profile', $water['scope'], 'sourced profile inspection must identify its scope');
	conversionAssertSame([], $water['blockers'], 'eligible inactive profile must have no eligibility blocker');
	conversionAssertSame('237', $water['factor'], 'eligible water profile must preserve the fixed USDA portion factor');
	conversionAssertSame('mass_volume', $water['dimension'], 'sourced density profile must identify its cross-dimension role');
	conversionAssertSame(true, $water['approximate'], 'eligible profile must be visibly approximate');
	conversionAssertSame('174158', $water['source_item_id'], 'eligible profile must disclose its fixed USDA FDC item ID');

	$service = new GrocyAiConversionService($pdo, false);
	conversionResolutionAssertUnavailable($service, 2, 'cup', 'g', 'explicit_taxonomy_required');
	conversionResolutionAssertUnavailable($service, 3, 'cup', 'g', 'explicit_taxonomy_required');
	conversionResolutionAssertUnavailable($service, 4, 'cup', 'g', 'explicit_taxonomy_required');
	conversionResolutionAssertUnavailable($service, 5, 'cup', 'g', 'explicit_taxonomy_required');
	conversionResolutionAssertUnavailable($service, 6, 'cup', 'g', 'explicit_taxonomy_required');
	conversionResolutionAssertUnavailable($service, 7, 'cup', 'g', 'taxonomy_leaf_excluded');
	conversionResolutionAssertUnavailable($service, 8, 'cup', 'g', 'profile_unavailable');
	conversionResolutionAssertUnavailable($service, 1, 'tbsp', 'g', 'profile_unavailable');
	conversionAssertSame('244', $service->InspectSourcedProfile(9, 'cup', 'g')['factor'], 'whole-milk profile must retain its fixed reviewed USDA factor');
	conversionAssertSame('13.5', $service->InspectSourcedProfile(10, 'tbsp', 'g')['factor'], 'olive-oil profile must retain its fixed reviewed USDA factor');
	$pdo->exec("UPDATE grocy_ai_conversion_profiles SET factor = '238' WHERE profile_key = 'water-like-beverage'");
	conversionResolutionAssertUnavailable($service, 1, 'cup', 'g', 'profile_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_profiles SET factor = '237', source_item_id = '999999', source_basis = 'tampered but nonempty' WHERE profile_key = 'water-like-beverage'");
	conversionResolutionAssertUnavailable($service, 1, 'cup', 'g', 'profile_invalid');
	$pdo->exec("UPDATE grocy_ai_conversion_profiles SET source_item_id = '174158', source_basis = '1 cup = 237 g' WHERE profile_key = 'water-like-beverage'");
	conversionAssertSame($protectedBeforeInspection, conversionResolutionProtectedSnapshot($pdo), 'all profile inspection outcomes must leave taxonomy, products, native conversions, cache, and projection state unchanged');

	$resolverBefore = conversionResolutionInspectionSnapshot($pdo);
	$pdo->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (92, 1, 1, 2, 241)");
	$productOverride = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution(1, 'cup', 'g');
	conversionAssertSame(['status', 'blockers', 'factor', 'dimension', 'approximate', 'winner_source', 'source_name', 'source_version', 'source_status', 'source_item_id', 'profile_key', 'taxonomy_leaf', 'precedence', 'inactive_revision_id'], array_keys($productOverride), 'resolution inspection DTO keys must remain fixed and bounded');
	conversionAssertSame('product_native', $productOverride['status'], 'an exact native product row must outrank the eligible sourced profile');
	conversionAssertSame('241', $productOverride['factor'], 'product override winner must retain its stored precise factor');
	conversionAssertSame('product_override', $productOverride['winner_source'], 'product override winner must expose its closed source category');
	conversionAssertSame('Grocy native product conversion', $productOverride['source_name'], 'product override winner must identify its native source');
	conversionAssertSame(null, $productOverride['source_version'], 'native rows without stored provenance must not invent a source version');
	conversionAssertSame('native', $productOverride['source_status'], 'product override winner must expose its native lifecycle status');
	conversionAssertSame(false, $productOverride['approximate'], 'native product override must not be relabeled approximate');
	$pdo->exec('DELETE FROM quantity_unit_conversions WHERE id = 92');
	$pdo->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (92, 1, 1, 4, 200)");
	$productPath = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution(1, 'cup', 'g');
	conversionAssertSame('product_native', $productPath['status'], 'one validated native product path must outrank the eligible sourced profile');
	conversionAssertSame('202', $productPath['factor'], 'native product path winner must multiply its exact stored factors deterministically');
	conversionAssertSame('product_override', $productPath['winner_source'], 'native product path must retain product-override precedence');
	$pdo->exec('DELETE FROM quantity_unit_conversions WHERE id = 92');

	$profileWinner = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution(1, 'cup', 'g');
	conversionAssertSame('inactive', $profileWinner['status'], 'eligible food profile must remain inactive for inspection');
	conversionAssertSame('237', $profileWinner['factor'], 'eligible food profile must win when no product override exists');
	conversionAssertSame('food_profile', $profileWinner['winner_source'], 'profile winner must expose its closed source category');
	conversionAssertSame('USDA FoodData Central', $profileWinner['source_name'], 'profile winner must expose the reviewed source name');
	conversionAssertSame('SR Legacy 2018-04; published 2019-04-01', $profileWinner['source_version'], 'profile winner must expose the reviewed source version');
	conversionAssertSame('inactive', $profileWinner['source_status'], 'profile winner must expose its inactive lifecycle');
	conversionAssertSame(true, $profileWinner['approximate'], 'food profile winner must remain visibly approximate');

	$universalWinner = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution(8, 'cup', 'ml');
	conversionAssertSame('inactive', $universalWinner['status'], 'universal candidate must remain inactive for inspection');
	conversionAssertSame('236.5882365', $universalWinner['factor'], 'universal fallback must derive the deterministic catalog factor');
	conversionAssertSame('universal', $universalWinner['winner_source'], 'universal fallback must expose its closed source category');
	conversionAssertSame('NIST SP 811', $universalWinner['source_name'], 'universal fallback must identify its named source');
	conversionAssertSame('NIST-SP-811-2008-Appendix-B.9', $universalWinner['source_version'], 'universal fallback must expose its accepted source version');
	conversionAssertSame('inactive', $universalWinner['source_status'], 'universal fallback must expose its inactive lifecycle');
	conversionAssertSame(false, $universalWinner['approximate'], 'same-dimension universal factor must remain exact');
	conversionAssertSame('product_override>food_profile>universal', $universalWinner['precedence'], 'resolver must disclose the fixed precedence policy');

	$unavailable = (new GrocyAiConversionService($pdo, false))->InspectConversionResolution(4, 'cup', 'g');
	conversionAssertSame('unavailable', $unavailable['status'], 'a cross-dimension request without an eligible profile must remain unavailable');
	conversionAssertSame(['explicit_taxonomy_required'], $unavailable['blockers'], 'unavailable resolution must retain the bounded taxonomy eligibility reason');
	conversionAssertSame(null, $unavailable['factor'], 'unavailable resolution must not expose a guessed factor');
	conversionAssertSame(null, $unavailable['winner_source'], 'unavailable resolution must not claim a winner');
	conversionAssertSame($resolverBefore, conversionResolutionInspectionSnapshot($pdo), 'all winning and unavailable resolution inspections must leave module lifecycle and protected state unchanged');

	$sameRank = conversionResolutionGraphPdo();
	$sameRank->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 2, 238), (202, 2, 1, 2, 239)");
	conversionResolutionAssertBlockedInspection($sameRank, 2, 'cup', 'g', 'same_rank_collision');
	$profileCollision = conversionResolutionGraphPdo();
	$profileCollision->exec("DROP INDEX grocy_ai_conversion_profiles_leaf_pair_idx");
	$profileCollision->exec("UPDATE grocy_ai_conversion_profiles SET profile_key = 'water-like-beverage-copy' WHERE profile_key = 'water-like-beverage'");
	$profileCollision->exec("INSERT INTO grocy_ai_conversion_profiles (profile_key, revision_id, taxonomy_leaf_id, from_unit_key, to_unit_key, factor, approximate, source_name, source_item_id, source_version, source_basis, status) VALUES ('water-like-beverage', 'conversion-profiles-v1', 'leaf-beverages', 'cup', 'g', '237', 1, 'USDA FoodData Central', '174158', 'SR Legacy 2018-04; published 2019-04-01', '1 cup = 237 g', 'inactive')");
	conversionResolutionAssertBlockedInspection($profileCollision, 1, 'cup', 'g', 'same_rank_collision');

	$malformed = conversionResolutionGraphPdo();
	$malformed->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 2, 'NAN')");
	conversionResolutionAssertBlockedInspection($malformed, 2, 'cup', 'g', 'malformed_factor');

	$reciprocal = conversionResolutionGraphPdo();
	$reciprocal->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 2, 240), (202, 2, 2, 1, 0.005)");
	conversionResolutionAssertBlockedInspection($reciprocal, 2, 'cup', 'g', 'reciprocal_inconsistency');

	$competing = conversionResolutionGraphPdo();
	$competing->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (203, 2, 4, 2, 1.1), (201, 2, 1, 2, 237), (202, 2, 1, 4, 200)");
	conversionResolutionAssertBlockedInspection($competing, 2, 'cup', 'g', 'competing_paths');
	$competingReverseOrder = conversionResolutionGraphPdo();
	$competingReverseOrder->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 4, 2, 1.1), (203, 2, 1, 2, 237), (202, 2, 1, 4, 200)");
	conversionResolutionAssertBlockedInspection($competingReverseOrder, 2, 'cup', 'g', 'competing_paths');

	$cycle = conversionResolutionGraphPdo();
	$cycle->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 4, 200), (202, 2, 4, 3, 10), (203, 2, 3, 1, 0.0005)");
	conversionResolutionAssertBlockedInspection($cycle, 2, 'cup', 'g', 'cycle_detected');

	$dimensionMismatch = conversionResolutionGraphPdo();
	$dimensionMismatch->exec("INSERT INTO grocy_ai_conversion_rules (revision_id, from_unit_key, to_unit_key, factor, source_version) VALUES ('conversion-catalog-v1', 'cup', 'g', '236.5882365', 'NIST-SP-811-2008-Appendix-B.9')");
	conversionResolutionAssertBlockedInspection($dimensionMismatch, 8, 'cup', 'ml', 'dimension_mismatch');

	$toleranceDrift = conversionResolutionGraphPdo();
	$toleranceDrift->exec("UPDATE grocy_ai_conversion_rules SET factor = '0.24' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'cup' AND to_unit_key = 'l'");
	conversionResolutionAssertBlockedInspection($toleranceDrift, 8, 'cup', 'ml', 'tolerance_drift');

	$malformedUniversal = conversionResolutionGraphPdo();
	$malformedUniversal->exec("UPDATE grocy_ai_conversion_rules SET factor = 'not-a-number' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'cup' AND to_unit_key = 'l'");
	conversionResolutionAssertBlockedInspection($malformedUniversal, 8, 'cup', 'ml', 'malformed_factor');

	$tamperedUniversalDirect = conversionResolutionGraphPdo();
	$tamperedUniversalDirect->exec("UPDATE grocy_ai_conversion_rules SET factor = 'not-a-number' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'cup' AND to_unit_key = 'l'");
	$tamperedUniversalDirect->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 2, 238)");
	$tamperedUniversalDirectBefore = conversionResolutionInspectionSnapshot($tamperedUniversalDirect);
	$tamperedDirectWinner = (new GrocyAiConversionService($tamperedUniversalDirect, false))->InspectConversionResolution(2, 'cup', 'g');
	conversionAssertSame('product_native', $tamperedDirectWinner['status'], 'invalid lower-ranked universal graph must not block a valid direct native product winner');
	conversionAssertSame('238', $tamperedDirectWinner['factor'], 'direct native winner must retain its stored factor despite lower-rank corruption');
	conversionAssertSame('product_override', $tamperedDirectWinner['winner_source'], 'direct native winner must retain highest precedence despite lower-rank corruption');
	conversionAssertSame($tamperedUniversalDirectBefore, conversionResolutionInspectionSnapshot($tamperedUniversalDirect), 'direct native rank isolation must remain fully read-only');

	$tamperedUniversalPath = conversionResolutionGraphPdo();
	$tamperedUniversalPath->exec("UPDATE grocy_ai_conversion_rules SET factor = 'not-a-number' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'cup' AND to_unit_key = 'l'");
	$tamperedUniversalPath->exec("INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (201, 2, 1, 4, 200), (202, 2, 4, 2, 1.1)");
	$tamperedUniversalPathBefore = conversionResolutionInspectionSnapshot($tamperedUniversalPath);
	$tamperedPathWinner = (new GrocyAiConversionService($tamperedUniversalPath, false))->InspectConversionResolution(2, 'cup', 'g');
	conversionAssertSame('product_native', $tamperedPathWinner['status'], 'invalid lower-ranked universal graph must not block one valid native product path');
	conversionAssertSame('220', $tamperedPathWinner['factor'], 'native path winner must multiply deterministically despite lower-rank corruption');
	conversionAssertSame('product_override', $tamperedPathWinner['winner_source'], 'native path winner must retain highest precedence despite lower-rank corruption');
	conversionAssertSame($tamperedUniversalPathBefore, conversionResolutionInspectionSnapshot($tamperedUniversalPath), 'native path rank isolation must remain fully read-only');

	$tamperedUniversalProfile = conversionResolutionGraphPdo();
	$tamperedUniversalProfile->exec("UPDATE grocy_ai_conversion_rules SET factor = 'not-a-number' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'cup' AND to_unit_key = 'l'");
	$tamperedUniversalProfileBefore = conversionResolutionInspectionSnapshot($tamperedUniversalProfile);
	$tamperedProfileWinner = (new GrocyAiConversionService($tamperedUniversalProfile, false))->InspectConversionResolution(1, 'cup', 'g');
	conversionAssertSame('inactive', $tamperedProfileWinner['status'], 'invalid lower-ranked universal graph must not block an eligible sourced profile winner');
	conversionAssertSame('237', $tamperedProfileWinner['factor'], 'eligible sourced profile must retain its reviewed factor despite lower-rank corruption');
	conversionAssertSame('food_profile', $tamperedProfileWinner['winner_source'], 'eligible sourced profile must retain second precedence despite lower-rank corruption');
	conversionAssertSame($tamperedUniversalProfileBefore, conversionResolutionInspectionSnapshot($tamperedUniversalProfile), 'profile rank isolation must remain fully read-only');

	conversionResolutionAssertBlockedInspection($tamperedUniversalProfile, 8, 'cup', 'ml', 'malformed_factor');
	$profileDrift = conversionResolutionGraphPdo();
	$profileDrift->exec("UPDATE grocy_ai_conversion_profiles SET factor = '238' WHERE profile_key = 'water-like-beverage'");
	conversionResolutionAssertBlockedInspection($profileDrift, 1, 'cup', 'g', 'tolerance_drift');
	$malformedProfile = conversionResolutionGraphPdo();
	$malformedProfile->exec("UPDATE grocy_ai_conversion_profiles SET factor = 'not-a-number' WHERE profile_key = 'water-like-beverage'");
	conversionResolutionAssertBlockedInspection($malformedProfile, 1, 'cup', 'g', 'malformed_factor');
	conversionProductStatusContract();

	fwrite(STDOUT, "Conversion resolution tests passed\n");
	exit(0);
}

function conversionNativeSaveHookPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec(<<<'SQL'
CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_plural TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY, qu_id_stock INTEGER NOT NULL, qu_id_purchase INTEGER NOT NULL, qu_id_consume INTEGER NOT NULL, qu_id_price INTEGER NOT NULL);
CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, product_id INTEGER, row_created_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE user_permissions_resolved (id INTEGER NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL, permission_name TEXT NOT NULL);
CREATE VIEW quantity_unit_conversions_resolved AS SELECT NULL AS id, NULL AS product_id, NULL AS from_qu_id, NULL AS from_qu_name, NULL AS from_qu_name_plural, NULL AS to_qu_id, NULL AS to_qu_name, NULL AS to_qu_name_plural, NULL AS factor, NULL AS path WHERE 0;
CREATE TRIGGER qu_conversions_inverse_INS AFTER INSERT ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_UPD AFTER UPDATE ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_DEL AFTER DELETE ON quantity_unit_conversions BEGIN SELECT 1; END;
SQL);

	$resolverSql = file_get_contents(dirname(__DIR__, 3) . '/migrations/0208.sql');
	$cacheSql = file_get_contents(dirname(__DIR__, 3) . '/migrations/0225.sql');
	$cachePrefix = is_string($cacheSql) ? strstr($cacheSql, 'DROP VIEW recipes_pos_resolved;', true) : false;
	if (!is_string($resolverSql) || $resolverSql === '' || !is_string($cachePrefix) || $cachePrefix === '')
	{
		throw new RuntimeException('native_conversion_fixture_migrations_unavailable');
	}
	$pdo->exec($resolverSql);
	$pdo->exec($cachePrefix);
	$pdo->exec(<<<'SQL'
INSERT INTO quantity_units (id, name, name_plural) VALUES
	(1, 'kg', 'kg'), (2, 'lb', 'lb'), (3, 'mL', 'mL'), (4, 'L', 'L'), (5, 'pack', 'packs'),
	(6, 'count', 'counts'), (7, 'g', 'g'), (8, 'tsp', 'tsp'), (9, 'fl oz', 'fl oz'), (10, 'piece', 'pieces');
INSERT INTO products (id, qu_id_stock, qu_id_purchase, qu_id_consume, qu_id_price) VALUES (11, 7, 8, 7, 7);
INSERT INTO quantity_unit_conversions (id, product_id, from_qu_id, to_qu_id, factor) VALUES (91, 11, 10, 7, 3);
INSERT INTO user_permissions_resolved (id, user_id, permission_name) VALUES (1, 1, 'MASTER_DATA_EDIT');
CREATE TABLE conversion_native_write_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, operation TEXT NOT NULL, object_id INTEGER NOT NULL, product_id INTEGER, from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL);
CREATE TRIGGER conversion_native_write_audit_INS AFTER INSERT ON quantity_unit_conversions BEGIN
	INSERT INTO conversion_native_write_audit (operation, object_id, product_id, from_qu_id, to_qu_id) VALUES ('insert', NEW.id, NEW.product_id, NEW.from_qu_id, NEW.to_qu_id);
END;
CREATE TRIGGER conversion_native_write_audit_UPD AFTER UPDATE ON quantity_unit_conversions BEGIN
	INSERT INTO conversion_native_write_audit (operation, object_id, product_id, from_qu_id, to_qu_id) VALUES ('update', NEW.id, NEW.product_id, NEW.from_qu_id, NEW.to_qu_id);
END;
SQL);
	return $pdo;
}

function conversionNativeSaveHookInstallDatabase(PDO $pdo): LessQL\Database
{
	$database = new LessQL\Database($pdo);
	$serviceReflection = new ReflectionClass(Grocy\Services\DatabaseService::class);
	foreach (['DbConnectionRaw' => $pdo, 'DbConnection' => $database, 'instance' => $serviceReflection->newInstance()] as $propertyName => $value)
	{
		$property = $serviceReflection->getProperty($propertyName);
		$property->setValue(null, $value);
	}
	return $database;
}

function conversionNativeSaveHookController(LessQL\Database $database): Grocy\Controllers\Api\GenericEntityApiController
{
	$reflection = new ReflectionClass(Grocy\Controllers\Api\GenericEntityApiController::class);
	$controller = $reflection->newInstanceWithoutConstructor();
	foreach (['DB' => $database, 'OpenApiSpec' => json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/grocy.openapi.json'), false, 512, JSON_THROW_ON_ERROR)] as $propertyName => $value)
	{
		$owner = $propertyName === 'DB' ? Grocy\Controllers\BaseController::class : Grocy\Controllers\Api\BaseApiController::class;
		$property = (new ReflectionClass($owner))->getProperty($propertyName);
		$property->setValue($controller, $value);
	}
	return $controller;
}

function conversionNativeSaveHookRequest(string $method, array $candidate): Psr\Http\Message\ServerRequestInterface
{
	return (new Slim\Psr7\Factory\ServerRequestFactory())
		->createServerRequest($method, '/api/objects/quantity_unit_conversions')
		->withHeader('Content-Type', 'application/json')
		->withParsedBody($candidate);
}

function conversionNativeSaveHookResponse(): Psr\Http\Message\ResponseInterface
{
	return (new Slim\Psr7\Factory\ResponseFactory())->createResponse();
}

function conversionNativeSaveHookSnapshot(PDO $pdo): array
{
	return [
		'native' => $pdo->query('SELECT id, product_id, from_qu_id, to_qu_id, factor FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'cache' => $pdo->query('SELECT product_id, from_qu_id, to_qu_id, factor, path FROM cache__quantity_unit_conversions_resolved ORDER BY product_id, from_qu_id, to_qu_id')->fetchAll(PDO::FETCH_ASSOC),
		'audit_count' => (int)$pdo->query('SELECT COUNT(*) FROM conversion_native_write_audit')->fetchColumn()
	];
}

function conversionValidationReadSnapshot(PDO $pdo): array
{
	$schema = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE 'grocy_ai_conversion_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
	$moduleState = [];
	foreach ($schema as $object)
	{
		if (($object['type'] ?? null) !== 'table')
		{
			continue;
		}
		$table = (string)$object['name'];
		$moduleState[$table] = $pdo->query('SELECT * FROM "' . str_replace('"', '""', $table) . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
	}
	return [
		'native' => conversionNativeSaveHookSnapshot($pdo),
		'module_schema' => $schema,
		'module_state' => $moduleState,
		'total_changes' => (int)$pdo->query('SELECT total_changes()')->fetchColumn()
	];
}

function conversionNativeSaveHookInvokeAdd(Grocy\Controllers\Api\GenericEntityApiController $controller, array $candidate): Psr\Http\Message\ResponseInterface
{
	return $controller->AddObject(conversionNativeSaveHookRequest('POST', $candidate), conversionNativeSaveHookResponse(), ['entity' => 'quantity_unit_conversions']);
}

function conversionNativeSaveHookInvokeEdit(Grocy\Controllers\Api\GenericEntityApiController $controller, int $objectId, array $candidate): Psr\Http\Message\ResponseInterface
{
	return $controller->EditObject(conversionNativeSaveHookRequest('PUT', $candidate), conversionNativeSaveHookResponse(), ['entity' => 'quantity_unit_conversions', 'objectId' => $objectId]);
}

function conversionNativeSaveHookAssertRejectedWithoutWrite(PDO $pdo, Grocy\Controllers\Api\GenericEntityApiController $controller, array $candidate, string $expectedReason): void
{
	$before = conversionNativeSaveHookSnapshot($pdo);
	$response = conversionNativeSaveHookInvokeAdd($controller, $candidate);
	conversionAssertSame(400, $response->getStatusCode(), 'invalid or reusable native conversion must return a bounded API error');
	$body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
	conversionAssertSame('conversion_write_blocked:' . $expectedReason, $body['error_message'] ?? null, 'native rejection reason must be bounded');
	conversionAssertSame($before, conversionNativeSaveHookSnapshot($pdo), 'rejected native conversion must mutate neither native rows, cache, nor native audit');
}

function conversionNativeSaveHookAssertRejectedEditWithoutWrite(PDO $pdo, Grocy\Controllers\Api\GenericEntityApiController $controller, int $objectId, array $candidate, string $expectedReason): void
{
	$before = conversionNativeSaveHookSnapshot($pdo);
	$response = conversionNativeSaveHookInvokeEdit($controller, $objectId, $candidate);
	conversionAssertSame(400, $response->getStatusCode(), 'invalid or reusable conversion edit must return a bounded API error');
	$body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
	conversionAssertSame('conversion_write_blocked:' . $expectedReason, $body['error_message'] ?? null, 'native edit rejection reason must be bounded');
	conversionAssertSame($before, conversionNativeSaveHookSnapshot($pdo), 'rejected conversion edit must mutate neither native rows, cache, nor native audit');
}

function conversionValidationAssertTamperBlocked(PDO $pdo, GrocyAI\Controllers\Api\GrocyAiApiController $controller, Psr\Http\Message\ServerRequestInterface $request, string $tamperSql, string $restoreSql, string $caseName): void
{
	$pdo->exec($tamperSql);
	try
	{
		$before = conversionValidationReadSnapshot($pdo);
		$response = $controller->ValidateConversion($request, conversionNativeSaveHookResponse(), []);
		conversionAssertSame($before, conversionValidationReadSnapshot($pdo), $caseName . ' validation GET must remain comprehensively non-persisting');
		conversionAssertSame(503, $response->getStatusCode(), $caseName . ' must fail closed despite preserved counts and source version');
		$body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		conversionAssertSame('Conversion validation unavailable', $body['error_message'] ?? null, $caseName . ' must return the bounded unavailable response');
	}
	finally
	{
		$pdo->exec($restoreSql);
	}
}

function runConversionNativeSaveHook(): never
{
	if (!defined('GROCY_MODE'))
	{
		define('GROCY_MODE', 'production');
	}
	if (!defined('GROCY_DATAPATH'))
	{
		define('GROCY_DATAPATH', sys_get_temp_dir());
	}
	if (!defined('GROCY_USER_ID'))
	{
		define('GROCY_USER_ID', 1);
	}
	if (!is_dir(GROCY_DATAPATH . '/viewcache') && !mkdir(GROCY_DATAPATH . '/viewcache', 0700, true) && !is_dir(GROCY_DATAPATH . '/viewcache'))
	{
		throw new RuntimeException('native_conversion_viewcache_unavailable');
	}
	require_once dirname(__DIR__, 3) . '/packages/autoload.php';
	require_once dirname(__DIR__, 3) . '/controllers/Api/GenericEntityApiController.php';
	require_once dirname(__DIR__) . '/src/GrocyAiApiController.php';

	$pdo = conversionNativeSaveHookPdo();
	$database = conversionNativeSaveHookInstallDatabase($pdo);
	$controller = conversionNativeSaveHookController($database);
	$apiReflection = new ReflectionClass(GrocyAI\Controllers\Api\GrocyAiApiController::class);
	$apiController = $apiReflection->newInstanceWithoutConstructor();
	$readRequest = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/grocy-ai/conversions/validate')->withQueryParams([
		'product_id' => '', 'from_qu_id' => '1', 'to_qu_id' => '2', 'factor' => '2.2046226218487757'
	]);
	$preBootstrapReadSnapshot = conversionValidationReadSnapshot($pdo);
	$preBootstrapReadResponse = $apiController->ValidateConversion($readRequest, conversionNativeSaveHookResponse(), []);
	conversionAssertSame($preBootstrapReadSnapshot, conversionValidationReadSnapshot($pdo), 'first validation GET must not create, seed, or otherwise mutate module/native state');
	conversionAssertSame(503, $preBootstrapReadResponse->getStatusCode(), 'validation GET must fail closed when inactive schema is unavailable');
	$preBootstrapReadBody = json_decode((string)$preBootstrapReadResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
	conversionAssertSame('Conversion validation unavailable', $preBootstrapReadBody['error_message'] ?? null, 'unavailable inactive validation state must return a bounded error');
	$productReadRequest = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/grocy-ai/conversions/validate')->withQueryParams([
		'product_id' => '11', 'from_qu_id' => '5', 'to_qu_id' => '7', 'factor' => '12'
	]);
	$preBootstrapProductSnapshot = conversionValidationReadSnapshot($pdo);
	$preBootstrapProductResponse = $apiController->ValidateConversion($productReadRequest, conversionNativeSaveHookResponse(), []);
	conversionAssertSame($preBootstrapProductSnapshot, conversionValidationReadSnapshot($pdo), 'product-scoped validation GET must also leave unavailable inactive state untouched');
	conversionAssertSame(503, $preBootstrapProductResponse->getStatusCode(), 'product-scoped validation GET must fail closed when inactive schema is unavailable');
	GrocyAiConversionMigration::Bootstrap($pdo);
	$pdo->exec('DROP TABLE grocy_ai_conversion_validation_ledger');
	$pdo->exec('DROP TABLE grocy_ai_conversion_migrations');
	$partialReadSnapshot = conversionValidationReadSnapshot($pdo);
	$partialReadResponse = $apiController->ValidateConversion($readRequest, conversionNativeSaveHookResponse(), []);
	conversionAssertSame($partialReadSnapshot, conversionValidationReadSnapshot($pdo), 'partial inactive module state validation GET must remain exactly non-persisting');
	conversionAssertSame(503, $partialReadResponse->getStatusCode(), 'validation GET must fail closed when migration marker or validation ledger is missing');
	$partialReadBody = json_decode((string)$partialReadResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
	conversionAssertSame('Conversion validation unavailable', $partialReadBody['error_message'] ?? null, 'partial inactive module state must return a bounded error');

	$addResponse = conversionNativeSaveHookInvokeAdd($controller, productScopedPackageCandidate());
	conversionAssertSame(200, $addResponse->getStatusCode(), 'valid product package conversion must retain native AddObject');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM quantity_unit_conversions WHERE product_id = 11 AND from_qu_id = 5 AND to_qu_id = 7 AND factor = 12")->fetchColumn(), 'native AddObject must create the submitted product conversion exactly once');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM conversion_native_write_audit WHERE operation = 'insert' AND product_id = 11 AND from_qu_id = 5 AND to_qu_id = 7")->fetchColumn(), 'native AddObject must execute the submitted write exactly once');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM cache__quantity_unit_conversions_resolved WHERE product_id = 11 AND from_qu_id = 5 AND to_qu_id = 7")->fetchColumn(), 'native AddObject must rebuild one resolved cache row for the submitted conversion');

	$editResponse = conversionNativeSaveHookInvokeEdit($controller, 91, productScopedDensityCandidate());
	conversionAssertSame(204, $editResponse->getStatusCode(), 'valid measured-density conversion must retain native EditObject');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM quantity_unit_conversions WHERE id = 91 AND product_id = 11 AND from_qu_id = 7 AND to_qu_id = 3 AND factor = 0.9")->fetchColumn(), 'native EditObject must update the actual requested object');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM conversion_native_write_audit WHERE operation = 'update' AND object_id = 91 AND product_id = 11 AND from_qu_id = 7 AND to_qu_id = 3")->fetchColumn(), 'native EditObject must execute the submitted write exactly once');
	conversionAssertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM cache__quantity_unit_conversions_resolved WHERE product_id = 11 AND from_qu_id = 7 AND to_qu_id = 3")->fetchColumn(), 'native EditObject must rebuild one resolved cache row for the measured-density conversion');

	conversionNativeSaveHookAssertRejectedWithoutWrite($pdo, $controller, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'reusable_scope_inactive');
	conversionNativeSaveHookAssertRejectedWithoutWrite($pdo, $controller, reusablePackageCandidate(), 'reusable_count_scope');
	conversionNativeSaveHookAssertRejectedWithoutWrite($pdo, $controller, crossDimensionCandidate(), 'dimension_mismatch');
	conversionNativeSaveHookAssertRejectedEditWithoutWrite($pdo, $controller, 91, ['product_id' => null, 'from_qu_id' => 1, 'to_qu_id' => 2, 'factor' => '2.2046226218487757'], 'reusable_scope_inactive');
	conversionValidationAssertTamperBlocked(
		$pdo, $apiController, $readRequest,
		"UPDATE grocy_ai_conversion_catalog_units SET metric_factor = '454' WHERE unit_key = 'lb'",
		"UPDATE grocy_ai_conversion_catalog_units SET metric_factor = '453.59237' WHERE unit_key = 'lb'",
		'catalog factor tamper'
	);
	conversionValidationAssertTamperBlocked(
		$pdo, $apiController, $readRequest,
		"UPDATE grocy_ai_conversion_catalog_units SET dimension = 'volume' WHERE unit_key = 'lb'",
		"UPDATE grocy_ai_conversion_catalog_units SET dimension = 'mass' WHERE unit_key = 'lb'",
		'catalog dimension tamper'
	);
	conversionValidationAssertTamperBlocked(
		$pdo, $apiController, $readRequest,
		"UPDATE grocy_ai_conversion_rules SET factor = '1001' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'",
		"UPDATE grocy_ai_conversion_rules SET factor = '1000' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'",
		'rule factor tamper'
	);
	conversionValidationAssertTamperBlocked(
		$pdo, $apiController, $readRequest,
		"UPDATE grocy_ai_conversion_rules SET to_unit_key = 'lb' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'g'",
		"UPDATE grocy_ai_conversion_rules SET to_unit_key = 'g' WHERE revision_id = 'conversion-catalog-v1' AND from_unit_key = 'kg' AND to_unit_key = 'lb'",
		'rule endpoint tamper'
	);

	$initializedReadSnapshot = conversionValidationReadSnapshot($pdo);
	$readResponse = $apiController->ValidateConversion($readRequest, conversionNativeSaveHookResponse(), []);
	$readBody = json_decode((string)$readResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
	conversionAssertSame('inactive', $readBody['status'] ?? null, 'permission-checked validation endpoint must remain read-only and inactive');
	conversionAssertSame($initializedReadSnapshot, conversionValidationReadSnapshot($pdo), 'successful initialized validation GET must leave all native and module schema/state exactly unchanged');
	$pdo->exec("DELETE FROM user_permissions_resolved WHERE permission_name = 'MASTER_DATA_EDIT'");
	$permissionReadSnapshot = conversionValidationReadSnapshot($pdo);
	try
	{
		$apiController->ValidateConversion($readRequest, conversionNativeSaveHookResponse(), []);
		throw new RuntimeException('conversion_validation_permission_not_checked');
	}
	catch (Grocy\Controllers\Users\PermissionMissingException)
	{
		// The read-only validation endpoint must check permission before validation.
	}
	conversionAssertSame($permissionReadSnapshot, conversionValidationReadSnapshot($pdo), 'permission rejection must not mutate native or module conversion state');

	fwrite(STDOUT, "Conversion native save hook tests passed\n");
	exit(0);
}

function runConversionCharacterization(string $mainRoot, string $stableRoot, string $fixtureRoot, string $blockedDataPath): array
{
	$openedPaths = [];
	$blockedDataPath = conversionCharacterizationNormalizePath($blockedDataPath);
	$fixtureRoot = conversionCharacterizationNormalizePath($fixtureRoot);
	if (conversionCharacterizationIsAtOrBelow($fixtureRoot, $blockedDataPath))
	{
		throw new RuntimeException('fixture_path_inside_grocy_datapath');
	}

	$mainManifest = conversionCharacterizationLoadManifest($fixtureRoot . '/conversion-characterization-main.json', 'main', $openedPaths);
	$stableManifest = conversionCharacterizationLoadManifest($fixtureRoot . '/conversion-characterization-stable.json', 'stable', $openedPaths);
	$mainRoot = conversionCharacterizationNormalizePath($mainRoot);
	$stableRoot = conversionCharacterizationNormalizePath($stableRoot);
	if ($mainRoot === $stableRoot)
	{
		throw new RuntimeException('identical_branch_roots');
	}
	conversionCharacterizationAssertBranchIdentity($mainRoot, $mainManifest);
	conversionCharacterizationAssertBranchIdentity($stableRoot, $stableManifest);
	$main = CharacterizeBranch('main', $mainRoot, $mainManifest, $blockedDataPath);
	$stable = CharacterizeBranch('stable', $stableRoot, $stableManifest, $blockedDataPath);
	if ($main['commit'] !== $mainManifest['expected_commit'] || $stable['commit'] !== $stableManifest['expected_commit'])
	{
		throw new RuntimeException('branch_commit_mismatch');
	}

	$parity = conversionCharacterizationAssertBranchParity($main, $stable);
	$manifest = conversionCharacterizationRedactedManifest($main, $stable);

	return [
		'main' => $main,
		'stable' => $stable,
		'manifest' => $manifest,
		'manifest_json' => conversionCharacterizationRedactedManifestJson($manifest),
		'opened_paths' => array_values(array_unique(array_merge($openedPaths, $main['opened_paths'], $stable['opened_paths']))),
		'protected_outputs' => [
			'equal' => $parity['protected_outputs_equal'],
			'categories' => $main['protected_outputs']['categories'],
			'schema_equal' => $parity['schema_equal'],
			'cache_equal' => $parity['cache_equal'],
			'query_plan_equal' => $parity['query_plan_equal']
		]
	];
}

function CharacterizeBranch(string $branchName, string $root, array $manifest, string $blockedDataPath): array
{
	$root = conversionCharacterizationNormalizePath($root);
	$openedPaths = [];
	conversionCharacterizationValidateBranchRoot($root, $blockedDataPath, $openedPaths);
	$temporaryDatabase = conversionCharacterizationCreateTemporaryDatabase($blockedDataPath, $openedPaths);
	$report = [];
	$database = null;
	try
	{
		$database = new PDO('sqlite:' . $temporaryDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		conversionCharacterizationBuildFixtureSchema($database, $root, $openedPaths);
		conversionCharacterizationAssertNativeTriggers($database);
		conversionCharacterizationSeedFixture($database, $manifest);
		$baseline = conversionCharacterizationProtectedOutputs($database, $manifest['protected_categories']);
		$baselineRows = conversionCharacterizationCacheRows($database);
		conversionCharacterizationExerciseNativeWrites($database, $manifest);
		$probe = conversionCharacterizationProtectedOutputs($database, $manifest['protected_categories']);
		$probeRows = conversionCharacterizationCacheRows($database);
		if ($baseline !== $probe)
		{
			throw new RuntimeException('protected_outputs_changed');
		}
		$cache = conversionCharacterizationCacheDelta($baselineRows, $probeRows);
		conversionCharacterizationAssertProbeCacheParity($cache['baseline'], $cache['probe']);

		$report = [
			'branch' => $branchName,
			'commit' => conversionCharacterizationImmutableCommit($root),
			'schema' => array_merge(conversionCharacterizationSchemaManifest($root, $openedPaths), [
				'fixture_sqlite_master' => RedactedSqliteMaster($database)
			]),
			'cache' => $cache,
			'protected_outputs' => [
				'categories' => $manifest['protected_categories'],
				'baseline' => $baseline,
				'probe' => $probe
			],
			'query_plan' => conversionCharacterizationQueryPlan($database),
			'opened_paths' => $openedPaths,
			'fixture_deleted' => false
		];
	}
	finally
	{
		$database = null;
		clearstatcache(true, $temporaryDatabase);
		if (is_file($temporaryDatabase))
		{
			if (!unlink($temporaryDatabase))
			{
				throw new RuntimeException('fixture_cleanup_failed');
			}
		}
		clearstatcache(true, $temporaryDatabase);
		if (file_exists($temporaryDatabase))
		{
			throw new RuntimeException('fixture_cleanup_failed');
		}
		if ($report !== [])
		{
			$report['fixture_deleted'] = !file_exists($temporaryDatabase);
		}
	}
	return $report;
}

function conversionCharacterizationNormalizePath(string $value): string
{
	$resolved = realpath($value);
	return rtrim(str_replace('\\', '/', $resolved === false ? $value : $resolved), '/');
}

function conversionCharacterizationConfiguredDataPath(string $checkoutRoot): string
{
	$configured = getenv('GROCY_DATAPATH');
	if (!is_string($configured) || $configured === '')
	{
		$configured = $checkoutRoot . '/data';
	}
	if ($configured[0] !== '/')
	{
		$configured = $checkoutRoot . '/' . $configured;
	}
	return conversionCharacterizationNormalizePath($configured);
}

function conversionCharacterizationAssertBranchParity(array $main, array $stable): array
{
	$schemaEqual = $main['schema']['migration_hashes'] === $stable['schema']['migration_hashes']
		&& $main['schema']['cache_objects'] === $stable['schema']['cache_objects']
		&& $main['schema']['fixture_sqlite_master'] === $stable['schema']['fixture_sqlite_master'];
	$cacheEqual = $main['cache']['baseline'] === $stable['cache']['baseline']
		&& $main['cache']['probe'] === $stable['cache']['probe'];
	$queryPlanEqual = $main['query_plan'] === $stable['query_plan'];
	$protectedOutputsEqual = $main['protected_outputs']['baseline'] === $stable['protected_outputs']['baseline']
		&& $main['protected_outputs']['probe'] === $stable['protected_outputs']['probe'];
	if (!$schemaEqual || !$cacheEqual || !$queryPlanEqual || !$protectedOutputsEqual)
	{
		throw new RuntimeException('branch_characterization_mismatch');
	}
	return [
		'schema_equal' => $schemaEqual,
		'cache_equal' => $cacheEqual,
		'query_plan_equal' => $queryPlanEqual,
		'protected_outputs_equal' => $protectedOutputsEqual
	];
}

function conversionCharacterizationRedactedManifest(array $main, array $stable): array
{
	$branchManifest = static fn(array $branch): array => [
		'commit' => $branch['commit'],
		'migration_hashes' => $branch['schema']['migration_hashes'],
		'cache_aggregate' => $branch['cache'],
		'query_plan' => $branch['query_plan'],
		'query_plan_sha256' => hash('sha256', json_encode($branch['query_plan'], JSON_THROW_ON_ERROR))
	];
	return ['main' => $branchManifest($main), 'stable' => $branchManifest($stable)];
}

function conversionCharacterizationRedactedManifestJson(array $manifest): string
{
	if (isset($manifest['manifest']) && is_array($manifest['manifest']))
	{
		$manifest = $manifest['manifest'];
	}
	return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function conversionCharacterizationVerifyEvidenceDocument(string $evidencePath, array $manifest): void
{
	if (!is_file($evidencePath))
	{
		throw new RuntimeException('characterization_evidence_missing');
	}
	$evidence = (string)file_get_contents($evidencePath);
	foreach (['main', 'stable'] as $branchName)
	{
		$branch = $manifest[$branchName];
		$required = [
			$branch['commit'],
			$branch['migration_hashes']['migrations/0208.sql'],
			$branch['migration_hashes']['migrations/0225.sql'],
			(string)$branch['cache_aggregate']['baseline']['row_count'],
			$branch['cache_aggregate']['baseline']['row_key_factor_path_sha256'],
			$branch['query_plan_sha256']
		];
		foreach ($required as $value)
		{
			if (!str_contains($evidence, $value))
			{
				throw new RuntimeException('characterization_evidence_mismatch');
			}
		}
	}
}

function conversionCharacterizationIsAtOrBelow(string $candidate, string $parent): bool
{
	return $candidate === $parent || str_starts_with($candidate . '/', $parent . '/');
}

function conversionCharacterizationLoadManifest(string $manifestPath, string $branchName, array &$openedPaths): array
{
	if (!is_file($manifestPath))
	{
		throw new RuntimeException('missing_branch_manifest');
	}
	$openedPaths[] = conversionCharacterizationNormalizePath($manifestPath);
	try
	{
		$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
	}
	catch (JsonException)
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	if (!is_array($manifest)
		|| ($manifest['branch'] ?? null) !== $branchName
		|| !is_string($manifest['expected_commit'] ?? null)
		|| preg_match('/^[a-f0-9]{40}$/', $manifest['expected_commit']) !== 1
		|| !is_array($manifest['native_default'] ?? null)
		|| !is_array($manifest['product_override'] ?? null)
		|| !is_array($manifest['protected_categories'] ?? null))
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	$requiredCategories = ['stock', 'recipe', 'purchase', 'consumption', 'price', 'transfer', 'meal-plan', 'quantity-display'];
	if ($manifest['protected_categories'] !== $requiredCategories)
	{
		throw new RuntimeException('invalid_branch_manifest');
	}
	return $manifest;
}

function conversionCharacterizationAssertBranchIdentity(string $root, array $manifest): void
{
	$commit = conversionCharacterizationImmutableCommit($root);
	if ($commit !== $manifest['expected_commit'])
	{
		throw new RuntimeException('branch_commit_mismatch');
	}
	$status = conversionCharacterizationGitOutput($root, ['status', '--porcelain', '--', 'migrations/0208.sql', 'migrations/0225.sql']);
	if (trim($status) !== '')
	{
		throw new RuntimeException('dirty_branch_source');
	}
}

function conversionCharacterizationValidateBranchRoot(string $root, string $blockedDataPath, array &$openedPaths): void
{
	if (conversionCharacterizationIsAtOrBelow($root, $blockedDataPath)
		|| (!is_dir($root . '/.git') && !is_file($root . '/.git')))
	{
		throw new RuntimeException('invalid_branch_root');
	}
	foreach (['migrations/0208.sql', 'migrations/0225.sql'] as $relativePath)
	{
		$sourcePath = $root . '/' . $relativePath;
		if (!is_file($sourcePath))
		{
			throw new RuntimeException('missing_cache_definitions');
		}
		$openedPaths[] = $sourcePath;
	}
}

function conversionCharacterizationCreateTemporaryDatabase(string $blockedDataPath, array &$openedPaths): string
{
	$temporaryDirectory = conversionCharacterizationNormalizePath(sys_get_temp_dir());
	if (conversionCharacterizationIsAtOrBelow($temporaryDirectory, $blockedDataPath))
	{
		throw new RuntimeException('temporary_fixture_inside_grocy_datapath');
	}
	$temporaryDatabase = tempnam($temporaryDirectory, 'grocy-conversion-characterization-');
	if ($temporaryDatabase === false)
	{
		throw new RuntimeException('temporary_fixture_creation_failed');
	}
	$temporaryDatabase = conversionCharacterizationNormalizePath($temporaryDatabase);
	if (conversionCharacterizationIsAtOrBelow($temporaryDatabase, $blockedDataPath))
	{
		unlink($temporaryDatabase);
		throw new RuntimeException('temporary_fixture_inside_grocy_datapath');
	}
	$openedPaths[] = $temporaryDatabase;
	return $temporaryDatabase;
}

function conversionCharacterizationBuildFixtureSchema(PDO $database, string $root, array &$openedPaths): void
{
	$database->exec(<<<'SQL'
CREATE TABLE quantity_units (id INTEGER PRIMARY KEY, name TEXT NOT NULL, name_plural TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY, qu_id_stock INTEGER NOT NULL, qu_id_purchase INTEGER NOT NULL, qu_id_consume INTEGER NOT NULL, qu_id_price INTEGER NOT NULL);
CREATE TABLE quantity_unit_conversions (from_qu_id INTEGER NOT NULL, to_qu_id INTEGER NOT NULL, factor REAL NOT NULL, product_id INTEGER, UNIQUE(product_id, from_qu_id, to_qu_id));
CREATE VIEW quantity_unit_conversions_resolved AS SELECT NULL AS id, NULL AS product_id, NULL AS from_qu_id, NULL AS from_qu_name, NULL AS from_qu_name_plural, NULL AS to_qu_id, NULL AS to_qu_name, NULL AS to_qu_name_plural, NULL AS factor, NULL AS path WHERE 0;
CREATE TRIGGER qu_conversions_inverse_INS AFTER INSERT ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_UPD AFTER UPDATE ON quantity_unit_conversions BEGIN SELECT 1; END;
CREATE TRIGGER qu_conversions_inverse_DEL AFTER DELETE ON quantity_unit_conversions BEGIN SELECT 1; END;
SQL);

	$resolverSql = conversionCharacterizationGitOutput($root, ['show', 'HEAD:migrations/0208.sql']);
	$cacheSql = conversionCharacterizationGitOutput($root, ['show', 'HEAD:migrations/0225.sql']);
	$cachePrefix = strstr($cacheSql, 'DROP VIEW recipes_pos_resolved;', true);
	if ($resolverSql === '' || $cachePrefix === false)
	{
		throw new RuntimeException('missing_cache_definitions');
	}

	$database->exec($resolverSql);
	$database->exec($cachePrefix);
	$database->exec(<<<'SQL'
CREATE TABLE fixture_stock_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, on_hand REAL NOT NULL, adjustment REAL NOT NULL);
CREATE TABLE fixture_recipe_positions (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, ingredient_amount REAL NOT NULL, servings REAL NOT NULL);
CREATE TABLE fixture_purchase_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, package_count REAL NOT NULL, package_amount REAL NOT NULL);
CREATE TABLE fixture_consumption_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, consumed_amount REAL NOT NULL);
CREATE TABLE fixture_price_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, priced_amount REAL NOT NULL, unit_price REAL NOT NULL);
CREATE TABLE fixture_transfer_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, sent_amount REAL NOT NULL, returned_amount REAL NOT NULL);
CREATE TABLE fixture_meal_plan_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, portion_amount REAL NOT NULL, servings REAL NOT NULL);
CREATE TABLE fixture_quantity_display_entries (product_id INTEGER NOT NULL, source_qu_id INTEGER NOT NULL, target_qu_id INTEGER NOT NULL, display_amount REAL NOT NULL);
SQL);
}

function conversionCharacterizationSeedFixture(PDO $database, array $manifest): void
{
	$database->exec("INSERT INTO quantity_units VALUES (1, 'stock', 'stocks'), (2, 'purchase', 'purchases'), (3, 'display', 'displays')");
	$database->exec('INSERT INTO products VALUES (1, 1, 2, 3, 3)');
	conversionCharacterizationInsertConversion($database, $manifest['native_default']);
	conversionCharacterizationInsertConversion($database, $manifest['product_override']);
	$database->exec(<<<'SQL'
INSERT INTO fixture_stock_entries VALUES (1, 1, 3, 3, 1);
INSERT INTO fixture_recipe_positions VALUES (1, 3, 1, 7, 1);
INSERT INTO fixture_purchase_entries VALUES (1, 2, 1, 2, 500);
INSERT INTO fixture_consumption_entries VALUES (1, 3, 1, 9);
INSERT INTO fixture_price_entries VALUES (1, 3, 1, 11, 1);
INSERT INTO fixture_transfer_entries VALUES (1, 1, 3, 5, 2);
INSERT INTO fixture_meal_plan_entries VALUES (1, 3, 1, 13, 1);
INSERT INTO fixture_quantity_display_entries VALUES (1, 2, 1, 1500);
SQL);
}

function conversionCharacterizationAssertNativeTriggers(PDO $database): void
{
	$triggers = $database->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name IN ('quantity_unit_conversions_INS', 'quantity_unit_conversions_UPD', 'quantity_unit_conversions_DEL') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
	if ($triggers !== ['quantity_unit_conversions_DEL', 'quantity_unit_conversions_INS', 'quantity_unit_conversions_UPD'])
	{
		throw new RuntimeException('missing_cache_definitions');
	}
}

function conversionCharacterizationExerciseNativeWrites(PDO $database, array $manifest): void
{
	conversionCharacterizationUpdateConversion($database, $manifest['native_default']);
	conversionCharacterizationUpdateConversion($database, $manifest['product_override']);
	conversionCharacterizationReplaceConversion($database, $manifest['native_default']);
	conversionCharacterizationReplaceConversion($database, $manifest['product_override']);
}

function conversionCharacterizationInsertConversion(PDO $database, array $conversion): void
{
	$statement = $database->prepare('INSERT INTO quantity_unit_conversions (product_id, from_qu_id, to_qu_id, factor) VALUES (:product_id, :from_qu_id, :to_qu_id, :factor)');
	$statement->execute([
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null,
		':factor' => $conversion['factor'] ?? null
	]);
}

function conversionCharacterizationReplaceConversion(PDO $database, array $conversion): void
{
	$delete = $database->prepare('DELETE FROM quantity_unit_conversions WHERE product_id IS :product_id AND from_qu_id = :from_qu_id AND to_qu_id = :to_qu_id');
	$delete->execute([
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null
	]);
	conversionCharacterizationInsertConversion($database, $conversion);
}

function conversionCharacterizationUpdateConversion(PDO $database, array $conversion): void
{
	$update = $database->prepare('UPDATE quantity_unit_conversions SET factor = :factor WHERE product_id IS :product_id AND from_qu_id = :from_qu_id AND to_qu_id = :to_qu_id');
	$update->execute([
		':factor' => $conversion['factor'] ?? null,
		':product_id' => $conversion['product_id'] ?? null,
		':from_qu_id' => $conversion['from_qu_id'] ?? null,
		':to_qu_id' => $conversion['to_qu_id'] ?? null
	]);
	if ($update->rowCount() !== 1)
	{
		throw new RuntimeException('native_trigger_not_exercised');
	}
}

function conversionCharacterizationCacheRows(PDO $database): array
{
	return $database->query('SELECT product_id, from_qu_id, to_qu_id, factor, path FROM cache__quantity_unit_conversions_resolved ORDER BY product_id, from_qu_id, to_qu_id')->fetchAll(PDO::FETCH_ASSOC);
}

function conversionCharacterizationCacheDelta(array $baseline, array $probe): array
{
	$aggregate = static fn(array $rows): array => [
		'row_count' => count($rows),
		'row_key_factor_path_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))
	];
	return ['baseline' => $aggregate($baseline), 'probe' => $aggregate($probe), 'equal' => $baseline === $probe];
}

function conversionCharacterizationAssertProbeCacheParity(array $baseline, array $probe): void
{
	if ($baseline !== $probe)
	{
		throw new RuntimeException('cache_aggregate_changed');
	}
}

function conversionCharacterizationProtectedOutputs(PDO $database, array $categories): array
{
	$queries = conversionCharacterizationProtectedOutputQueries();
	$outputs = [];
	foreach ($categories as $category)
	{
		if (!isset($queries[$category]))
		{
			throw new RuntimeException('invalid_branch_manifest');
		}
		$fixture = $queries[$category];
		$row = $database->query($fixture['sql'])->fetch(PDO::FETCH_ASSOC);
		if ($row === false)
		{
			throw new RuntimeException('protected_output_missing');
		}
		$outputs[$category] = [
			'operation' => $fixture['operation'],
			'factor' => (float)$row['factor'],
			'path' => (string)$row['path'],
			'converted_amount' => (float)$row['converted_amount']
		];
	}
	return $outputs;
}

function conversionCharacterizationProtectedOutputQueries(): array
{
	return [
		'stock' => ['operation' => 'stock-adjustment', 'sql' => 'SELECT (e.on_hand + e.adjustment) * c.factor AS converted_amount, c.factor, c.path FROM fixture_stock_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'recipe' => ['operation' => 'recipe-ingredient', 'sql' => 'SELECT e.ingredient_amount * e.servings * c.factor AS converted_amount, c.factor, c.path FROM fixture_recipe_positions e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'purchase' => ['operation' => 'purchase-entry', 'sql' => 'SELECT e.package_count * e.package_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_purchase_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'consumption' => ['operation' => 'consumption-entry', 'sql' => 'SELECT e.consumed_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_consumption_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'price' => ['operation' => 'price-entry', 'sql' => 'SELECT e.priced_amount * e.unit_price * c.factor AS converted_amount, c.factor, c.path FROM fixture_price_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'transfer' => ['operation' => 'transfer-entry', 'sql' => 'SELECT (e.sent_amount - e.returned_amount) * c.factor AS converted_amount, c.factor, c.path FROM fixture_transfer_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'meal-plan' => ['operation' => 'meal-plan-entry', 'sql' => 'SELECT e.portion_amount * e.servings * c.factor AS converted_amount, c.factor, c.path FROM fixture_meal_plan_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id'],
		'quantity-display' => ['operation' => 'quantity-display', 'sql' => 'SELECT e.display_amount * c.factor AS converted_amount, c.factor, c.path FROM fixture_quantity_display_entries e JOIN cache__quantity_unit_conversions_resolved c ON c.product_id = e.product_id AND c.from_qu_id = e.source_qu_id AND c.to_qu_id = e.target_qu_id']
	];
}

function conversionCharacterizationSchemaManifest(string $root, array &$openedPaths): array
{
	$objects = [];
	$hashes = [];
	foreach (['migrations/0208.sql', 'migrations/0225.sql'] as $relativePath)
	{
		$source = conversionCharacterizationGitOutput($root, ['show', 'HEAD:' . $relativePath]);
		$containsResolver = str_contains($source, 'quantity_unit_conversions_resolved');
		$containsCache = str_contains($source, 'cache__quantity_unit_conversions_resolved');
		$containsTrigger = str_contains($source, 'quantity_unit_conversions_INS');
		if (!$containsResolver || ($relativePath === 'migrations/0225.sql' && (!$containsCache || !$containsTrigger)))
		{
			throw new RuntimeException('missing_cache_definitions');
		}
		$hashes[$relativePath] = hash('sha256', $source);
		preg_match_all('/CREATE (?:TABLE|TRIGGER|INDEX)\s+([a-zA-Z0-9_]+)/', $source, $matches);
		$objects[$relativePath] = array_values(array_filter($matches[1], static fn(string $name): bool => str_contains($name, 'quantity_unit_conversions')));
	}
	return ['migration_hashes' => $hashes, 'cache_objects' => $objects];
}

function RedactedSqliteMaster(PDO $database): array
{
	$statement = $database->query("SELECT type, name, sql FROM sqlite_master WHERE name LIKE 'cache__quantity_unit_conversions_resolved%' OR name LIKE 'quantity_unit_conversions_%' ORDER BY type, name");
	$objects = [];
	foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$objects[] = [
			'type' => (string)$row['type'],
			'name' => (string)$row['name'],
			'sql_sha256' => hash('sha256', (string)$row['sql'])
		];
	}
	return $objects;
}

function conversionCharacterizationQueryPlan(PDO $database): array
{
	$plans = [];
	foreach (conversionCharacterizationProtectedOutputQueries() as $category => $fixture)
	{
		$statement = $database->query('EXPLAIN QUERY PLAN ' . $fixture['sql']);
		$plans[$category] = array_map(static fn(array $row): string => (string)$row['detail'], $statement->fetchAll(PDO::FETCH_ASSOC));
	}
	return $plans;
}

function conversionCharacterizationGitOutput(string $root, array $arguments): string
{
	$process = proc_open(array_merge(['git', '-C', $root], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process))
	{
		throw new RuntimeException('invalid_branch_root');
	}
	$stdout = stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	if (proc_close($process) !== 0)
	{
		throw new RuntimeException('invalid_branch_root');
	}
	return $stdout;
}

function conversionCharacterizationImmutableCommit(string $root): string
{
	$commit = trim(conversionCharacterizationGitOutput($root, ['rev-parse', 'HEAD']));
	if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1)
	{
		throw new RuntimeException('invalid_branch_root');
	}
	return $commit;
}
