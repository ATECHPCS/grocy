<?php

declare(strict_types=1);

/**
 * audit-conversions.php — read-only conversion audit + regression tripwire (Phase 6 / 06-06, DATA-03/04/05).
 *
 * Closes the conversion-cleanup requirements by verification rather than by building any deletion or
 * comparison machinery. The audit issues only SELECT statements; it performs no writes and mutates
 * nothing. It asserts there are zero product-specific conversions (rows whose product_id is not null),
 * summarizes the global set (rows whose product_id is null), and — as a durable regression tripwire —
 * exits non-zero while naming every offending row if any product-specific conversion is ever present.
 *
 * Library (also required by tests/conversion_audit.php):
 *   auditConversions(PDO): array   — {product_specific_count, global_count, offenders:[...], ok:bool}
 *
 * CLI:
 *   audit-conversions.php [--db PATH]
 * Opens the database read-only (PRAGMA query_only=1). With no --db it audits the 06-01 local snapshot at
 * custom/grocy_AI/.snapshots/grocy-prod.sqlite. Prints the summary; exits 0 when zero product-specific
 * conversions exist, or non-zero (naming each offending row) when the tripwire finds any.
 */

/**
 * Audit the quantity_unit_conversions table read-only.
 *
 * @return array{product_specific_count:int, global_count:int, offenders:list<array<string,mixed>>, ok:bool}
 */
function auditConversions(PDO $pdo): array
{
	$productSpecificCount = (int)$pdo
		->query('SELECT COUNT(*) FROM quantity_unit_conversions WHERE product_id IS NOT NULL')
		->fetchColumn();
	$globalCount = (int)$pdo
		->query('SELECT COUNT(*) FROM quantity_unit_conversions WHERE product_id IS NULL')
		->fetchColumn();

	$offenders = [];
	if ($productSpecificCount > 0)
	{
		$offenders = $pdo
			->query('SELECT id, product_id, from_qu_id, to_qu_id, factor FROM quantity_unit_conversions WHERE product_id IS NOT NULL ORDER BY product_id, id')
			->fetchAll(PDO::FETCH_ASSOC);
	}

	return [
		'product_specific_count' => $productSpecificCount,
		'global_count' => $globalCount,
		'offenders' => $offenders,
		'ok' => $productSpecificCount === 0,
	];
}

/** Resolve a product name for an offending row when a products table is present; '' otherwise. */
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
		"[audit-conversions] %s\n[audit-conversions] product-specific conversions: %d\n[audit-conversions] global conversions (product_id IS NULL): %d\n",
		$dbPath,
		$result['product_specific_count'],
		$result['global_count']
	));

	if ($result['product_specific_count'] > 0)
	{
		fwrite(STDERR, "[audit-conversions] TRIPWIRE: product-specific conversions are present (expected zero):\n");
		foreach ($result['offenders'] as $row)
		{
			$productId = (int)$row['product_id'];
			$name = auditConversionsProductName($pdo, $productId);
			$label = $name === '' ? '' : " ({$name})";
			fwrite(STDERR, sprintf(
				"[audit-conversions]   conversion id=%d product_id=%d%s from_qu_id=%d to_qu_id=%d factor=%s\n",
				(int)$row['id'],
				$productId,
				$label,
				(int)$row['from_qu_id'],
				(int)$row['to_qu_id'],
				(string)$row['factor']
			));
		}
		fwrite(STDERR, sprintf(
			"[audit-conversions] FAIL: %d product-specific conversion(s) across %d product(s)\n",
			$result['product_specific_count'],
			count(array_unique(array_map(static fn(array $r): int => (int)$r['product_id'], $result['offenders'])))
		));
		exit(1);
	}

	fwrite(STDOUT, sprintf(
		"[audit-conversions] OK: 0 product-specific conversions; %d global conversion(s) left untouched\n",
		$result['global_count']
	));
	exit(0);
}
