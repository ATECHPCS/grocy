<?php

declare(strict_types=1);

/**
 * conversion_audit.php — DATA-03/04/05 read-only conversion audit + regression tripwire (Phase 6 / 06-06).
 *
 * Exercises the read-only audit library (bin/audit-conversions.php) to prove, on production-shaped data:
 *   - against a table holding only global conversions (product_id IS NULL) the audit PASSES and reports
 *     the global count (DATA-03/04/05 closed by verification);
 *   - against a table seeded with one product-scoped conversion (product_id NOT NULL) the audit FAILS
 *     (the regression tripwire) and names the offending product;
 *   - the audit performs no writes: the conversions table is byte-identical (row-value equality) and
 *     SQLite total_changes() is unchanged across the audit call.
 *
 * When the 06-01 snapshot is present, an INFORMATIONAL pass reports the audit's observed counts against
 * the real snapshot (never fails the suite regardless of what it finds — the CLI is the enforcing path).
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

/** A fixture holding only global (product_id IS NULL) conversions — the shape the audit must accept. */
function conversionAuditGlobalOnlyPdo(int $globalRows = 3): PDO
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE quantity_unit_conversions (id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, from_qu_id INT NOT NULL, to_qu_id INT NOT NULL, factor REAL NOT NULL, product_id INT)');
	$seed = $pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, NULL)');
	for ($i = 1; $i <= $globalRows; $i++)
	{
		$seed->execute([$i, $i + 1, (float)$i]);
	}
	return $pdo;
}

function runConversionAuditSuite(): void
{
	// RED gate: without the audit library these assertions fail (Task 1 is RED until Task 2 lands the bin).
	if (!function_exists('auditConversions'))
	{
		check(false, 'conversion_audit: the read-only audit library bin/audit-conversions.php is available');
		return;
	}

	// (a) A table of only global conversions passes and the global count is reported exactly.
	$globalOnly = conversionAuditGlobalOnlyPdo(3);
	$result = auditConversions($globalOnly);
	check(($result['ok'] ?? null) === true, 'conversion_audit: a table of only global conversions passes the audit');
	check(($result['product_specific_count'] ?? null) === 0, 'conversion_audit: zero product-specific conversions are reported for a global-only table');
	check(($result['global_count'] ?? null) === 3, 'conversion_audit: the global conversion count is reported exactly');
	check(($result['offenders'] ?? null) === [], 'conversion_audit: a global-only table names no offenders');

	// (b) Tripwire: one product-scoped conversion (product_id NOT NULL) fails the audit and is named.
	$tripped = conversionAuditGlobalOnlyPdo(3);
	$tripped->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)')
		->execute([2, 11, 10.0, 42]);
	$trip = auditConversions($tripped);
	check(($trip['ok'] ?? null) === false, 'conversion_audit: a product-scoped conversion trips the audit');
	check(($trip['product_specific_count'] ?? null) === 1, 'conversion_audit: the product-specific conversion is counted');
	$offenderProductIds = array_map(static fn(array $r): int => (int)$r['product_id'], $trip['offenders'] ?? []);
	check($offenderProductIds === [42], 'conversion_audit: the tripwire names the offending product (product_id 42)');

	// (c) Zero-write: the audit performs no writes — row-value equality AND an unchanged total_changes().
	$readOnly = conversionAuditGlobalOnlyPdo(4);
	$rowsBefore = $readOnly->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$changesBefore = (int)$readOnly->query('SELECT total_changes()')->fetchColumn();
	auditConversions($readOnly);
	auditConversions($readOnly);
	$changesAfter = (int)$readOnly->query('SELECT total_changes()')->fetchColumn();
	$rowsAfter = $readOnly->query('SELECT * FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	check($rowsAfter === $rowsBefore, 'conversion_audit: the conversions table is byte-identical after auditing (row-value equality)');
	check($changesAfter === $changesBefore, 'conversion_audit: the audit executes zero writes (total_changes() unchanged)');

	// Informational: report the audit's observed counts on the real 06-01 snapshot when present. This
	// NEVER fails the suite — the bin/audit-conversions.php CLI is the enforcing path (non-zero exit).
	$observed = runConversionAuditSnapshotReport();

	fwrite(STDOUT, sprintf(
		"[conversion_audit] DATA-03/04/05 read-only audit + product-specific tripwire verified; snapshot %s\n",
		$observed
	));
}

/**
 * INFORMATIONAL snapshot report: if the 06-01 snapshot is present, open a working copy read-only, run the
 * audit, and return a human-readable summary of the observed counts. Never asserts pass/fail — it only
 * observes, so the suite stays green whatever the live data currently holds.
 */
function runConversionAuditSnapshotReport(): string
{
	$snapshot = getenv('GROCY_AI_SNAPSHOT');
	if ($snapshot === false || $snapshot === '')
	{
		$snapshot = __DIR__ . '/../.snapshots/grocy-prod.sqlite';
	}
	if (!is_file($snapshot))
	{
		check(true, 'conversion_audit snapshot report skipped: no 06-01 snapshot present (CI-safe)');
		return 'not present';
	}

	try
	{
		$pdo = new PDO('sqlite:' . $snapshot, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('PRAGMA query_only = 1');
		$hasTable = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='quantity_unit_conversions'")->fetchColumn() === 1;
		if (!$hasTable)
		{
			check(true, 'conversion_audit snapshot report skipped: snapshot has no conversions table (CI-safe)');
			return 'has no conversions table';
		}
		$result = auditConversions($pdo);
		return sprintf(
			'observed %d product-specific, %d global conversions',
			(int)$result['product_specific_count'],
			(int)$result['global_count']
		);
	}
	catch (\Throwable $e)
	{
		check(true, 'conversion_audit snapshot report skipped: ' . $e->getMessage() . ' (CI-safe)');
		return 'skipped (read error)';
	}
}
