<?php

declare(strict_types=1);

/**
 * audit-conversions.php — read-only conversion classifier + integrity tripwire (Phase 6 / 06-06, DATA-03/04/05).
 *
 * Closes the conversion-cleanup requirements by verification, using the correct real-data invariant:
 * product-specific quantity_unit_conversions rows are legitimate package-size definitions (a product's
 * own discrete unit expressed in a measured unit), NOT junk. The audit issues only SELECT statements and
 * mutates nothing. It summarizes the global set (rows whose product_id is null), classifies every
 * product-specific row as EXPECTED or SUSPICIOUS by four explicit integrity rules, and — as a durable
 * tripwire — exits non-zero while naming every SUSPICIOUS row (and the rule it failed).
 *
 * A product-specific row is EXPECTED iff ALL hold:
 *   1. product_id references an existing products row (referential integrity).
 *   2. from_qu_id OR to_qu_id equals the product's qu_id_stock or qu_id_purchase (a package definition).
 *   3. factor is finite and > 0.
 *   4. it is not an exact (from_qu_id, to_qu_id, factor) match of a global (product_id IS NULL) row.
 * Any row failing any rule is SUSPICIOUS.
 *
 * Library (also required by tests/conversion_audit.php):
 *   auditConversions(PDO): array
 *     {global_count, product_specific_count, expected_count, expected_product_count, suspicious:[...], ok:bool}
 *
 * CLI:
 *   audit-conversions.php [--db PATH]
 * Opens the database read-only (PRAGMA query_only=1). With no --db it audits the 06-01 local snapshot at
 * custom/grocy_AI/.snapshots/grocy-prod.sqlite. Prints the baseline (global count, expected count,
 * distinct product count); exits 0 when every product-specific row is a legitimate package definition, or
 * non-zero (naming each SUSPICIOUS row + the failed rule) when the tripwire finds any.
 */

/**
 * Classify one product-specific conversion row. Returns null when the row is a legitimate package
 * definition (EXPECTED), or the identifier of the first failed integrity rule (SUSPICIOUS).
 *
 * @param array<string,mixed> $row     joined row: id, from_qu_id, to_qu_id, factor, product_id,
 *                                      resolved_product_id (null when orphaned), qu_id_stock, qu_id_purchase
 * @param array<string,list<float>> $globalTuples  map "from|to" => list of global factors
 */
function auditConversionsClassify(array $row, array $globalTuples): ?string
{
	// Rule 1 — referential integrity: the product_id must resolve to a real products row.
	if ($row['resolved_product_id'] === null)
	{
		return 'orphaned_product_id';
	}

	// Rule 2 — at least one side must be the product's own stock or purchase unit (a package definition).
	$stock = (int)$row['qu_id_stock'];
	$purchase = (int)$row['qu_id_purchase'];
	$from = (int)$row['from_qu_id'];
	$to = (int)$row['to_qu_id'];
	$sideIsOwnUnit = in_array($from, [$stock, $purchase], true) || in_array($to, [$stock, $purchase], true);
	if (!$sideIsOwnUnit)
	{
		return 'neither_side_product_unit';
	}

	// Rule 3 — factor must be finite and strictly positive.
	$factor = (float)$row['factor'];
	if (!is_finite($factor) || $factor <= 0.0)
	{
		return 'non_positive_or_non_finite_factor';
	}

	// Rule 4 — must not exactly match a global (from_qu_id, to_qu_id, factor) tuple.
	$key = $from . '|' . $to;
	if (isset($globalTuples[$key]))
	{
		foreach ($globalTuples[$key] as $globalFactor)
		{
			if ($globalFactor === $factor)
			{
				return 'global_duplicate';
			}
		}
	}

	return null;
}

/**
 * Audit the quantity_unit_conversions table read-only, classifying every product-specific row.
 *
 * @return array{global_count:int, product_specific_count:int, expected_count:int,
 *               expected_product_count:int, suspicious:list<array<string,mixed>>, ok:bool}
 */
function auditConversions(PDO $pdo): array
{
	$globalCount = (int)$pdo
		->query('SELECT COUNT(*) FROM quantity_unit_conversions WHERE product_id IS NULL')
		->fetchColumn();

	// Global (from,to,factor) tuples, keyed for the rule-4 duplicate test.
	$globalRows = $pdo
		->query('SELECT from_qu_id, to_qu_id, factor FROM quantity_unit_conversions WHERE product_id IS NULL')
		->fetchAll(PDO::FETCH_ASSOC);
	$globalTuples = [];
	foreach ($globalRows as $globalRow)
	{
		$globalTuples[(int)$globalRow['from_qu_id'] . '|' . (int)$globalRow['to_qu_id']][] = (float)$globalRow['factor'];
	}

	$rows = $pdo
		->query(
			'SELECT c.id, c.from_qu_id, c.to_qu_id, c.factor, c.product_id, '
			. 'p.id AS resolved_product_id, p.qu_id_stock, p.qu_id_purchase '
			. 'FROM quantity_unit_conversions c '
			. 'LEFT JOIN products p ON p.id = c.product_id '
			. 'WHERE c.product_id IS NOT NULL '
			. 'ORDER BY c.product_id, c.id'
		)
		->fetchAll(PDO::FETCH_ASSOC);

	$suspicious = [];
	$expectedCount = 0;
	$expectedProducts = [];

	foreach ($rows as $row)
	{
		$rule = auditConversionsClassify($row, $globalTuples);
		if ($rule === null)
		{
			$expectedCount++;
			$expectedProducts[(int)$row['product_id']] = true;
			continue;
		}
		$suspicious[] = [
			'id' => (int)$row['id'],
			'product_id' => (int)$row['product_id'],
			'from_qu_id' => (int)$row['from_qu_id'],
			'to_qu_id' => (int)$row['to_qu_id'],
			'factor' => (float)$row['factor'],
			'rule' => $rule,
		];
	}

	return [
		'global_count' => $globalCount,
		'product_specific_count' => count($rows),
		'expected_count' => $expectedCount,
		'expected_product_count' => count($expectedProducts),
		'suspicious' => $suspicious,
		'ok' => $suspicious === [],
	];
}

/** Resolve a product name for a row when a products table is present; '' otherwise. */
function auditConversionsProductName(PDO $pdo, int $productId): string
{
	try
	{
		$stmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
		$stmt->execute([$productId]);
		$name = $stmt->fetchColumn();
		return $name === false ? '' : (string)$name;
	}
	catch (\Throwable)
	{
		return '';
	}
}

// --------------------------------------------------------------------------
// CLI (only when executed directly; requiring this file just loads the library)
// --------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__))
{
	auditConversionsCli($argv);
}

function auditConversionsCliArg(array $argv, string $name, ?string $default = null): ?string
{
	$flag = '--' . $name;
	foreach ($argv as $i => $a)
	{
		if ($a === $flag)
		{
			return $argv[$i + 1] ?? '';
		}
		if (str_starts_with($a, $flag . '='))
		{
			return substr($a, strlen($flag) + 1);
		}
	}
	return $default;
}

function auditConversionsCli(array $argv): never
{
	$dbPath = auditConversionsCliArg($argv, 'db');
	if ($dbPath === null || $dbPath === '')
	{
		$dbPath = __DIR__ . '/../.snapshots/grocy-prod.sqlite';
	}
	if (!is_file($dbPath))
	{
		fwrite(STDERR, "db not found: {$dbPath}\n");
		exit(2);
	}

	$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec('PRAGMA query_only = 1');

	$result = auditConversions($pdo);

	fwrite(STDOUT, sprintf(
		"[audit-conversions] %s\n"
		. "[audit-conversions] global conversions (product_id IS NULL): %d\n"
		. "[audit-conversions] product-specific conversions: %d\n"
		. "[audit-conversions] expected (legitimate package definitions): %d across %d product(s)\n"
		. "[audit-conversions] suspicious: %d\n",
		$dbPath,
		$result['global_count'],
		$result['product_specific_count'],
		$result['expected_count'],
		$result['expected_product_count'],
		count($result['suspicious'])
	));

	if (!$result['ok'])
	{
		fwrite(STDERR, "[audit-conversions] TRIPWIRE: suspicious product-specific conversion(s) found:\n");
		foreach ($result['suspicious'] as $row)
		{
			$productId = (int)$row['product_id'];
			$name = auditConversionsProductName($pdo, $productId);
			$label = $name === '' ? '' : " ({$name})";
			fwrite(STDERR, sprintf(
				"[audit-conversions]   conversion id=%d product_id=%d%s from_qu_id=%d to_qu_id=%d factor=%s rule=%s\n",
				(int)$row['id'],
				$productId,
				$label,
				(int)$row['from_qu_id'],
				(int)$row['to_qu_id'],
				(string)$row['factor'],
				(string)$row['rule']
			));
		}
		fwrite(STDERR, sprintf(
			"[audit-conversions] FAIL: %d suspicious conversion(s)\n",
			count($result['suspicious'])
		));
		exit(1);
	}

	fwrite(STDOUT, sprintf(
		"[audit-conversions] OK: %d global + %d expected package definition(s) across %d product(s); nothing suspicious, nothing changed\n",
		$result['global_count'],
		$result['expected_count'],
		$result['expected_product_count']
	));
	exit(0);
}
