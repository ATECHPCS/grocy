<?php

require_once __DIR__ . '/../custom/grocy_AI/src/GrocyAiGtin.php';

use GrocyAI\Services\GrocyAiGtin;

$pdo = isset($grocyAiMigrationDb) && $grocyAiMigrationDb instanceof \PDO
	? $grocyAiMigrationDb
	: \Grocy\Services\DatabaseService::GetInstance()->GetDbConnectionRaw();
$canonicalExpression = GrocyAiGtin::CanonicalSqlExpression('barcode');
$startedTransaction = !$pdo->inTransaction();

if ($startedTransaction)
{
	$pdo->beginTransaction();
}

try
{
	$collisionCount = (int)$pdo->query(
		'SELECT COUNT(*) FROM ('
		. 'SELECT ' . $canonicalExpression . ' AS canonical_gtin'
		. ' FROM product_barcodes'
		. ' GROUP BY canonical_gtin'
		. ' HAVING canonical_gtin IS NOT NULL AND COUNT(*) > 1'
		. ')'
	)->fetchColumn();
	if ($collisionCount !== 0)
	{
		throw new \RuntimeException('Canonical GTIN collisions require manual data resolution before migration 0256');
	}

	$pdo->exec(
		'CREATE UNIQUE INDEX ix_product_barcodes_canonical_gtin'
		. ' ON product_barcodes (' . $canonicalExpression . ')'
	);

	if ($startedTransaction)
	{
		$pdo->commit();
	}
}
catch (\Throwable $ex)
{
	if ($startedTransaction && $pdo->inTransaction())
	{
		$pdo->rollBack();
	}
	throw $ex;
}
