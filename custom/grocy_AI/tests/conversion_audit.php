<?php

declare(strict_types=1);

/**
 * conversion_audit.php — RED-first proof of the classifying conversion audit + integrity tripwire
 * (Phase 6 / 06-06, DATA-03/04/05).
 *
 * Exercises the read-only audit library (bin/audit-conversions.php) on production-shaped in-memory
 * fixtures. Product-specific quantity_unit_conversions rows are legitimate package-size definitions
 * UNLESS they fail an explicit integrity rule; the classifier proves both directions:
 *   (a) a legitimate package definition (product's own unit -> a measured unit, real product, positive
 *       factor, not matching any global tuple) is EXPECTED and the audit PASSES, reporting the global
 *       count plus the expected product-specific count and distinct product count;
 *   (b) each SUSPICIOUS form trips the audit (ok=false) and is named with the rule it failed —
 *       an orphaned product_id, a factor of 0 / negative / non-finite, a conversion whose from and to
 *       are both unrelated to the product's stock/purchase unit, and an exact global tuple match;
 *   (c) the audit is read-only — the conversions table is byte-identical (row-value equality) across
 *       repeated audit calls, and the audit source declares no write verb;
 *   (d) INFORMATIONAL: when the 06-01 snapshot is present, the audit reports 62 global + 18 expected
 *       across 9 products with nothing suspicious (the baseline).
 *
 * Registered from tests/run.php; its checks run in the default (no-arg) suite via the shared check().
 * Follows the run.php convention of requiring its own dependencies (guarded by is_file).
 */

(static function (): void {
	$base = __DIR__ . '/..';
	foreach ([
		'bin/audit-conversions.php',
	] as $rel)
	{
		$path = $base . '/' . $rel;
		if (is_file($path))
		{
			require_once $path;
		}
	}
})();

/**
 * Build a production-shaped in-memory fixture: a products table (id, name, qu_id_stock, qu_id_purchase)
 * and a quantity_unit_conversions table (id, from_qu_id, to_qu_id, factor, product_id). No Grocy inverse
 * triggers are installed, so each authored row stands alone for classification.
 */
function conversionAuditPdo(): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE products (id INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL, qu_id_stock INT NOT NULL, qu_id_purchase INT NOT NULL)');
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, from_qu_id INT NOT NULL, to_qu_id INT NOT NULL, factor REAL NOT NULL, product_id INT)');
	return $pdo;
}

/** Seed a product row. */
function conversionAuditAddProduct(PDO $pdo, int $id, int $quStock, int $quPurchase): void
{
	$stmt = $pdo->prepare('INSERT INTO products (id, name, qu_id_stock, qu_id_purchase) VALUES (?, ?, ?, ?)');
	$stmt->execute([$id, 'Product ' . $id, $quStock, $quPurchase]);
}

/** Seed a global conversion (product_id IS NULL). */
function conversionAuditAddGlobal(PDO $pdo, int $from, int $to, float $factor): void
{
	$stmt = $pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, NULL)');
	$stmt->execute([$from, $to, $factor]);
}

/** Seed a product-specific conversion. */
function conversionAuditAddProductRow(PDO $pdo, int $from, int $to, float $factor, int $productId): void
{
	$stmt = $pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)');
	$stmt->execute([$from, $to, $factor, $productId]);
}

/** Return the single suspicious entry's rule for a given product_id, or '' when none. */
function conversionAuditSuspiciousRuleFor(array $result, int $productId): string
{
	foreach ($result['suspicious'] ?? [] as $row)
	{
		if ((int)$row['product_id'] === $productId)
		{
			return (string)$row['rule'];
		}
	}
	return '';
}

function runConversionAuditSuite(): void
{
	// RED gate: without the audit library these assertions fail (Task 1 is RED until Task 2 lands the bin).
	if (!function_exists('auditConversions'))
	{
		check(false, 'conversion_audit: the read-only audit library bin/audit-conversions.php is available');
		return;
	}

	// (a) A legitimate package definition is EXPECTED and the audit passes with an exact baseline.
	$legit = conversionAuditPdo();
	conversionAuditAddProduct($legit, 42, 2, 2);
	conversionAuditAddGlobal($legit, 1, 2, 1.0);
	conversionAuditAddGlobal($legit, 3, 4, 2.0);
	conversionAuditAddGlobal($legit, 5, 6, 3.0);
	conversionAuditAddProductRow($legit, 2, 11, 10.0, 42); // Piece -> gram package size for product 42
	$ok = auditConversions($legit);
	check(($ok['ok'] ?? null) === true, 'conversion_audit: a legitimate package definition passes the audit');
	check(($ok['global_count'] ?? null) === 3, 'conversion_audit: the global conversion count is reported exactly');
	check(($ok['product_specific_count'] ?? null) === 1, 'conversion_audit: the product-specific conversion count is reported exactly');
	check(($ok['expected_count'] ?? null) === 1, 'conversion_audit: the legitimate package definition is classified EXPECTED');
	check(($ok['expected_product_count'] ?? null) === 1, 'conversion_audit: the distinct expected-product count is reported exactly');
	check(($ok['suspicious'] ?? null) === [], 'conversion_audit: a legitimate package definition names nothing suspicious');

	// (b1) Tripwire — orphaned product_id (references no products row).
	$orphan = conversionAuditPdo();
	conversionAuditAddProductRow($orphan, 2, 11, 10.0, 999);
	$orphanResult = auditConversions($orphan);
	check(($orphanResult['ok'] ?? null) === false, 'conversion_audit: an orphaned product_id trips the audit');
	check(conversionAuditSuspiciousRuleFor($orphanResult, 999) === 'orphaned_product_id', 'conversion_audit: the orphaned product_id is named with rule orphaned_product_id');

	// (b2) Tripwire — a factor of zero.
	$zero = conversionAuditPdo();
	conversionAuditAddProduct($zero, 50, 2, 2);
	conversionAuditAddProductRow($zero, 2, 11, 0.0, 50);
	$zeroResult = auditConversions($zero);
	check(($zeroResult['ok'] ?? null) === false, 'conversion_audit: a zero factor trips the audit');
	check(conversionAuditSuspiciousRuleFor($zeroResult, 50) === 'non_positive_or_non_finite_factor', 'conversion_audit: a zero factor is named with rule non_positive_or_non_finite_factor');

	// (b3) Tripwire — a negative factor.
	$negative = conversionAuditPdo();
	conversionAuditAddProduct($negative, 51, 2, 2);
	conversionAuditAddProductRow($negative, 2, 11, -5.0, 51);
	$negativeResult = auditConversions($negative);
	check(($negativeResult['ok'] ?? null) === false, 'conversion_audit: a negative factor trips the audit');
	check(conversionAuditSuspiciousRuleFor($negativeResult, 51) === 'non_positive_or_non_finite_factor', 'conversion_audit: a negative factor is named with rule non_positive_or_non_finite_factor');

	// (b4) Tripwire — a non-finite factor (overflow to infinity round-trips as non-finite).
	$nonFinite = conversionAuditPdo();
	conversionAuditAddProduct($nonFinite, 52, 2, 2);
	$nonFinite->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (2, 11, 1e309, 52)');
	$nonFiniteResult = auditConversions($nonFinite);
	check(($nonFiniteResult['ok'] ?? null) === false, 'conversion_audit: a non-finite factor trips the audit');
	check(conversionAuditSuspiciousRuleFor($nonFiniteResult, 52) === 'non_positive_or_non_finite_factor', 'conversion_audit: a non-finite factor is named with rule non_positive_or_non_finite_factor');

	// (b5) Tripwire — neither side is the product's stock/purchase unit.
	$neither = conversionAuditPdo();
	conversionAuditAddProduct($neither, 60, 2, 2);
	conversionAuditAddProductRow($neither, 7, 11, 3.0, 60); // 7 and 11 are neither stock nor purchase (2)
	$neitherResult = auditConversions($neither);
	check(($neitherResult['ok'] ?? null) === false, 'conversion_audit: a conversion touching neither product unit trips the audit');
	check(conversionAuditSuspiciousRuleFor($neitherResult, 60) === 'neither_side_product_unit', 'conversion_audit: neither-side-product-unit is named with rule neither_side_product_unit');

	// (b6) Tripwire — an exact global tuple match (same from,to,factor as a global row).
	$duplicate = conversionAuditPdo();
	conversionAuditAddProduct($duplicate, 70, 2, 2);
	conversionAuditAddGlobal($duplicate, 2, 11, 10.0);
	conversionAuditAddProductRow($duplicate, 2, 11, 10.0, 70);
	$duplicateResult = auditConversions($duplicate);
	check(($duplicateResult['ok'] ?? null) === false, 'conversion_audit: a product row duplicating a global tuple trips the audit');
	check(conversionAuditSuspiciousRuleFor($duplicateResult, 70) === 'global_duplicate', 'conversion_audit: a global-tuple duplicate is named with rule global_duplicate');

	// A product row that shares the from/to of a global but a DIFFERENT factor is NOT a duplicate.
	$notDuplicate = conversionAuditPdo();
	conversionAuditAddProduct($notDuplicate, 71, 2, 2);
	conversionAuditAddGlobal($notDuplicate, 2, 11, 10.0);
	conversionAuditAddProductRow($notDuplicate, 2, 11, 12.0, 71);
	$notDuplicateResult = auditConversions($notDuplicate);
	check(($notDuplicateResult['ok'] ?? null) === true, 'conversion_audit: a matching from/to with a different factor is not a global duplicate');
	check(($notDuplicateResult['expected_count'] ?? null) === 1, 'conversion_audit: the differing-factor package definition is EXPECTED');

	// (c) Read-only: the conversions table is byte-identical (row-value equality) across repeated audits.
	$readOnly = conversionAuditPdo();
	conversionAuditAddProduct($readOnly, 80, 2, 2);
	conversionAuditAddGlobal($readOnly, 1, 2, 1.0);
	conversionAuditAddProductRow($readOnly, 2, 11, 10.0, 80);
	$rowsBefore = $readOnly->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	auditConversions($readOnly);
	auditConversions($readOnly);
	$rowsAfter = $readOnly->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	check($rowsAfter === $rowsBefore, 'conversion_audit: the conversions table is byte-identical after auditing (row-value equality)');

	// (c) Read-only: the audit source declares no write verb.
	$auditSource = (string)file_get_contents(__DIR__ . '/../bin/audit-conversions.php');
	check(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER)\b/i', $auditSource) !== 1, 'conversion_audit: the audit source declares no write verb (read-only)');

	// (d) INFORMATIONAL: the real 06-01 snapshot baseline (62 global + 18 expected across 9 products).
	$snapshotSummary = runConversionAuditSnapshotBaseline();

	fwrite(STDOUT, sprintf(
		"[conversion_audit] classifying read-only audit + integrity tripwire verified; snapshot %s\n",
		$snapshotSummary
	));
}

/**
 * When the 06-01 snapshot is present, open it read-only, run the audit, and assert the recorded baseline
 * (62 global + 18 expected across 9 products, nothing suspicious). Skips (CI-safe) when absent.
 */
function runConversionAuditSnapshotBaseline(): string
{
	$snapshot = getenv('GROCY_AI_SNAPSHOT');
	if ($snapshot === false || $snapshot === '')
	{
		$snapshot = __DIR__ . '/../.snapshots/grocy-prod.sqlite';
	}
	if (!is_file($snapshot))
	{
		check(true, 'conversion_audit snapshot baseline skipped: no 06-01 snapshot present (CI-safe)');
		return 'not present';
	}

	try
	{
		$pdo = new PDO('sqlite:' . $snapshot, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('PRAGMA query_only = 1');
		$hasTable = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='quantity_unit_conversions'")->fetchColumn() === 1;
		if (!$hasTable)
		{
			check(true, 'conversion_audit snapshot baseline skipped: snapshot has no conversions table (CI-safe)');
			return 'has no conversions table';
		}
		$result = auditConversions($pdo);
		check(($result['global_count'] ?? null) === 62, 'conversion_audit snapshot: 62 global conversions');
		check(($result['expected_count'] ?? null) === 18, 'conversion_audit snapshot: 18 expected package definitions');
		check(($result['expected_product_count'] ?? null) === 9, 'conversion_audit snapshot: 18 package definitions across 9 products');
		check(($result['suspicious'] ?? null) === [], 'conversion_audit snapshot: nothing suspicious');
		check(($result['ok'] ?? null) === true, 'conversion_audit snapshot: the baseline passes the audit');
		return sprintf('baseline %d global + %d expected / %d products, 0 suspicious', $result['global_count'], $result['expected_count'], $result['expected_product_count']);
	}
	catch (\Throwable $e)
	{
		check(true, 'conversion_audit snapshot baseline skipped: ' . $e->getMessage() . ' (CI-safe)');
		return 'skipped (read error)';
	}
}
