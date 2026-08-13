<?php

declare(strict_types=1);

$gtinFile = __DIR__ . '/../src/GrocyAiGtin.php';
$barcodeServiceFile = __DIR__ . '/../src/GrocyAiBarcodeService.php';
if (is_file($gtinFile))
{
	require_once $gtinFile;
}
if (is_file($barcodeServiceFile))
{
	require_once $barcodeServiceFile;
}

use GrocyAI\Services\GrocyAiBarcodeService;
use GrocyAI\Services\GrocyAiGtin;

$failures = 0;
$tests = 0;

function checkBarcode(bool $condition, string $message): void
{
	global $failures, $tests;
	$tests++;
	if (!$condition)
	{
		$failures++;
		fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
	}
}

function expectBarcodeException(callable $callback, string $exceptionClass, string $message): void
{
	try
	{
		$callback();
		checkBarcode(false, $message);
	}
	catch (Throwable $ex)
	{
		checkBarcode($ex instanceof $exceptionClass, $message . ' (received ' . get_class($ex) . ')');
	}
}

function expectedBarcodeRed(string $message): never
{
	fwrite(STDERR, 'EXPECTED_RED: gtin.shared_predicate' . PHP_EOL);
	fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
	exit(1);
}

function canonicalVectors(): array
{
	return [
		['id' => 'valid-8', 'value' => '96385074', 'canonical' => '00000096385074'],
		['id' => 'valid-12-leading-zero', 'value' => '012345678905', 'canonical' => '00012345678905'],
		['id' => 'valid-13', 'value' => '4006381333931', 'canonical' => '04006381333931'],
		['id' => 'valid-14', 'value' => '10012345000017', 'canonical' => '10012345000017']
	];
}

function invalidVectors(): array
{
	return [
		['id' => 'invalid-8-checksum', 'value' => '96385075'],
		['id' => 'invalid-12-checksum', 'value' => '012345678906'],
		['id' => 'invalid-13-checksum', 'value' => '4006381333932'],
		['id' => 'invalid-14-checksum', 'value' => '10012345000018'],
		['id' => 'numeric-unsupported-length', 'value' => '123456789'],
		['id' => 'arbitrary-text', 'value' => 'shelf-A-123']
	];
}

function assertSharedPredicate(): void
{
	if (!class_exists(GrocyAiGtin::class)
		|| !method_exists(GrocyAiGtin::class, 'CanonicalOrNull')
		|| !method_exists(GrocyAiGtin::class, 'CanonicalSqlExpression'))
	{
		expectedBarcodeRed('The shared PHP/SQLite GTIN predicate is not implemented');
	}

	foreach (canonicalVectors() as $vector)
	{
		checkBarcode(
			GrocyAiGtin::CanonicalOrNull($vector['value']) === $vector['canonical'],
			$vector['id'] . ' preserves its text and canonicalizes to 14 characters'
		);
	}
	foreach (invalidVectors() as $vector)
	{
		checkBarcode(
			GrocyAiGtin::CanonicalOrNull($vector['value']) === null,
			$vector['id'] . ' remains outside canonical GTIN ownership'
		);
	}
	checkBarcode(GrocyAiGtin::CanonicalOrNull('00012345678905') === '00012345678905', 'A leading-zero canonical equivalent resolves identically');
	checkBarcode(GrocyAiGtin::CanonicalOrNull(' 012345678905 ') === null, 'Canonical ownership never trims or normalizes the scanned display string');
}

if (($argv[1] ?? null) === '--case')
{
	if (($argv[2] ?? null) !== 'gtin.shared_predicate')
	{
		fwrite(STDERR, 'Unknown test case' . PHP_EOL);
		exit(2);
	}
	assertSharedPredicate();
	if ($failures > 0)
	{
		exit(1);
	}
	fwrite(STDOUT, 'Shared GTIN predicate case passed' . PHP_EOL);
	exit(0);
}

assertSharedPredicate();

$sqlExpression = GrocyAiGtin::CanonicalSqlExpression('barcode');
checkBarcode(str_contains($sqlExpression, 'CASE'), 'The SQL predicate is a closed CASE expression');
checkBarcode(str_contains($sqlExpression, 'ELSE NULL'), 'The SQL predicate excludes arbitrary Grocy barcodes with NULL');
expectBarcodeException(
	fn() => GrocyAiGtin::CanonicalSqlExpression('barcode); DELETE FROM products; --'),
	InvalidArgumentException::class,
	'The SQL expression owner rejects untrusted column expressions'
);

$repositoryRoots = [
	'main' => dirname(__DIR__, 3),
	'stable' => '/Users/ian/Documents/Repos/grocy-atech-release'
];

foreach ($repositoryRoots as $branchName => $repositoryRoot)
{
	$createMigration = file_get_contents($repositoryRoot . '/migrations/0103.sql');
	$uniqueMigration = file_get_contents($repositoryRoot . '/migrations/0128.sql');
	checkBarcode(str_contains($createMigration, 'barcode TEXT NOT NULL'), $branchName . ' schema keeps barcodes as text');
	checkBarcode(str_contains($uniqueMigration, 'CREATE UNIQUE INDEX ix_product_barcodes'), $branchName . ' schema has the exact-text uniqueness baseline');

	$tempPath = tempnam(sys_get_temp_dir(), 'grocy-ai-barcode-');
	if ($tempPath === false)
	{
		throw new RuntimeException('Unable to allocate the isolated barcode fixture');
	}
	try
	{
		$pdo = new PDO('sqlite:' . $tempPath);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE product_barcodes (id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, barcode TEXT NOT NULL)');
		$pdo->exec('CREATE UNIQUE INDEX ix_product_barcodes ON product_barcodes (barcode)');
		$pdo->exec("INSERT INTO products (id, name) VALUES (10, 'Current'), (20, 'Other')");

		$insert = $pdo->prepare('INSERT INTO product_barcodes (product_id, barcode) VALUES (:product_id, :barcode)');
		$insert->execute(['product_id' => 20, 'barcode' => '012345678905']);
		$query = $pdo->prepare(
			'SELECT pb.product_id, p.name AS owner_label FROM product_barcodes pb JOIN products p ON p.id = pb.product_id WHERE '
			. GrocyAiGtin::CanonicalSqlExpression('pb.barcode') . ' = :canonical ORDER BY pb.id LIMIT 2'
		);
		$query->execute(['canonical' => '00012345678905']);
		$rows = $query->fetchAll(PDO::FETCH_ASSOC);
		checkBarcode(count($rows) === 1 && (int)$rows[0]['product_id'] === 20, $branchName . ' fixture resolves a leading-zero equivalent owner');

		$insert->execute(['product_id' => 10, 'barcode' => '00012345678905']);
		$collisionQuery = $pdo->query(
			'SELECT COUNT(*) FROM (SELECT ' . GrocyAiGtin::CanonicalSqlExpression('barcode')
			. ' AS canonical_gtin FROM product_barcodes GROUP BY canonical_gtin HAVING canonical_gtin IS NOT NULL AND COUNT(*) > 1)'
		);
		checkBarcode((int)$collisionQuery->fetchColumn() === 1, $branchName . ' fixture detects one canonical collision group without row output');

		$pdo->exec("DELETE FROM product_barcodes WHERE barcode = '00012345678905'");
		$pdo->exec('CREATE UNIQUE INDEX ix_product_barcodes_canonical_fixture ON product_barcodes (' . $sqlExpression . ')');
		expectBarcodeException(
			fn() => $insert->execute(['product_id' => 10, 'barcode' => '00012345678905']),
			PDOException::class,
			$branchName . ' fixture rejects a canonical-equivalent duplicate'
		);
		$insert->execute(['product_id' => 10, 'barcode' => '012345678906']);
		$insert->execute(['product_id' => 20, 'barcode' => '00012345678906']);
		$insert->execute(['product_id' => 10, 'barcode' => 'shelf-A-123']);
		checkBarcode((int)$pdo->query('SELECT COUNT(*) FROM product_barcodes')->fetchColumn() === 4, $branchName . ' fixture preserves invalid numeric-looking and arbitrary text barcodes');
	}
	finally
	{
		$pdo = null;
		@unlink($tempPath);
	}
}

if (!class_exists(GrocyAiBarcodeService::class))
{
	checkBarcode(false, 'The read-only barcode owner service exists');
}
else
{
	$lookupRows = [[
		'product_id' => 20,
		'owner_label' => str_repeat('Owner label ', 30)
	]];
	$lookupCalls = 0;
	$service = new GrocyAiBarcodeService(function (string $canonical, string $expression) use (&$lookupCalls, &$lookupRows): array
	{
		$lookupCalls++;
		checkBarcode($canonical === '00012345678905', 'Owner lookup receives only the canonical text key');
		checkBarcode(str_contains($expression, 'CASE'), 'Owner lookup receives the shared SQL expression');
		return $lookupRows;
	}, 10);

	$current = $service->ResolveOwner('012345678905');
	checkBarcode($current['status'] === 'owned_other' && $current['owner_product_id'] === 20, 'Owner-other is derived from the local lookup result');
	checkBarcode($current['scanned_gtin'] === '012345678905', 'Owner response preserves the exact scanned display string');
	checkBarcode(strlen($current['owner_label']) <= 120, 'Owner label is bounded before leaving the server');

	$lookupRows = [['product_id' => 10, 'owner_label' => 'Current product']];
	$current = $service->ResolveOwner('012345678905');
	checkBarcode($current['status'] === 'owned_current' && $current['owner_product_id'] === 10, 'Owner-current is deterministic');

	$lookupRows = [];
	$unused = $service->ResolveOwner('012345678905');
	checkBarcode($unused['status'] === 'unused' && $unused['owner_product_id'] === null, 'No owner returns one transient unused result');
	checkBarcode($unused['equivalents_checked'] === ['012345678905', '00012345678905'], 'Equivalent display is bounded and keeps the scan first');

	$lookupRows = [
		['product_id' => 10, 'owner_label' => 'Current product'],
		['product_id' => 20, 'owner_label' => 'Other product']
	];
	expectBarcodeException(fn() => $service->ResolveOwner('012345678905'), RuntimeException::class, 'A pre-existing canonical collision fails closed');
	expectBarcodeException(fn() => $service->ResolveOwner('012345678906'), InvalidArgumentException::class, 'Invalid numeric-looking barcodes are rejected only by enrichment ownership');

	$permissionCalls = 0;
	$providerCalls = 0;
	$lookupCalls = 0;
	$lookupRows = [];
	expectBarcodeException(
		function () use ($service, &$permissionCalls, &$providerCalls): array
		{
			return $service->ResolveBeforeProvider(
				'012345678905',
				function () use (&$permissionCalls): void
				{
					$permissionCalls++;
					throw new RuntimeException('denied');
				},
				function () use (&$providerCalls): array
				{
					$providerCalls++;
					return [];
				}
			);
		},
		RuntimeException::class,
		'Authorization failure remains closed'
	);
	checkBarcode(
		$permissionCalls === 1 && $lookupCalls === 0 && $providerCalls === 0,
		'Authorization fails before owner lookup or provider work (permission=' . $permissionCalls . ', lookup=' . $lookupCalls . ', provider=' . $providerCalls . ')'
	);

	$lookupRows = [['product_id' => 20, 'owner_label' => 'Other product']];
	$guarded = $service->ResolveBeforeProvider('012345678905', function () use (&$permissionCalls): void
	{
		$permissionCalls++;
	}, function () use (&$providerCalls): array
	{
		$providerCalls++;
		return ['outcome' => 'should-not-run'];
	});
	checkBarcode($guarded['ownership']['status'] === 'owned_other' && $guarded['provider_result'] === null, 'An existing owner suppresses provider work');
	checkBarcode($providerCalls === 0, 'Owner-other performs zero provider calls');

	$lookupRows = [];
	$guarded = $service->ResolveBeforeProvider('012345678905', static function (): void
	{
	}, function () use (&$providerCalls): array
	{
		$providerCalls++;
		return ['outcome' => 'found'];
	});
	checkBarcode($guarded['ownership']['status'] === 'unused' && $guarded['provider_result']['outcome'] === 'found', 'Only an unused barcode reaches the provider');
}

$routesSource = file_get_contents(__DIR__ . '/../routes.php');
$controllerSource = file_get_contents(__DIR__ . '/../src/GrocyAiApiController.php');
checkBarcode(str_contains($routesSource, "get('/barcodes/resolve/{barcode}'"), 'The barcode resolver is registered as a GET route');
checkBarcode(!str_contains($routesSource, "post('/barcodes/resolve/"), 'The barcode resolver has no write route');
checkBarcode(
	preg_match('/function ResolveBarcode[\s\S]*?User::CheckPermission[\s\S]*?ResolveOwner/', $controllerSource) === 1,
	'The owner route checks MASTER_DATA_EDIT before lookup'
);
checkBarcode(
	preg_match('/function EnrichByUpc[\s\S]*?ResolveBeforeProvider/', $controllerSource) === 1,
	'The enrichment route uses the permission-first owner guard before provider work'
);
checkBarcode(!str_contains($controllerSource, "$" . "args['owner_product_id']"), 'Controller navigation authority never comes from route owner IDs');
checkBarcode(!str_contains($controllerSource, "getQueryParams()['owner_product_id']"), 'Controller navigation authority never comes from query owner IDs');

if ($failures > 0)
{
	fwrite(STDERR, $failures . ' of ' . $tests . ' barcode handoff checks failed' . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, 'All ' . $tests . ' barcode handoff checks passed' . PHP_EOL);
